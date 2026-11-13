<?php
/**
 * Unit test bootstrap: pure classes only, no WordPress.
 *
 * @package AiCrawlerAnalytics
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AN10_ROOT', dirname( __DIR__ ) );

require AN10_ROOT . '/vendor/autoload.php';
require AN10_ROOT . '/bin/generate.php';
define( 'DAY_IN_SECONDS', 86400 );
foreach ( array( 'robots', 'ip', 'agents', 'content', 'schema', 'llms', 'chart', 'store', 'verifier' ) as $an10_class ) {
	require AN10_ROOT . "/includes/class-an10-{$an10_class}.php";
}
require AN10_ROOT . '/includes/generated/class-an10-registry.php';

/**
 * The product export the plugin is generated from.
 */
function an10_test_export(): array {
	return json_decode( (string) file_get_contents( AN10_ROOT . '/data/product-export.json' ), true );
}
