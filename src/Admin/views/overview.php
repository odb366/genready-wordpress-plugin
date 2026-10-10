<?php
/**
 * Overview tab: score card, AI view, sections and footer.
 *
 * Included from Page::view(), so every variable here is local to that method.
 * $view: connected (bool), state (array from Reports::state(), or state 'error').
 *
 * @package GenReady
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside Page::view(); these variables are local.

use GenReady\Admin\ScoreCard;

defined( 'ABSPATH' ) || exit;

$state  = is_array( $view['state'] ?? null ) ? $view['state'] : array( 'state' => 'none' );
$report = 'complete' === ( $state['state'] ?? '' ) && is_array( $state['report'] ?? null ) ? $state['report'] : null;

if ( null === $report ) :
	?>
	<section class="genready-card genready-empty" data-genready-state="<?php echo esc_attr( (string) ( $state['state'] ?? 'none' ) ); ?>">
		<?php if ( 'pending' === ( $state['state'] ?? '' ) ) : ?>
			<h2><?php esc_html_e( 'The scan is running', 'genready' ); ?></h2>
			<p><?php esc_html_e( 'GenReady is scanning your home page. This page updates when the report is ready.', 'genready' ); ?></p>
		<?php elseif ( 'failed' === ( $state['state'] ?? '' ) || 'error' === ( $state['state'] ?? '' ) ) : ?>
			<h2><?php esc_html_e( 'No report to show', 'genready' ); ?></h2>
			<div class="notice notice-error inline"><p><?php echo esc_html( (string) ( $state['message'] ?? '' ) ); ?></p></div>
			<p><button type="button" class="button button-primary" data-genready-action="scan"><?php esc_html_e( 'Scan again', 'genready' ); ?></button></p>
		<?php else : ?>
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
		<h2 id="genready-aiview-title"><?php esc_html_e( 'How AI sees your homepage', 'genready' ); ?></h2>
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
				<img class="genready-shot" src="<?php echo esc_url( $screenshot ); ?>" alt="<?php esc_attr_e( 'Screenshot of your homepage', 'genready' ); ?>" loading="lazy" width="1280" height="800">
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
		<button type="button" class="button" data-genready-action="scan"><?php esc_html_e( 'Scan again', 'genready' ); ?></button>
		<?php if ( ! empty( $report['scanned_at'] ) ) : ?>
			<span class="genready-footer__date">
				<?php
				/* translators: %s: date of the scan. */
				echo esc_html( sprintf( __( 'Scanned on %s', 'genready' ), date_i18n( (string) get_option( 'date_format' ), (int) $report['scanned_at'] ) ) );
				?>
			</span>
		<?php endif; ?>
		<?php if ( '' !== (string) ( $report['report_link'] ?? '' ) ) : ?>
			<a href="<?php echo esc_url( (string) $report['report_link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the full report on genready.ai', 'genready' ); ?></a>
		<?php endif; ?>
	</p>
</div>
