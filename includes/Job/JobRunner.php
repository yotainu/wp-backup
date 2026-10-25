<?php

namespace WpPcloudBackup\Job;

use WpPcloudBackup\Db\DbDumper;
use WpPcloudBackup\History;
use WpPcloudBackup\Manifest;
use WpPcloudBackup\Notifier;
use WpPcloudBackup\Retention;
use WpPcloudBackup\Scan\FileScanner;
use WpPcloudBackup\Scheduler;
use WpPcloudBackup\Settings;
use WpPcloudBackup\StaleMonitor;
use WpPcloudBackup\Storage\StorageException;
use WpPcloudBackup\Storage\StorageInterface;
use WpPcloudBackup\WorkDir;
use WpPcloudBackup\Zip\PartWriter;
use WpPcloudBackup\Zip\ZipWriter;

/**
 * Resumable backup job: a step machine driven by short, time-boxed runs.
 *
 * Steps: prepare → scan → dump → compress → zip ⇄ upload → finalize → cleanup.
 * Every bit of progress lives in state.json inside the work directory, so any
 * run may die at any point and the next run continues from the last save.
 *
 * State schema (state.json):
 *
 *   step               string   Current step (one of STEPS).
 *   trigger            string   'schedule' | 'manual' | 'url'.
 *   started            int      Job start (epoch).
 *   slot               int|null Schedule slot the job was started for.
 *   stamp              string   Generation name, YYYY-MM-DD_HHMMSS in the site timezone.
 *   attempts           array    step => consecutive failures of that step.
 *   last_error         string|null Message of the last failure.
 *   last_run_started   int      Start of the last run (epoch).
 *   last_run_finished  int      End of the last run (epoch); older than last_run_started = the run died
 *                               (counted as a failed attempt of the current step by the next run).
 *   in_progress_file   string|null Relative path the zip step is adding (saved before addFile).
 *   last_fatal         array|null  {type, message, file} of a fatal error recorded by the shutdown handler.
 *   site_folder_id     int      Remote root_folder/site_folder.
 *   incomplete_id      int      Remote .incomplete-{stamp} folder.
 *   scanner            array    FileScanner::state().
 *   scan_lines         int      Lines in filelist.jsonl covered by the state.
 *   scan_bytes         int      Bytes of filelist.jsonl covered by the state.
 *   db                 array    DbDumper::state().
 *   zip_line           int      List lines consumed by the zip step.
 *   zip_byte_offset    int      Byte offset in filelist.jsonl matching zip_line.
 *   zip_bytes          int      Source bytes added to the archive.
 *   zip                array    ZipWriter::state().
 *   parts              array    PartWriter::state().
 *   return_to          string   Step to continue with once no part is pending ('zip' | 'finalize').
 *   warnings           string[] Warnings found after the scan (the scan's own are in scanner.warnings).
 *   db_sha1, db_sha256, db_size  Hashes and size of database.sql.gz.
 *   db_uploaded, manifest_uploaded, renamed  bool  Idempotent finalize sub-steps.
 *   finished_at        int      Completion time used by the report.
 *   pruned, history_added, last_success_saved, notified, reported  bool  Report sub-steps (each at most once).
 *
 * Settings written: last_scheduled (slot of a scheduled job), next_run (next slot; 0 = compute
 * from start_time; the admin page resets it to 0 when schedule or start_time change),
 * last_success; StaleMonitor (called from tick) writes last_stale_notice and activated_at.
 * runner.lock is never deleted.
 */
final class JobRunner {

	public const STEPS = array( 'prepare', 'scan', 'dump', 'compress', 'zip', 'upload', 'finalize', 'cleanup' );

	/**
	 * Failures of one step that abort the job.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * A job older than this (seconds) is aborted.
	 */
	public const MAX_JOB_SECONDS = 172800;

	private const STATE_FILE    = 'state.json';
	private const LOCK_FILE     = 'runner.lock';
	private const LIST_FILE     = 'filelist.jsonl';
	private const CENTRAL_FILE  = 'central.bin';
	private const SQL_FILE      = 'database.sql';
	private const GZ_FILE       = 'database.sql.gz';
	private const MANIFEST_FILE = 'manifest.json';
	private const PARTS_BASE    = 'files.zip';

	/**
	 * Coarse progress at the start of each step.
	 */
	private const PROGRESS = array(
		'prepare'  => 0.0,
		'scan'     => 0.05,
		'dump'     => 0.1,
		'compress' => 0.15,
		'zip'      => 0.2,
		'upload'   => 0.2,
		'finalize' => 0.95,
		'cleanup'  => 0.99,
	);

	/**
	 * Share of the progress bar covered by zip/upload (from 0.2 to 0.9).
	 */
	private const ZIP_SPAN = 0.7;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var WorkDir
	 */
	private $workDir;

	/**
	 * @var StorageInterface
	 */
	private $storage;

	/**
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * @var History
	 */
	private $history;

	/**
	 * Database access object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Returns the current epoch.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Site timezone (generation names, schedule grid, manifest).
	 *
	 * @var \DateTimeZone
	 */
	private $tz;

	/**
	 * Manifest metadata overrides.
	 *
	 * @var array
	 */
	private $env;

	/**
	 * "Backups have stopped" detection, run on every tick.
	 *
	 * @var StaleMonitor
	 */
	private $staleMonitor;

	/**
	 * State of the job being run (null outside run() or once the job is gone).
	 *
	 * @var JobState|null
	 */
	private $state = null;

	/**
	 * State instance holding the run lock.
	 *
	 * @var JobState|null
	 */
	private $lock = null;

	/**
	 * Runner whose run is executing, for the shutdown handler (null between runs).
	 *
	 * @var JobRunner|null
	 */
	private static $running = null;

	/**
	 * Whether the shutdown handler is registered (once per process).
	 *
	 * @var bool
	 */
	private static $shutdownRegistered = false;

	/**
	 * Memory released by the shutdown handler so it can still save after an out-of-memory error.
	 *
	 * @var string|null
	 */
	private static $reserve = null;

	/**
	 * @param Settings         $s     Settings.
	 * @param WorkDir          $w     Work directory.
	 * @param StorageInterface $st    Remote storage.
	 * @param Notifier         $n     Mail notifier.
	 * @param History          $h     Run history.
	 * @param \wpdb            $wpdb  Database access object.
	 * @param callable|null    $clock fn(): int, defaults to time().
	 * @param \DateTimeZone|null $tz  Site timezone, defaults to UTC.
	 * @param array            $env   Overrides for site_url, home_url, wp_version, plugin_version, abspath, storage_dir.
	 * @param StaleMonitor|null $staleMonitor Stale detection; defaults to one that knows the site is connected.
	 */
	public function __construct( Settings $s, WorkDir $w, StorageInterface $st, Notifier $n, History $h, $wpdb, ?callable $clock = null, ?\DateTimeZone $tz = null, array $env = array(), ?StaleMonitor $staleMonitor = null ) {
		$this->settings = $s;
		$this->workDir  = $w;
		$this->storage  = $st;
		$this->notifier = $n;
		$this->history  = $h;
		$this->wpdb     = $wpdb;
		$this->clock    = $clock ?? 'time';
		$this->tz       = $tz ?? new \DateTimeZone( 'UTC' );
		$this->env      = $env;

		$this->staleMonitor = $staleMonitor ?? new StaleMonitor( $s, $n, array( 'connected' => true ) );
	}

	/**
	 * Cron entry point: send a stale notice if due, start a job when next_run is due, run the job.
	 *
	 * The schedule lives in the next_run setting. After a scheduled job starts, next_run moves
	 * to the first grid slot after now (slots missed while nothing ran are not replayed).
	 */
	public function tick(): array {
		$now = $this->now();
		$this->staleMonitor->check( $now );

		if ( null !== $this->loadState() ) {
			return $this->run();
		}

		$slot = $this->nextRunAt( $now );
		if ( $slot <= $now && $this->startJob( 'schedule', $slot ) ) {
			$this->settings->update( array( 'next_run' => $this->gridAfter( $slot, $now ) ) );
			return $this->run();
		}

		return $this->status();
	}

	/**
	 * Time of the next scheduled job (epoch), for the admin page.
	 *
	 * When next_run is 0 (fresh install, or reset by the admin page after schedule or
	 * start_time changed, see Task 16) it is computed as the next start time + site
	 * offset after now and stored.
	 */
	public function nextRun(): int {
		return $this->nextRunAt( $this->now() );
	}

	/**
	 * Create a new job. The remote work happens in the first run.
	 *
	 * @param string   $trigger 'schedule' | 'manual' | 'url'.
	 * @param int|null $slot    Schedule slot (epoch) for a scheduled job; recorded as last_scheduled.
	 * @return bool False when a job already exists.
	 */
	public function startJob( string $trigger, ?int $slot = null ): bool {
		$this->workDir->ensure();

		if ( null !== $this->loadState() ) {
			return false;
		}

		$this->deleteJobFiles();

		$now = $this->now();
		JobState::create(
			$this->file( self::STATE_FILE ),
			array(
				'step'              => 'prepare',
				'trigger'           => $trigger,
				'started'           => $now,
				'slot'              => $slot,
				'stamp'             => ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $this->tz )->format( 'Y-m-d_His' ),
				'attempts'          => array(),
				'last_error'        => null,
				'last_run_started'  => 0,
				'last_run_finished' => 0,
				'warnings'          => array(),
			)
		);

		if ( 'schedule' === $trigger && null !== $slot ) {
			$this->settings->update( array( 'last_scheduled' => $slot ) );
		}

		return true;
	}

	/**
	 * One run: continue the job until the time budget is used up.
	 *
	 * Does nothing when no job exists, another run holds the lock, or the
	 * previous run started less than run_budget_sec × 3 ago and never finished.
	 */
	public function run(): array {
		$lock = $this->loadState();
		if ( null === $lock || ! $lock->acquireLock( $this->file( self::LOCK_FILE ) ) ) {
			return $this->status();
		}

		$this->lock = $lock;
		$tracked    = false;

		try {
			$this->state = $this->loadState();
			if ( null === $this->state ) {
				return $this->status();
			}

			$now          = $this->now();
			$budget       = $this->budget();
			$lastStarted  = (int) $this->state->get( 'last_run_started', 0 );
			$lastFinished = (int) $this->state->get( 'last_run_finished', 0 );

			if ( $lastStarted > 0 && $now - $lastStarted < $budget * 3 && $lastFinished < $lastStarted ) {
				$this->state = null;
				return $this->status();
			}

			// The previous run died (fatal error, time limit, out of memory): a failed attempt of its step.
			if ( $lastStarted > 0 && $lastFinished < $lastStarted && $this->countUnfinishedRun() ) {
				return $this->status();
			}

			$this->state->set( 'last_run_started', $now );
			$this->state->save();
			$tracked = true;
			$this->watchForFatalErrors();

			// Once the manifest is uploaded the generation is complete: never time it out.
			if ( ! $this->state->get( 'manifest_uploaded' ) && (int) $this->state->get( 'started', $now ) + self::MAX_JOB_SECONDS < $now ) {
				$this->abort( 'timeout' );
				return $this->status();
			}

			$deadline = $now + $budget;
			$this->runSteps(
				function () use ( $deadline ): bool {
					return $this->now() >= $deadline;
				}
			);
		} finally {
			if ( $this === self::$running ) {
				self::$running = null;
				self::$reserve = null;
			}
			if ( $tracked && null !== $this->state ) {
				try {
					$this->state->set( 'last_run_finished', $this->now() );
					$this->state->save();
				} catch ( \RuntimeException $e ) {
					// The next run sees an unfinished run; nothing else to do here.
					unset( $e );
				}
			}
			$this->state = null;
			if ( null !== $this->lock ) {
				$this->lock->releaseLock();
				$this->lock = null;
			}
		}

		return $this->status();
	}

	/**
	 * Progress summary for the admin screen and the cron URL.
	 */
	public function status(): array {
		$state = $this->loadState();

		if ( null === $state ) {
			return array(
				'running'        => false,
				'step'           => null,
				'progress'       => 0.0,
				'files_done'     => 0,
				'files_total'    => 0,
				'parts_uploaded' => 0,
				'started'        => null,
				'message'        => 'idle',
				'last_error'     => null,
			);
		}

		$step     = (string) $state->get( 'step', 'prepare' );
		$done     = (int) $state->get( 'zip_line', 0 );
		$total    = (int) $state->get( 'scan_lines', 0 );
		$uploaded = 0;
		foreach ( (array) ( $state->get( 'parts', array() )['parts'] ?? array() ) as $part ) {
			if ( ! empty( $part['uploaded'] ) ) {
				++$uploaded;
			}
		}

		$progress = self::PROGRESS[ $step ] ?? 0.0;
		$zipping  = 'zip' === $step || ( 'upload' === $step && 'zip' === $state->get( 'return_to' ) );
		if ( $zipping ) {
			$progress = self::PROGRESS['zip'] + self::ZIP_SPAN * min( 1, $done / max( 1, $total ) );
		} elseif ( 'upload' === $step ) {
			$progress = self::PROGRESS['zip'] + self::ZIP_SPAN;
		}

		$error = $state->get( 'last_error' );

		return array(
			'running'        => true,
			'step'           => $step,
			'progress'       => (float) max( 0.0, min( 1.0, $progress ) ),
			'files_done'     => $done,
			'files_total'    => $total,
			'parts_uploaded' => $uploaded,
			'started'        => (int) $state->get( 'started', 0 ),
			'message'        => $this->message( $step, $state, $done, $total, $uploaded ),
			'last_error'     => null === $error ? null : (string) $error,
		);
	}

	/**
	 * Fail the job: delete the remote .incomplete folder, notify, record history, delete job files.
	 *
	 * Outside a run the lock is taken first; if another run holds it, nothing happens.
	 */
	public function abort( string $reason ): void {
		$state = $this->state;

		if ( null === $state ) {
			$state = $this->loadState();
			if ( null === $state || ! $state->acquireLock( $this->file( self::LOCK_FILE ) ) ) {
				return;
			}
			$this->lock = $state;
		}

		$step     = (string) $state->get( 'step', 'prepare' );
		$attempts = (int) ( ( (array) $state->get( 'attempts', array() ) )[ $step ] ?? 0 );
		$folder   = $state->get( 'incomplete_id' );

		// Never delete a completed generation: after the rename the folder id points at it.
		if ( null !== $folder && ! $state->get( 'renamed' ) ) {
			try {
				if ( ! $this->renameAlreadyDone( $state ) ) {
					$this->storage->deleteFolder( (int) $folder );
				}
			} catch ( \Throwable $e ) {
				// Retention removes stale .incomplete folders later.
				unset( $e );
			}
		}

		try {
			$this->notifier->failure( $step, $reason, $attempts );
			$this->history->add(
				array(
					'started'  => (int) $state->get( 'started', 0 ),
					'finished' => $this->now(),
					'result'   => 'failed',
					'bytes'    => 0,
					'files'    => 0,
					'remote'   => '',
					'message'  => $reason,
				)
			);
		} finally {
			$this->deleteJobFiles();
			$this->state = null;

			if ( null !== $this->lock ) {
				$this->lock->releaseLock();
				$this->lock = null;
			}
		}
	}

	/**
	 * Record a fatal error of the current run in the state (called from the shutdown handler).
	 *
	 * Reloads the state from disk, so progress that was never saved is not persisted. Only the
	 * basename of files is kept. last_run_finished stays behind, so the next run counts the attempt.
	 *
	 * @internal
	 * @param array|null $error Value of error_get_last().
	 */
	public function recordFatal( ?array $error ): void {
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( null === $error || ! in_array( (int) ( $error['type'] ?? 0 ), $fatal, true ) ) {
			return;
		}

		try {
			$state = $this->loadState();
			if ( null === $state ) {
				return;
			}
			$state->set(
				'last_fatal',
				array(
					'type'    => (int) $error['type'],
					'message' => self::withoutPaths( (string) ( $error['message'] ?? '' ) ),
					'file'    => basename( (string) ( $error['file'] ?? '' ) ) . ':' . (int) ( $error['line'] ?? 0 ),
				)
			);
			$state->save();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Store a message as last_error of the current job, if there is one (used by Trigger for
	 * exceptions that escaped a run). Best effort: never throws.
	 */
	public function recordError( string $message ): void {
		try {
			$state = $this->loadState();
			if ( null !== $state ) {
				$state->set( 'last_error', $message );
				$state->save();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Drop directory parts of absolute paths in a message, keeping basenames.
	 */
	public static function withoutPaths( string $message ): string {
		return (string) preg_replace( '#(?<![\w.])/(?:[^/\s\'"()]+/)+#u', '', $message );
	}

	// ------------------------------------------------------------------
	// Run loop and failure policy.
	// ------------------------------------------------------------------

	/**
	 * Count the previous, unfinished run as a failed attempt of the current step.
	 *
	 * @return bool True when the job was aborted (MAX_ATTEMPTS reached).
	 */
	private function countUnfinishedRun(): bool {
		$step     = (string) $this->state->get( 'step', 'prepare' );
		$file     = $this->state->get( 'in_progress_file' );
		$fatal    = $this->state->get( 'last_fatal' );
		$attempts = (array) $this->state->get( 'attempts', array() );

		$attempts[ $step ] = (int) ( $attempts[ $step ] ?? 0 ) + 1;

		$message = sprintf(
			/* translators: %s: file or step that was being processed. */
			__( '前回の実行が途中で終了しました（時間制限/メモリ不足の可能性。処理中: %s）', 'wp-pcloud-backup' ),
			is_string( $file ) && '' !== $file ? $file : $step
		);
		if ( is_array( $fatal ) && isset( $fatal['message'] ) ) {
			$message .= ' ' . $fatal['message'] . ' (' . ( $fatal['file'] ?? '' ) . ')';
		}

		$this->state->merge(
			array(
				'attempts'   => $attempts,
				'last_error' => $message,
				'last_fatal' => null,
			)
		);

		// After the rename the generation is complete: keep retrying, never fail the job.
		$complete = 'finalize' === $step && $this->state->get( 'renamed' );
		if ( ! $complete && $attempts[ $step ] >= self::MAX_ATTEMPTS ) {
			$this->abort( $message );
			return true;
		}

		return false;
	}

	/**
	 * Arm the shutdown handler for this run. It is registered once per process and holds no
	 * reference to a runner between runs.
	 */
	private function watchForFatalErrors(): void {
		self::$running = $this;
		self::$reserve = str_repeat( ' ', 65536 );

		if ( self::$shutdownRegistered ) {
			return;
		}
		self::$shutdownRegistered = true;

		register_shutdown_function(
			static function (): void {
				self::$reserve = null;
				if ( null !== self::$running ) {
					self::$running->recordFatal( error_get_last() );
				}
			}
		);
	}

	/**
	 * Execute steps until the budget is used up or a step stops early.
	 *
	 * Step switches (zip -> upload -> zip, upload -> finalize) and further part uploads
	 * happen inside the same run while time remains. An upload itself is never cut by
	 * the budget, so a run sends at least one pending part and may overrun by one part.
	 */
	private function runSteps( callable $timeUp ): void {
		while ( null !== $this->state ) {
			$step = (string) $this->state->get( 'step' );

			try {
				$completed = $this->runStep( $step, $timeUp );
			} catch ( \Throwable $e ) {
				$this->handleFailure( $step, $e );
				return;
			}

			if ( null === $this->state || ! $completed || $timeUp() ) {
				return;
			}
		}
	}

	/**
	 * @return bool True when the step finished (the state moved on), false when it stopped on the budget.
	 */
	private function runStep( string $step, callable $timeUp ): bool {
		switch ( $step ) {
			case 'prepare':
				return $this->stepPrepare();
			case 'scan':
				return $this->stepScan( $timeUp );
			case 'dump':
				return $this->stepDump( $timeUp );
			case 'compress':
				return $this->stepCompress( $timeUp );
			case 'zip':
				return $this->stepZip( $timeUp );
			case 'upload':
				return $this->stepUpload();
			case 'finalize':
				return $this->stepFinalize();
			case 'cleanup':
				$this->deleteJobFiles();
				$this->state = null;
				return true;
		}

		throw new \RuntimeException( 'Unknown job step: ' . $step );
	}

	/**
	 * Count the failure; abort on a permanent error or the fifth failure, otherwise retry next run.
	 */
	private function handleFailure( string $step, \Throwable $error ): void {
		if ( null === $this->state ) {
			return; // The job is already gone (failed inside abort()).
		}

		$attempts          = (array) $this->state->get( 'attempts', array() );
		$attempts[ $step ] = (int) ( $attempts[ $step ] ?? 0 ) + 1;
		$message           = $error->getMessage();

		$this->state->merge(
			array(
				'attempts'   => $attempts,
				'last_error' => $message,
			)
		);

		$permanent = $error instanceof StorageException
			&& ( ! $error->isRetryable() || in_array( $error->category(), array( 'auth', 'quota' ), true ) );

		// After the rename the generation is complete: keep retrying, never fail the job.
		$complete = 'finalize' === $step && $this->state->get( 'renamed' );

		if ( ! $complete && ( $permanent || $attempts[ $step ] >= self::MAX_ATTEMPTS ) ) {
			$this->abort( $message );
			return;
		}

		try {
			$this->state->save();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
	}

	/**
	 * Move to the next step, clearing the failure count of the finished one, and save.
	 */
	private function advance( string $next ): void {
		$this->resetAttempts();
		$this->state->set( 'step', $next );
		$this->state->save();
	}

	private function resetAttempts(): void {
		$attempts = (array) $this->state->get( 'attempts', array() );
		$attempts[ (string) $this->state->get( 'step' ) ] = 0;
		$this->state->merge(
			array(
				'attempts'   => $attempts,
				'last_error' => null,
			)
		);
	}

	// ------------------------------------------------------------------
	// Steps.
	// ------------------------------------------------------------------

	private function stepPrepare(): bool {
		$base = $this->siteFolderPath();

		$siteId       = $this->storage->ensureFolder( $base );
		$incompleteId = $this->storage->ensureFolder( $base . '/.incomplete-' . $this->state->get( 'stamp' ) );

		$this->state->merge(
			array(
				'site_folder_id' => $siteId,
				'incomplete_id'  => $incompleteId,
			)
		);
		$this->advance( 'scan' );

		return true;
	}

	private function stepScan( callable $timeUp ): bool {
		$list      = $this->file( self::LIST_FILE );
		$scanBytes = (int) $this->state->get( 'scan_bytes', 0 );

		// Drop lines a crashed run appended after the last save.
		clearstatcache( true, $list );
		$actual = is_file( $list ) ? (int) filesize( $list ) : 0;
		if ( $actual < $scanBytes ) {
			throw new \RuntimeException( 'File list is shorter than recorded.' );
		}
		if ( $actual > $scanBytes ) {
			$this->truncateFile( $list, $scanBytes );
		}

		$scanner = new FileScanner( $this->backupRoot(), $this->excludes(), $list, (array) $this->state->get( 'scanner', array() ), $this->largeFileBytes() );
		$done    = $scanner->run( $timeUp );
		$scan    = $scanner->state();

		clearstatcache( true, $list );
		$this->state->merge(
			array(
				'scanner'    => $scan,
				'scan_lines' => (int) $scan['files'],
				'scan_bytes' => is_file( $list ) ? (int) filesize( $list ) : 0,
			)
		);

		if ( $done ) {
			$this->advance( 'dump' );
		} else {
			$this->state->save();
		}

		return $done;
	}

	private function stepDump( callable $timeUp ): bool {
		$dumper = new DbDumper( $this->wpdb, $this->file( self::SQL_FILE ), $this->file( self::GZ_FILE ), (array) $this->state->get( 'db', array() ) );
		$done   = $dumper->dump( $timeUp );
		$db     = $dumper->state();

		$this->state->set( 'db', $db );

		if ( $done && array() === $db['tables'] ) {
			$this->abort( 'no tables found' );
			return true;
		}

		if ( $done ) {
			$this->advance( 'compress' );
		} else {
			$this->state->save();
		}

		return $done;
	}

	private function stepCompress( callable $timeUp ): bool {
		$sql = $this->file( self::SQL_FILE );
		$gz  = $this->file( self::GZ_FILE );
		$db  = (array) $this->state->get( 'db', array() );

		// A previous run finished compressing (and removed the SQL file) but died before saving.
		// Checked before constructing DbDumper, which would cut the gzip file back to gz_bytes.
		clearstatcache();
		$gzSize = is_file( $gz ) ? (int) filesize( $gz ) : 0;
		if ( 'compress' === ( $db['phase'] ?? '' ) && ! is_file( $sql ) && ( (int) ( $db['compress_offset'] ?? 0 ) > 0 || $gzSize > 0 ) ) {
			// Accept the gzip only if it holds the whole dump; a SQL file lost any other way
			// leaves a prefix, which fails here (retried, then the job aborts).
			if ( $this->gzipPlainBytes( $gz ) !== (int) ( $db['sql_bytes'] ?? -1 ) ) {
				throw new \RuntimeException( 'database.sql is missing and database.sql.gz does not hold the whole dump.' );
			}
			$db['phase']    = 'done';
			$db['gz_bytes'] = $gzSize;
			$this->state->set( 'db', $db );
			$this->advance( 'zip' );

			return true;
		}

		$dumper = new DbDumper( $this->wpdb, $sql, $gz, $db );
		$done   = $dumper->compress( $timeUp );

		$this->state->set( 'db', $dumper->state() );

		if ( $done ) {
			$this->advance( 'zip' );
		} else {
			$this->state->save();
		}

		return $done;
	}

	/**
	 * Add listed files to the archive; hand over to upload as soon as a part is complete.
	 */
	private function stepZip( callable $timeUp ): bool {
		$sink = new PartWriter( $this->workDir->path(), self::PARTS_BASE, $this->settings->partSizeBytes(), (array) $this->state->get( 'parts', array() ) );
		$zip  = new ZipWriter( $sink, $this->file( self::CENTRAL_FILE ), (array) $this->state->get( 'zip', array() ) );

		// The previous run died (or failed) in the middle of an entry.
		if ( null !== $zip->entryStartOffset() ) {
			$sink->truncateTo( $zip->entryStartOffset() );
		}

		$list   = $this->file( self::LIST_FILE );
		$handle = @fopen( $list, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			throw new \RuntimeException( 'Cannot open the file list.' );
		}

		$line     = (int) $this->state->get( 'zip_line', 0 );
		$offset   = (int) $this->state->get( 'zip_byte_offset', 0 );
		$bytes    = (int) $this->state->get( 'zip_bytes', 0 );
		$warnings = (array) $this->state->get( 'warnings', array() );
		$root     = $this->backupRoot();

		try {
			if ( 0 !== fseek( $handle, $offset ) ) {
				throw new \RuntimeException( 'Cannot seek in the file list.' );
			}

			$progress = function () use ( &$line, &$offset, &$bytes, &$warnings, $zip, $sink ): array {
				return array(
					'zip_line'         => $line,
					'zip_byte_offset'  => $offset,
					'zip_bytes'        => $bytes,
					'warnings'         => $warnings,
					'zip'              => $zip->state(),
					'parts'            => $sink->state(),
					'in_progress_file' => null,
				);
			};

			while ( true ) {
				$raw = fgets( $handle );

				if ( false === $raw ) {
					$zip->finish();
					$this->state->merge( $progress() );
					$this->state->set( 'return_to', 'finalize' );
					$this->advance( array() === $sink->pendingUploads() ? 'finalize' : 'upload' );

					return true;
				}

				$entry   = json_decode( rtrim( $raw, "\r\n" ), true );
				$valid   = is_array( $entry ) && isset( $entry['p'] ) && is_string( $entry['p'] );
				$relPath = $valid ? $entry['p'] : null;

				// Progress up to the previous entry, plus the entry about to be added: if the run
				// dies inside addFile(), the next run can name the file (see countUnfinishedRun()).
				$this->state->merge( $progress() );
				$this->state->set( 'in_progress_file', $relPath );
				$this->state->save();

				if ( ! $valid ) {
					$warnings[] = 'invalid file list line: ' . ( $line + 1 );
				} else {
					$absPath = $root . '/' . $relPath;

					try {
						$added  = $zip->addFile( $absPath, $relPath, (int) ( $entry['m'] ?? 0 ) );
						$bytes += $added['size'];
					} catch ( \InvalidArgumentException $e ) {
						$warnings[] = 'invalid file name: ' . $relPath;
					} catch ( \RuntimeException $e ) {
						if ( is_file( $absPath ) && is_readable( $absPath ) ) {
							throw $e; // The file is fine: a write or disk error, retried by the failure policy.
						}
						$warnings[] = 'unreadable file: ' . $relPath;
						if ( null !== $zip->entryStartOffset() ) {
							$sink->truncateTo( $zip->entryStartOffset() );
						}
					}
				}

				++$line;
				$offset += strlen( $raw );

				if ( array() !== $sink->pendingUploads() ) {
					$this->state->merge( $progress() );
					$this->state->set( 'return_to', 'zip' );
					$this->advance( 'upload' );

					return true;
				}

				if ( $timeUp() ) {
					$this->state->merge( $progress() );
					$this->state->save();

					return false;
				}
			}
		} catch ( \Throwable $e ) {
			// Mid-entry, keep the writers' view (in_progress_offset included) so the next run rolls
			// the entry back. Otherwise (e.g. finish() failed half-way) keep the last saved state:
			// rebuilding the writers from it discards whatever was written after it.
			if ( null !== $zip->entryStartOffset() ) {
				$this->state->merge(
					array(
						'zip'   => $zip->state(),
						'parts' => $sink->state(),
					)
				);
			}
			throw $e;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Send the first pending part, regardless of the budget. runSteps() calls this again
	 * for the next part while the budget allows.
	 */
	private function stepUpload(): bool {
		$sink    = new PartWriter( $this->workDir->path(), self::PARTS_BASE, $this->settings->partSizeBytes(), (array) $this->state->get( 'parts', array() ) );
		$pending = $sink->pendingUploads();

		if ( array() !== $pending ) {
			$part = $pending[0];
			$this->uploadVerified( (string) $part['path'], (string) $part['name'], (string) $part['sha1'] );

			$sink->markUploaded( (int) $part['index'] );
			$this->state->set( 'parts', $sink->state() );
			$this->resetAttempts();
			$this->state->save();

			// Delete only after the state records the upload, so a crash never loses an un-uploaded part.
			@unlink( (string) $part['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( array() === $sink->pendingUploads() ) {
			$this->advance( (string) $this->state->get( 'return_to', 'finalize' ) );
		}

		return true;
	}

	/**
	 * Upload database, manifest, rename the generation, prune, report.
	 */
	private function stepFinalize(): bool {
		$incompleteId = (int) $this->state->get( 'incomplete_id' );
		$gz           = $this->file( self::GZ_FILE );

		if ( null === $this->state->get( 'db_sha1' ) ) {
			$sha1   = is_file( $gz ) ? sha1_file( $gz ) : false;
			$sha256 = is_file( $gz ) ? hash_file( 'sha256', $gz ) : false;
			if ( false === $sha1 || false === $sha256 ) {
				throw new \RuntimeException( 'Cannot hash the database dump.' );
			}
			clearstatcache( true, $gz );
			$this->state->merge(
				array(
					'db_sha1'   => $sha1,
					'db_sha256' => $sha256,
					'db_size'   => (int) filesize( $gz ),
				)
			);
			$this->state->save();
		}

		if ( ! $this->state->get( 'db_uploaded' ) ) {
			$this->uploadVerified( $gz, Manifest::DATABASE_FILE, (string) $this->state->get( 'db_sha1' ) );
			$this->state->set( 'db_uploaded', true );
			$this->state->save();
		}

		if ( ! $this->state->get( 'manifest_uploaded' ) ) {
			$this->assertAllPartsUploaded();

			try {
				$json = Manifest::build( $this->manifestInput() );
			} catch ( \RuntimeException $e ) {
				$this->abort( $e->getMessage() );
				return true;
			}

			$path = $this->file( self::MANIFEST_FILE );
			if ( strlen( $json ) !== file_put_contents( $path, $json ) ) {
				throw new \RuntimeException( 'Cannot write manifest.json.' );
			}
			$this->uploadVerified( $path, self::MANIFEST_FILE, sha1( $json ) );
			$this->state->set( 'manifest_uploaded', true );
			$this->state->save();
		}

		if ( ! $this->state->get( 'renamed' ) ) {
			$this->renameGeneration( $incompleteId );
			$this->state->set( 'renamed', true );
			$this->state->save();
		}

		if ( ! $this->state->get( 'reported' ) ) {
			$this->report();
			$this->state->set( 'reported', true );
			$this->state->save();
		}

		$this->advance( 'cleanup' );

		return true;
	}

	/**
	 * @throws \RuntimeException When a part is still pending or unfinished (retried by the failure policy).
	 */
	private function assertAllPartsUploaded(): void {
		$sink  = new PartWriter( $this->workDir->path(), self::PARTS_BASE, $this->settings->partSizeBytes(), (array) $this->state->get( 'parts', array() ) );
		$parts = $sink->completedParts();

		if ( array() === $parts || array() !== $sink->pendingUploads() || 0 !== (int) $sink->state()['current_size'] ) {
			throw new \RuntimeException( 'Not every archive part has been uploaded.' );
		}
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Upload a file and compare the SHA-1 the storage reports.
	 *
	 * @throws \RuntimeException On a checksum mismatch (retried by the failure policy).
	 */
	private function uploadVerified( string $path, string $name, string $sha1 ): void {
		$result = $this->storage->uploadFile( (int) $this->state->get( 'incomplete_id' ), $path, $name );

		if ( ! hash_equals( strtolower( $sha1 ), strtolower( (string) ( $result['sha1'] ?? '' ) ) ) ) {
			throw new \RuntimeException( 'Checksum mismatch after uploading ' . $name );
		}
	}

	/**
	 * Rename .incomplete-{stamp} to {stamp}; a rename that already happened (crash before saving) counts as done.
	 */
	private function renameGeneration( int $incompleteId ): void {
		$stamp = (string) $this->state->get( 'stamp' );

		try {
			$this->storage->renameFolder( $incompleteId, $stamp );
		} catch ( StorageException $e ) {
			if ( ! $this->renameAlreadyDone( $this->state ) ) {
				throw $e;
			}
		}
	}

	/**
	 * Whether the remote site folder holds {stamp} and no longer holds .incomplete-{stamp}.
	 */
	private function renameAlreadyDone( JobState $state ): bool {
		$stamp      = (string) $state->get( 'stamp' );
		$renamed    = false;
		$incomplete = false;

		foreach ( $this->storage->listFolder( (int) $state->get( 'site_folder_id' ) ) as $item ) {
			if ( empty( $item['isfolder'] ) ) {
				continue;
			}
			$renamed    = $renamed || $stamp === (string) $item['name'];
			$incomplete = $incomplete || '.incomplete-' . $stamp === (string) $item['name'];
		}

		return $renamed && ! $incomplete;
	}

	/**
	 * Prune, history entry, last_success, completion mail: each runs at most once (flag saved
	 * right after it). A failure becomes a warning in the state and never fails the job.
	 */
	private function report(): void {
		if ( null === $this->state->get( 'finished_at' ) ) {
			$this->state->set( 'finished_at', $this->now() );
			$this->state->save();
		}

		$finished = (int) $this->state->get( 'finished_at' );
		$started  = (int) $this->state->get( 'started', $finished );
		$files    = (int) ( ( (array) $this->state->get( 'zip', array() ) )['entries'] ?? 0 );
		$remote   = $this->siteFolderPath() . '/' . $this->state->get( 'stamp' );
		$bytes    = (int) $this->state->get( 'db_size', 0 );
		foreach ( $this->completedParts() as $part ) {
			$bytes += (int) $part['size'];
		}

		$tasks = array(
			'pruned'             => array(
				'retention',
				function () use ( $finished ): void {
					( new Retention( $this->storage, (int) $this->state->get( 'site_folder_id' ) ) )->prune( (int) $this->settings->get( 'keep' ), $finished );
				},
			),
			'history_added'      => array(
				'history',
				function () use ( $started, $finished, $bytes, $files, $remote ): void {
					$warnings = $this->allWarnings();
					$this->history->add(
						array(
							'started'  => $started,
							'finished' => $finished,
							'result'   => array() === $warnings ? 'success' : 'warning',
							'bytes'    => $bytes,
							'files'    => $files,
							'remote'   => $remote,
							'message'  => array() === $warnings ? '' : count( $warnings ) . ' warning(s)',
						)
					);
				},
			),
			'last_success_saved' => array(
				'last_success',
				function () use ( $finished ): void {
					$this->settings->update( array( 'last_success' => $finished ) );
				},
			),
			'notified'           => array(
				'notification',
				function () use ( $started, $finished, $bytes, $files, $remote ): void {
					$this->notifier->completed(
						array(
							'bytes'    => $bytes,
							'files'    => $files,
							'duration' => $finished - $started,
							'remote'   => $remote,
						),
						$this->allWarnings()
					);
				},
			),
		);

		foreach ( $tasks as $flag => list( $label, $task ) ) {
			if ( $this->state->get( $flag ) ) {
				continue;
			}

			try {
				$task();
			} catch ( \Throwable $e ) {
				$warnings   = (array) $this->state->get( 'warnings', array() );
				$warnings[] = $label . ' failed: ' . $e->getMessage();
				$this->state->set( 'warnings', $warnings );
			}

			$this->state->set( $flag, true );
			$this->state->save();
		}
	}

	private function manifestInput(): array {
		$db = (array) $this->state->get( 'db', array() );

		return array(
			'plugin_version' => $this->envValue( 'plugin_version' ),
			'created_at'     => (int) $this->state->get( 'started', 0 ),
			'timezone'       => $this->tz,
			'site_url'       => $this->envValue( 'site_url' ),
			'home_url'       => $this->envValue( 'home_url' ),
			'wp_version'     => $this->envValue( 'wp_version' ),
			'php_version'    => PHP_VERSION,
			'backup_root'    => (string) $this->settings->get( 'backup_root' ),
			'abspath'        => $this->envValue( 'abspath' ),
			'files_count'    => (int) ( ( (array) $this->state->get( 'zip', array() ) )['entries'] ?? 0 ),
			'files_bytes'    => (int) $this->state->get( 'zip_bytes', 0 ),
			'parts'          => array_map(
				static function ( array $part ): array {
					return array(
						'name'   => (string) $part['name'],
						'size'   => (int) $part['size'],
						'sha1'   => (string) $part['sha1'],
						'sha256' => (string) $part['sha256'],
					);
				},
				$this->completedParts()
			),
			'database'       => array(
				'tables' => (array) ( $db['tables'] ?? array() ),
				'rows'   => (int) ( $db['rows'] ?? 0 ),
				'sha1'   => (string) $this->state->get( 'db_sha1', '' ),
				'sha256' => (string) $this->state->get( 'db_sha256', '' ),
			),
			'warnings'       => $this->allWarnings(),
			'excludes'       => (array) $this->settings->get( 'excludes' ),
		);
	}

	/**
	 * @return array[]
	 */
	private function completedParts(): array {
		return (array) ( ( (array) $this->state->get( 'parts', array() ) )['parts'] ?? array() );
	}

	/**
	 * Scan warnings followed by zip warnings.
	 *
	 * @return string[]
	 */
	private function allWarnings(): array {
		$scanner = (array) $this->state->get( 'scanner', array() );

		return array_values( array_merge( (array) ( $scanner['warnings'] ?? array() ), (array) $this->state->get( 'warnings', array() ) ) );
	}

	/**
	 * Stored next_run, computed and stored first when it is 0.
	 */
	private function nextRunAt( int $now ): int {
		$next = (int) $this->settings->get( 'next_run' );

		if ( $next <= 0 ) {
			$next = Scheduler::nextRun( (string) $this->settings->get( 'schedule' ), (string) $this->settings->get( 'start_time' ), $this->siteOffset(), null, $now, $this->tz );
			$this->settings->update( array( 'next_run' => $next ) );
		}

		return $next;
	}

	/**
	 * First grid slot after $now, counting periods from $slot.
	 */
	private function gridAfter( int $slot, int $now ): int {
		return Scheduler::nextRun( (string) $this->settings->get( 'schedule' ), (string) $this->settings->get( 'start_time' ), $this->siteOffset(), $slot, $now, $this->tz );
	}

	private function siteOffset(): int {
		return Scheduler::offsetMinutes( (string) $this->settings->get( 'site_folder' ) );
	}

	private function message( string $step, JobState $state, int $done, int $total, int $uploaded ): string {
		switch ( $step ) {
			case 'scan':
				return sprintf( 'scan: %d files listed', (int) ( ( (array) $state->get( 'scanner', array() ) )['files'] ?? 0 ) );
			case 'dump':
				return sprintf( 'dump: %d rows', (int) ( ( (array) $state->get( 'db', array() ) )['rows'] ?? 0 ) );
			case 'zip':
				return sprintf( 'zip: %d / %d files', $done, $total );
			case 'upload':
				return sprintf( 'upload: %d parts uploaded', $uploaded );
			default:
				return $step;
		}
	}

	/**
	 * Delete the job's files in the work directory; state.json goes last so a crash mid-way is
	 * re-cleaned. runner.lock is kept: deleting a lock file other processes may open breaks flock.
	 */
	private function deleteJobFiles(): void {
		try {
			$dir = $this->workDir->path();
		} catch ( \RuntimeException $e ) {
			return;
		}

		$files = glob( $dir . '/' . self::PARTS_BASE . '.*' );
		foreach ( array( self::LIST_FILE, self::CENTRAL_FILE, self::SQL_FILE, self::GZ_FILE, self::MANIFEST_FILE, self::STATE_FILE . '.tmp', self::STATE_FILE ) as $name ) {
			$files[] = $dir . '/' . $name;
		}
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	private function loadState(): ?JobState {
		try {
			$file = $this->workDir->path( self::STATE_FILE );
		} catch ( \RuntimeException $e ) {
			return null;
		}

		return JobState::load( $file );
	}

	private function file( string $name ): string {
		return $this->workDir->path( $name );
	}

	private function now(): int {
		return (int) ( $this->clock )();
	}

	private function budget(): int {
		return max( 1, (int) $this->settings->get( 'run_budget_sec' ) );
	}

	private function siteFolderPath(): string {
		return rtrim( (string) $this->settings->get( 'root_folder' ), '/' ) . '/' . $this->settings->get( 'site_folder' );
	}

	/**
	 * Large-file warning threshold: WP_PCLOUD_BACKUP_LARGE_FILE_MB (0 disables), else FileScanner's default.
	 */
	private function largeFileBytes(): int {
		if ( defined( 'WP_PCLOUD_BACKUP_LARGE_FILE_MB' ) ) {
			return max( 0, (int) constant( 'WP_PCLOUD_BACKUP_LARGE_FILE_MB' ) ) * 1048576;
		}

		return FileScanner::LARGE_FILE_BYTES;
	}

	private function backupRoot(): string {
		return rtrim( (string) $this->settings->get( 'backup_root' ), '/\\' );
	}

	/**
	 * Configured excludes plus the work directory and the local storage directory
	 * (development mode) when they lie inside the backup root.
	 *
	 * @return string[]
	 */
	private function excludes(): array {
		$excludes = array_values( (array) $this->settings->get( 'excludes' ) );
		$root     = $this->backupRoot() . '/';

		foreach ( array( $this->workDir->path(), $this->envValue( 'storage_dir' ) ) as $dir ) {
			$dir = rtrim( $dir, '/\\' );
			if ( '' !== $dir && 0 === strpos( $dir . '/', $root ) && $dir . '/' !== $root ) {
				$excludes[] = substr( $dir, strlen( $root ) );
			}
		}

		return $excludes;
	}

	/**
	 * Uncompressed size of a (possibly multi-member) gzip file, read in 1 MiB chunks.
	 *
	 * @throws \RuntimeException If the file cannot be read.
	 */
	private function gzipPlainBytes( string $file ): int {
		$handle = @gzopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			throw new \RuntimeException( 'Cannot open database.sql.gz.' );
		}

		$bytes = 0;
		try {
			while ( ! gzeof( $handle ) ) {
				$chunk = gzread( $handle, 1048576 );
				if ( false === $chunk ) {
					throw new \RuntimeException( 'Cannot read database.sql.gz.' );
				}
				if ( '' === $chunk ) {
					break;
				}
				$bytes += strlen( $chunk );
			}
		} finally {
			gzclose( $handle );
		}

		return $bytes;
	}

	private function truncateFile( string $path, int $size ): void {
		$handle = @fopen( $path, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle || ! ftruncate( $handle, $size ) ) {
			if ( false !== $handle ) {
				fclose( $handle );
			}
			throw new \RuntimeException( 'Cannot truncate ' . basename( $path ) );
		}
		fclose( $handle );
		clearstatcache( true, $path );
	}

	private function envValue( string $key ): string {
		if ( array_key_exists( $key, $this->env ) ) {
			return (string) $this->env[ $key ];
		}

		switch ( $key ) {
			case 'site_url':
				return function_exists( 'site_url' ) ? (string) site_url() : '';
			case 'home_url':
				return function_exists( 'home_url' ) ? (string) home_url() : '';
			case 'wp_version':
				return (string) ( $GLOBALS['wp_version'] ?? '' );
			case 'plugin_version':
				return defined( 'WP_PCLOUD_BACKUP_VERSION' ) ? (string) constant( 'WP_PCLOUD_BACKUP_VERSION' ) : 'dev';
			case 'abspath':
				return defined( 'ABSPATH' ) ? (string) ABSPATH : '';
			case 'storage_dir':
				return (string) \WpPcloudBackup\Plugin::devStorageDir();
		}

		return '';
	}
}
