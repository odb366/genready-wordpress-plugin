<?php
/**
 * Connection tab, and the connect form when the site is not connected.
 *
 * Included from Page::view(), so every variable here is local to that method.
 * $view: connected (bool), account (array from Reports::account(), or null), tag_on (bool).
 *
 * @package GenReady
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside Page::view(); these variables are local.

defined( 'ABSPATH' ) || exit;

$connected = ! empty( $view['connected'] );
$account   = is_array( $view['account'] ?? null ) ? $view['account'] : array();
$tag_on    = ! empty( $view['tag_on'] );
$unknown   = __( 'Not known', 'genready' );

if ( ! $connected ) :
	?>
	<section class="genready-card genready-connect" aria-labelledby="genready-connect-title">
		<h2 id="genready-connect-title"><?php esc_html_e( 'Connect GenReady', 'genready' ); ?></h2>
		<p><?php esc_html_e( 'See how AI search engines and agents read your site, and apply fixes for robots.txt, llms.txt and structured data.', 'genready' ); ?></p>
		<p class="genready-consent">
			<?php esc_html_e( "Connecting sends this site's address to genready.ai.", 'genready' ); ?>
			<?php
			printf(
				/* translators: 1: link to the Terms, 2: link to the Privacy Policy. */
				esc_html__( "GenReady's %1\$s and %2\$s apply.", 'genready' ),
				'<a href="' . esc_url( 'https://genready.ai/terms' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'genready' ) . '</a>',
				'<a href="' . esc_url( 'https://genready.ai/privacy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'genready' ) . '</a>'
			);
			?>
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
?>
<section class="genready-card genready-connection" aria-labelledby="genready-connection-title">
	<h2 id="genready-connection-title"><?php esc_html_e( 'Connection', 'genready' ); ?></h2>
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
			<tr>
				<th scope="row"><?php esc_html_e( 'Site name in GenReady', 'genready' ); ?></th>
				<td><?php echo esc_html( is_string( $account['site_name'] ?? null ) && '' !== $account['site_name'] ? $account['site_name'] : ( is_string( $account['hostname'] ?? null ) ? $account['hostname'] : $unknown ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Verification', 'genready' ); ?></th>
				<td>
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
		<button type="button" class="button" data-genready-action="disconnect"><?php esc_html_e( 'Disconnect', 'genready' ); ?></button>
		<span class="description"><?php esc_html_e( 'Applied fixes stay after you disconnect.', 'genready' ); ?></span>
	</p>

	<details class="genready-key-instead">
		<summary><?php esc_html_e( 'Use an API key instead', 'genready' ); ?></summary>
		<p><?php esc_html_e( 'Disconnect GenReady first, then add the API key.', 'genready' ); ?></p>
	</details>

	<h3><?php esc_html_e( 'Verification tag', 'genready' ); ?></h3>
	<?php if ( $tag_on ) : ?>
		<p><?php esc_html_e( 'Your home page shows the GenReady verification tag. It stays after you disconnect, so the site stays verified.', 'genready' ); ?></p>
		<p><button type="button" class="button" data-genready-action="remove-tag"><?php esc_html_e( 'Remove the verification tag', 'genready' ); ?></button></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Your home page does not show the verification tag.', 'genready' ); ?></p>
	<?php endif; ?>

	<div class="genready-result" data-genready-field="result" role="status" aria-live="polite" tabindex="-1"></div>
</section>
