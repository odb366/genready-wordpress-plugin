<?php
/**
 * The "Page address" field, its list and "Check this page" (spec 4.1, D5).
 *
 * Included from Page::view(), so every variable here is local to that method.
 * $view: home (string), suggestions (string[]), notice (string, optional: an error for the result line).
 *
 * @package GenReady
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside Page::view(); these variables are local.

defined( 'ABSPATH' ) || exit;

$home        = (string) ( $view['home'] ?? '' );
$suggestions = is_array( $view['suggestions'] ?? null ) ? $view['suggestions'] : array();
$notice      = (string) ( $view['notice'] ?? '' );
?>
<form id="genready-scan-form" class="genready-scan-form" novalidate data-genready-onboarding>
	<p>
		<label for="genready-page-url"><?php esc_html_e( 'Page address', 'genready' ); ?></label><br>
		<input type="url" id="genready-page-url" class="regular-text" value="<?php echo esc_url( $home ); ?>" list="genready-pages" maxlength="2048" autocomplete="off" spellcheck="false" data-genready-input="url">
		<datalist id="genready-pages">
			<?php foreach ( $suggestions as $suggestion ) : ?>
				<option value="<?php echo esc_url( (string) $suggestion ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
	</p>
	<p><button type="submit" class="button button-primary" data-genready-action="check"><?php esc_html_e( 'Check this page', 'genready' ); ?></button></p>
</form>
<div id="genready-scan-result" class="genready-result<?php echo '' !== $notice ? ' genready-result--error' : ''; ?>" role="status" aria-live="polite" tabindex="-1"><?php echo '' !== $notice ? '<p>' . esc_html( $notice ) . '</p>' : ''; ?></div>
