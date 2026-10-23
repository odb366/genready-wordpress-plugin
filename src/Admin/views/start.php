<?php
/**
 * Overview without a connection and without a stored scan: the first screen (spec 4.1).
 *
 * Included from Page::view(), so every variable here is local to that method.
 * $view: home (string), suggestions (string[]), notice (string: err.gone when the only stored scan is gone).
 *
 * @package GenReady
 */

use GenReady\Admin\Page;

defined( 'ABSPATH' ) || exit;
?>
<section class="genready-card genready-start" aria-labelledby="genready-start-title">
	<h2 id="genready-start-title"><?php esc_html_e( 'See how AI search engines and agents read your site', 'genready' ); ?></h2>
	<?php echo Page::view( 'scan-form', $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the view escapes every value. ?>
	<p class="genready-consent">
		<?php esc_html_e( 'Sends your site address to genready.ai. No account needed.', 'genready' ); ?>
		<?php echo Page::terms(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Page::terms(). ?>
	</p>
	<p><button type="button" class="button-link" data-genready-action="show-connect" aria-expanded="false" aria-controls="genready-connect-panel"><?php esc_html_e( 'Already have a GenReady account? Connect', 'genready' ); ?></button></p>
	<div id="genready-connect-panel" hidden><?php echo Page::view( 'connection', array( 'connected' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the view escapes every value. ?></div>
</section>
