<?php
require dirname( __DIR__ ) . '/vendor/autoload.php';
define( 'ABSPATH', sys_get_temp_dir() . '/genready-wp/' );
define( 'GENREADY_TESTING', true );
if ( ! is_dir( ABSPATH ) ) {
	mkdir( ABSPATH, 0777, true );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}
require dirname( __DIR__ ) . '/genready.php';
require __DIR__ . '/fixtures/wp-stubs.php'; // WP_Error and other tiny stubs (Task 3 adds WP_Error)
