<?php
namespace GenReady\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Checks on the admin stylesheet that the browser tests found.
 */
class AdminAssetsTest extends TestCase {
	public function test_hidden_elements_stay_hidden_inside_the_admin_page(): void {
		// WordPress gives .button display: inline-block, which beats the browser's [hidden] rule.
		// Without this rule the Apply, live check and download buttons show while they are hidden.
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin.css' );
		$this->assertMatchesRegularExpression( '/\.genready-admin\s+\[hidden\]\s*\{\s*display:\s*none\s*!important;?\s*\}/', $css );
	}
}
