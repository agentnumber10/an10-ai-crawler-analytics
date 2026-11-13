<?php
/**
 * The admin screens.
 *
 * Overview: can AI reach you, and what AI actually did, side by side.
 * Pages: the pages no verified AI crawler has fetched, and each page's facts.
 * Changes: what was changed, what happened after, and undo.
 * Settings: retention, the weekly email, proxies, what is stored.
 *
 * No screen asks for an account. The one mention of agentnumber10.com appears
 * only once verified crawler data exists, because only then is there a real
 * question to answer.
 *
 * The screens are drawn in the product's brand language (assets/admin), with
 * its tokens generated from the product, so the plugin reads as a piece of
 * the product. Nothing outside these screens is restyled.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI and form handlers.
 */
final class AN10_Admin {

	const CAP = 'manage_options';

	const CHECKER = 'https://agentnumber10.com/ai-visibility-checker';

	/**
	 * Each answer engine's colour, as the product draws it. Engines without
	 * one get a neutral dot.
	 */
	/**
	 * The AI answers each search engine crawler's fetches feed.
	 */
	const ENGINE_FEEDS = array(
		'Googlebot' => 'Google AI Overviews and AI Mode',
		'Bingbot'   => 'Copilot',
	);

	const ENGINE_DOT = array(
		'ChatGPT'    => 'chatgpt',
		'OpenAI'     => 'chatgpt',
		'Perplexity' => 'perplexity',
		'Gemini'     => 'gemini',
		'Claude'     => 'claude',
		'Anthropic'  => 'claude',
		'Google'     => 'google',
	);

	/**
	 * The plugin's name on its screens and notices. One place, so the name
	 * reads the same everywhere.
	 */
	public static function name(): string {
		return __( 'AI Crawler Analytics', 'an10-ai-crawler-analytics' );
	}

	/**
	 * Hook the admin.
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'first_data_notice' ) );
		foreach ( array( 'apply', 'undo', 'rescan', 'settings', 'refresh_lists', 'dismiss', 'verification' ) as $action ) {
			add_action( "admin_post_an10_{$action}", array( self::class, "handle_{$action}" ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( AN10_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Register the menu page.
	 */
	public static function menu(): void {
		// The product's mark as a one-colour icon; WordPress paints it in the
		// admin colour scheme like its own icons.
		$icon = (string) file_get_contents( AN10_DIR . 'assets/admin/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file in this plugin.
		add_menu_page(
			self::name(),
			__( 'AI Crawlers', 'an10-ai-crawler-analytics' ),
			self::CAP,
			AN10_SLUG,
			array( self::class, 'render' ),
			'data:image/svg+xml;base64,' . base64_encode( $icon ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a data URI for the menu icon.
			81
		);
	}

	/**
	 * Link to the screen from the Plugins list.
	 *
	 * @param array $links Action links.
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'an10-ai-crawler-analytics' ) . '</a>' );
		return $links;
	}

	/**
	 * Land on the results right after activation.
	 */
	public static function activation_redirect(): void {
		if ( ! get_transient( 'an10_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'an10_activation_redirect' );
		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) || ! current_user_can( self::CAP ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Styles, on this plugin's screen only: the product's tokens, then the
	 * screen. No script.
	 *
	 * @param string $hook Screen hook suffix.
	 */
	public static function assets( $hook ): void {
		if ( 'toplevel_page_' . AN10_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'an10-tokens', AN10_URL . 'assets/admin/tokens.css', array(), AN10_VERSION );
		wp_enqueue_style( 'an10-admin', AN10_URL . 'assets/admin/admin.css', array( 'an10-tokens' ), AN10_VERSION );
	}

	/**
	 * URL of a screen.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra query arguments.
	 */
	public static function url( string $tab = 'overview', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => AN10_SLUG ), 'overview' === $tab ? array() : array( 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the current screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$fix = isset( $_GET['fix'] ) ? sanitize_key( wp_unslash( $_GET['fix'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap an10"><div class="an10-head">';
		printf( '<img src="%s" alt="" width="28" height="28">', esc_url( AN10_URL . 'assets/admin/mark.svg' ) );
		echo '<h1>' . esc_html( self::name() ) . '</h1><span class="an10-by">' . esc_html__( 'by agentnumber10.com', 'an10-ai-crawler-analytics' ) . '</span></div>';
		// WordPress moves admin notices to just after this line, under the header.
		echo '<hr class="wp-header-end">';
		self::flash();

		$tabs = array(
			'overview' => __( 'Overview', 'an10-ai-crawler-analytics' ),
			'pages'    => __( 'Pages', 'an10-ai-crawler-analytics' ),
			'changes'  => __( 'Changes', 'an10-ai-crawler-analytics' ),
			'settings' => __( 'Settings', 'an10-ai-crawler-analytics' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab && '' === $fix ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( '' !== $fix ) {
			self::render_fix( $fix );
		} elseif ( 'pages' === $tab ) {
			self::render_pages();
		} elseif ( 'changes' === $tab ) {
			self::render_changes();
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	/**
	 * Overview: three numbers, the last 30 days by day, the question only the
	 * product can answer, then can AI reach you and which crawlers came, then
	 * the findings.
	 */
	private static function render_overview(): void {
		$since30   = time() - 30 * DAY_IN_SECONDS;
		$summary   = AN10_Store::crawler_summary( $since30 );
		$access    = AN10_Site::crawler_access();
		$counts    = AN10_Scanner::counts();
		$state     = (array) get_option( AN10_Scanner::STATE, array() );
		$scanned   = count( AN10_Scanner::scanned_posts( 100000 ) );
		$referrals = AN10_Store::referrals( $since30, time() + 1 );
		$fetched   = count( AN10_Store::verified_post_ids( $since30, AN10_Agents::SEARCH_ENGINES ) );
		$verified  = array_filter(
			$summary,
			static function ( array $r ): bool {
				return $r['verified'] > 0;
			}
		);
		uasort(
			$verified,
			static function ( array $a, array $b ): int {
				return $b['verified'] <=> $a['verified'];
			}
		);
		// Search engines are listed, never totalled: see AN10_Agents::SEARCH_ENGINES.
		$ai        = array_diff_key( $verified, array_flip( AN10_Agents::SEARCH_ENGINES ) );
		$engines   = array_intersect_key( $verified, array_flip( AN10_Agents::SEARCH_ENGINES ) );
		$fetches   = array_sum( array_column( $ai, 'verified' ) );
		$listening = (int) get_option( 'an10_installed_at', time() );

		// Nothing can be verified, and no list is downloaded, until the owner agrees.
		if ( ! AN10_Verifier::enabled() ) {
			self::render_verification_offer();
		}

		// Three numbers first.
		echo '<div class="an10-kpis">';
		self::kpi(
			__( 'Verified AI crawler fetches', 'an10-ai-crawler-analytics' ),
			$fetches,
			$ai
				/* translators: %d: crawlers. */
				? esc_html( sprintf( _n( '%d crawler, last 30 days', '%d crawlers, last 30 days', count( $ai ), 'an10-ai-crawler-analytics' ), count( $ai ) ) )
				/* translators: %s: date. */
				: esc_html( sprintf( __( 'Listening since %s', 'an10-ai-crawler-analytics' ), wp_date( get_option( 'date_format' ), $listening ) ) )
		);
		self::kpi(
			__( 'Pages fetched', 'an10-ai-crawler-analytics' ),
			$fetched,
			/* translators: %d: published pages. */
			esc_html( sprintf( _n( 'of %d published page, last 30 days', 'of %d published pages, last 30 days', $scanned, 'an10-ai-crawler-analytics' ), $scanned ) )
		);
		$sources = '';
		foreach ( $referrals as $engine => $n ) {
			$sources .= '<span class="' . esc_attr( self::dot_class( (string) $engine ) ) . '">' . esc_html( $engine . ' ' . number_format_i18n( $n ) ) . '</span>';
		}
		self::kpi(
			__( 'Visits from AI answers', 'an10-ai-crawler-analytics' ),
			array_sum( $referrals ),
			'' !== $sources ? $sources : esc_html__( 'None yet, last 30 days', 'an10-ai-crawler-analytics' )
		);
		echo '</div><p class="an10-coverage">' . esc_html( AN10_Coverage::statement() ) . ' ' . esc_html__( 'Visits from AI answers are a floor too: some AI apps hide where a visitor came from, and a visit from Google AI Overviews looks like any other Google search visit, so it is not counted here.', 'an10-ai-crawler-analytics' ) . '</p>';

		// The last 30 days, by day.
		$days   = AN10_Store::daily_verified( 30, AN10_Agents::SEARCH_ENGINES );
		$points = array();
		foreach ( $days as $day => $n ) {
			$ts       = ( new DateTimeImmutable( $day, wp_timezone() ) )->getTimestamp();
			$points[] = array(
				'label' => wp_date( 'j M', $ts ),
				/* translators: 1: date, 2: fetches. */
				'title' => sprintf( _n( '%1$s: %2$s verified fetch', '%1$s: %2$s verified fetches', $n, 'an10-ai-crawler-analytics' ), wp_date( 'j M Y', $ts ), number_format_i18n( $n ) ),
				'value' => $n,
			);
		}
		echo '<section class="an10-card an10-days"><div class="an10-card__head"><h2>' . esc_html__( 'Verified AI crawler fetches per day', 'an10-ai-crawler-analytics' ) . '</h2><span class="an10-muted">' . esc_html__( 'Last 30 days', 'an10-ai-crawler-analytics' ) . '</span></div>';
		$chart = AN10_Chart::columns(
			$points,
			__( 'Verified AI crawler fetches per day, last 30 days', 'an10-ai-crawler-analytics' ),
			__( 'No verified fetch yet. Crawlers visit on their own schedule.', 'an10-ai-crawler-analytics' )
		);
		echo $chart; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AN10_Chart escapes every label and attribute.
		$active = array_filter( $days );
		if ( $active ) {
			echo '<details class="an10-data"><summary>' . esc_html__( 'Show as a table', 'an10-ai-crawler-analytics' ) . '</summary><table class="widefat"><thead><tr><th>' . esc_html__( 'Day', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num">' . esc_html__( 'Verified fetches', 'an10-ai-crawler-analytics' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $active, true ) as $day => $n ) {
				printf( '<tr><td>%s</td><td class="an10-num">%s</td></tr>', esc_html( wp_date( get_option( 'date_format' ), ( new DateTimeImmutable( $day, wp_timezone() ) )->getTimestamp() ) ), esc_html( number_format_i18n( $n ) ) );
			}
			echo '</tbody></table></details>';
		}
		echo '</section>';

		// The next step, shown only once there is real crawler evidence.
		if ( $verified ) {
			$top     = (string) array_key_first( $ai ? $ai : $verified );
			$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$checker = add_query_arg(
				array(
					'domain'       => rawurlencode( $host ),
					'utm_source'   => 'wp-plugin',
					'utm_medium'   => 'plugin',
					'utm_campaign' => 'crawled-citing',
				),
				self::CHECKER
			);
			echo '<div class="an10-next"><div class="an10-next__text"><p><strong>' . esc_html(
				/* translators: 1: crawler, 2: pages fetched, 3: published pages. */
				sprintf( __( '%1$s fetched %2$d of your %3$d pages. Are AI answers citing you?', 'an10-ai-crawler-analytics' ), $top, $verified[ $top ]['pages'], max( $scanned, $verified[ $top ]['pages'] ) )
			) . '</strong></p><p>' . esc_html__( 'Crawling is not citing. This plugin cannot tell whether ChatGPT, Perplexity and Google AI Overviews name you when a buyer asks, because that means asking them. agentnumber10.com does, free.', 'an10-ai-crawler-analytics' ) . '</p></div>';
			printf(
				'<a class="button an10-primary" href="%s" target="_blank" rel="noopener">%s <span aria-hidden="true">&#8599;</span><span class="screen-reader-text">%s</span></a></div>',
				esc_url( $checker ),
				esc_html__( 'Check your AI visibility', 'an10-ai-crawler-analytics' ),
				esc_html__( '(opens in a new tab)', 'an10-ai-crawler-analytics' )
			);
		}

		echo '<div class="an10-panels">';

		// Can AI reach you.
		echo '<section class="an10-panel"><h2>' . esc_html__( 'Can AI reach you', 'an10-ai-crawler-analytics' ) . '</h2><ul class="an10-checks">';
		$answer  = array_filter(
			$access,
			static function ( array $c ): bool {
				return 'training' !== $c['purpose'];
			}
		);
		$blocked = array_filter(
			$answer,
			static function ( array $c ): bool {
				return ! $c['allowed'];
			}
		);
		$allowed = count( $answer ) - count( $blocked );
		self::check_item(
			$blocked ? 'bad' : 'ok',
			/* translators: 1: allowed, 2: total. */
			sprintf( __( '%1$d of %2$d AI search and user crawlers allowed by robots.txt', 'an10-ai-crawler-analytics' ), $allowed, count( $answer ) )
		);
		foreach ( $blocked as $c ) {
			echo '<li class="an10-sub">' . esc_html(
				$c['line']
					/* translators: 1: crawler, 2: line number. */
					? sprintf( __( '%1$s is blocked by robots.txt line %2$d', 'an10-ai-crawler-analytics' ), $c['token'], $c['line'] )
					/* translators: %s: crawler. */
					: sprintf( __( '%s is blocked by robots.txt', 'an10-ai-crawler-analytics' ), $c['token'] )
			) . '</li>';
		}
		if ( AN10_Site::behind_cloudflare() ) {
			self::check_item( 'info', __( 'Your site is behind Cloudflare, which can block AI crawlers or add its own rules to robots.txt before a request reaches WordPress. This check cannot see that, so look at the AI crawler settings in your Cloudflare dashboard too.', 'an10-ai-crawler-analytics' ) );
		}
		$training         = array_filter(
			$access,
			static function ( array $c ): bool {
				return 'training' === $c['purpose'];
			}
		);
		$training_allowed = count(
			array_filter(
				$training,
				static function ( array $c ): bool {
					return $c['allowed'];
				}
			)
		);
		self::check_item(
			'info',
			/* translators: 1: allowed, 2: total. */
			sprintf( __( '%1$d of %2$d model training crawlers allowed. Blocking these is your choice and does not affect today\'s answers.', 'an10-ai-crawler-analytics' ), $training_allowed, count( $training ) )
		);
		foreach ( AN10_Scanner::FLAG_LABELS as $flag => $label ) {
			if ( ! empty( $counts[ $flag ] ) ) {
				/* translators: 1: number of pages, 2: finding. */
				self::check_item( 'finding', sprintf( _n( '%1$d page: %2$s', '%1$d pages: %2$s', $counts[ $flag ], 'an10-ai-crawler-analytics' ), $counts[ $flag ], self::flag_label( $flag ) ) );
			}
		}
		if ( empty( $state['finished_at'] ) ) {
			/* translators: 1: scanned, 2: total. */
			self::check_item( 'info', sprintf( __( 'Scanning: %1$d of %2$d pages so far.', 'an10-ai-crawler-analytics' ), $scanned, (int) ( $state['total'] ?? 0 ) ) );
		} else {
			/* translators: %d: pages. */
			self::check_item( 'info', sprintf( _n( 'Read from %d published page in the database.', 'Read from %d published pages in the database.', $scanned, 'an10-ai-crawler-analytics' ), $scanned ) );
		}
		echo '</ul></section>';

		// Which crawlers came.
		echo '<section class="an10-panel"><h2>' . esc_html__( 'Crawlers, last 30 days', 'an10-ai-crawler-analytics' ) . '</h2>';
		if ( ! $verified ) {
			echo '<p class="description">' . esc_html(
				AN10_Verifier::enabled()
					? __( 'A visit appears here only after its address matches the list its operator publishes, so this may stay empty for a while.', 'an10-ai-crawler-analytics' )
					: __( 'Crawler verification is off, so no visit can be confirmed yet. Turn it on above to start counting.', 'an10-ai-crawler-analytics' )
			) . '</p>';
		} else {
			$operators = array_column( AN10_Registry::crawlers(), 'operator', 'token' );
			echo '<table class="widefat an10-table"><thead><tr><th>' . esc_html__( 'Crawler', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num">' . esc_html__( 'Fetches', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num">' . esc_html__( 'Pages', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num">' . esc_html__( 'Last seen', 'an10-ai-crawler-analytics' ) . '</th></tr></thead><tbody>';
			foreach ( $ai + $engines as $token => $r ) {
				$operator = (string) ( $operators[ $token ] ?? '' );
				printf(
					'<tr><td><span class="%s">%s</span> <span class="an10-muted">%s</span></td><td class="an10-num">%s</td><td class="an10-num">%s</td><td class="an10-num an10-muted">%s</td></tr>',
					esc_attr( self::dot_class( $operator ) ),
					esc_html( (string) $token ),
					esc_html( $operator ),
					esc_html( number_format_i18n( (int) $r['verified'] ) ),
					esc_html( number_format_i18n( (int) $r['pages'] ) ),
					esc_html( self::ago( (string) $r['last_seen'] ) )
				);
			}
			echo '</tbody></table>';
			if ( $engines ) {
				$feeds = array();
				foreach ( array_keys( $engines ) as $token ) {
					$feeds[] = self::ENGINE_FEEDS[ $token ] ?? $token;
				}
				echo '<p class="description an10-after">' . esc_html(
					sprintf(
						/* translators: 1: search engine crawlers, such as "Googlebot and Bingbot"; 2: AI products, such as "Google AI Overviews and Copilot". */
						_n(
							'%1$s crawls for search, and what it fetches also feeds %2$s. It is listed here but not added to the AI totals above.',
							'%1$s crawl for search, and what they fetch also feeds %2$s. They are listed here but not added to the AI totals above.',
							count( $engines ),
							'an10-ai-crawler-analytics'
						),
						wp_sprintf( '%l', array_keys( $engines ) ),
						wp_sprintf( '%l', $feeds )
					)
				) . '</p>';
			}
		}
		$spoofed    = array_sum( array_column( $summary, 'spoofed' ) );
		$unverified = array_sum( array_column( $summary, 'unverified' ) );
		if ( $spoofed || $unverified ) {
			echo '<p class="description an10-after">' . esc_html(
				/* translators: 1: spoofed requests, 2: unverified requests. */
				sprintf( __( 'Not counted: %1$d spoofed (a crawler\'s name from an address its operator does not publish) and %2$d that could not be checked.', 'an10-ai-crawler-analytics' ), $spoofed, $unverified )
			) . '</p>';
		}
		echo '</section></div>';

		self::render_findings( $counts, $blocked );
	}

	/**
	 * The consent step for crawler verification: what is downloaded, from
	 * whom, what is sent, and one button. Shown until the owner turns it on.
	 */
	private static function render_verification_offer(): void {
		$operators = array();
		foreach ( AN10_Registry::crawlers() as $c ) {
			if ( null !== $c['ip_ranges'] ) {
				$operators[] = (string) $c['operator'];
			}
		}
		$operators   = array_values( array_unique( $operators ) );
		$operators[] = 'Cloudflare';
		echo '<div class="an10-next an10-consent"><div class="an10-next__text"><p><strong>' . esc_html__( 'Turn on crawler verification', 'an10-ai-crawler-analytics' ) . '</strong></p><p>' . esc_html(
			sprintf(
				/* translators: %s: list of companies, such as "OpenAI, Anthropic and Cloudflare". */
				__( 'Anyone can send a request that says it is GPTBot. To count only real AI crawlers, this plugin checks each visit against the IP address lists published by %s. Turning this on downloads those lists now and once a day after that. Each download sends only the WordPress and plugin versions, nothing about your site or its visitors. You can turn it off in Settings.', 'an10-ai-crawler-analytics' ),
				wp_sprintf( '%l', $operators )
			)
		) . '</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'an10_verification' );
		echo '<input type="hidden" name="action" value="an10_verification"><input type="hidden" name="on" value="1">';
		echo '<button type="submit" class="button an10-primary">' . esc_html__( 'Turn on verification', 'an10-ai-crawler-analytics' ) . '</button></form></div>';
	}

	/**
	 * One figure: label, number, and a line of context.
	 *
	 * @param string $label Label.
	 * @param int    $value Number.
	 * @param string $sub   Context, already escaped HTML.
	 */
	private static function kpi( string $label, int $value, string $sub ): void {
		printf(
			'<div class="an10-card an10-kpi"><span class="an10-kpi__label">%s</span><span class="an10-kpi__value">%s</span><span class="an10-kpi__sub">%s</span></div>',
			esc_html( $label ),
			esc_html( number_format_i18n( $value ) ),
			wp_kses( $sub, array( 'span' => array( 'class' => true ) ) )
		);
	}

	/**
	 * The identity-dot class for an engine or a crawler's operator.
	 *
	 * @param string $name Engine or operator name.
	 */
	private static function dot_class( string $name ): string {
		return 'an10-id an10-id--' . ( self::ENGINE_DOT[ $name ] ?? 'none' );
	}

	/**
	 * The findings list, worst first, each with its action.
	 *
	 * @param array $counts  Finding counts.
	 * @param array $blocked Blocked answer crawlers.
	 */
	private static function render_findings( array $counts, array $blocked ): void {
		$rows = array();
		if ( $blocked ) {
			$rows[] = array( __( 'AI search or user crawlers blocked by robots.txt', 'an10-ai-crawler-analytics' ), count( $blocked ), self::url( 'overview', array( 'fix' => 'robots' ) ), __( 'Allow them', 'an10-ai-crawler-analytics' ) );
		}
		$actions = array(
			'noindex_menu' => array( self::url( 'pages', array( 'flag' => 'noindex_menu' ) ), __( 'Show me', 'an10-ai-crawler-analytics' ) ),
			'no_schema'    => array( self::url( 'overview', array( 'fix' => 'schema' ) ), __( 'Add structured data', 'an10-ai-crawler-analytics' ) ),
			'skip'         => array( self::url( 'pages', array( 'flag' => 'skip' ) ), __( 'Review proposed edits', 'an10-ai-crawler-analytics' ) ),
			'h1'           => array( self::url( 'pages', array( 'flag' => 'h1' ) ), __( 'Review proposed edits', 'an10-ai-crawler-analytics' ) ),
			'canonical'    => array( self::url( 'pages', array( 'flag' => 'canonical' ) ), __( 'Show me', 'an10-ai-crawler-analytics' ) ),
			'thin'         => array( self::url( 'pages', array( 'flag' => 'thin' ) ), __( 'Show me', 'an10-ai-crawler-analytics' ) ),
		);
		foreach ( $actions as $flag => $action ) {
			if ( ! empty( $counts[ $flag ] ) ) {
				$rows[] = array( self::flag_label( $flag ), $counts[ $flag ], $action[0], $action[1] );
			}
		}
		$llms = AN10_Site::llms_txt( true );
		if ( 'other-invalid' === $llms ) {
			$rows[] = array( __( 'Another plugin serves /llms.txt, but not in the llms.txt format (it has no "# Title" first line)', 'an10-ai-crawler-analytics' ), '—', home_url( '/llms.txt' ), __( 'Show me', 'an10-ai-crawler-analytics' ) );
		}
		if ( 'none' === $llms ) {
			$rows[] = array( __( 'No llms.txt (neutral: no major AI engine has said it reads one)', 'an10-ai-crawler-analytics' ), '—', self::url( 'overview', array( 'fix' => 'llms' ) ), __( 'Generate one', 'an10-ai-crawler-analytics' ) );
		}

		echo '<h2>' . esc_html__( 'Findings', 'an10-ai-crawler-analytics' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing to fix from what this plugin can see on your server.', 'an10-ai-crawler-analytics' ) . '</p>';
			return;
		}
		echo '<table class="widefat an10-table"><thead><tr><th>' . esc_html__( 'Finding', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num">' . esc_html__( 'Pages', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num"><span class="screen-reader-text">' . esc_html__( 'Action', 'an10-ai-crawler-analytics' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf( '<tr><td>%s</td><td class="an10-num">%s</td><td class="an10-num"><a class="button" href="%s">%s</a></td></tr>', esc_html( $r[0] ), esc_html( (string) $r[1] ), esc_url( $r[2] ), esc_html( $r[3] ) );
		}
		echo '</tbody></table>';
		$seo = AN10_Site::seo_plugins();
		if ( $seo ) {
			echo '<p class="description">' . esc_html(
				/* translators: %s: SEO plugin names. */
				sprintf( _n( 'Detected %s. Titles, meta descriptions, canonicals, Open Graph and sitemaps are left to it, and structured data is only added where none is output.', 'Detected %s. Titles, meta descriptions, canonicals, Open Graph and sitemaps are left to them, and structured data is only added where none is output.', count( $seo ), 'an10-ai-crawler-analytics' ), implode( ', ', array_column( $seo, 'name' ) ) )
			) . '</p>';
		}
	}

	/**
	 * Pages: never fetched, then the facts for each page.
	 */
	private static function render_pages(): void {
		$flag = isset( $_GET['flag'] ) ? sanitize_key( wp_unslash( $_GET['flag'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $flag ) {
			$never = self::never_crawled_ids();
			echo '<h2>' . esc_html__( 'Never fetched by a verified AI crawler', 'an10-ai-crawler-analytics' ) . '</h2>';
			echo '<p class="description">' . esc_html(
				/* translators: %s: date. */
				sprintf( __( 'Since %s, when this plugin started listening. ', 'an10-ai-crawler-analytics' ), wp_date( get_option( 'date_format' ), (int) get_option( 'an10_installed_at', time() ) ) )
			) . esc_html( AN10_Coverage::statement() ) . '</p>';
			if ( ! AN10_Store::has_verified() ) {
				echo '<p>' . esc_html__( 'No verified crawler visit has been recorded yet, so this list would contain every page. It becomes useful once crawlers start arriving.', 'an10-ai-crawler-analytics' ) . '</p>';
			} else {
				/* translators: %d: pages. */
				echo '<p><strong>' . esc_html( sprintf( _n( '%d page', '%d pages', count( $never ), 'an10-ai-crawler-analytics' ), count( $never ) ) ) . '</strong></p>';
				self::post_list( array_slice( $never, 0, 200 ) );
			}
		}

		$ids = '' === $flag ? AN10_Scanner::scanned_posts( 200 ) : AN10_Scanner::posts_with( $flag );
		echo '<h2>' . esc_html( '' === $flag ? __( 'Page facts', 'an10-ai-crawler-analytics' ) : self::flag_label( $flag ) ) . '</h2>';
		if ( '' !== $flag ) {
			echo '<p><a href="' . esc_url( self::url( 'pages' ) ) . '">' . esc_html__( 'All pages', 'an10-ai-crawler-analytics' ) . '</a></p>';
		}
		echo '<p class="description">' . esc_html__( 'Counts, not grades. "Words before the first h2" is shown without a verdict: there is no measured threshold for it.', 'an10-ai-crawler-analytics' ) . '</p>';
		echo '<table class="widefat an10-table"><thead><tr><th>' . esc_html__( 'Page', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num">' . esc_html__( 'Words', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num">' . esc_html__( 'Words before the first h2', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num">' . esc_html__( 'Question headings', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-mid">' . esc_html__( 'Structured data', 'an10-ai-crawler-analytics' ) . '</th><th>' . esc_html__( 'Findings', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num"><span class="screen-reader-text">' . esc_html__( 'Action', 'an10-ai-crawler-analytics' ) . '</span></th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$facts = AN10_Scanner::facts( $id );
			if ( ! $facts ) {
				continue;
			}
			$flags  = array_filter( explode( ',', (string) get_post_meta( $id, AN10_Scanner::FLAGS, true ) ) );
			$schema = $facts['jsonld_types'] ? implode( ', ', $facts['jsonld_types'] ) : ( $facts['schema_owner'] ? sprintf( /* translators: %s: plugin. */ __( 'from %s', 'an10-ai-crawler-analytics' ), $facts['schema_owner'] ) : ( get_post_meta( $id, AN10_Changes::SCHEMA_META, true ) ? __( 'added by this plugin', 'an10-ai-crawler-analytics' ) : __( 'none found', 'an10-ai-crawler-analytics' ) ) );
			$action = ( in_array( 'skip', $flags, true ) || in_array( 'h1', $flags, true ) )
				? '<a class="button" href="' . esc_url(
					self::url(
						'overview',
						array(
							'fix'  => 'headings',
							'post' => $id,
						)
					)
				) . '">' . esc_html__( 'Propose edit', 'an10-ai-crawler-analytics' ) . '</a>'
				: '';
			printf(
				'<tr><td><a href="%s">%s</a> <a href="%s" class="an10-muted">%s</a></td><td class="an10-num">%d</td><td class="an10-num">%s</td><td class="an10-num">%d</td><td class="an10-mid">%s</td><td>%s</td><td class="an10-num">%s</td></tr>',
				esc_url( (string) get_permalink( $id ) ),
				esc_html( get_the_title( $id ) ),
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html__( 'edit', 'an10-ai-crawler-analytics' ),
				(int) $facts['word_count'],
				null === $facts['words_before_first_h2'] ? esc_html__( 'no h2', 'an10-ai-crawler-analytics' ) : (int) $facts['words_before_first_h2'],
				(int) $facts['question_headings'],
				esc_html( $schema ),
				esc_html( implode( '; ', array_map( array( self::class, 'flag_label' ), $flags ) ) ),
				$action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Changes: outcomes, then the log with undo.
	 */
	private static function render_changes(): void {
		$outcomes = AN10_Loop::outcomes();
		$log      = AN10_Changes::log();
		echo '<h2>' . esc_html__( 'What happened after', 'an10-ai-crawler-analytics' ) . '</h2>';
		if ( ! $outcomes ) {
			echo '<p>' . esc_html( $log ? __( 'No change made through this plugin is in place now.', 'an10-ai-crawler-analytics' ) : __( 'Nothing changed through this plugin yet.', 'an10-ai-crawler-analytics' ) ) . '</p>';
		}
		foreach ( $outcomes as $o ) {
			echo '<div class="an10-outcome"><p><strong>' . esc_html( $o['summary'] ) . '</strong>, ' . esc_html( wp_date( get_option( 'date_format' ), $o['applied_at'] ) );
			/* translators: %d: pages. */
			echo ' (' . esc_html( sprintf( _n( '%d page', '%d pages', $o['pages'], 'an10-ai-crawler-analytics' ), $o['pages'] ) ) . ')</p><ul>';
			if ( $o['crawlers'] ) {
				foreach ( $o['crawlers'] as $token => $pages ) {
					/* translators: 1: crawler, 2: pages fetched, 3: pages changed. */
					echo '<li>' . esc_html( sprintf( __( '%1$s has fetched %2$d of them since.', 'an10-ai-crawler-analytics' ), $token, $pages ) ) . '</li>';
				}
			} else {
				echo '<li>' . esc_html__( 'No verified AI crawler has fetched them since.', 'an10-ai-crawler-analytics' ) . '</li>';
			}
			/* translators: 1: before, 2: after. */
			echo '<li>' . esc_html( sprintf( __( 'Visits from AI answers to these pages: %1$d before, %2$d after (equal-length periods).', 'an10-ai-crawler-analytics' ), $o['before'], $o['after'] ) ) . '</li></ul></div>';
		}
		if ( $outcomes ) {
			echo '<p class="description">' . esc_html__( 'Correlation, not proof: the periods are short, other things change at the same time, and crawler counts are a floor on cached sites.', 'an10-ai-crawler-analytics' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Change log', 'an10-ai-crawler-analytics' ) . '</h2>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No changes yet.', 'an10-ai-crawler-analytics' ) . '</p>';
			return;
		}
		echo '<table class="widefat an10-table"><thead><tr><th>' . esc_html__( 'When', 'an10-ai-crawler-analytics' ) . '</th><th>' . esc_html__( 'Change', 'an10-ai-crawler-analytics' ) . '</th><th>' . esc_html__( 'By', 'an10-ai-crawler-analytics' ) . '</th><th>' . esc_html__( 'Status', 'an10-ai-crawler-analytics' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $log, 0, 100, true ) as $id => $r ) {
			$user = get_userdata( (int) $r['user_id'] );
			$undo = '';
			if ( 'applied' === $r['status'] ) {
				$undo = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
					. wp_nonce_field( 'an10_undo_' . $id, '_wpnonce', true, false )
					. '<input type="hidden" name="action" value="an10_undo"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">'
					. '<button class="button">' . esc_html__( 'Undo', 'an10-ai-crawler-analytics' ) . '</button></form>';
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $r['applied_at'] ) ),
				esc_html( $r['summary'] ),
				esc_html( $user ? $user->display_name : '—' ),
				esc_html( 'applied' === $r['status'] ? __( 'Applied', 'an10-ai-crawler-analytics' ) : __( 'Undone', 'an10-ai-crawler-analytics' ) ),
				$undo // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Settings, privacy and the verification lists.
	 */
	private static function render_settings(): void {
		$s = wp_parse_args( (array) get_option( AN10_Plugin::SETTINGS, array() ), AN10_Plugin::DEFAULTS );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'an10_settings' );
		echo '<input type="hidden" name="action" value="an10_settings"><table class="form-table" role="presentation">';
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="verify_crawlers" value="1"%s> %s</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Crawler verification', 'an10-ai-crawler-analytics' ),
			checked( ! empty( $s['verify_crawlers'] ), true, false ),
			esc_html__( 'Download each crawler operator\'s published IP address list once a day, so visits can be verified', 'an10-ai-crawler-analytics' ),
			esc_html__( 'Off until you turn it on. Each download sends only the WordPress and plugin versions. Turning it off stops the downloads and deletes the stored lists.', 'an10-ai-crawler-analytics' )
		);
		printf(
			'<tr><th scope="row"><label for="an10-retention">%s</label></th><td><input id="an10-retention" type="number" min="7" max="730" name="retention_days" value="%d"> %s</td></tr>',
			esc_html__( 'Keep events for', 'an10-ai-crawler-analytics' ),
			(int) $s['retention_days'],
			esc_html__( 'days', 'an10-ai-crawler-analytics' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="weekly_email" value="1"%s> %s</label></td></tr>',
			esc_html__( 'Weekly email', 'an10-ai-crawler-analytics' ),
			checked( ! empty( $s['weekly_email'] ), true, false ),
			esc_html(
				/* translators: %s: email address. */
				sprintf( __( 'Send a weekly summary to %s', 'an10-ai-crawler-analytics' ), (string) get_option( 'admin_email' ) )
			)
		);
		$headers = array(
			''                     => __( 'None: use the connection address (Cloudflare is detected automatically)', 'an10-ai-crawler-analytics' ),
			'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For',
			'HTTP_X_REAL_IP'       => 'X-Real-IP',
			'HTTP_TRUE_CLIENT_IP'  => 'True-Client-IP',
		);
		echo '<tr><th scope="row"><label for="an10-proxy">' . esc_html__( 'Your proxy sets', 'an10-ai-crawler-analytics' ) . '</label></th><td><select id="an10-proxy" name="proxy_header">';
		foreach ( $headers as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $s['proxy_header'], $value, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Only choose a header if your own load balancer or proxy sets it. A header anyone can send would let a fake crawler pass verification.', 'an10-ai-crawler-analytics' ) . '</p></td></tr>';
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1"%s> %s</label></td></tr>',
			esc_html__( 'On uninstall', 'an10-ai-crawler-analytics' ),
			checked( ! empty( $s['delete_on_uninstall'] ), true, false ),
			esc_html__( 'Delete the crawler log, findings and change history', 'an10-ai-crawler-analytics' )
		);
		echo '</table>';
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'What is stored', 'an10-ai-crawler-analytics' ) . '</h2><p>' . esc_html__( 'For each AI crawler request: the time, the crawler\'s name, whether its address matched the operator\'s published list, the page and the HTTP status. For each visitor from an AI answer: the time, the engine and the page. No IP address, no full user agent, no query string, no cookie and nothing that identifies a person is stored. Unless you click a link to agentnumber10.com, nothing about your site, its content or its visitors leaves your server.', 'an10-ai-crawler-analytics' ) . '</p>';

		$verifying = AN10_Verifier::enabled();
		echo '<h2>' . esc_html__( 'Crawler address lists', 'an10-ai-crawler-analytics' ) . '</h2><p class="description">' . esc_html(
			$verifying
				? __( 'Downloaded once a day from each operator, so a visit can be checked against the addresses that operator publishes. These are the only requests this plugin makes to other sites.', 'an10-ai-crawler-analytics' )
				: __( 'Crawler verification is off, so none of these lists is downloaded and this plugin makes no request to other sites. Turn verification on above to use them.', 'an10-ai-crawler-analytics' )
		) . '</p>';
		echo '<table class="widefat an10-table"><thead><tr><th>' . esc_html__( 'List', 'an10-ai-crawler-analytics' ) . '</th><th class="an10-num">' . esc_html__( 'Ranges', 'an10-ai-crawler-analytics' ) . '</th><th>' . esc_html__( 'Updated', 'an10-ai-crawler-analytics' ) . '</th></tr></thead><tbody>';
		$lists = AN10_Verifier::lists();
		foreach ( AN10_Verifier::list_urls() as $url ) {
			$l = $lists[ $url ] ?? array();
			printf(
				'<tr><td><code>%s</code></td><td class="an10-num">%d</td><td>%s</td></tr>',
				esc_html( $url ),
				count( $l['ranges'] ?? array() ),
				esc_html( empty( $l['fetched_at'] ) ? __( 'not yet', 'an10-ai-crawler-analytics' ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $l['fetched_at'] ) ) . ( empty( $l['error'] ) ? '' : ' — ' . esc_html( $l['error'] ) )
			);
		}
		echo '</tbody></table>';
		if ( $verifying ) {
			self::button_form( 'an10_refresh_lists', __( 'Refresh lists now', 'an10-ai-crawler-analytics' ) );
		}
		self::button_form( 'an10_rescan', __( 'Rescan all pages now', 'an10-ai-crawler-analytics' ) );
	}

	/**
	 * A fix screen: the exact change, then one explicit button.
	 *
	 * @param string $fix Fix type.
	 */
	private static function render_fix( string $fix ): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<p class="an10-back"><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'Overview', 'an10-ai-crawler-analytics' ) . '</a></p>';

		if ( 'schema' === $fix ) {
			$ids   = AN10_Scanner::posts_with( 'no_schema' );
			$items = array();
			foreach ( $ids as $id ) {
				$preview = AN10_Changes::preview( 'schema', $id );
				if ( ! is_wp_error( $preview ) ) {
					$items[ $id ] = $preview;
				}
			}
			echo '<h2>' . esc_html__( 'Add structured data', 'an10-ai-crawler-analytics' ) . '</h2><p>' . esc_html__( 'Built only from what WordPress already knows about each page: its title, address, dates, author, featured image, and your site name and icon. Nothing is guessed. Untick any page you want to leave out.', 'an10-ai-crawler-analytics' ) . '</p>';
			if ( ! $items ) {
				echo '<p>' . esc_html__( 'No page needs this right now.', 'an10-ai-crawler-analytics' ) . '</p>';
				return;
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'an10_apply' );
			echo '<input type="hidden" name="action" value="an10_apply"><input type="hidden" name="type" value="schema"><ul class="an10-items">';
			foreach ( $items as $id => $p ) {
				printf(
					'<li><label><input type="checkbox" name="items[%d]" value="%s" checked> %s <span class="an10-muted">%s</span></label></li>',
					(int) $id,
					esc_attr( AN10_Changes::fingerprint( $p['after'] ) ),
					esc_html( get_the_title( $id ) ),
					esc_html( $p['after']['@type'] )
				);
			}
			echo '</ul><p>' . esc_html__( 'Example, for the first page:', 'an10-ai-crawler-analytics' ) . '</p><pre class="an10-pre">' . esc_html( (string) wp_json_encode( reset( $items )['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
			submit_button( __( 'Add to the selected pages', 'an10-ai-crawler-analytics' ) );
			echo '</form>';
			return;
		}

		$preview = AN10_Changes::preview( $fix, $post_id );
		if ( is_wp_error( $preview ) ) {
			echo '<div class="notice notice-info"><p>' . nl2br( esc_html( $preview->get_error_message() ) ) . '</p></div>';
			return;
		}

		if ( 'headings' === $fix ) {
			echo '<h2>' . esc_html( get_the_title( $post_id ) ) . '</h2><p>' . esc_html__( 'A proposal for you to review. Which level a heading belongs at is an editorial decision, so this is never applied without you. Only the heading levels change; the text stays as it is.', 'an10-ai-crawler-analytics' ) . '</p>';
			echo '<div class="an10-panels"><section class="an10-panel"><h3>' . esc_html__( 'Now', 'an10-ai-crawler-analytics' ) . '</h3>' . self::outline( $preview['before_outline'] ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- outline() escapes.
			echo '<section class="an10-panel"><h3>' . esc_html__( 'Proposed', 'an10-ai-crawler-analytics' ) . '</h3>' . self::outline( $preview['after_outline'] ) . '</section></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- outline() escapes.
		} elseif ( 'robots' === $fix ) {
			echo '<h2>' . esc_html__( 'Allow AI search and user crawlers', 'an10-ai-crawler-analytics' ) . '</h2><p>' . esc_html__( 'These lines are added to the robots.txt WordPress serves. Training crawlers are left as they are.', 'an10-ai-crawler-analytics' ) . '</p>';
			echo '<pre class="an10-pre">' . esc_html( $preview['after'] ) . '</pre>';
		} elseif ( 'llms' === $fix ) {
			echo '<h2>' . esc_html__( 'Generate llms.txt', 'an10-ai-crawler-analytics' ) . '</h2><p>' . esc_html__( 'Served at /llms.txt and kept up to date as you publish. No major AI engine has confirmed it reads llms.txt, so treat this as a convenience, not a ranking factor.', 'an10-ai-crawler-analytics' ) . '</p>';
			echo '<pre class="an10-pre">' . esc_html( implode( "\n", array_slice( explode( "\n", (string) $preview['after'] ), 0, 40 ) ) ) . '</pre>';
		} else {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'an10_apply' );
		printf(
			'<input type="hidden" name="action" value="an10_apply"><input type="hidden" name="type" value="%s"><input type="hidden" name="post_id" value="%d"><input type="hidden" name="hash" value="%s">',
			esc_attr( $fix ),
			(int) $post_id,
			esc_attr( AN10_Changes::fingerprint( $preview['after'] ) )
		);
		submit_button( __( 'Apply this change', 'an10-ai-crawler-analytics' ) );
		echo '</form>';
	}

	/**
	 * Apply one or more changes.
	 */
	public static function handle_apply(): void {
		check_admin_referer( 'an10_apply' );
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! in_array( $type, AN10_Changes::TYPES, true ) ) {
			wp_die( esc_html__( 'Unknown change.', 'an10-ai-crawler-analytics' ) );
		}
		$items = array();
		if ( 'schema' === $type && isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
			foreach ( wp_unslash( $_POST['items'] ) as $id => $hash ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item.
				$items[ absint( $id ) ] = sanitize_text_field( (string) $hash );
			}
		} else {
			$items[ isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 ] = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		}

		$batch  = wp_generate_uuid4();
		$done   = 0;
		$errors = array();
		foreach ( $items as $id => $hash ) {
			$result = AN10_Changes::apply( $type, (int) $id, $hash, 'local', $batch );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				++$done;
			}
		}
		if ( $errors ) {
			self::set_flash( 'error', implode( ' ', array_unique( $errors ) ) );
		} else {
			/* translators: %d: changes. */
			self::set_flash( 'success', sprintf( _n( 'Applied %d change. You can undo it below.', 'Applied %d changes. You can undo them below.', $done, 'an10-ai-crawler-analytics' ), $done ) );
		}
		wp_safe_redirect( self::url( 'changes' ) );
		exit;
	}

	/**
	 * Undo a change.
	 */
	public static function handle_undo(): void {
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		check_admin_referer( 'an10_undo_' . $id );
		$result = AN10_Changes::undo( $id );
		if ( is_wp_error( $result ) ) {
			self::set_flash( 'error', $result->get_error_message() );
		} else {
			self::set_flash( 'success', __( 'Undone. The page is back as it was.', 'an10-ai-crawler-analytics' ) );
		}
		wp_safe_redirect( self::url( 'changes' ) );
		exit;
	}

	/**
	 * Start a rescan.
	 */
	public static function handle_rescan(): void {
		check_admin_referer( 'an10_rescan' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'an10-ai-crawler-analytics' ) );
		}
		AN10_Scanner::start_full_scan();
		self::set_flash( 'success', __( 'Rescan started.', 'an10-ai-crawler-analytics' ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Save settings.
	 */
	public static function handle_settings(): void {
		check_admin_referer( 'an10_settings' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'an10-ai-crawler-analytics' ) );
		}
		$header = isset( $_POST['proxy_header'] ) ? sanitize_text_field( wp_unslash( $_POST['proxy_header'] ) ) : '';
		$verify = ! empty( $_POST['verify_crawlers'] );
		$was    = AN10_Verifier::enabled();
		update_option(
			AN10_Plugin::SETTINGS,
			array(
				'retention_days'      => max( 7, min( 730, isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 90 ) ),
				'weekly_email'        => ! empty( $_POST['weekly_email'] ),
				'proxy_header'        => in_array( $header, array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP' ), true ) ? $header : '',
				'delete_on_uninstall' => ! empty( $_POST['delete_on_uninstall'] ),
				'verify_crawlers'     => $was,
			)
		);
		// Turning verification on or off also starts or stops the downloads.
		if ( $verify !== $was ) {
			AN10_Verifier::set_enabled( $verify );
		}
		self::set_flash( 'success', __( 'Settings saved.', 'an10-ai-crawler-analytics' ) );
		wp_safe_redirect( self::url( 'settings' ) );
		exit;
	}

	/**
	 * Turn crawler verification on or off.
	 */
	public static function handle_verification(): void {
		check_admin_referer( 'an10_verification' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'an10-ai-crawler-analytics' ) );
		}
		$on = ! empty( $_POST['on'] );
		AN10_Verifier::set_enabled( $on );
		self::set_flash(
			'success',
			$on
				? __( 'Crawler verification is on. The lists download in the background, and visits are checked against them from then on.', 'an10-ai-crawler-analytics' )
				: __( 'Crawler verification is off. No more lists will be downloaded, and the stored copies were deleted.', 'an10-ai-crawler-analytics' )
		);
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url() );
		exit;
	}

	/**
	 * Refresh the IP lists now.
	 */
	public static function handle_refresh_lists(): void {
		check_admin_referer( 'an10_refresh_lists' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'an10-ai-crawler-analytics' ) );
		}
		AN10_Verifier::refresh();
		self::set_flash( 'success', __( 'Lists refreshed.', 'an10-ai-crawler-analytics' ) );
		wp_safe_redirect( self::url( 'settings' ) );
		exit;
	}

	/**
	 * Dismiss the first-data notice for this user.
	 */
	public static function handle_dismiss(): void {
		check_admin_referer( 'an10_dismiss' );
		update_user_meta( get_current_user_id(), 'an10_dismissed_first', 1 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Tell the admin, once, that real crawler data has arrived.
	 *
	 * Only on the Dashboard and the Plugins screen: never on another plugin's
	 * screens, the editor or settings pages, where it would be in the way.
	 */
	public static function first_data_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) || ! get_option( 'an10_first_verified_at' ) || get_user_meta( get_current_user_id(), 'an10_dismissed_first', true ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=an10_dismiss' ), 'an10_dismiss' );
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a> · <a href="%s">%s</a></p></div>',
			/* translators: %s: plugin name. */
			esc_html( sprintf( __( '%s recorded its first verified AI crawler visit.', 'an10-ai-crawler-analytics' ), self::name() ) ),
			esc_url( self::url() ),
			esc_html__( 'See what it fetched', 'an10-ai-crawler-analytics' ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'an10-ai-crawler-analytics' )
		);
	}

	/**
	 * Published, scanned posts no verified crawler has fetched since install.
	 *
	 * @return int[]
	 */
	public static function never_crawled_ids(): array {
		$seen = AN10_Store::verified_post_ids( (int) get_option( 'an10_installed_at', 0 ), AN10_Agents::SEARCH_ENGINES );
		return array_values( array_diff( AN10_Scanner::scanned_posts( 100000 ), $seen ) );
	}

	/**
	 * Human label for a finding.
	 *
	 * @param string $flag Finding key.
	 */
	public static function flag_label( string $flag ): string {
		$labels = array(
			'no_schema'    => __( 'No structured data on the page', 'an10-ai-crawler-analytics' ),
			'skip'         => __( 'A heading level is skipped', 'an10-ai-crawler-analytics' ),
			'h1'           => __( 'An h1 inside the content (the title is usually the h1 already)', 'an10-ai-crawler-analytics' ),
			'noindex_menu' => __( 'Set to noindex but linked from a menu', 'an10-ai-crawler-analytics' ),
			'canonical'    => __( 'Canonical URL points to another page', 'an10-ai-crawler-analytics' ),
			/* translators: %d: words. */
			'thin'         => sprintf( __( 'Under %d words', 'an10-ai-crawler-analytics' ), (int) AN10_Registry::thresholds()['thin_content_words'] ),
		);
		return $labels[ $flag ] ?? $flag;
	}

	/**
	 * One line in the checks list: a status dot, with the status in words for
	 * screen readers, so it is never colour alone.
	 *
	 * @param string $status ok, finding, bad or info.
	 * @param string $text   Text.
	 */
	private static function check_item( string $status, string $text ): void {
		$words = array(
			'ok'      => __( 'Passed:', 'an10-ai-crawler-analytics' ),
			'finding' => __( 'Finding:', 'an10-ai-crawler-analytics' ),
			'bad'     => __( 'Problem:', 'an10-ai-crawler-analytics' ),
			'info'    => '',
		);
		printf(
			'<li class="is-%s">%s<span>%s</span></li>',
			esc_attr( $status ),
			'' === $words[ $status ] ? '' : '<span class="screen-reader-text">' . esc_html( $words[ $status ] ) . '</span>',
			esc_html( $text )
		);
	}

	/**
	 * A heading outline.
	 *
	 * @param array $outline Headings with level and text.
	 */
	private static function outline( array $outline ): string {
		$out = '<ol class="an10-outline">';
		foreach ( $outline as $h ) {
			$out .= sprintf( '<li style="margin-left:%dem"><code>h%d</code> %s</li>', ( (int) $h['level'] - 2 ) * 1.5, (int) $h['level'], esc_html( $h['text'] ) );
		}
		return $out . '</ol>';
	}

	/**
	 * A list of post links.
	 *
	 * @param int[] $ids Posts.
	 */
	private static function post_list( array $ids ): void {
		echo '<ul class="an10-items">';
		foreach ( $ids as $id ) {
			printf( '<li><a href="%s">%s</a> <span class="an10-muted">%s</span></li>', esc_url( (string) get_permalink( $id ) ), esc_html( get_the_title( $id ) ), esc_html( (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH ) ) );
		}
		echo '</ul>';
	}

	/**
	 * A one-button form posting to admin-post.php.
	 *
	 * @param string $action Action.
	 * @param string $label  Button text.
	 */
	private static function button_form( string $action, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="an10-inline">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * "3 hours ago" for a stored UTC datetime.
	 *
	 * @param string $utc Datetime.
	 */
	private static function ago( string $utc ): string {
		$ts = strtotime( $utc . ' UTC' );
		/* translators: %s: time span. */
		return $ts ? sprintf( __( '%s ago', 'an10-ai-crawler-analytics' ), human_time_diff( $ts ) ) : '—';
	}

	/**
	 * Queue a message for the next screen.
	 *
	 * @param string $type success or error.
	 * @param string $text Message.
	 */
	private static function set_flash( string $type, string $text ): void {
		set_transient( 'an10_flash_' . get_current_user_id(), array( $type, $text ), MINUTE_IN_SECONDS );
	}

	/**
	 * Show and clear the queued message.
	 */
	private static function flash(): void {
		$key   = 'an10_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $flash[0] ? 'error' : 'success', esc_html( (string) $flash[1] ) );
	}
}
