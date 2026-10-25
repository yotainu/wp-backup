<?php
/**
 * Connection tab: pCloud token, external cron URL and diagnostics.
 *
 * @var array $data Prepared by AdminPage::render().
 */

defined( 'ABSPATH' ) || exit;

$wppb_diag = $data['diagnostics'];
$wppb_yes  = static function ( bool $value, string $yes, string $no ): string {
	return $value ? $yes : $no;
};
$wppb_rows = array(
	'php_version'        => __( 'PHP バージョン', 'wp-pcloud-backup' ),
	'max_execution_time' => __( 'max_execution_time（秒）', 'wp-pcloud-backup' ),
	'memory_limit'       => __( 'memory_limit', 'wp-pcloud-backup' ),
	'ext_zlib'           => __( 'zlib 拡張', 'wp-pcloud-backup' ),
	'ext_curl'           => __( 'curl 拡張', 'wp-pcloud-backup' ),
	'ext_openssl'        => __( 'openssl 拡張（AES-256-GCM）', 'wp-pcloud-backup' ),
	'workdir_writable'   => __( '作業フォルダへの書き込み', 'wp-pcloud-backup' ),
	'workdir_htaccess'   => __( '作業フォルダの .htaccess', 'wp-pcloud-backup' ),
	'workdir_index'      => __( '作業フォルダの index.php', 'wp-pcloud-backup' ),
	'server_apache'      => __( 'Apache 系サーバー', 'wp-pcloud-backup' ),
	'disable_wp_cron'    => __( 'DISABLE_WP_CRON', 'wp-pcloud-backup' ),
	'free_bytes'         => __( 'ディスク空き容量', 'wp-pcloud-backup' ),
	'incomplete_job'     => __( '未完了のジョブ', 'wp-pcloud-backup' ),
);
$wppb_text = static function ( string $key, $value ) use ( $wppb_yes ): string {
	switch ( $key ) {
		case 'ext_zlib':
		case 'ext_curl':
		case 'ext_openssl':
		case 'workdir_htaccess':
		case 'workdir_index':
			return $wppb_yes( (bool) $value, __( 'あり', 'wp-pcloud-backup' ), __( 'なし', 'wp-pcloud-backup' ) );
		case 'workdir_writable':
			return $wppb_yes( (bool) $value, __( '可', 'wp-pcloud-backup' ), __( '不可', 'wp-pcloud-backup' ) );
		case 'server_apache':
			return $wppb_yes( (bool) $value, __( 'はい', 'wp-pcloud-backup' ), __( 'いいえ', 'wp-pcloud-backup' ) );
		case 'disable_wp_cron':
			return $wppb_yes( (bool) $value, __( '有効（WP-Cron は動きません）', 'wp-pcloud-backup' ), __( '無効', 'wp-pcloud-backup' ) );
		case 'incomplete_job':
			return $wppb_yes( (bool) $value, __( 'あり', 'wp-pcloud-backup' ), __( 'なし', 'wp-pcloud-backup' ) );
		case 'free_bytes':
			return null === $value ? __( '不明', 'wp-pcloud-backup' ) : (string) size_format( (int) $value );
		default:
			return (string) $value;
	}
};
?>
<h2><?php esc_html_e( 'pCloud 接続', 'wp-pcloud-backup' ); ?></h2>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( '状態', 'wp-pcloud-backup' ); ?></th>
		<td>
			<?php if ( $data['connected'] ) : ?>
				<strong><?php esc_html_e( '接続済み', 'wp-pcloud-backup' ); ?></strong>
				<?php if ( '' !== $data['email'] ) : ?>
					(<?php echo esc_html( $data['email'] ); ?>)
				<?php elseif ( $data['dev_storage'] ) : ?>
					(<?php esc_html_e( 'ローカルフォルダ（開発モード）', 'wp-pcloud-backup' ); ?>)
				<?php endif; ?>
			<?php else : ?>
				<strong><?php esc_html_e( '未接続', 'wp-pcloud-backup' ); ?></strong>
			<?php endif; ?>
			<?php if ( $data['token_locked'] ) : ?>
				<p class="description"><?php esc_html_e( 'アクセストークンは wp-config.php の定数で設定済みです。', 'wp-pcloud-backup' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
</table>

<?php if ( ! $data['token_locked'] ) : ?>
	<h3><?php esc_html_e( 'アクセストークンを貼り付ける', 'wp-pcloud-backup' ); ?></h3>
	<form method="post" action="<?php echo esc_url( $data['post_url'] ); ?>">
		<?php $data['fields']( 'save_token' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wppb-access-token"><?php esc_html_e( 'アクセストークン', 'wp-pcloud-backup' ); ?></label></th>
				<td>
					<input type="password" id="wppb-access-token" name="access_token" class="regular-text" autocomplete="off" spellcheck="false">
					<p class="description"><?php esc_html_e( '保存前に pCloud へ接続して確認します。保存したトークンは暗号化されます。EU リージョンのアカウントは wp-config.php で WP_PCLOUD_BACKUP_API_HOST に eapi.pcloud.com を指定してください。', 'wp-pcloud-backup' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( '確認して保存', 'wp-pcloud-backup' ), 'secondary' ); ?>
	</form>

	<h3><?php esc_html_e( '認可コードからトークンを取得する', 'wp-pcloud-backup' ); ?></h3>
	<p><?php esc_html_e( 'pCloud のアプリ（my.pcloud.com の開発者向け設定）で作成した Client ID と Client Secret を入力し、認可ページで表示されるコードを貼り付けてください。Client ID と Client Secret は保存されません。', 'wp-pcloud-backup' ); ?></p>
	<form method="post" action="<?php echo esc_url( $data['post_url'] ); ?>">
		<?php $data['fields']( 'exchange_code' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wppb-client-id">Client ID</label></th>
				<td>
					<input type="text" id="wppb-client-id" name="client_id" class="regular-text" autocomplete="off">
					<button type="button" id="wppb-open-authorize" class="button button-small"><?php esc_html_e( '認可ページを開く', 'wp-pcloud-backup' ); ?></button>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wppb-client-secret">Client Secret</label></th>
				<td><input type="password" id="wppb-client-secret" name="client_secret" class="regular-text" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wppb-code"><?php esc_html_e( '認可コード', 'wp-pcloud-backup' ); ?></label></th>
				<td><input type="text" id="wppb-code" name="code" class="regular-text" autocomplete="off"></td>
			</tr>
		</table>
		<?php submit_button( __( 'トークンを取得して保存', 'wp-pcloud-backup' ), 'secondary' ); ?>
	</form>
<?php endif; ?>

<?php if ( $data['connected'] && ! $data['token_locked'] && ! $data['dev_storage'] ) : ?>
	<h3><?php esc_html_e( 'アクセストークンを表示する', 'wp-pcloud-backup' ); ?></h3>
	<?php if ( null !== $data['revealed'] ) : ?>
		<p>
			<input type="text" id="wppb-revealed-token" class="large-text code" readonly value="<?php echo esc_attr( $data['revealed'] ); ?>">
			<button type="button" class="button" data-wppb-copy="wppb-revealed-token"><?php esc_html_e( 'コピー', 'wp-pcloud-backup' ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'このトークンは今回だけ表示されます。ページを再読み込みすると消えます。', 'wp-pcloud-backup' ); ?></p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( $data['post_url'] ); ?>">
		<?php $data['fields']( 'reveal_token' ); ?>
		<p>
			<label for="wppb-current-password"><?php esc_html_e( '現在のパスワード', 'wp-pcloud-backup' ); ?></label>
			<input type="password" id="wppb-current-password" name="current_password" autocomplete="current-password">
			<button type="submit" class="button"><?php esc_html_e( 'トークンを表示', 'wp-pcloud-backup' ); ?></button>
		</p>
	</form>
<?php endif; ?>

<?php if ( $data['connected'] && ! $data['token_locked'] ) : ?>
	<form method="post" action="<?php echo esc_url( $data['post_url'] ); ?>" data-wppb-confirm="confirmDisc">
		<?php $data['fields']( 'disconnect' ); ?>
		<?php submit_button( __( '接続を解除', 'wp-pcloud-backup' ), 'delete' ); ?>
	</form>
<?php endif; ?>

<h2><?php esc_html_e( '外部 cron の URL', 'wp-pcloud-backup' ); ?></h2>
<p><?php esc_html_e( 'WP-Cron が使えないサーバーでは、この URL を 1 分ごとなど定期的に呼び出してください。URL を知っていれば誰でも実行を進められるため、他人に共有しないでください。', 'wp-pcloud-backup' ); ?></p>
<p>
	<input type="text" id="wppb-cron-url" class="large-text code" readonly value="<?php echo esc_attr( $data['cron_url'] ); ?>">
	<button type="button" class="button" data-wppb-copy="wppb-cron-url"><?php esc_html_e( 'コピー', 'wp-pcloud-backup' ); ?></button>
</p>
<form method="post" action="<?php echo esc_url( $data['post_url'] ); ?>" data-wppb-confirm="confirmRegen">
	<?php $data['fields']( 'regenerate_key' ); ?>
	<button type="submit" class="button"><?php esc_html_e( 'URL を再生成', 'wp-pcloud-backup' ); ?></button>
</form>

<h2><?php esc_html_e( 'WP-Cron', 'wp-pcloud-backup' ); ?></h2>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( 'WP-Cron で実行', 'wp-pcloud-backup' ); ?></th>
		<td><?php echo esc_html( $data['use_wp_cron'] ? __( 'オン', 'wp-pcloud-backup' ) : __( 'オフ（外部 cron を使用）', 'wp-pcloud-backup' ) ); ?></td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( '次回の定期チェック', 'wp-pcloud-backup' ); ?></th>
		<td><?php echo esc_html( $data['format_time']( (int) $data['next_cron'], 'Y-m-d H:i:s' ) ); ?></td>
	</tr>
</table>
<?php if ( $data['use_wp_cron'] && ! empty( $wppb_diag['disable_wp_cron']['value'] ) ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'DISABLE_WP_CRON が有効なため、WP-Cron は実行されません。上の URL を外部 cron から呼び出してください。', 'wp-pcloud-backup' ); ?></p></div>
<?php endif; ?>

<h2><?php esc_html_e( 'サーバー診断', 'wp-pcloud-backup' ); ?></h2>
<?php if ( empty( $wppb_diag['server_apache']['value'] ) ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'nginx 環境では作業フォルダへの直接アクセスをサーバー設定で遮断してください', 'wp-pcloud-backup' ); ?></p></div>
<?php endif; ?>
<table class="widefat striped wppb-diagnostics">
	<tbody>
		<?php foreach ( $wppb_rows as $wppb_key => $wppb_label ) : ?>
			<?php $wppb_entry = $wppb_diag[ $wppb_key ]; ?>
			<tr>
				<th scope="row"><?php echo esc_html( $wppb_label ); ?></th>
				<td><?php echo esc_html( $wppb_text( $wppb_key, $wppb_entry['value'] ) ); ?></td>
				<td>
					<?php if ( null === $wppb_entry['ok'] ) : ?>
						<span class="wppb-diag-info">-</span>
					<?php elseif ( $wppb_entry['ok'] ) : ?>
						<span class="wppb-diag-ok">OK</span>
					<?php else : ?>
						<span class="wppb-diag-ng"><?php esc_html_e( '要確認', 'wp-pcloud-backup' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
