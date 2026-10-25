<?php
/**
 * Status tab: last/next backup, progress, manual start and history.
 *
 * @var array $data Prepared by AdminPage::render().
 */

defined( 'ABSPATH' ) || exit;

$wppb_status  = $data['status'];
$wppb_running = ! empty( $wppb_status['running'] );
$wppb_percent = (int) round( 100 * (float) $wppb_status['progress'] );
$wppb_results = array(
	'success' => __( '成功', 'wp-pcloud-backup' ),
	'warning' => __( '警告あり', 'wp-pcloud-backup' ),
	'failed'  => __( '失敗', 'wp-pcloud-backup' ),
	'updated' => __( '更新', 'wp-pcloud-backup' ),
);
$wppb_date    = $data['format_time'];
?>
<?php if ( ! $data['connected'] ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e( 'pCloud に接続されていません。', 'wp-pcloud-backup' ); ?>
			<a href="<?php echo esc_url( $data['tabs']['connection']['url'] ); ?>"><?php esc_html_e( '接続タブで設定してください。', 'wp-pcloud-backup' ); ?></a>
		</p>
	</div>
<?php endif; ?>

<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( '最終成功', 'wp-pcloud-backup' ); ?></th>
		<td><?php echo esc_html( $wppb_date( (int) $data['last_success'] ) ); ?></td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( '次回予定', 'wp-pcloud-backup' ); ?></th>
		<td><?php echo esc_html( $wppb_date( (int) $data['next_run'] ) ); ?></td>
	</tr>
</table>

<h2><?php esc_html_e( 'バックアップ', 'wp-pcloud-backup' ); ?></h2>
<div id="wppb-status" data-running="<?php echo $wppb_running ? '1' : '0'; ?>">
	<div class="wppb-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $wppb_percent ); ?>"><div style="width:<?php echo esc_attr( $wppb_percent ); ?>%"></div></div>
	<p id="wppb-message"><?php echo esc_html( (string) $wppb_status['message'] ); ?></p>
	<?php if ( ! empty( $wppb_status['last_error'] ) ) : ?>
		<p id="wppb-error" class="wppb-error"><?php echo esc_html( (string) $wppb_status['last_error'] ); ?></p>
	<?php endif; ?>
	<div class="wppb-actions">
		<button type="button" id="wppb-start" class="button button-primary"<?php echo ( $data['connected'] && ! $wppb_running ) ? '' : ' disabled'; ?>><?php esc_html_e( '今すぐバックアップ', 'wp-pcloud-backup' ); ?></button>
		<form method="post" action="<?php echo esc_url( $data['post_url'] ); ?>" id="wppb-abort-form" class="wppb-inline-form" data-wppb-confirm="confirmAbort"<?php echo $wppb_running ? '' : ' hidden'; ?>>
			<?php $data['fields']( 'abort' ); ?>
			<button type="submit" class="button"><?php esc_html_e( '中止', 'wp-pcloud-backup' ); ?></button>
		</form>
	</div>
	<p class="description" id="wppb-hint"<?php echo $wppb_running ? '' : ' hidden'; ?>><?php esc_html_e( 'この画面を開いている間はブラウザがバックアップを進めます。', 'wp-pcloud-backup' ); ?></p>
</div>

<h2><?php esc_html_e( '履歴', 'wp-pcloud-backup' ); ?></h2>
<table class="widefat striped wppb-history">
	<thead>
		<tr>
			<th><?php esc_html_e( '日時', 'wp-pcloud-backup' ); ?></th>
			<th><?php esc_html_e( '結果', 'wp-pcloud-backup' ); ?></th>
			<th><?php esc_html_e( 'サイズ', 'wp-pcloud-backup' ); ?></th>
			<th><?php esc_html_e( 'ファイル数', 'wp-pcloud-backup' ); ?></th>
			<th><?php esc_html_e( '所要時間', 'wp-pcloud-backup' ); ?></th>
			<th><?php esc_html_e( 'pCloud パス', 'wp-pcloud-backup' ); ?></th>
			<th><?php esc_html_e( 'メッセージ', 'wp-pcloud-backup' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $data['history'] ) ) : ?>
			<tr><td colspan="7"><?php esc_html_e( '履歴はまだありません。', 'wp-pcloud-backup' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $data['history'] as $wppb_entry ) : ?>
			<?php
			$wppb_seconds = max( 0, (int) ( $wppb_entry['finished'] ?? 0 ) - (int) ( $wppb_entry['started'] ?? 0 ) );
			$wppb_result  = (string) ( $wppb_entry['result'] ?? '' );
			?>
			<tr>
				<td><?php echo esc_html( $wppb_date( (int) ( $wppb_entry['started'] ?? 0 ) ) ); ?></td>
				<td><?php echo esc_html( $wppb_results[ $wppb_result ] ?? $wppb_result ); ?></td>
				<td><?php echo esc_html( (string) size_format( (int) ( $wppb_entry['bytes'] ?? 0 ) ) ); ?></td>
				<td><?php echo esc_html( (string) (int) ( $wppb_entry['files'] ?? 0 ) ); ?></td>
				<td><?php echo esc_html( sprintf( /* translators: 1: minutes, 2: seconds. */ __( '%1$d分%2$d秒', 'wp-pcloud-backup' ), intdiv( $wppb_seconds, 60 ), $wppb_seconds % 60 ) ); ?></td>
				<td><code><?php echo esc_html( (string) ( $wppb_entry['remote'] ?? '' ) ); ?></code></td>
				<td><?php echo esc_html( (string) ( $wppb_entry['message'] ?? '' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
