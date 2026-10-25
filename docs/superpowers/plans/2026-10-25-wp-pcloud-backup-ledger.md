# SDD ledger — plan: docs/superpowers/plans/2026-10-25-wp-pcloud-backup.md

Spec: docs/superpowers/specs/2026-10-24-wpbackup-design.md (read; binding authority)

## Preflight scan (2026-10-25)

| Pair / Task | Produces vs consumes | Finding |
|---|---|---|
| T2 Settings ↔ T3 Crypto | accessToken()/setAccessToken() via Crypto::encrypt/decrypt | consistent |
| T2 ↔ T4 BackupRoot | backup_root default/validate | consistent |
| T5 WorkDir/JobState ↔ T14 | ctor WorkDir(Settings, uploadsBaseDir); JobState acquireLock/save | consistent |
| T6 FileScanner ↔ T14 zip step | JSONL {"p","s","m"}; state.pending | consistent |
| T7 PartWriter ↔ T8 ZipWriter ↔ T14 | write/offset/truncateTo/finish/pendingUploads/markUploaded; entryStartOffset | consistent |
| T9 DbDumper ↔ T14 | dump()/compress() phases map to steps dump/compress | consistent |
| T10 StorageInterface ↔ T11 Retention ↔ T14 | listFolder must return modified (LocalStorage: mtime) | consistent; LocalStorage must expose mtime as modified |
| T12 Scheduler ↔ T14 tick ↔ T15 registerCron | nextRun/isStale/registerCron | consistent |
| T13 Notifier ↔ T14 ↔ T17 | failure/stalled/completed/updateFailed signatures | consistent |
| T15 Plugin::boot ↔ T16 AdminPage ↔ T17 Updater | Plugin::runner()/settings(); Updater::register added in T17 | consistent |
| T1 self | Step 1 `git init -b main` | repo already initialized by controller → implementer skips git init |
| T8 self | tests use ZipArchive; php:7.4-cli lacks ext-zip | plan already allows Dockerfile.test; fine |
| T14 self | test wants 512KB parts but part_size_mb ∈ {10,25,50,100} | see Ruling 2 |
| Global ↔ step commit commands | steps omit Co-Authored-By trailer required by Global Constraints | implementers add trailer (carried in every dispatch) |

Ruling: work directly on branch `main` of the freshly initialized repo (no worktree) — greenfield project with no remote, no collaborators, user approved a plan whose Task 1 initializes main — cost if wrong: none beyond history on main.
Ruling: Settings gains `partSizeBytes(): int` (= part_size_mb × 1048576) and JobRunner reads part size only through it, so tests can stub sub-MB sizes — plan T14 test needs 512KB parts while the setting is MB-granular — cost if wrong: one extra trivial method.
Ruling: Task 1 Step 1 (`git init -b main`) already performed by controller; implementer starts from Step 1's .gitignore/.distignore.

## Progress
Task 1: implemented (commit c1232e3), review dispatched 2026-10-25
Task 1: minor (deferred): bin/test.sh only installs deps when vendor/ missing (stale deps after composer.json change)
Task 1: minor (deferred): .distignore has no catch-all for stray root *.md
Task 1: minor (deferred): TempDir::create ignores mkdir return
Task 1: Ruling: add failOnWarning/failOnRisky/beStrictAboutOutputDuringTests to phpunit.xml.dist in Task 2 (reviewer minor; cheap, improves 7.4/8.2 matrix signal) — cost if wrong: a noisy warning fails a run
Task 1: complete (commit c1232e3 root, review clean; trailer verified by controller)
Task 2: implemented (commit f5c1be3); concern: composer.lock resolved for PHP 8.4 due to --ignore-platform-reqs in bin/test.sh → implementer resumed to fix before review
Task 2: minor (deferred): get() recomputes defaults each call (memoize later if profiling shows cost)
Task 2: minor (deferred): non-string input to sanitizers warns (admin-only input)
Task 2: minor (deferred): all() exposes access_token_enc — UI must never dump all()
Task 2: Ruling: carry three reviewer minors into Task 3 (it edits Settings anyway): (1) save() re-reads the option and merges only the keys changed by this instance, to avoid cron/admin lost updates; (2) reject `.`/`..` path segments in site_folder and root_folder; (3) drop `final` from Settings so Task 14 tests can subclass it — cost if wrong: slightly larger Task 3 diff
Task 2: complete (commits c1232e3..9cc9f43, review clean)
Task 3: implemented (commit cc7df7b), review dispatched
Task 3: review found 1 Important (save() reads option through alloptions cache → stale merge in long cron run); fix round 1 dispatched to implementer
Task 3: minor (deferred): Crypto::isAvailable() not enforced by encrypt (undefined-function Error without openssl) — activation check in Task 18 covers the host-level case
Task 3: minor (deferred): empty/placeholder AUTH_KEY accepted by key derivation
Task 3: minor (deferred): no-plaintext test only asserts blob != 'tok'; setAccessToken('') untested
Task 3: minor (deferred): CryptoTest::setUp branches on getName() (brittle)
Task 3: fix round 1/5 (1 addressed, 0 open — option cache bust before merge; commits cc7df7b..0bbed9f)
Task 3: minor (deferred): long cron process reading Settings sees values loaded at run start (read staleness)
Task 3: complete (commits 9cc9f43..0bbed9f, review clean)
Task 4: implemented (commit 13f2064), review dispatched
Task 4: minor (deferred, FLAG for final review): detect() climbs by URL depth without checking ABSPATH's trailing segments equal the extra site segments → could climb to account root if URL and filesystem disagree (spec anti-goal). Suggested guard: only climb while trailing ABSPATH segment == trailing site segment.
Task 4: minor (deferred): normalize forces leading '/', breaks Windows drive paths (out of target scope)
Task 4: complete (commits 0bbed9f..13f2064, review clean)
Task 5: implemented (commit 72a8ffd), review dispatched
Task 5: minor (deferred, FLAG for final review): JobState::save leaves .tmp behind when file_put_contents fails (disk full); add @unlink before throw
Task 5: minor (deferred): WorkDir::ensure check-then-act race between concurrent first callers (acceptable under runner lock)
Task 5: minor (deferred): WorkDir::path() does not guard '..' (callers pass constants)
Task 5: complete (commits 13f2064..72a8ffd, review clean)
Task 6: implemented (commit 4476e4f), review dispatched
Task 6: Ruling (carry to Task 14): a crash mid-directory can leave partially appended JSONL lines for a directory still in `pending`; JobRunner must record the filelist line count (and byte offset) in state after each scan run and truncate filelist.jsonl back to it on resume — cost if wrong: duplicate entries in ZIP
Task 6: review found 2 Important: (1) filesize/filemtime race emits warnings and lists vanished files; (2) plan-mandated: root-anchored excludes leave nested .git/node_modules in the backup
Task 6: Ruling: excludes without '/' match basename at any depth (with trailing-* prefix support); excludes containing '/' stay root-anchored — matches the user's evident intent (skip dev artifacts wherever they are) — cost if wrong: a legitimately named nested folder gets skipped
Task 6: fix round 1 dispatched
Task 6: minor (deferred, FLAG for final review): fwrite result unchecked when appending JSONL (disk full → silent truncation)
Task 6: minor (deferred): sort() default flags on scandir names; warnings list uncapped in state
Task 6: fix round 1/5 (2 addressed, 0 open — stat race guarded; bare excludes match at any depth; commits 4476e4f..e1d7344)
Task 6: minor (deferred): lone '*' exclude → strpos empty needle warning on PHP 7.4; normalization should reject a bare '*'
Task 6: complete (commits 72a8ffd..e1d7344, review clean)
Task 7: implemented (commit 8193e74), review dispatched. Note for Task 14: PartWriter throws InvalidArgumentException on out-of-range offsets (not RuntimeException); truncateTo refuses to touch any uploaded part
Task 7: review found 1 Important (resume with current_size=0 or stale higher-index part files appends onto leftover bytes → silent corruption); fix round 1 dispatched (also carries reviewer minors: hash-recompute assertion, current_size<=partSize validation)
Task 7: minor (deferred): truncateTo not atomic after validation; writer state stale after a mid-write failure (document "unusable after exception"; runner should restart the entry)
Task 7: fix round 1/5 (1 addressed, 0 open — stale part bytes discarded on resume, 'wb' for fresh parts; commits 8193e74..ba70f36)
Task 7: complete (commits e1d7344..ba70f36, review clean)
Task 8: Ruling: php:7.4-cli lacks ext-zip; add Dockerfile.test (ARG PHP_VERSION, installs zip ext) and make bin/test.sh build/use wppb-test:${PHP_VERSION} (PHP_VERSION env, default 7.4) — plan already anticipated this — cost if wrong: slower first test run
Task 8: implemented (commits eb88520 test image, 7b11232 ZipWriter), review dispatched
Task 8: Ruling: commits authored by an Opus-model implementer carry "Co-Authored-By: Claude Opus 5.5" — accurate attribution of the authoring model; not rewriting history — cost if wrong: trailer inconsistency across commits
Task 8: Ruling (apply in fix round or final wave): EOCD fields should carry real values when they fit (disk numbers 0, entry counts and cd size/offset when < limits) while still always emitting ZIP64 EOCD + locator — matches mainstream writers and maximizes Windows Explorer compatibility, which we cannot test here — cost if wrong: none (still valid ZIP64)
Task 8: minor (deferred, FLAG for final review): same-instance recovery after a partial central-directory append can corrupt the directory; Ruling for Task 14: after a failed addFile the runner ENDS the run (saves state) so the next run constructs a fresh ZipWriter/PartWriter which trims spool/parts — no same-instance retry
Task 8: minor (deferred, FLAG for final review): apply EOCD real-values ruling (disk 0, counts/sizes when they fit) in the final fix wave together with the ZIP64 EOCD version-made-by consistency
Task 8: minor (deferred): finish() guard untested; name check allows backslash/NUL; bin/test.sh does not rebuild image on Dockerfile.test change
Task 8: ⚠️ carried: Windows Explorer not verifiable here → README (Task 18) lists verified readers (unzip, Python zipfile, bsdtar, ditto, Archive Utility, ZipArchive) and marks Windows as "要確認"
Task 8: note for Task 14: ZipWriter::entryStartOffset() is null after a successful addFile (only non-null mid-entry); PartWriter/ZipWriter constructors trim stale parts/spool on resume
Task 8: complete (commits ba70f36..7b11232, review clean)
Task 9: implemented (commit 534b4ec), review dispatched
Task 9: Ruling: DbDumper state gains `sql_bytes` and `gz_bytes`; constructor truncates sqlFile/gzFile to them on resume (crash between page append and state save would otherwise duplicate INSERTs) — same class of defect as Task 6; fixing in the class is cleaner than in the runner — cost if wrong: two extra state keys. Apply in Task 9 fix round.
Task 9: minor (deferred): spatial column types dumped as strings (not restorable); not used by WordPress core
Task 9: review found 2 Important: (1) wpdb errors return array() so failures look like empty pages; (2) stale filesize() in compress() could drop the SQL tail before unlink
Task 9: Ruling: DbDumper may read `$wpdb->last_error` (extends the "only get_col/get_row/get_results" restriction) — the only reliable error signal from wpdb — cost if wrong: none
Task 9: Ruling: skip views via SHOW FULL TABLES / BASE TABLE filter instead of aborting the backup — cost if wrong: a view definition is not backed up (views are recreatable from plugin code)
Task 9: fix round 1 dispatched (2 Important + sql_bytes/gz_bytes resume truncation + view skip + compress phase guard)
Task 9: minor (deferred): batch is a row count only (max_allowed_packet / memory with wide rows); unchecked unlink; FakeWpdb ignores ORDER BY
Task 9: fix round 1/5 (5 addressed, 0 open — last_error checks, feof compress, sql_bytes/gz_bytes resume truncation, view skip, phase guard; commits 534b4ec..9c3cb00)
Task 9: note for Task 14: if phase=compress and the SQL file is missing with compress_offset>0 (crash between unlink and state save), treat compression as done; non-array wpdb result without last_error is returned as empty (wpdb not ready) — runner should fail the job if zero tables are found
Task 9: complete (commits 7b11232..9c3cb00, review clean)
Task 10: implemented (commit 9b992a0), review dispatched. Note for Task 14: uploadFile's checksum call may fail after the remote file exists → on retry, uploading the same name again overwrites (pCloud keeps old as revision) — acceptable
Task 10: minor (deferred, FLAG for final review): PcloudClient lacks guards for missing metadata[0].fileid (uploadFile), missing metadata.folderid (ensureFolder), and empty access_token from exchangeCode — add throws
Task 10: minor (deferred): host whitelist (api/eapi.pcloud.com); 600s upload cap vs part size (≤100MB → ≥170KB/s needed; acceptable); test fixture weekday mismatch; LocalStorage ids file not atomic
Task 10: complete (commits 9c3cb00..9b992a0, review clean)
Task 11-13: Ruling: batched into one implementer dispatch (three small independent pure classes with their own tests; one review) — cost if wrong: a larger single review surface
Task 11-13: implemented (commits 21b07b5, 5f0ed61, caebf57, 5cff5eb), review dispatched
Task 11-13: review found 2 Important: (1) Manifest::build returns '' on invalid UTF-8; (2) isStale(0) treated as real epoch → spurious stale mail on fresh install
Task 11-13: Ruling: Retention never TTL-deletes a folder whose modified is 0/missing (treat as now) — pCloud's modified parse failure must fail safe — cost if wrong: an orphaned .incomplete folder lingers
Task 11-13: fix round 1 dispatched (+ investigate touch() skew explanation)
Task 11-13: minor (deferred): nextRun ignores start_time/offset once lastScheduled is set (plan-mandated grid; document; admin could reset last_scheduled when start_time changes → carry to Task 16); monthly anchor day lost after short month
Task 11-13: minor (deferred, FLAG for final review): Notifier passes error strings unscrubbed — add redaction of `access_token=`/`Bearer ` in send()
Task 11-13: ⚠️ carried to Task 15: Scheduler::registerCron() must run on every request (plugins_loaded), not only on activation, so the cron_schedules filter exists
Task 11-13: fix round 1/5 (4 addressed, 0 open — manifest UTF-8 substitute+throw, isStale(0), retention modified<=0 safety, boundary test; commits 5cff5eb..5b0550e)
Task 11-13: minor (deferred): RetentionTest docblock states Patchwork wrapper as cause of +1s touch skew without confirmation; one assertion indexes the wrong folder
Task 11-13: note for Task 14: Manifest::build throws RuntimeException → abort job
Task 11-13: complete (commits 9b992a0..5b0550e, review clean)
Task 14: implemented (commit ee26fbc, 1131 lines), review dispatched
Task 14: Ruling: controller's tick() rule "nextRun(...) <= now" was wrong (nextRun always returns > now). Replace with a persisted `next_run` Settings timestamp: tick() sets next_run = nextRun(null) when 0; when next_run <= now → startJob('schedule', next_run) and next_run = nextRun(lastScheduled = slot) (grid, no replay of missed slots). First backup after install = next start-time occurrence, as the spec intends. Apply in Task 14 fix round (adds `next_run` to Settings KEYS/TIMESTAMP_KEYS). Cost if wrong: one extra option key.
Task 14: review: crash-safety core verified correct; 2 Important: (1) finalize report (history/mail/last_success) not idempotent across crash; (2) plan-mandated compress shortcut accepts a truncated gz when SQL vanished
Task 14: Ruling: never destroy a completed generation — skip 48h abort once manifest_uploaded; abort() must not delete a folder already renamed; assert all parts uploaded before manifest — cost if wrong: none (pure safety)
Task 14: Ruling: keep runner.lock (never delete while held); manifest parts stripped to name/size/sha1/sha256; nullable param syntax ?callable/?\DateTimeZone
Task 14: fix round 1 dispatched (2 Important + next_run scheduling + safety rulings)
Task 14: minor (deferred): attempts reset only on advance (sparse transient errors over a long scan could strike out); state save per file rewrites full parts array (write amplification); work-dir exclusion is raw strpos; PartWriter construction duplicated; 1141 lines — natural seams: schedule policy / ArchiveStep / finalize+report
Task 14: ⚠️ verified by controller from earlier tasks: PcloudClient::uploadFile overwrites (nopartial=1, no renameifexists); deleteFolder uses deletefolderrecursive; Settings::update merges partial arrays; JobState::save is atomic
Task 14: minor (deferred, FLAG for final review): crash between startJob() and saving next_run can start a second job for the same slot; fix: in tick(), if last_scheduled >= next_run advance next_run without starting
Task 14: fix round 1/5 (5 addressed, 0 open — idempotent report, gz integrity guard, next_run scheduling, generation-preserving abort, safety items; commits ee26fbc..296fde5)
Task 14: minor (deferred, FLAG for final review): after `renamed`, finalize retries forever (no cap, deadline skipped) → a persistent save() failure could block scheduling; add a generous cap (e.g. 50 runs) that forces cleanup with a warning
Task 14: complete (commits 5b0550e..296fde5, review clean)
Task 15: implemented (commit 02cfef5), review dispatched. Note: Settings::update([]) does not persist defaults (merge semantics) — harmless, defaults are computed on read
Task 15: minor (deferred, FLAG for final review): URL endpoint returns raw last_error (may carry absolute paths) — strip ABSPATH prefix / cap length; wrap runner->tick()/run() in try/catch in Trigger so a Throwable cannot fatal cron or leak paths
Task 15: minor (deferred): $_GET read without wp_unslash (harmless for hex key); ajaxStart ignores startJob() false (UI cannot tell "already running"); Plugin::activate() update([]) is inert
Task 15: complete (commits 296fde5..02cfef5, review clean)
Task 16: implemented (commit 5c7b229), review dispatched. Concerns: not strictly red-first; DbDumperTest bound loosened due to Patchwork fread cap; tab render has side effects (ensure(), nextRun() store)
Task 16: minor (deferred, FLAG for final review): Diagnostics::all() calls WorkDir::ensure() so workdir_htaccess is always true after a page view and a GET creates the dir — check before ensure()
Task 16: minor (deferred): notice transient is global (per-user key trivial); disable_wp_cron row flags ok=false even when external cron intended; scrub() misses URL-encoded token; duplicate _wpnonce ids; DbDumperTest order dependency on Patchwork fread cap
Task 16: complete (commits 02cfef5..5c7b229, review clean)
Task 17: implemented (commit e519e7d), review dispatched
Env check for Task 19: npx @wordpress/env@latest resolves (12.0.0); Docker daemon running
Task 17: review found 1 Critical (CurlTransport does not follow redirects → GitHub .sha256 fetch always 302 → no site could ever update), 2 Important (verifyPackage fails open when manifest stale/null; misleading failure message)
Task 17: Ruling: Updater uses the WordPress HTTP API (wp_remote_get, redirection 5) via a protected httpGet() seam instead of HttpTransportInterface; constructor loses the transport param — cost if wrong: none (WP HTTP is always available in WP context)
Task 17: Ruling: ownership from $hookExtra['plugin']; checksum URL falls back to $package.'.sha256' (release asset naming convention); our package never installs unverified
Task 17: fix round 1 dispatched
Task 17: minor (deferred): isValidManifest accepts any https host; Plugin::boot builds Notifier eagerly; boot() registration untested
Task 17: fix round 1/5 (4 addressed, 0 open — WP HTTP API with redirects, fail-closed verify via hookExtra + derived .sha256 URL, distinct messages + 6h mail throttle; commits e519e7d..ba28d1a)
Task 17: minor (deferred): throttle transient shared by both failure types; isValidManifest does not require https for sha256_url
Task 17: complete (commits 5c7b229..ba28d1a, review clean)
Env check for Task 19: port 8888 free; unrelated containers studionana-* on 8080/3306 must not be touched
Task 18: implemented (commits bc67dc8, 31d66e2), review dispatched
Task 18: Ruling (carry to Task 19 or final wave): add Dockerfile.test to .distignore; release.sh should also rewrite the default WP_PCLOUD_BACKUP_UPDATE_URL OWNER in wp-pcloud-backup.php from the origin URL — cost if wrong: none
Task 18: review found 4 Important (Windows join wildcard order/self-match; cat order breaks at 1000+ parts; no recovery when push/gh release fails after tag; Windows extraction steps missing)
Task 18: Ruling: keep 3-digit part naming (spec) and document numeric-order join (`sort -t. -k3,3n`) as the recommended command — cost if wrong: none
Task 18: Ruling: release.sh publishes tag → gh release → branch push (update.json last) so no site sees a version without an asset; ERR trap prints recovery commands; branch + remote-tag checks — cost if wrong: none
Task 18: fix round 1 dispatched (+ Dockerfile.test to .distignore, README notes)
Task 18: minor (deferred): relative notes path resolved after cd; SSH-alias remotes fail OWNER parse; no committed uninstall test; activation message wording
Task 18: fix round 1/5 (4 addressed, 0 open — numeric-order join, PowerShell join, release order+recovery, Windows steps; commits 31d66e2..31eca80)
Task 18: minor (deferred): ls-remote check fail-open; --help truncated; no INT/TERM trap; "existing files.zip fails" wording vs overwrite; retry after partial gh release create
Task 18: ⚠️ .pot generation deferred to Task 19 (needs wp-env)
Task 18: complete (commits ba28d1a..31eca80, review clean)
Task 19: implemented (commits c88014b, 1c9470c, d3ae4f7, 2007b68); steps 3-8 passed (100MB + 3GB backups, restore diff clean, 12/12 tables, 2 container kills resumed, 403/200); step 9 (real pCloud) NOT verified (no token) — manual steps in README; review dispatched
Task 19: Ruling (final fix wave): throughput — JobRunner must keep working within the run budget across step switches: upload step uploads pending parts while !timeUp (at least one), then returns to zip in the same run if time remains; README/接続タブ recommend a 1-minute external cron (not 5) and explain throughput (parts per run × interval) — found by Task 19: one part per run × 5-min cron caps a 48h job at ~14GB — cost if wrong: longer runs on slow hosts (still bounded by budget)
Task 19: ⚠️ scratchpad holds several GB of restore copies (rm denied to the subagent) — controller to clean
Task 19: scratchpad cleanup (12GB restore copies) denied to controller too — leave for the user/OS temp cleanup; path noted in final report
Task 19: review found 3 Important: (1) DB restore proof non-discriminating (fresh WP has same tables/rows); (2) README cron/throughput guidance contradicts measurements; (3) other empty-constant checks for WP_PCLOUD_BACKUP_STORAGE_DIR may remain
Task 19: Ruling: apply the throughput change (continue zip/upload within the run budget) in this fix round rather than the final wave, so the README numbers are measured once — cost if wrong: a JobRunner behavior change late in the plan (covered by unit test + wp-env re-measurement)
Task 19: fix round 1 dispatched
Task 19: minor (deferred): JobRunner reads STORAGE_DIR constant via envValue instead of asking the storage; fixture script trap/$2 guard; screenshots outside repo; .pot header placeholders
Task 19: fix round 1/5 submitted (commits 2007b68..c733d2b: run continues zip/upload within budget — 3GB: 120 parts in 8 calls / 156s vs 115 calls / 382s; devStorageDir() single source of truth; sentinel DB proof; README 1-min cron + measured limits); re-review dispatched
Task 19: ⚠️ scratchpad restore copies now ~25GB, host has 26GB free — user must delete /private/tmp/claude-501/-Users-yogawa-Library-CloudStorage-Dropbox-Blog-04-Apps-wpbackup/61739814-0c35-41dc-96ad-24dd8a441e4b/scratchpad/restore*
Task 19: fix round 1/5 (4 addressed, 0 open; commits 2007b68..c733d2b)
Task 19: ⚠️ ~9GB fixtures/generations remain under ~/.wp-env (wp-env stopped, installed); sentinel post/option remain in the dev site
Task 19: complete (commits 31eca80..c733d2b, review clean)
ALL TASKS COMPLETE — final whole-branch review dispatched
FINAL REVIEW: With fixes — C1 (stale detection gaps), I1-I7, flagged F1-F7 → ONE fix wave dispatched; findings saved to final-review-findings.md
FINAL FIX WAVE: landed (commits c733d2b..789814d, 16 commits; C1, I1-I7, F1, F3-F7 done; 335 tests on 7.4 and 8.2 per implementer)
Ruling: F2 (EOCD real values) PARKED — implementer found macOS `ditto -x -k` rejects a real central-directory offset in the classic EOCD; keeping all-0xFFFF EOCD, which 5 readers accept (unzip, zipfile, bsdtar, ditto, Archive Utility, ZipArchive). Windows Explorer remains untested (README points to tar/7-Zip). Cost if wrong: Windows users use 7-Zip.
Ruling: implementer concern "unfinished-run attempts accumulate per step and never reset on progress" is valid — a job that progresses but is killed 1-in-N runs by the web server would abort after 5 kills. Fix: reset the unfinished-run counter whenever the job made progress since the previous run (zip_line, uploaded parts, table_index/offset, scanner pending changed); dispatched as fix-wave addendum.
Controller verified after session restart (2026-10-26): tree clean at 789814d; bin/test.sh OK 335/2914 on PHP 7.4 and 8.2; Docker restarted; addendum (dead-run attempts reset on progress) dispatched to the fix-wave implementer; scoped re-review pending
FINAL FIX WAVE addendum: e97565d (dead-run attempts reset on progress); 336 tests OK 7.4/8.2; scoped re-review dispatched
FINAL FIX WAVE re-review: all items ADDRESSED (F2 parked); 1 new Important regression from I3 — binary PK values in last_key break json_encode in JobState::save → dump aborts on sites with BINARY-keyed plugin tables (e.g. Wordfence)
Ruling: deviate from "no second fix wave" for this ONE load-bearing regression — a targeted single-commit fix (base64-encode keyset cursor values) + test, then a tiny scoped re-review. Leaving a known backup-aborting regression for Wordfence sites is not acceptable for a backup tool, and the fix is ~20 lines. Cost if wrong: one extra commit.
Ruling: re-review minors parked — recordError without lock (microsecond window, only after an escape from run()); withoutPaths strips URL paths (cosmetic); unconnected sites get a daily 停止中 mail after 2 periods (matches spec intent); cross-process throttle race may duplicate one mail.
REGRESSION FIX landed: e2ac4a9 (base64 keyset cursor), 7ce471f (recordError under lock); controller confirmed 339 tests OK on 7.4; scoped re-review dispatched
REGRESSION FIX re-review: ADDRESSED, no new Critical/Important. Minor parked — Ruling: legacy raw last_key cursors from e97565d are not cleanly refused when they happen to be valid base64 (plugin unreleased; only an in-flight dev job across that commit could be affected) — cost if wrong: none for real sites.
FINAL: whole-branch review clean after fix wave + regression fix (HEAD 7ce471f, 56 commits, 339 tests OK on 7.4/8.2).
