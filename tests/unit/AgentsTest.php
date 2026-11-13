<?php
use PHPUnit\Framework\TestCase;

final class AgentsTest extends TestCase {

	public function test_every_crawler_matches_its_own_user_agent(): void {
		$crawlers = AN10_Registry::crawlers();
		foreach ( $crawlers as $c ) {
			if ( null === $c['user_agent'] ) {
				continue;
			}
			$this->assertSame( $c['token'], AN10_Agents::crawler_for( $c['user_agent'], $crawlers )['token'], $c['token'] );
		}
	}

	public function test_lookalike_tokens_stay_apart(): void {
		$crawlers = AN10_Registry::crawlers();
		$this->assertSame( 'Claude-User', AN10_Agents::crawler_for( 'Mozilla/5.0 (compatible; Claude-User/1.0)', $crawlers )['token'] );
		$this->assertSame( 'ClaudeBot', AN10_Agents::crawler_for( 'Mozilla/5.0 (compatible; ClaudeBot/1.0)', $crawlers )['token'] );
		$this->assertSame( 'Googlebot', AN10_Agents::crawler_for( 'Googlebot-Image/1.0', $crawlers )['token'] );
		$this->assertSame( 'Bingbot', AN10_Agents::crawler_for( 'Mozilla/5.0 (compatible; bingbot/2.0)', $crawlers )['token'] );
	}

	public function test_browsers_and_robots_only_tokens_never_match(): void {
		$crawlers = AN10_Registry::crawlers();
		$this->assertNull( AN10_Agents::crawler_for( 'Mozilla/5.0 (Macintosh) Chrome/129.0 Safari/537.36', $crawlers ) );
		$this->assertNull( AN10_Agents::crawler_for( 'Google-Extended', $crawlers ) );
		$this->assertNull( AN10_Agents::crawler_for( '', $crawlers ) );
	}

	public function test_engines_by_referrer_or_utm(): void {
		$hosts = AN10_Registry::ai_source_hosts();
		$this->assertSame( 'ChatGPT', AN10_Agents::engine_for( 'chatgpt.com', $hosts ) );
		$this->assertSame( 'ChatGPT', AN10_Agents::engine_for( 'https://chatgpt.com/c/abc?x=1', $hosts ) );
		$this->assertSame( 'Perplexity', AN10_Agents::engine_for( 'www.perplexity.ai', $hosts ) );
		$this->assertSame( 'Gemini', AN10_Agents::engine_for( 'gemini.google.com', $hosts ) );
		$this->assertNull( AN10_Agents::engine_for( 'notchatgpt.com', $hosts ) );
		$this->assertNull( AN10_Agents::engine_for( 'google.com', $hosts ) );
		$this->assertNull( AN10_Agents::engine_for( '', $hosts ) );
	}

	public function test_search_engines_left_out_of_ai_totals_are_registry_crawlers(): void {
		$tokens = array_column( AN10_Registry::crawlers(), 'token' );
		foreach ( AN10_Agents::SEARCH_ENGINES as $token ) {
			$this->assertContains( $token, $tokens, $token );
		}
	}
}
