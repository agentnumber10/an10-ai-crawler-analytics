=== AN10 – AI Crawler Analytics ===
Contributors: agentnumber10
Tags: aeo, ai crawler, ai visibility, llms.txt, geo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verified AI crawler log, visits from ChatGPT and Perplexity, and AEO readiness for every page. Free, no account, stores no IP addresses.

== Description ==

Most AI crawler logs trust the user agent. Anyone can send `User-agent: GPTBot`. This plugin checks every claimed AI crawler visit against the IP addresses its operator publishes, and labels it **Verified**, **Spoofed** or **Unverified**. Only verified visits are ever counted as crawler visits. A visit is called Spoofed only when there is no doubt: if it came through a proxy whose real visitor address the plugin cannot see, or the operator's list could not be refreshed for a week, it is Unverified instead, so a real crawler is never accused.

Verification is off until you turn it on, because it means downloading those published lists from each operator. One click on the plugin's screen starts it, and you can turn it off again in Settings.

AN10 is short for Agent Number 10 (agentnumber10.com), the team that makes this plugin.

**What it shows you, with no account and no setup:**

* **Can AI reach you.** For each AI crawler (ChatGPT, Claude, Perplexity, Google, Bing and the training crawlers), whether your robots.txt lets it in, and the exact line that decides.
* **What AI actually did.** Verified fetches per crawler, the pages they fetched, and when. Googlebot and Bingbot are listed too, but never added to the AI totals: most of what they fetch is ordinary search indexing.
* **The pages no AI crawler has ever fetched.** Often the most useful list here.
* **Visits from AI answers.** Arrivals from ChatGPT, Perplexity, Gemini, Copilot, Claude and others, counted per page. Counted in the browser, so cached pages are included; a reload or a step back is not counted again. At most 2,000 are recorded a day, so a script posting fake visits cannot fill your database. A visit from Google AI Overviews looks like any other Google search visit, so it cannot be told apart.
* **Facts about every page.** Structured data found, heading structure, words before the first h2, question-shaped headings. Counts, never a score.
* **What happened after a fix.** Which verified crawlers fetched the pages you changed, and AI referrals to those pages before and after.

**Free fixes, always previewed, always undoable:**

* Add JSON-LD built only from what WordPress already knows about the page. Nothing guessed.
* Allow AI search crawlers that robots.txt is blocking (training crawlers are left alone).
* Generate llms.txt. No major AI engine has confirmed it reads one, so it is offered as a convenience, not a ranking factor.
* Propose a heading structure without skipped levels, for you to review. Never applied without you.

**Honest about what it cannot see.** When a page cache serves a request, WordPress never runs, so no plugin can log that crawler visit. This plugin detects common caches and says next to every count that the numbers are a floor.

**Works with your SEO plugin.** Yoast SEO, Rank Math, SEOPress and All in One SEO keep titles, meta descriptions, canonicals, Open Graph and sitemaps. This plugin never writes them, and adds structured data only where none is output.

**Whether AI engines cite you** needs the engines to be asked a question, which this plugin never does. The free checker at agentnumber10.com does that, and the plugin mentions it only once it has real crawler data to show you.

== Privacy ==

Unless you click a link to agentnumber10.com, nothing about your site, its content or its visitors leaves your server. Everything is worked out on your site and stored in your own database. The plugin stores, per AI crawler request: the time, the crawler's name, whether its address matched the operator's published list, the page and the HTTP status. Per visitor from an AI answer: the time, the engine and the page. It never stores an IP address, a full user agent, a query string, a cookie or anything that identifies a person.

The admin screens load their fonts and images from the plugin's own folder. Nothing is requested from a font service or any other site when you open them.

== External services ==

**Crawler IP lists.** Off until you turn on crawler verification on the plugin's screen; activating the plugin downloads nothing. Once you turn it on, a scheduled task downloads each operator's published IP range list right away and then once a day, never during a page request. Each download names only the WordPress and plugin versions in its user agent. Your site's address, its content and anything about its visitors are never sent. Turning verification off in Settings stops the downloads and deletes the stored lists.

* OpenAI: https://openai.com/gptbot.json, https://openai.com/searchbot.json, https://openai.com/chatgpt-user.json ([terms](https://openai.com/policies/terms-of-use), [privacy](https://openai.com/policies/privacy-policy))
* Anthropic: https://claude.com/crawling/bots.json ([privacy](https://www.anthropic.com/legal/privacy))
* Perplexity: https://www.perplexity.ai/perplexitybot.json, https://www.perplexity.ai/perplexity-user.json ([privacy](https://www.perplexity.ai/hub/legal/privacy-policy))
* Google: https://developers.google.com/static/crawling/ipranges/common-crawlers.json ([privacy](https://policies.google.com/privacy))
* Microsoft: https://www.bing.com/toolbox/bingbot.json ([privacy](https://privacy.microsoft.com/privacystatement))
* Common Crawl: https://index.commoncrawl.org/ccbot.json ([terms](https://commoncrawl.org/terms-of-use))
* Cloudflare: https://api.cloudflare.com/client/v4/ips, used only to read the real visitor address on sites behind Cloudflare ([privacy](https://www.cloudflare.com/privacypolicy/))

**Free AI visibility checker (agentnumber10.com).** Once the plugin has recorded a verified AI crawler visit, the Overview screen shows a "Check your AI visibility" button. Only if you click it, your browser opens https://agentnumber10.com/ai-visibility-checker in a new tab with your site's domain filled in, so the check can start. If you never click it, nothing is sent. ([privacy](https://agentnumber10.com/privacy), [terms](https://agentnumber10.com/terms))

== Frequently Asked Questions ==

= Why does the crawler panel say nothing yet? =

It only counts a visit after checking the address against the operator's list, so crawler verification has to be on first. After that, a new install takes time to see crawlers. The panel says so rather than showing unverified numbers.

= My site is behind Cloudflare. Does verification work? =

Yes. With verification on, Cloudflare is detected from its published ranges. For any other proxy, choose the header it sets in Settings; until then its visits are labelled Unverified, never Spoofed.

Cloudflare can also block AI crawlers, or add its own rules to robots.txt, before a request reaches WordPress. The plugin cannot see that, and says so on sites behind Cloudflare.

= Does it slow my site down? =

Human visits cost one string comparison of the user agent. A claimed AI crawler visit adds one database insert after the page is sent.

= Does it block crawlers? =

No. It observes. Blocking is a different job.

== Credits ==

The admin screens use the Inter and JetBrains Mono typefaces, bundled under the SIL Open Font License 1.1. The licence and copyright notices are in assets/fonts/OFL.txt.

== Screenshots ==

1. Verified AI crawler fetches, pages fetched and visits from AI answers, by day.
2. Each AI crawler checked against the addresses its operator publishes, and whether your robots.txt lets it in.
3. Pages no verified AI crawler has fetched, and the facts behind each page's findings.
4. A heading proposal to review. Only the heading levels change, and nothing is applied without you.

== Changelog ==

= 0.1.0 =
* First release.
