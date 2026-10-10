<?php
/**
 * Score rings and readiness bands.
 *
 * @package GenReady
 */

namespace GenReady\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the score rings as inline SVG and names the readiness band, with the
 * same thresholds as genready.ai:
 * - ring colour: client/src/components/ui/circular-progress.tsx (85 and up
 *   green, 50 to 84 amber, below 50 red);
 * - band text: shared/scoring.ts getReadinessDescription() (80 Pass, 60 Fair,
 *   40 Poor, below 40 Fail).
 */
final class ScoreCard {
	/** Ring colours from circular-progress.tsx. CSS custom properties override them. */
	const COLORS = array(
		'high'  => '#0cce6a',
		'mid'   => '#ffa400',
		'low'   => '#ff4e43',
		'track' => '#e2e8f0',
	);

	/**
	 * An accessible score ring. The number is text inside the SVG.
	 *
	 * @param int    $score Score from 0 to 100. Other values are clamped.
	 * @param string $label Name of the score, for example "Content".
	 * @param int    $size  Width and height in pixels.
	 */
	public static function ring( int $score, string $label, int $size ): string {
		$score  = self::clamp( $score );
		$size   = max( 32, min( 256, $size ) );
		$stroke = max( 4, (int) round( $size / 10 ) );
		$center = $size / 2;
		$radius = ( $size - $stroke ) / 2;
		$length = 2 * M_PI * $radius;
		$offset = $length - ( $score / 100 ) * $length;
		$level  = self::level( $score );
		$aria   = sprintf(
			/* translators: 1: score name, for example Content. 2: score from 0 to 100. */
			__( '%1$s: %2$d of 100', 'genready' ),
			$label,
			$score
		);
		$number = sprintf( '%.2F', $center );
		return sprintf(
			'<svg class="genready-ring %1$s" role="img" aria-label="%2$s" width="%3$d" height="%3$d" viewBox="0 0 %3$d %3$d">'
			. '<circle class="genready-ring__track" cx="%4$s" cy="%4$s" r="%5$s" fill="none" stroke="%6$s" stroke-width="%7$d"/>'
			. '<circle class="genready-ring__bar" cx="%4$s" cy="%4$s" r="%5$s" fill="none" stroke="%8$s" stroke-width="%7$d" stroke-linecap="%9$s" stroke-dasharray="%10$s" stroke-dashoffset="%11$s" transform="rotate(-90 %4$s %4$s)"/>'
			. '<text class="genready-ring__value" x="50%%" y="50%%" text-anchor="middle" dominant-baseline="central" font-size="%12$d">%13$d</text>'
			. '</svg>',
			esc_attr( self::ring_class( $score ) ),
			esc_attr( $aria ),
			$size,
			esc_attr( $number ),
			esc_attr( sprintf( '%.2F', $radius ) ),
			esc_attr( self::COLORS['track'] ),
			$stroke,
			esc_attr( self::COLORS[ $level ] ),
			$score > 0 ? 'round' : 'butt',
			esc_attr( sprintf( '%.2F', $length ) ),
			esc_attr( sprintf( '%.2F', $offset ) ),
			(int) round( $size / 4 ),
			$score
		);
	}

	/**
	 * The CSS class of a ring's colour.
	 *
	 * @param int $score Score from 0 to 100.
	 */
	public static function ring_class( int $score ): string {
		return 'genready-ring--' . self::level( self::clamp( $score ) );
	}

	/**
	 * The readiness band of the overall score, as genready.ai names it.
	 *
	 * @param int $score Score from 0 to 100.
	 * @return array array( 'text' => string, 'class' => string ).
	 */
	public static function band( int $score ): array {
		if ( $score >= 80 ) {
			return array(
				'text'  => __( 'Pass', 'genready' ),
				'class' => 'genready-band--pass',
			);
		}
		if ( $score >= 60 ) {
			return array(
				'text'  => __( 'Fair', 'genready' ),
				'class' => 'genready-band--fair',
			);
		}
		if ( $score >= 40 ) {
			return array(
				'text'  => __( 'Poor', 'genready' ),
				'class' => 'genready-band--poor',
			);
		}
		return array(
			'text'  => __( 'Fail', 'genready' ),
			'class' => 'genready-band--fail',
		);
	}

	/**
	 * The colour level of a clamped score.
	 *
	 * @param int $score Score from 0 to 100.
	 */
	private static function level( int $score ): string {
		if ( $score >= 85 ) {
			return 'high';
		}
		return $score >= 50 ? 'mid' : 'low';
	}

	/**
	 * Keep a score between 0 and 100.
	 *
	 * @param int $score Score.
	 */
	private static function clamp( int $score ): int {
		return max( 0, min( 100, $score ) );
	}
}
