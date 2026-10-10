<?php
namespace GenReady\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The release copy is built with rsync --exclude-from=.distignore, so every
 * top-level entry that is not part of the plugin must be listed there.
 */
class DistignoreTest extends TestCase {
	const SHIPPED = array( 'assets', 'src', 'languages', 'genready.php', 'uninstall.php', 'readme.txt' );

	public function test_every_dev_entry_is_excluded_from_the_release(): void {
		$root    = dirname( __DIR__, 2 );
		$ignored = array_filter( array_map( 'trim', file( $root . '/.distignore' ) ) );
		foreach ( scandir( $root ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || in_array( $entry, self::SHIPPED, true ) ) {
				continue;
			}
			$this->assertContains( $entry, $ignored, $entry . ' would ship in the release zip.' );
		}
	}
}
