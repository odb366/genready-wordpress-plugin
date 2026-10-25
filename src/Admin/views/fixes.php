<?php
/**
 * The Fixes section of the Overview, under the results: an intro and one card for each fix.
 *
 * Included from Page::view(), so every variable here is local to that method.
 * $view: connected (bool), cards (arrays from FixCards::card()), found (kind => list of
 * array( 'name', 'reason' ): the checks of the shown report that the fix solves).
 *
 * @package GenReady
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside Page::view(); these variables are local.

use GenReady\Admin\FixCards;

defined( 'ABSPATH' ) || exit;

$connected = ! empty( $view['connected'] );
$cards     = is_array( $view['cards'] ?? null ) ? $view['cards'] : array();
$found     = is_array( $view['found'] ?? null ) ? $view['found'] : array();
?>
<section class="genready-fixes" id="genready-fixes" aria-labelledby="genready-fixes-title">
	<h2 id="genready-fixes-title"><?php esc_html_e( 'Fixes', 'genready' ); ?></h2>
	<p class="genready-fixes__intro"><?php esc_html_e( 'Fixes add what AI search engines and agents look for on your site. GenReady writes each change for you. You see it first, nothing changes until you click Apply, and Undo puts the old version back.', 'genready' ); ?></p>
	<?php foreach ( $cards as $card ) : ?>
		<?php
		$kind = (string) ( $card['kind'] ?? '' );
		if ( ! in_array( $kind, array( 'robots', 'llms', 'jsonld' ), true ) ) {
			continue;
		}
		$card_id  = 'genready-' . $kind;
		$live     = is_array( $card['live'] ?? null ) ? $card['live'] : null;
		$versions = is_array( $card['versions'] ?? null ) ? $card['versions'] : array();
		$blocked  = ! empty( $card['blocked'] );
		$applied  = ! empty( $card['applied'] );
		$problems = is_array( $found[ $kind ] ?? null ) ? $found[ $kind ] : array();
		?>
		<section class="genready-card genready-fix" id="<?php echo esc_attr( $card_id ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>" aria-labelledby="<?php echo esc_attr( $card_id . '-title' ); ?>">
			<h3 id="<?php echo esc_attr( $card_id . '-title' ); ?>"><?php echo esc_html( (string) ( $card['title'] ?? '' ) ); ?></h3>
			<p class="genready-fix__about"><?php echo esc_html( FixCards::about( $kind ) ); ?></p>
			<?php if ( array() !== $problems ) : ?>
				<div class="genready-fix__found">
					<p><strong><?php esc_html_e( 'Your scan found:', 'genready' ); ?></strong></p>
					<ul>
						<?php foreach ( $problems as $problem ) : ?>
							<li>
								<?php echo esc_html( (string) ( $problem['name'] ?? '' ) ); ?>
								<?php if ( '' !== (string) ( $problem['reason'] ?? '' ) ) : ?>
									<span class="genready-check__reason"><?php echo esc_html( (string) $problem['reason'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<p class="genready-fix__state">
				<span class="<?php echo esc_attr( 'genready-state genready-state--' . (string) ( $card['state'] ?? 'not_applied' ) ); ?>" data-genready-field="state"><?php echo esc_html( (string) ( $card['state_text'] ?? '' ) ); ?></span>
			</p>
			<p class="genready-fix__message" data-genready-field="message"<?php echo '' === (string) ( $card['message'] ?? '' ) ? ' hidden' : ''; ?>><?php echo esc_html( (string) ( $card['message'] ?? '' ) ); ?></p>
			<?php if ( ! empty( $card['account_link'] ) ) : ?>
				<p class="genready-fix__account"><a class="button button-primary" href="#genready-account"><?php esc_html_e( 'Create my free account', 'genready' ); ?></a></p>
			<?php endif; ?>
			<p class="genready-fix__live" data-genready-field="live"<?php echo null === $live ? ' hidden' : ''; ?>><?php echo esc_html( null === $live ? '' : (string) ( $live['message'] ?? '' ) ); ?></p>

			<?php if ( 'robots' === $kind ) : ?>
				<p>
					<label>
						<input type="checkbox" data-genready-input="content_signal" checked="checked">
						<?php esc_html_e( 'Add the Content-Signal line (search=yes, ai-input=yes, ai-train=no)', 'genready' ); ?>
					</label>
				</p>
			<?php elseif ( 'llms' === $kind ) : ?>
				<p>
					<label for="genready-llms-tagline"><?php esc_html_e( 'Site summary', 'genready' ); ?></label><br>
					<textarea id="genready-llms-tagline" class="large-text" rows="2" maxlength="500" data-genready-input="tagline"><?php echo esc_textarea( (string) ( $card['tagline'] ?? '' ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'One or two sentences about your site. GenReady puts them at the top of llms.txt.', 'genready' ); ?></span>
				</p>
			<?php endif; ?>

			<p class="genready-fix__actions">
				<button type="button" class="button button-primary" data-genready-action="preview"<?php echo ( ! $connected || $blocked ) ? ' disabled' : ''; ?>><?php esc_html_e( 'Preview', 'genready' ); ?></button>
				<button type="button" class="button button-primary" data-genready-action="apply" hidden><?php esc_html_e( 'Apply', 'genready' ); ?></button>
				<button type="button" class="button" data-genready-action="undo"<?php echo $applied ? '' : ' disabled'; ?>><?php esc_html_e( 'Undo', 'genready' ); ?></button>
				<?php if ( 'jsonld' !== $kind ) : ?>
					<button type="button" class="button" data-genready-action="live-check"<?php echo ( ! empty( $card['live_check'] ) && $connected ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Check the live file', 'genready' ); ?></button>
				<?php endif; ?>
				<?php if ( 'jsonld' !== $kind ) : ?>
					<button type="button" class="button" data-genready-action="download"<?php echo ( empty( $card['can_write'] ) && $connected ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Download the file', 'genready' ); ?></button>
				<?php endif; ?>
				<?php if ( 'llms' === $kind ) : ?>
					<button type="button" class="button" data-genready-action="as-file"<?php echo ( null !== $live && 'no_route' === ( $live['state'] ?? '' ) && ! empty( $card['as_file'] ) && $connected ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Write llms.txt as a file', 'genready' ); ?></button>
				<?php endif; ?>
			</p>

			<div class="genready-preview" data-genready-field="preview" hidden></div>

			<details class="genready-versions" data-genready-field="versions"<?php echo array() === $versions ? ' hidden' : ''; ?>>
				<summary><?php esc_html_e( 'Earlier versions', 'genready' ); ?></summary>
				<p class="description"><?php esc_html_e( 'Undo goes back one step.', 'genready' ); ?></p>
				<ol>
					<?php foreach ( $versions as $version ) : ?>
						<li><?php echo esc_html( (string) $version ); ?></li>
					<?php endforeach; ?>
				</ol>
			</details>

			<div class="genready-result" data-genready-field="result" role="status" aria-live="polite" tabindex="-1"></div>
		</section>
	<?php endforeach; ?>
</section>
