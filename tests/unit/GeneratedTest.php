<?php
use PHPUnit\Framework\TestCase;

/**
 * The checked-in generated files must be exactly what the generator produces
 * from the product export. Edit the export, never a generated file.
 */
final class GeneratedTest extends TestCase {

	public function test_generated_registry_is_up_to_date(): void {
		$this->assertSame(
			an10_render_registry( an10_test_export() ),
			file_get_contents( AN10_ROOT . '/includes/generated/class-an10-registry.php' ),
			'Run: php bin/generate.php'
		);
	}

	public function test_generated_brand_tokens_are_up_to_date(): void {
		$this->assertSame(
			an10_render_tokens( an10_test_export() ),
			file_get_contents( AN10_ROOT . '/assets/admin/tokens.css' ),
			'Run: php bin/generate.php'
		);
	}

	public function test_brand_tokens_are_scoped_to_the_plugin_screens(): void {
		$css = an10_render_tokens( an10_test_export() );
		$this->assertStringContainsString( ".an10 {\n", $css );
		$this->assertStringNotContainsString( ':root', $css );
		$this->assertStringContainsString( '--an10-primary: #', $css );
	}

	public function test_an_unsafe_token_value_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		an10_render_tokens( array( 'brand' => array( 'primary' => 'red; } body { display: none' ) ) );
	}
}
