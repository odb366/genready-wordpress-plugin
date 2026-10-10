<?php
require dirname( __DIR__ ) . '/vendor/autoload.php';
define( 'ABSPATH', sys_get_temp_dir() . '/genready-wp/' );
define( 'GENREADY_TESTING', true );
if ( ! is_dir( ABSPATH ) ) {
	mkdir( ABSPATH, 0777, true );
}
require dirname( __DIR__ ) . '/genready.php';
require __DIR__ . '/fixtures/wp-stubs.php'; // WP_Error and other tiny stubs (Task 3 adds WP_Error)
