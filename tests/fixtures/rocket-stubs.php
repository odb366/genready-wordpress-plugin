<?php
// Stand-ins for WP Rocket's purge functions. Load only in a separate process.
function rocket_clean_domain() {
	$GLOBALS['genready_purged'][] = 'rocket_clean_domain';
}

function rocket_clean_post( $post_id ) {
	$GLOBALS['genready_purged'][] = 'rocket_clean_post:' . $post_id;
}

function rocket_clean_files( $urls ) {
	$GLOBALS['genready_purged'][] = 'rocket_clean_files:' . implode( ',', (array) $urls );
}
