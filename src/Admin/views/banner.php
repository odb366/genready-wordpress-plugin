<?php
/**
 * The account banner at the top of the Overview without an account (spec 4.3 item 1).
 *
 * Included from Page::view(), so every variable here is local to that method.
 * $view: email (string).
 *
 * @package GenReady
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside Page::view(); these variables are local.

use GenReady\Admin\Page;

defined( 'ABSPATH' ) || exit;

$email = (string) ( $view['email'] ?? '' );
?>
<section class="genready-card genready-account" id="genready-account" aria-labelledby="genready-account-title">
	<h2 id="genready-account-title"><?php esc_html_e( 'Save your site and track your score', 'genready' ); ?></h2>
	<form id="genready-account-form" class="genready-account-form" novalidate data-genready-onboarding>
		<p>
			<label for="genready-account-email"><?php esc_html_e( 'Email', 'genready' ); ?></label><br>
			<input type="email" id="genready-account-email" class="regular-text" value="<?php echo esc_attr( $email ); ?>" maxlength="254" autocomplete="email" spellcheck="false" data-genready-input="email">
			<button type="submit" class="button button-primary" data-genready-action="create-account"><?php esc_html_e( 'Create my free account', 'genready' ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'Creates a GenReady account for this email and adds this site to it. GenReady emails you a link to set your password.', 'genready' ); ?></p>
		<p class="description"><?php echo Page::terms(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Page::terms(). ?></p>
	</form>
	<div id="genready-account-result" class="genready-result" role="status" aria-live="polite" tabindex="-1"></div>
	<p><button type="button" class="button-link" data-genready-action="show-connect" aria-expanded="false" aria-controls="genready-connect-panel"><?php esc_html_e( 'Already have a GenReady account? Connect', 'genready' ); ?></button></p>
	<div id="genready-connect-panel" hidden><?php echo Page::view( 'connection', array( 'connected' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the view escapes every value. ?></div>
</section>
