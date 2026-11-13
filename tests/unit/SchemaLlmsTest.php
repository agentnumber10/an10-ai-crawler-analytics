<?php
use PHPUnit\Framework\TestCase;

final class SchemaLlmsTest extends TestCase {

	public function test_article_uses_only_given_facts(): void {
		$node = AN10_Schema::build(
			array(
				'kind'           => 'article',
				'title'          => 'How AI engines pick sources',
				'url'            => 'https://example.com/ai-sources/',
				'date_published' => '2026-11-03T10:00:00+00:00',
				'date_modified'  => null,
				'author_name'    => 'Rakesh',
				'image_url'      => '',
				'site_name'      => 'Example',
				'site_logo_url'  => null,
			)
		);
		$this->assertSame( 'BlogPosting', $node['@type'] );
		$this->assertSame( 'Rakesh', $node['author']['name'] );
		$this->assertArrayNotHasKey( 'dateModified', $node );
		$this->assertArrayNotHasKey( 'image', $node );
		$this->assertArrayNotHasKey( 'logo', $node['publisher'] );
	}

	public function test_no_title_or_url_means_no_schema(): void {
		$this->assertNull( AN10_Schema::build( array( 'kind' => 'webpage', 'title' => '', 'url' => 'https://x' ) ) );
	}

	public function test_page_is_a_webpage(): void {
		$node = AN10_Schema::build( array( 'kind' => 'webpage', 'title' => 'Pricing', 'url' => 'https://x/pricing/' ) );
		$this->assertSame( array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'Pricing', 'url' => 'https://x/pricing/' ), $node );
	}

	public function test_llms_txt_homepage_first(): void {
		$txt = AN10_Llms::render(
			'Example',
			'We make examples.',
			array(
				array( 'title' => 'Pricing', 'url' => 'https://x/pricing/', 'description' => 'Plans' ),
				array( 'title' => 'Home', 'url' => 'https://x/', 'description' => '', 'is_home' => true ),
			)
		);
		$this->assertSame( "# Example\n\n> We make examples.\n\n- [Home](https://x/)\n- [Pricing](https://x/pricing/): Plans\n", $txt );
	}
}
