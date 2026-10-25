<?php
/**
 * Settings tab.
 *
 * @var array $data Prepared by AdminPage::render().
 */

defined( 'ABSPATH' ) || exit;

$wppb_v      = $data['values'];
$wppb_locked = $data['locked'];

// Marks a field that a wp-config.php constant controls.
$wppb_lock_note = static function ( string $key ) use ( $wppb_locked ): void {
	if ( ! empty( $wppb_locked[ $key ] ) ) {
		echo '<p class="description">' . esc_html__( 'wp-config.php の定数で設定済み', 'wp-pcloud-backup' ) . '</p>';
	}
};
$wppb_off       = static function ( string $key ) use ( $wppb_locked ): string {
	return ! empty( $wppb_locked[ $key ] ) ? ' disabled' : '';
};
?>
<form method="post" action="<?php echo esc_url( $data['post_url'] ); ?>">
	<?php $data['fields']( 'save_settings' ); ?>

	<h2><?php esc_html_e( '保存先', 'wp-pcloud-backup' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wppb-root-folder"><?php esc_html_e( 'pCloud ルートフォルダ', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<input type="text" id="wppb-root-folder" name="root_folder" class="regular-text" value="<?php echo esc_attr( (string) $wppb_v['root_folder'] ); ?>"<?php echo $wppb_off( 'root_folder' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?>>
				<?php $wppb_lock_note( 'root_folder' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wppb-site-folder"><?php esc_html_e( 'サイトフォルダ名', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<input type="text" id="wppb-site-folder" name="site_folder" class="regular-text" value="<?php echo esc_attr( (string) $wppb_v['site_folder'] ); ?>">
				<p class="description"><?php esc_html_e( 'ルートフォルダの下に作られるフォルダ名です（英数字と . _ - のみ）。', 'wp-pcloud-backup' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wppb-backup-root"><?php esc_html_e( 'バックアップ対象フォルダ', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<input type="text" id="wppb-backup-root" name="backup_root" class="large-text code" value="<?php echo esc_attr( (string) $wppb_v['backup_root'] ); ?>">
				<button type="submit" form="wppb-detect-root-form" class="button button-small"><?php esc_html_e( '自動検出に戻す', 'wp-pcloud-backup' ); ?></button>
				<p class="description"><?php esc_html_e( 'WordPress をサブディレクトリに設置している場合は、サイトのルートが自動検出されます。', 'wp-pcloud-backup' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'スケジュール', 'wp-pcloud-backup' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wppb-schedule"><?php esc_html_e( '頻度', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<select id="wppb-schedule" name="schedule"<?php echo $wppb_off( 'schedule' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?>>
					<?php foreach ( $data['schedules'] as $wppb_value => $wppb_label ) : ?>
						<option value="<?php echo esc_attr( $wppb_value ); ?>"<?php selected( $wppb_v['schedule'], $wppb_value ); ?>><?php echo esc_html( $wppb_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php $wppb_lock_note( 'schedule' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wppb-start-time"><?php esc_html_e( '開始時刻', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<input type="time" id="wppb-start-time" name="start_time" value="<?php echo esc_attr( (string) $wppb_v['start_time'] ); ?>">
				<p class="description"><?php esc_html_e( 'サイトのタイムゾーンで指定します。', 'wp-pcloud-backup' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wppb-keep"><?php esc_html_e( '保持世代数', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<input type="number" id="wppb-keep" name="keep" class="small-text" min="1" max="30" value="<?php echo esc_attr( (string) $wppb_v['keep'] ); ?>"<?php echo $wppb_off( 'keep' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?>>
				<?php $wppb_lock_note( 'keep' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'WP-Cron', 'wp-pcloud-backup' ); ?></th>
			<td>
				<label><input type="checkbox" name="use_wp_cron" value="1"<?php checked( ! empty( $wppb_v['use_wp_cron'] ) ); ?>> <?php esc_html_e( 'WP-Cron で自動実行する', 'wp-pcloud-backup' ); ?></label>
				<p class="description"><?php esc_html_e( '外部 cron から接続タブの URL を呼び出す場合はオフにできます。', 'wp-pcloud-backup' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( '通知', 'wp-pcloud-backup' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wppb-notify-email"><?php esc_html_e( '通知先メール', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<input type="text" id="wppb-notify-email" name="notify_email" class="regular-text" value="<?php echo esc_attr( (string) $wppb_v['notify_email'] ); ?>"<?php echo $wppb_off( 'notify_email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?>>
				<p class="description"><?php esc_html_e( '複数ある場合はカンマで区切ります。', 'wp-pcloud-backup' ); ?></p>
				<?php $wppb_lock_note( 'notify_email' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( '成功時の通知', 'wp-pcloud-backup' ); ?></th>
			<td><label><input type="checkbox" name="notify_on_success" value="1"<?php checked( ! empty( $wppb_v['notify_on_success'] ) ); ?>> <?php esc_html_e( '成功したときもメールを送る（失敗時は常に送信）', 'wp-pcloud-backup' ); ?></label></td>
		</tr>
	</table>

	<h2><?php esc_html_e( '詳細', 'wp-pcloud-backup' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wppb-excludes"><?php esc_html_e( '除外パターン', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<textarea id="wppb-excludes" name="excludes" rows="8" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $wppb_v['excludes'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( '1 行に 1 パターン。バックアップ対象フォルダからの相対パスで、* が使えます。', 'wp-pcloud-backup' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wppb-part-size"><?php esc_html_e( '分割サイズ', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<select id="wppb-part-size" name="part_size_mb">
					<?php foreach ( $data['part_sizes_mb'] as $wppb_mb ) : ?>
						<option value="<?php echo esc_attr( (string) $wppb_mb ); ?>"<?php selected( (int) $wppb_v['part_size_mb'], (int) $wppb_mb ); ?>><?php echo esc_html( $wppb_mb . ' MB' ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wppb-run-budget"><?php esc_html_e( '1 回の実行時間（秒）', 'wp-pcloud-backup' ); ?></label></th>
			<td>
				<input type="number" id="wppb-run-budget" name="run_budget_sec" class="small-text" min="5" max="120" value="<?php echo esc_attr( (string) $wppb_v['run_budget_sec'] ); ?>">
				<p class="description"><?php esc_html_e( 'サーバーの実行時間制限より短くしてください。', 'wp-pcloud-backup' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( '自動更新', 'wp-pcloud-backup' ); ?></th>
			<td><label><input type="checkbox" name="auto_update" value="1"<?php checked( ! empty( $wppb_v['auto_update'] ) ); ?>> <?php esc_html_e( 'このプラグインを自動で更新する', 'wp-pcloud-backup' ); ?></label></td>
		</tr>
	</table>

	<?php submit_button( __( '設定を保存', 'wp-pcloud-backup' ) ); ?>
</form>

<form method="post" action="<?php echo esc_url( $data['post_url'] ); ?>" id="wppb-detect-root-form">
	<?php $data['fields']( 'detect_root' ); ?>
</form>
