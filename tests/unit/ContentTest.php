<?php
use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase {

	public function test_facts_not_verdicts(): void {
		$html = '<p>One two three.</p><h2>What is AEO?</h2><p>Four five</p><h4>How it works</h4><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebPage"},{"@type":["Organization"]}]}</script>';
		$facts = AN10_Content::analyze( $html, $html );
		$this->assertSame( 3, $facts['words_before_first_h2'] );
		$this->assertSame( 0, $facts['h1_count'] );
		$this->assertSame( 2, $facts['question_headings'] );
		$this->assertSame( array( array( 'index' => 1, 'from' => 2, 'to' => 4 ) ), $facts['skips'] );
		$this->assertSame( array( 'WebPage', 'Organization' ), $facts['jsonld_types'] );
		$this->assertSame( 0, $facts['jsonld_invalid'] );
		$this->assertSame( 10, $facts['word_count'] );
	}

	public function test_no_h2_is_unknown_not_zero(): void {
		$this->assertNull( AN10_Content::analyze( '<p>Just text</p>', '<p>Just text</p>' )['words_before_first_h2'] );
	}

	public function test_invalid_jsonld_is_counted(): void {
		$this->assertSame( array( array(), 1 ), AN10_Content::jsonld( '<script type="application/ld+json">{oops</script>' ) );
	}

	public function test_proposal_removes_skips_and_content_h1_keeping_nesting(): void {
		$this->assertSame( array( 2, 3, 3, 2 ), AN10_Content::propose_levels( array( 2, 4, 4, 2 ) ) );
		$this->assertSame( array( 2, 2, 3 ), AN10_Content::propose_levels( array( 1, 2, 3 ) ) );
		$this->assertSame( array( 2, 3, 4 ), AN10_Content::propose_levels( array( 3, 4, 6 ) ) );
		$this->assertSame( array( 2, 3, 2 ), AN10_Content::propose_levels( array( 2, 3, 2 ) ) );
		$this->assertSame( array( 2, 3, 4, 3, 2 ), AN10_Content::propose_levels( array( 2, 4, 6, 4, 2 ) ) );
	}

	public function test_rewrites_block_markup_and_classic_html(): void {
		$blocks = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">A</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:heading {\"level\":4,\"className\":\"x\"} -->\n<h4 class=\"wp-block-heading x\">B</h4>\n<!-- /wp:heading -->\n"
			. "<p>tail</p><h5>C</h5>";
		$out    = AN10_Content::rewrite_headings( $blocks, array( 2, 3, 2 ) );
		$this->assertStringContainsString( "<!-- wp:heading {\"level\":3,\"className\":\"x\"} -->\n<h3 class=\"wp-block-heading x\">B</h3>", $out );
		$this->assertStringContainsString( '<h2>C</h2>', $out );
		$this->assertStringContainsString( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">A</h2>", $out );
		$this->assertStringContainsString( '<p>tail</p>', $out );
	}

	public function test_level_two_drops_the_attribute(): void {
		$in  = "<!-- wp:heading {\"level\":4} -->\n<h4 class=\"wp-block-heading\">B</h4>\n<!-- /wp:heading -->";
		$out = AN10_Content::rewrite_headings( $in, array( 2 ) );
		$this->assertSame( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">B</h2>\n<!-- /wp:heading -->", $out );
	}

	public function test_refuses_when_headings_changed_since_the_proposal(): void {
		$this->assertNull( AN10_Content::rewrite_headings( '<h2>A</h2>', array( 2, 3 ) ) );
	}
}
