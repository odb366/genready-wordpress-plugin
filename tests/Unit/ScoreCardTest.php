<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Admin\ScoreCard;
use PHPUnit\Framework\TestCase;

class ScoreCardTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( '__' )->returnArg( 1 );
		Monkey\Functions\when( 'esc_attr' )->alias( function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ring_is_accessible_and_escaped(): void {
		Monkey\Functions\when( 'esc_attr' )->alias( function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } );
		$svg = ScoreCard::ring( 72, 'Content <b>', 96 );
		$this->assertStringContainsString( 'role="img"', $svg );
		$this->assertStringContainsString( 'aria-label="Content &lt;b&gt;: 72 of 100"', $svg );
		$this->assertStringContainsString( '>72<', $svg );
	}

	public function test_ring_clamps_the_score_and_sets_the_size(): void {
		$svg = ScoreCard::ring( 140, 'AI Readiness', 128 );
		$this->assertStringContainsString( '>100<', $svg );
		$this->assertStringContainsString( 'width="128"', $svg );
		$this->assertStringContainsString( 'height="128"', $svg );
		$this->assertStringContainsString( '>0<', ScoreCard::ring( -5, 'x', 80 ) );
	}

	/**
	 * Band texts from shared/scoring.ts getReadinessDescription: 80 Pass, 60 Fair, 40 Poor, else Fail.
	 *
	 * @dataProvider band_boundaries
	 */
	public function test_band_matches_the_genready_report( int $score, string $text, string $class ): void {
		$this->assertSame(
			array(
				'text'  => $text,
				'class' => $class,
			),
			ScoreCard::band( $score )
		);
	}

	public function band_boundaries(): array {
		return array(
			'80 is Pass' => array( 80, 'Pass', 'genready-band--pass' ),
			'79 is Fair' => array( 79, 'Fair', 'genready-band--fair' ),
			'60 is Fair' => array( 60, 'Fair', 'genready-band--fair' ),
			'59 is Poor' => array( 59, 'Poor', 'genready-band--poor' ),
			'40 is Poor' => array( 40, 'Poor', 'genready-band--poor' ),
			'39 is Fail' => array( 39, 'Fail', 'genready-band--fail' ),
		);
	}

	/**
	 * Ring colours from client/src/components/ui/circular-progress.tsx: 85 green, 50 amber, else red.
	 *
	 * @dataProvider ring_boundaries
	 */
	public function test_ring_colour_matches_the_genready_ring( int $score, string $class ): void {
		$this->assertSame( $class, ScoreCard::ring_class( $score ) );
		$this->assertStringContainsString( $class, ScoreCard::ring( $score, 'x', 80 ) );
	}

	public function ring_boundaries(): array {
		return array(
			'85 is high' => array( 85, 'genready-ring--high' ),
			'84 is mid'  => array( 84, 'genready-ring--mid' ),
			'50 is mid'  => array( 50, 'genready-ring--mid' ),
			'49 is low'  => array( 49, 'genready-ring--low' ),
		);
	}
}
