<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;
use GenReady\Admin\RecentReports;
use PHPUnit\Framework\TestCase;

class RecentReportsTest extends TestCase {
	const ID1 = '11111111-1111-4111-8111-111111111111';
	const ID2 = '22222222-2222-4222-8222-222222222222';

	/** @var array */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$opts          = &$this->options;
		Monkey\Functions\when( 'get_option' )->alias( function ( $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Monkey\Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$opts ) { $opts[ $k ] = $v; return true; } );
		Monkey\Functions\when( 'delete_option' )->alias( function ( $k ) use ( &$opts ) { unset( $opts[ $k ] ); return true; } );
		Monkey\Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Monkey\Functions\when( 'wp_parse_url' )->alias( function ( $u, $c = -1 ) { return parse_url( $u, $c ); } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_keeps_the_ten_newest_reports_newest_first_without_doubles(): void {
		$recent = new RecentReports();
		for ( $i = 0; $i < 12; $i++ ) {
			$recent->add( sprintf( '%08d-1111-4111-8111-111111111111', $i ), 'https://example.com/p' . $i . '/' );
		}
		$recent->add( sprintf( '%08d-1111-4111-8111-111111111111', 5 ), 'https://example.com/p5/' );
		$list = $recent->all();
		$this->assertCount( RecentReports::MAX, $list );
		$this->assertSame( '00000005-1111-4111-8111-111111111111', $list[0]['id'] );
		$this->assertSame( '00000011-1111-4111-8111-111111111111', $list[1]['id'] );
		$this->assertCount( 1, array_keys( array_column( $list, 'id' ), '00000005-1111-4111-8111-111111111111', true ) );
		$this->assertNull( $list[0]['score'] );
		$this->assertIsInt( $list[0]['at'] );
	}

	public function test_ignores_an_id_that_is_not_a_uuid_and_lower_cases_the_rest(): void {
		$recent = new RecentReports();
		$recent->add( 'abc-123', 'https://example.com/' );
		$this->assertSame( array(), $recent->all() );
		$recent->add( strtoupper( self::ID1 ), 'https://example.com/' );
		$this->assertTrue( $recent->has( self::ID1 ) );
		$this->assertSame( self::ID1, $recent->all()[0]['id'] );
	}

	public function test_keeps_a_score_from_0_to_100_for_a_listed_report_only(): void {
		$recent = new RecentReports();
		$recent->add( self::ID1, 'https://example.com/' );
		$recent->set_score( self::ID1, 87 );
		$recent->set_score( self::ID2, 50 );
		$recent->set_score( self::ID1, 101 );
		$this->assertSame( 87, $recent->all()[0]['score'] );
		$this->assertFalse( $recent->has( self::ID2 ) );
	}

	public function test_a_new_scan_of_the_same_report_keeps_no_old_score(): void {
		$recent = new RecentReports();
		$recent->add( self::ID1, 'https://example.com/' );
		$recent->set_score( self::ID1, 87 );
		$recent->add( self::ID1, 'https://example.com/' );
		$this->assertNull( $recent->all()[0]['score'] );
	}

	public function test_drops_malformed_entries(): void {
		$this->options[ RecentReports::OPTION ] = array(
			array( 'id' => self::ID1, 'url' => 'https://example.com/', 'at' => 5, 'score' => 40 ),
			array( 'id' => 'nope', 'url' => 'https://example.com/', 'at' => 5 ),
			array( 'id' => self::ID2, 'url' => 7, 'at' => 5 ),
			'junk',
		);
		$list = ( new RecentReports() )->all();
		$this->assertSame( array( array( 'id' => self::ID1, 'url' => 'https://example.com/', 'at' => 5, 'score' => 40 ) ), $list );
	}

	public function test_forget_drops_one_report_and_clear_drops_all(): void {
		$recent = new RecentReports();
		$recent->add( self::ID1, 'https://example.com/' );
		$recent->add( self::ID2, 'https://example.com/b/' );
		$recent->forget( self::ID2 );
		$this->assertSame( array( self::ID1 ), array_column( $recent->all(), 'id' ) );
		$recent->clear();
		$this->assertArrayNotHasKey( RecentReports::OPTION, $this->options );
	}

	public function test_append_adds_an_older_report_at_the_end_once_and_only_while_there_is_room(): void {
		$recent = new RecentReports();
		$recent->add( self::ID1, 'https://example.com/' );
		$recent->append( self::ID2, 'https://example.com/b/', 5, 61 );
		$recent->append( self::ID2, 'https://example.com/b/', 6, 62 );
		$recent->append( self::ID1, 'https://example.com/', 7, 63 );
		$this->assertSame( array( self::ID1, self::ID2 ), array_column( $recent->all(), 'id' ) );
		$this->assertSame( array( 'id' => self::ID2, 'url' => 'https://example.com/b/', 'at' => 5, 'score' => 61 ), $recent->all()[1] );

		$full = new RecentReports();
		$full->clear();
		for ( $i = 0; $i < RecentReports::MAX; $i++ ) {
			$full->add( sprintf( '%08d-1111-4111-8111-111111111111', $i ), 'https://example.com/p' . $i . '/' );
		}
		$full->append( self::ID2, 'https://example.com/b/', 5, 61 );
		$this->assertFalse( $full->has( self::ID2 ) );
	}

	public function test_newest_of_page_is_the_latest_scan_of_the_same_page(): void {
		$recent = new RecentReports();
		$third  = '33333333-3333-4333-8333-333333333333';
		$recent->add( self::ID1, 'https://example.com/' );
		$recent->add( self::ID2, 'https://example.com/pricing/' );
		$this->assertSame( self::ID1, $recent->newest_of_page( self::ID1 )['id'], 'a scan of another page is no later scan' );
		$recent->add( $third, 'https://www.example.com' );
		$this->assertSame( $third, $recent->newest_of_page( strtoupper( self::ID1 ) )['id'] );
		$this->assertSame( self::ID2, $recent->newest_of_page( self::ID2 )['id'] );
		$this->assertNull( $recent->newest_of_page( '44444444-4444-4444-8444-444444444444' ) );
	}

	public function test_replace_keeps_valid_entries_in_their_order(): void {
		$recent = new RecentReports();
		$recent->replace(
			array(
				array( 'id' => self::ID2, 'url' => 'https://example.com/b/', 'at' => 9, 'score' => 70 ),
				array( 'id' => self::ID1, 'url' => 'https://example.com/', 'at' => 8, 'score' => null ),
				array( 'id' => 'bad', 'url' => 'https://example.com/', 'at' => 7 ),
			)
		);
		$this->assertSame( array( self::ID2, self::ID1 ), array_column( $recent->all(), 'id' ) );
		$this->assertSame( 70, $recent->all()[0]['score'] );
	}
}
