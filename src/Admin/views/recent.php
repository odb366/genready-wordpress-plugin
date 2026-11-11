<?php
/**
 * The recent scans: the page, the score and the date of each, with the open scan marked.
 *
 * Included from Page::view(), so every variable here is local to that method.
 * $view: scans (array[] with id, url, at and score, newest first), open_scan (string).
 *
 * @package GenReady
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside Page::view(); these variables are local.

use GenReady\Admin\ScoreCard;
use GenReady\Onboarding\PageUrl;

defined( 'ABSPATH' ) || exit;

$scans     = is_array( $view['scans'] ?? null ) ? $view['scans'] : array();
$open_scan = (string) ( $view['open_scan'] ?? '' );
?>
<section class="genready-card genready-recent" aria-labelledby="genready-recent-title">
	<h2 id="genready-recent-title"><?php esc_html_e( 'Recent scans', 'genready' ); ?></h2>
	<table class="genready-recent__table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Page', 'genready' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Score', 'genready' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Date', 'genready' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $scans as $scan ) :
				$current = $scan['id'] === $open_scan;
				$label   = PageUrl::label( (string) $scan['url'] );
				$score   = is_int( $scan['score'] ?? null ) ? $scan['score'] : null;
				$band    = null === $score ? null : ScoreCard::band( $score );
				$at      = (int) ( $scan['at'] ?? 0 );
				?>
				<tr class="genready-recent__row<?php echo $current ? ' is-current' : ''; ?>">
					<td class="genready-recent__page">
						<?php if ( $current ) : ?>
							<span aria-current="true"><?php echo esc_html( $label ); ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=genready&scan=' . $scan['id'] ) ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endif; ?>
					</td>
					<td class="genready-recent__score">
						<?php if ( null === $band ) : ?>
							<span class="genready-recent__none"><?php esc_html_e( 'No score yet', 'genready' ); ?></span>
						<?php else : ?>
							<span class="genready-pill <?php echo esc_attr( $band['class'] ); ?>"><?php echo esc_html( (string) $score ); ?></span> <span class="genready-recent__band"><?php echo esc_html( $band['text'] ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $at > 0 ) : ?>
							<span class="genready-recent__date"><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), $at ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>
