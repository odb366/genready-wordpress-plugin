<?php
/**
 * Runs when an admin deletes the plugin. Deactivating does nothing.
 *
 * @package GenReady
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Load the constants and the autoloader only. This guard keeps genready.php from registering its hooks.
define( 'GENREADY_UNINSTALLING', true );
require_once __DIR__ . '/genready.php';

\GenReady\Uninstaller::create()->run();
