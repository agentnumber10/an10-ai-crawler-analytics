<?php
/**
 * Crawler, AI engine and threshold data. Kept identical to the list
 * agentnumber10.com uses, so the plugin and the service never disagree
 * about a crawler. Plain data: no logic lives in this file.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product rules the plugin must agree with.
 */
final class AN10_Registry {

	/**
	 * The AI crawlers, in the product's order.
	 *
	 * @return array<int, array<string, string|null>>
	 */
	public static function crawlers(): array {
		return array (
  0 => 
  array (
    'token' => 'OAI-SearchBot',
    'operator' => 'OpenAI',
    'purpose' => 'search',
    'purpose_label' => 'AI search',
    'role' => 'Builds the index ChatGPT search answers from',
    'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)',
    'ip_ranges' => 'https://openai.com/searchbot.json',
  ),
  1 => 
  array (
    'token' => 'Claude-SearchBot',
    'operator' => 'Anthropic',
    'purpose' => 'search',
    'purpose_label' => 'AI search',
    'role' => 'Indexes pages for Claude\'s web search',
    'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +https://www.anthropic.com)',
    'ip_ranges' => 'https://claude.com/crawling/bots.json',
  ),
  2 => 
  array (
    'token' => 'PerplexityBot',
    'operator' => 'Perplexity',
    'purpose' => 'search',
    'purpose_label' => 'AI search',
    'role' => 'Indexes pages Perplexity cites in its answers',
    'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
    'ip_ranges' => 'https://www.perplexity.ai/perplexitybot.json',
  ),
  3 => 
  array (
    'token' => 'Googlebot',
    'operator' => 'Google',
    'purpose' => 'search',
    'purpose_label' => 'AI search',
    'role' => 'Google Search, including AI Overviews and AI Mode',
    'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'ip_ranges' => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
  ),
  4 => 
  array (
    'token' => 'Bingbot',
    'operator' => 'Microsoft',
    'purpose' => 'search',
    'purpose_label' => 'AI search',
    'role' => 'Bing, which Copilot and other assistants search through',
    'user_agent' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    'ip_ranges' => 'https://www.bing.com/toolbox/bingbot.json',
  ),
  5 => 
  array (
    'token' => 'ChatGPT-User',
    'operator' => 'OpenAI',
    'purpose' => 'user',
    'purpose_label' => 'Fetches for a user',
    'role' => 'Opens a page when a ChatGPT user asks about it',
    'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot)',
    'ip_ranges' => 'https://openai.com/chatgpt-user.json',
  ),
  6 => 
  array (
    'token' => 'Claude-User',
    'operator' => 'Anthropic',
    'purpose' => 'user',
    'purpose_label' => 'Fetches for a user',
    'role' => 'Opens a page when a Claude user asks about it',
    'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +https://www.anthropic.com)',
    'ip_ranges' => 'https://claude.com/crawling/bots.json',
  ),
  7 => 
  array (
    'token' => 'Perplexity-User',
    'operator' => 'Perplexity',
    'purpose' => 'user',
    'purpose_label' => 'Fetches for a user',
    'role' => 'Opens a page when a Perplexity user asks about it',
    'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)',
    'ip_ranges' => 'https://www.perplexity.ai/perplexity-user.json',
  ),
  8 => 
  array (
    'token' => 'GPTBot',
    'operator' => 'OpenAI',
    'purpose' => 'training',
    'purpose_label' => 'Model training',
    'role' => 'Collects content to train OpenAI\'s models',
    'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)',
    'ip_ranges' => 'https://openai.com/gptbot.json',
  ),
  9 => 
  array (
    'token' => 'ClaudeBot',
    'operator' => 'Anthropic',
    'purpose' => 'training',
    'purpose_label' => 'Model training',
    'role' => 'Collects content to train Anthropic\'s models',
    'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
    'ip_ranges' => 'https://claude.com/crawling/bots.json',
  ),
  10 => 
  array (
    'token' => 'Google-Extended',
    'operator' => 'Google',
    'purpose' => 'training',
    'purpose_label' => 'Model training',
    'role' => 'robots.txt switch for Gemini training; Googlebot does the fetching',
    'user_agent' => NULL,
    'ip_ranges' => NULL,
  ),
  11 => 
  array (
    'token' => 'Applebot-Extended',
    'operator' => 'Apple',
    'purpose' => 'training',
    'purpose_label' => 'Model training',
    'role' => 'robots.txt switch for Apple\'s AI training; Applebot does the fetching',
    'user_agent' => NULL,
    'ip_ranges' => NULL,
  ),
  12 => 
  array (
    'token' => 'meta-externalagent',
    'operator' => 'Meta',
    'purpose' => 'training',
    'purpose_label' => 'Model training',
    'role' => 'Collects content for Meta\'s AI models',
    'user_agent' => 'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
    'ip_ranges' => NULL,
  ),
  13 => 
  array (
    'token' => 'CCBot',
    'operator' => 'Common Crawl',
    'purpose' => 'training',
    'purpose_label' => 'Model training',
    'role' => 'Open web archive many models are trained on',
    'user_agent' => 'CCBot/2.0 (https://commoncrawl.org/faq/)',
    'ip_ranges' => 'https://index.commoncrawl.org/ccbot.json',
  ),
  14 => 
  array (
    'token' => 'Bytespider',
    'operator' => 'ByteDance',
    'purpose' => 'training',
    'purpose_label' => 'Model training',
    'role' => 'Collects content for ByteDance\'s models',
    'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Bytespider; +spider-feedback@bytedance.com)',
    'ip_ranges' => NULL,
  ),
);
	}

	/**
	 * Hosts AI answers send visitors from, mapped to the engine name.
	 *
	 * @return array<string, string>
	 */
	public static function ai_source_hosts(): array {
		return array (
  'bard.google.com' => 'Gemini',
  'chat.openai.com' => 'ChatGPT',
  'chatgpt.com' => 'ChatGPT',
  'claude.ai' => 'Claude',
  'copilot.microsoft.com' => 'Copilot',
  'deepseek.com' => 'DeepSeek',
  'gemini.google.com' => 'Gemini',
  'grok.com' => 'Grok',
  'meta.ai' => 'Meta AI',
  'mistral.ai' => 'Mistral',
  'openai.com' => 'ChatGPT',
  'perplexity.ai' => 'Perplexity',
  'phind.com' => 'Phind',
  'x.ai' => 'Grok',
  'you.com' => 'You.com',
);
	}

	/**
	 * Named content thresholds.
	 *
	 * @return array<string, int>
	 */
	public static function thresholds(): array {
		return array (
  'thin_content_words' => 150,
);
	}
}
