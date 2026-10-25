<?php
/**
 * Page frame: heading, notice and tab navigation.
 *
 * @var array $data Prepared by AdminPage::render().
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php echo esc_html( 'pCloud Backup' ); ?></h1>

	<?php if ( ! empty( $data['notice'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'success' === $data['notice']['type'] ? 'success' : 'error' ); ?> is-dismissible">
			<p><?php echo esc_html( $data['notice']['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $data['tabs'] as $wppb_tab ) : ?>
			<a href="<?php echo esc_url( $wppb_tab['url'] ); ?>" class="nav-tab<?php echo $wppb_tab['current'] ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $wppb_tab['label'] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php include __DIR__ . '/tab-' . $data['tab'] . '.php'; ?>
</div>
