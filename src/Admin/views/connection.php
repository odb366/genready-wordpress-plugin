<?php
/**
 * Connection tab, and the connect form when the site is not connected.
 *
 * Included from Page::view(), so every variable here is local to that method.
 * $view: connected (bool), account (array from Reports::account(), or null), tag_on (bool),
 * paused (bool, true while GenReady paused free scans).
 *
 * @package GenReady
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside Page::view(); these variables are local.

use GenReady\Admin\Page;

defined( 'ABSPATH' ) || exit;

$connected = ! empty( $view['connected'] );
$account   = is_array( $view['account'] ?? null ) ? $view['account'] : array();
$tag_on    = ! empty( $view['tag_on'] );
$paused    = ! empty( $view['paused'] );
$unknown   = __( 'Not known', 'genready' );

if ( ! $connected ) :
	?>
	<section class="genready-card genready-connect"<?php echo $paused ? ' id="genready-account"' : ''; ?> aria-labelledby="genready-connect-title">
		<h2 id="genready-connect-title"><?php esc_html_e( 'Connect GenReady', 'genready' ); ?></h2>
		<?php if ( $paused ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'GenReady paused free scans for now. Connect with a GenReady account.', 'genready' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'See how AI search engines and agents read your site, and apply fixes for robots.txt, structured data and llms.txt.', 'genready' ); ?></p>
		<p>
			<?php
			/* translators: %d: number of scans a month on the free plan. */
			echo esc_html( sprintf( __( 'A free GenReady account includes %d scans a month for any page on this site.', 'genready' ), Page::FREE_SCANS ) );
			?>
		</p>
		<p class="genready-consent">
			<?php esc_html_e( "Connecting sends this site's address to genready.ai. The plugin then adds a verification tag to your home page. The tag proves that you own this site. Visitors do not see it.", 'genready' ); ?>
			<?php echo Page::terms(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Page::terms(). ?>
		</p>
		<p>
			<label>
				<input type="checkbox" id="genready-send-email" data-genready-input="send_email">
				<?php esc_html_e( 'Send my admin email to fill in the sign-in form', 'genready' ); ?>
			</label>
		</p>
		<p>
			<button type="button" class="button button-primary" data-genready-action="connect"><?php esc_html_e( 'Connect to GenReady', 'genready' ); ?></button>
			<button type="button" class="button-link" data-genready-action="show-key" aria-expanded="false" aria-controls="genready-key-form"><?php esc_html_e( 'Use an API key', 'genready' ); ?></button>
		</p>
		<form id="genready-key-form" class="genready-key-form" hidden>
			<p>
				<label for="genready-key"><?php esc_html_e( 'API key', 'genready' ); ?></label><br>
				<input type="password" id="genready-key" class="regular-text" autocomplete="off" spellcheck="false" maxlength="200" data-genready-input="key">
				<button type="submit" class="button"><?php esc_html_e( 'Connect with the key', 'genready' ); ?></button>
			</p>
			<p class="description">
				<?php esc_html_e( 'Use this when GenReady cannot reach your site to connect. Create a key for one site on genready.ai.', 'genready' ); ?>
				<a href="<?php echo esc_url( 'https://genready.ai/api-keys' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a key', 'genready' ); ?></a>
			</p>
		</form>
		<div class="genready-result" data-genready-field="result" role="status" aria-live="polite" tabindex="-1"></div>
	</section>
	<?php
	return;
endif;

$method   = 'oauth' === ( $account['method'] ?? '' ) ? __( 'GenReady account (OAuth)', 'genready' ) : __( 'API key', 'genready' );
$verified = $account['verified'] ?? null;
$credits  = is_array( $account['credits'] ?? null ) ? $account['credits'] : null;
$limit    = null !== $credits && is_int( $credits['limit'] ?? null ) ? $credits['limit'] : null;
if ( null !== $limit ) {
	/* translators: %d: number of scans a month on the site's GenReady plan. */
	$lose = sprintf( __( 'Without a connection, the plugin cannot use the %d scans a month of your plan.', 'genready' ), $limit );
} else {
	$lose = __( 'Without a connection, the plugin cannot use the scans of your GenReady plan.', 'genready' );
}
$keep = __( 'Applied fixes stay. Your recent scans come back when you connect this account again.', 'genready' );
// The scans left this month, or null when GenReady did not say.
$credits_text = null;
if ( null !== $credits && null === $limit ) {
	$credits_text = __( 'No monthly limit', 'genready' );
} elseif ( null !== $limit && is_int( $credits['remaining'] ?? null ) ) {
	/* translators: 1: scans left this month, 2: scans a month on the plan. */
	$credits_text = sprintf( __( '%1$d of %2$d left', 'genready' ), $credits['remaining'], $limit );
} elseif ( null !== $limit ) {
	/* translators: %d: scans a month on the plan. */
	$credits_text = sprintf( __( '%d a month', 'genready' ), $limit );
}
?>
<section class="genready-card genready-connection" aria-labelledby="genready-connection-title">
	<h2 id="genready-connection-title"><?php esc_html_e( 'Account', 'genready' ); ?></h2>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connected with', 'genready' ); ?></th>
				<td><?php echo esc_html( $method ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Plan', 'genready' ); ?></th>
				<td><?php echo esc_html( is_string( $account['plan'] ?? null ) ? ucfirst( $account['plan'] ) : $unknown ); ?></td>
			</tr>
			<?php if ( null !== $credits_text ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Scans this month', 'genready' ); ?></th>
					<td data-genready-field="credits"><?php echo esc_html( $credits_text ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Site name in GenReady', 'genready' ); ?></th>
				<td><?php echo esc_html( is_string( $account['site_name'] ?? null ) && '' !== $account['site_name'] ? $account['site_name'] : ( is_string( $account['hostname'] ?? null ) ? $account['hostname'] : $unknown ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Verification', 'genready' ); ?></th>
				<td data-genready-field="verified">
					<?php if ( true === $verified ) : ?>
						<?php esc_html_e( 'Verified', 'genready' ); ?>
					<?php else : ?>
						<?php echo esc_html( false === $verified ? __( 'Not verified', 'genready' ) : $unknown ); ?>
						<button type="button" class="button" data-genready-action="verify"><?php esc_html_e( 'Verify now', 'genready' ); ?></button>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<p>
		<button type="button" class="button" data-genready-action="disconnect" data-genready-confirm="<?php echo esc_attr( __( 'Disconnect GenReady?', 'genready' ) . ' ' . $lose . ' ' . $keep ); ?>"><?php esc_html_e( 'Disconnect', 'genready' ); ?></button>
	</p>
	<p class="description"><?php echo esc_html( $lose . ' ' . $keep ); ?></p>

	<details class="genready-key-instead">
		<summary><?php esc_html_e( 'Use an API key instead', 'genready' ); ?></summary>
		<p><?php esc_html_e( 'Disconnect GenReady first, then add the API key.', 'genready' ); ?></p>
	</details>

	<h3><?php esc_html_e( 'Verification tag', 'genready' ); ?></h3>
	<?php // Both states are on the page, so admin.js switches them without a reload. ?>
	<div data-genready-field="tag-on"<?php echo $tag_on ? '' : ' hidden'; ?>>
		<p><?php esc_html_e( 'Your home page has the GenReady verification tag. The tag proves that you own this site. It stays after you disconnect.', 'genready' ); ?></p>
		<p><?php esc_html_e( 'GenReady checks the tag about once a week. Without the tag, the site loses its verified status.', 'genready' ); ?></p>
		<p><button type="button" class="button" data-genready-action="remove-tag"><?php esc_html_e( 'Remove the verification tag', 'genready' ); ?></button></p>
	</div>
	<div data-genready-field="tag-off"<?php echo $tag_on ? ' hidden' : ''; ?>>
		<p><?php esc_html_e( 'Your home page does not have the verification tag. GenReady needs the tag to keep the site verified.', 'genready' ); ?></p>
		<p><button type="button" class="button" data-genready-action="add-tag"><?php esc_html_e( 'Add the verification tag', 'genready' ); ?></button></p>
	</div>

	<div class="genready-result" data-genready-field="result" role="status" aria-live="polite" tabindex="-1"></div>
</section>
