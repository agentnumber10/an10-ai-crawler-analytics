<?php
/**
 * Build the generated files from data/product-export.json.
 *
 * The JSON is exported from the product repository (scripts/wp-plugin/export.ts),
 * which owns the crawler registry, the AI referrer hosts, the content
 * thresholds and the brand tokens. This script turns it into a plain PHP class,
 * so a page request never parses JSON, and into the stylesheet of brand
 * tokens the admin screens are drawn with. Nothing in either file is typed by
 * hand.
 *
 *   php bin/generate.php           write both files
 *   php bin/generate.php --check   exit 1 if either is out of date
 *
 * @package AiCrawlerAnalytics
 */

$an10_root   = dirname( __DIR__ );
$an10_source = $an10_root . '/data/product-export.json';

/**
 * Render the generated class from the export.
 *
 * @param array $data Decoded product export.
 * @return string PHP source.
 */
function an10_render_registry( array $data ): string {
	$crawlers = array();
	foreach ( $data['crawlers'] as $c ) {
		$crawlers[] = array(
			'token'         => (string) $c['token'],
			'operator'      => (string) $c['operator'],
			'purpose'       => (string) $c['purpose'],
			'purpose_label' => (string) $c['purposeLabel'],
			'role'          => (string) $c['role'],
			'user_agent'    => null === $c['userAgent'] ? null : (string) $c['userAgent'],
			'ip_ranges'     => null === $c['ipRanges'] ? null : (string) $c['ipRanges'],
		);
	}
	$hosts      = $data['aiSourceHosts'];
	$thresholds = array( 'thin_content_words' => (int) $data['thresholds']['thinContentWords'] );

	$export = static function ( $value ): string {
		return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	};

	return "<?php\n"
		. "/**\n"
		. " * Crawler, AI engine and threshold data. Kept identical to the list\n"
		. " * agentnumber10.com uses, so the plugin and the service never disagree\n"
		. " * about a crawler. Plain data: no logic lives in this file.\n"
		. " *\n"
		. " * @package AiCrawlerAnalytics\n"
		. " */\n\n"
		. "defined( 'ABSPATH' ) || exit;\n\n"
		. "/**\n * Product rules the plugin must agree with.\n */\n"
		. "final class AN10_Registry {\n\n"
		. "\t/**\n\t * The AI crawlers, in the product's order.\n\t *\n\t * @return array<int, array<string, string|null>>\n\t */\n"
		. "\tpublic static function crawlers(): array {\n\t\treturn " . $export( $crawlers ) . ";\n\t}\n\n"
		. "\t/**\n\t * Hosts AI answers send visitors from, mapped to the engine name.\n\t *\n\t * @return array<string, string>\n\t */\n"
		. "\tpublic static function ai_source_hosts(): array {\n\t\treturn " . $export( $hosts ) . ";\n\t}\n\n"
		. "\t/**\n\t * Named content thresholds.\n\t *\n\t * @return array<string, int>\n\t */\n"
		. "\tpublic static function thresholds(): array {\n\t\treturn " . $export( $thresholds ) . ";\n\t}\n"
		. "}\n";
}

/**
 * Render the brand token stylesheet from the export.
 *
 * Scoped to the plugin's own screens (.an10) and prefixed, so nothing else in
 * wp-admin changes.
 *
 * @param array $data Decoded product export.
 * @return string CSS source.
 */
function an10_render_tokens( array $data ): string {
	$lines = '';
	foreach ( $data['brand'] as $name => $value ) {
		if ( ! preg_match( '/^[a-z0-9-]+$/', (string) $name ) || preg_match( '/[;{}<>]/', (string) $value ) ) {
			throw new InvalidArgumentException( "Unsafe brand token: $name" );
		}
		$lines .= "\t--an10-{$name}: {$value};\n";
	}
	return "/*\n"
		. " * Brand colours and type for these screens, kept identical to\n"
		. " * agentnumber10.com (light theme) so the plugin and the service look alike.\n"
		. " */\n"
		. ".an10 {\n" . $lines . "}\n";
}

/**
 * Every generated file and its expected content.
 *
 * @param array  $data Decoded product export.
 * @param string $root Plugin root.
 * @return array<string, string> Path => content.
 */
function an10_generated_files( array $data, string $root ): array {
	return array(
		$root . '/includes/generated/class-an10-registry.php' => an10_render_registry( $data ),
		$root . '/assets/admin/tokens.css'                    => an10_render_tokens( $data ),
	);
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === realpath( __FILE__ ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$an10_data = json_decode( (string) file_get_contents( $an10_source ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! is_array( $an10_data ) ) {
		fwrite( STDERR, "Cannot read $an10_source\n" ); // phpcs:ignore
		exit( 2 );
	}
	$an10_files = an10_generated_files( $an10_data, $an10_root );
	if ( in_array( '--check', $argv, true ) ) {
		$an10_stale = 0;
		foreach ( $an10_files as $an10_path => $an10_content ) {
			if ( ! is_file( $an10_path ) || file_get_contents( $an10_path ) !== $an10_content ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				fwrite( STDERR, "stale: $an10_path. Run: php bin/generate.php\n" ); // phpcs:ignore
				$an10_stale = 1;
			}
		}
		if ( ! $an10_stale ) {
			echo "up to date\n"; // phpcs:ignore
		}
		exit( $an10_stale );
	}
	foreach ( $an10_files as $an10_path => $an10_content ) {
		file_put_contents( $an10_path, $an10_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		echo "wrote $an10_path\n"; // phpcs:ignore
	}
}
