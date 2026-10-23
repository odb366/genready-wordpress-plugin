<?php
/**
 * Overview tab: score card, AI view, sections and footer.
 *
 * Included from Page::view(), so every variable here is local to that method.
 * $view: connected (bool), state (array from Reports::state(), or state 'error'), and without a
 * connection: home, suggestions, email, scans (from AnonymousScans::all()).
 *
 * @package GenReady
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside Page::view(); these variables are local.

use GenReady\Admin\Page;
use GenReady\Admin\ScoreCard;
use GenReady\Onboarding\PageUrl;

defined( 'ABSPATH' ) || exit;

$state  = is_array( $view['state'] ?? null ) ? $view['state'] : array( 'state' => 'none' );
$report = 'complete' === ( $state['state'] ?? '' ) && is_array( $state['report'] ?? null ) ? $state['report'] : null;

$connected = ! empty( $view['connected'] );
$scans     = is_array( $view['scans'] ?? null ) ? $view['scans'] : array();
$open_scan = (string) ( $state['scan_id'] ?? '' );

if ( ! $connected ) {
	echo Page::view( 'banner', $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the view escapes every value.
	echo '<section class="genready-card genready-check" aria-label="' . esc_attr__( 'Check this page', 'genready' ) . '">';
	echo Page::view( 'scan-form', $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the view escapes every value.
	echo '</section>';
	if ( count( $scans ) >= 2 ) :
		?>
		<section class="genready-card genready-recent" aria-labelledby="genready-recent-title">
			<h2 id="genready-recent-title"><?php esc_html_e( 'Recent scans', 'genready' ); ?></h2>
			<ul>
				<?php foreach ( $scans as $scan ) : ?>
					<li>
						<?php if ( $scan['id'] === $open_scan ) : ?>
							<span aria-current="true"><?php echo esc_html( PageUrl::label( (string) $scan['url'] ) ); ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=genready&scan=' . $scan['id'] ) ); ?>"><?php echo esc_html( PageUrl::label( (string) $scan['url'] ) ); ?></a>
						<?php endif; ?>
						<span class="genready-recent__date"><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) $scan['at'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	endif;
}

if ( null === $report ) :
	?>
	<section class="genready-card genready-empty" data-genready-state="<?php echo esc_attr( (string) ( $state['state'] ?? 'none' ) ); ?>">
		<?php if ( 'pending' === ( $state['state'] ?? '' ) ) : ?>
			<h2><?php esc_html_e( 'The scan is running', 'genready' ); ?></h2>
			<p><?php esc_html_e( 'Scanning this page. This takes about 30 seconds.', 'genready' ); ?></p>
		<?php elseif ( in_array( $state['state'] ?? '', array( 'failed', 'error', 'gone' ), true ) ) : ?>
			<h2><?php esc_html_e( 'No report to show', 'genready' ); ?></h2>
			<div class="notice notice-error inline"><p><?php echo esc_html( (string) ( $state['message'] ?? '' ) ); ?></p></div>
			<?php if ( $connected ) : ?>
				<p><button type="button" class="button button-primary" data-genready-action="scan"><?php esc_html_e( 'Scan again', 'genready' ); ?></button></p>
			<?php endif; ?>
		<?php elseif ( $connected ) : ?>
			<h2><?php esc_html_e( 'No scan yet', 'genready' ); ?></h2>
			<p><?php esc_html_e( 'Scan your home page to see how AI search engines and agents read it. A scan uses 1 API credit.', 'genready' ); ?></p>
			<p><button type="button" class="button button-primary" data-genready-action="scan"><?php esc_html_e( 'Scan now', 'genready' ); ?></button></p>
		<?php endif; ?>
	</section>
	<?php
	return;
endif;

$band       = ScoreCard::band( (int) $report['overall'] );
$agents     = is_array( $report['agents'] ?? null ) ? $report['agents'] : null;
$ai_view    = is_array( $report['ai_view'] ?? null ) ? $report['ai_view'] : array();
$screenshot = is_string( $ai_view['screenshot'] ?? null ) ? $ai_view['screenshot'] : null;
$markdown   = is_string( $ai_view['markdown'] ?? null ) ? $ai_view['markdown'] : null;
$gap        = is_int( $ai_view['gap'] ?? null ) ? $ai_view['gap'] : null;
$first      = null !== $screenshot ? 'screenshot' : 'text';
$is_home    = '' === (string) ( $report['url'] ?? '' ) || PageUrl::is_home( (string) $report['url'] );
$fixes_url  = admin_url( 'options-general.php?page=genready&tab=fixes' );
$verdicts   = array(
	'pass' => __( 'Pass', 'genready' ),
	'warn' => __( 'Warning', 'genready' ),
	'fail' => __( 'Fail', 'genready' ),
);
$sections   = array(
	'content'      => __( 'Content', 'genready' ),
	'crawlability' => __( 'Crawlability', 'genready' ),
	'agents'       => __( 'Agent readiness', 'genready' ),
);
?>
<div class="genready-overview">
	<section class="genready-card genready-score" aria-labelledby="genready-score-title">
		<h2 id="genready-score-title"><?php esc_html_e( 'AI Readiness Score', 'genready' ); ?></h2>
		<div class="genready-score__main">
			<?php echo ScoreCard::ring( (int) $report['overall'], __( 'AI Readiness', 'genready' ), 128 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ScoreCard::ring() escapes every attribute; the rest is fixed markup and integers. ?>
			<p class="genready-band <?php echo esc_attr( $band['class'] ); ?>"><?php echo esc_html( $band['text'] ); ?></p>
		</div>
		<ul class="genready-score__items">
			<li>
				<a class="genready-score__item" href="#genready-section-content">
					<?php echo ScoreCard::ring( (int) $report['content'], __( 'Content', 'genready' ), 80 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in ScoreCard::ring(). ?>
					<span><?php esc_html_e( 'Content', 'genready' ); ?></span>
				</a>
			</li>
			<li>
				<a class="genready-score__item" href="#genready-section-crawlability">
					<?php echo ScoreCard::ring( (int) $report['crawlability'], __( 'Crawlability', 'genready' ), 80 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in ScoreCard::ring(). ?>
					<span><?php esc_html_e( 'Crawlability', 'genready' ); ?></span>
				</a>
			</li>
			<?php if ( null !== $agents ) : ?>
				<li>
					<a class="genready-score__item" href="#genready-section-agents">
						<span class="genready-badge" aria-hidden="true"><?php echo esc_html( (int) $agents['detected'] . '/8' ); ?></span>
						<span>
							<?php
							/* translators: %d: number of agent standards found, out of 8. */
							$agents_text = sprintf( __( 'Agents %d/8', 'genready' ), (int) $agents['detected'] );
							if ( (int) $agents['bonus'] > 0 ) {
								$agents_text .= ' +' . (int) $agents['bonus'];
							}
							echo esc_html( $agents_text );
							?>
						</span>
					</a>
				</li>
			<?php endif; ?>
		</ul>
	</section>

	<section class="genready-card genready-aiview" aria-labelledby="genready-aiview-title">
		<h2 id="genready-aiview-title"><?php echo esc_html( $is_home ? __( 'How AI sees your homepage', 'genready' ) : __( 'How AI sees this page', 'genready' ) ); ?></h2>
		<?php if ( ! $is_home ) : ?>
			<p class="genready-aiview__url"><?php echo esc_html( (string) $report['url'] ); ?></p>
		<?php endif; ?>
		<div class="genready-toggle" role="group" aria-label="<?php esc_attr_e( 'Choose a view', 'genready' ); ?>">
			<button type="button" class="button" data-genready-view="screenshot" aria-pressed="<?php echo 'screenshot' === $first ? 'true' : 'false'; ?>"><?php esc_html_e( 'Screenshot', 'genready' ); ?></button>
			<button type="button" class="button" data-genready-view="text" aria-pressed="<?php echo 'text' === $first ? 'true' : 'false'; ?>"><?php esc_html_e( 'What AI reads', 'genready' ); ?></button>
		</div>
		<?php if ( null !== $gap && $gap >= 30 ) : ?>
			<div class="notice notice-warning inline genready-gap">
				<p>
					<?php
					/* translators: %d: percent of the page that needs JavaScript. */
					echo esc_html( sprintf( __( 'AI crawlers that do not run JavaScript miss %d%% of this page.', 'genready' ), $gap ) );
					?>
				</p>
			</div>
		<?php endif; ?>
		<div class="genready-aiview__panel" data-genready-panel="screenshot"<?php echo 'screenshot' === $first ? '' : ' hidden'; ?>>
			<?php if ( null !== $screenshot ) : ?>
				<img class="genready-shot" src="<?php echo esc_url( $screenshot ); ?>" alt="<?php echo esc_attr( $is_home ? __( 'Screenshot of your homepage', 'genready' ) : __( 'Screenshot of this page', 'genready' ) ); ?>" loading="lazy" width="1280" height="800">
			<?php else : ?>
				<p><?php esc_html_e( 'This scan has no screenshot.', 'genready' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="genready-aiview__panel" data-genready-panel="text"<?php echo 'text' === $first ? '' : ' hidden'; ?>>
			<?php if ( null !== $markdown ) : ?>
				<pre class="genready-ai-text" tabindex="0"><?php echo esc_html( $markdown ); ?></pre>
				<?php if ( ! empty( $ai_view['truncated'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'The text is long, so GenReady shows only the first part.', 'genready' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'This scan has no text view.', 'genready' ); ?></p>
			<?php endif; ?>
		</div>
	</section>

	<?php foreach ( $sections as $section_key => $section_title ) : ?>
		<?php
		$checks = is_array( $report['sections'][ $section_key ] ?? null ) ? $report['sections'][ $section_key ] : array();
		if ( array() === $checks ) {
			continue;
		}
		?>
		<section class="genready-card genready-section" id="<?php echo esc_attr( 'genready-section-' . $section_key ); ?>" aria-labelledby="<?php echo esc_attr( 'genready-section-' . $section_key . '-title' ); ?>">
			<h2 id="<?php echo esc_attr( 'genready-section-' . $section_key . '-title' ); ?>"><?php echo esc_html( $section_title ); ?></h2>
			<ul class="genready-checks">
				<?php foreach ( $checks as $check ) : ?>
					<?php $verdict = isset( $verdicts[ $check['verdict'] ?? '' ] ) ? $check['verdict'] : 'warn'; ?>
					<li class="<?php echo esc_attr( 'genready-check genready-check--' . $verdict ); ?>">
						<span class="genready-verdict"><?php echo esc_html( $verdicts[ $verdict ] ); ?></span>
						<span class="genready-check__body">
							<strong><?php echo esc_html( (string) ( $check['name'] ?? '' ) ); ?></strong>
							<?php if ( '' !== (string) ( $check['reason'] ?? '' ) ) : ?>
								<span class="genready-check__reason"><?php echo esc_html( (string) $check['reason'] ); ?></span>
							<?php endif; ?>
						</span>
						<?php if ( in_array( $check['fix'] ?? null, array( 'robots', 'llms', 'jsonld' ), true ) ) : ?>
							<a class="genready-check__fix" href="<?php echo esc_url( $fixes_url . '#genready-' . $check['fix'] ); ?>"><?php esc_html_e( 'Fix with one click', 'genready' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>

	<p class="genready-footer">
		<button type="button" class="button" data-genready-action="scan" data-genready-url="<?php echo esc_url( (string) ( $report['url'] ?? '' ) ); ?>"><?php esc_html_e( 'Scan again', 'genready' ); ?></button>
		<?php if ( ! empty( $report['scanned_at'] ) ) : ?>
			<span class="genready-footer__date">
				<?php
				/* translators: %s: date of the scan. */
				echo esc_html( sprintf( __( 'Scanned on %s', 'genready' ), wp_date( (string) get_option( 'date_format' ), (int) $report['scanned_at'] ) ) );
				?>
			</span>
		<?php endif; ?>
		<?php if ( '' !== (string) ( $report['report_link'] ?? '' ) ) : ?>
			<a href="<?php echo esc_url( (string) $report['report_link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the full report on genready.ai', 'genready' ); ?></a>
		<?php endif; ?>
	</p>
</div>
