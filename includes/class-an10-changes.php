<?php
/**
 * The execution engine: every change the plugin makes goes through here.
 *
 * One path, whatever proposed the change. Each change is:
 *   1. previewed: the exact before and after, computed on the server;
 *   2. authorized: the person applying it must have the capability to make
 *      that edit themselves, checked as that person;
 *   3. checked against the preview: if the page changed after the preview
 *      was shown, nothing is written and the person is asked to look again;
 *   4. logged with its previous state;
 *   5. reversible: undo restores the previous state, and refuses when the
 *      post was edited since, rather than overwrite someone's work.
 *
 * Nothing is applied without an explicit click. Four kinds of change exist,
 * all free: JSON-LD built from facts already on the page, robots.txt rules,
 * llms.txt, and a heading structure the person reviews first.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Change preview, application, logging and undo.
 */
final class AN10_Changes {

	const LOG           = 'an10_changes';
	const MAX           = 500;
	const SCHEMA_META   = '_an10_schema';
	const BEFORE_META   = '_an10_before_';
	const ROBOTS_OPTION = 'an10_robots_rules';
	const LLMS_OPTION   = 'an10_llms_enabled';

	const TYPES = array( 'schema', 'headings', 'robots', 'llms' );

	/**
	 * Hook the outputs a change can produce.
	 */
	public static function register_outputs(): void {
		add_action( 'wp_head', array( self::class, 'print_schema' ), 30 );
		add_filter( 'robots_txt', array( self::class, 'filter_robots' ), 100, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Preview a change. Computed fresh every time; never trusted from a form.
	 *
	 * @param string $type    Change type.
	 * @param int    $post_id Post, for post-level changes.
	 * @return array|WP_Error
	 */
	public static function preview( string $type, int $post_id = 0 ) {
		switch ( $type ) {
			case 'schema':
				return self::preview_schema( $post_id );
			case 'headings':
				return self::preview_headings( $post_id );
			case 'robots':
				return self::preview_robots();
			case 'llms':
				return self::preview_llms();
		}
		return new WP_Error( 'an10_type', __( 'Unknown change.', 'an10-ai-crawler-analytics' ) );
	}

	/**
	 * Apply a previewed change.
	 *
	 * @param string $type          Change type.
	 * @param int    $post_id       Post, for post-level changes.
	 * @param string $expected_hash Fingerprint of the preview the person approved.
	 * @param string $origin        Where the change came from: 'local' for now.
	 * @param string $batch         Groups changes applied together.
	 * @return array|WP_Error The log record.
	 */
	public static function apply( string $type, int $post_id, string $expected_hash, string $origin = 'local', string $batch = '' ) {
		if ( ! self::can( $type, $post_id ) ) {
			return new WP_Error( 'an10_cap', __( 'Your account is not allowed to make this change.', 'an10-ai-crawler-analytics' ) );
		}
		$preview = self::preview( $type, $post_id );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( ! hash_equals( self::fingerprint( $preview['after'] ), $expected_hash ) ) {
			return new WP_Error( 'an10_changed', __( 'The page changed after the preview was shown. Nothing was written; review the change again.', 'an10-ai-crawler-analytics' ) );
		}

		$id     = wp_generate_uuid4();
		$record = array(
			'id'         => $id,
			'type'       => $type,
			'post_id'    => $post_id,
			'origin'     => $origin,
			'batch'      => '' === $batch ? $id : $batch,
			'user_id'    => get_current_user_id(),
			'applied_at' => time(),
			'summary'    => $preview['summary'],
			'before'     => $preview['before'],
			'after'      => 'headings' === $type ? $preview['after_outline'] : $preview['after'],
			'status'     => 'applied',
		);

		switch ( $type ) {
			case 'schema':
				update_post_meta( $post_id, self::SCHEMA_META, wp_slash( $preview['after'] ) );
				AN10_Scanner::scan_post( $post_id );
				break;
			case 'headings':
				$done = self::write_headings( $post_id, $id, $preview );
				if ( is_wp_error( $done ) ) {
					return $done;
				}
				$record['after_hash'] = $done;
				$record['before']     = $preview['before_outline'];
				break;
			case 'robots':
				update_option( self::ROBOTS_OPTION, $preview['after'], false );
				break;
			case 'llms':
				update_option( self::LLMS_OPTION, true, false );
				break;
		}

		self::save_record( $record );
		return $record;
	}

	/**
	 * Undo a change.
	 *
	 * @param string $id Change id.
	 * @return true|WP_Error
	 */
	public static function undo( string $id ) {
		$log    = self::log();
		$record = $log[ $id ] ?? null;
		if ( ! $record || 'applied' !== $record['status'] ) {
			return new WP_Error( 'an10_undo', __( 'That change is not active, so there is nothing to undo.', 'an10-ai-crawler-analytics' ) );
		}
		if ( ! self::can( $record['type'], (int) $record['post_id'] ) ) {
			return new WP_Error( 'an10_cap', __( 'Your account is not allowed to undo this change.', 'an10-ai-crawler-analytics' ) );
		}

		$post_id = (int) $record['post_id'];
		switch ( $record['type'] ) {
			case 'schema':
				if ( empty( $record['before'] ) ) {
					delete_post_meta( $post_id, self::SCHEMA_META );
				} else {
					update_post_meta( $post_id, self::SCHEMA_META, wp_slash( $record['before'] ) );
				}
				AN10_Scanner::scan_post( $post_id );
				break;
			case 'headings':
				$post = get_post( $post_id );
				if ( ! $post || md5( (string) $post->post_content ) !== ( $record['after_hash'] ?? '' ) ) {
					return new WP_Error( 'an10_edited', __( 'The post was edited after this change. Undo would overwrite those edits, so nothing was done. Use the post\'s revisions instead.', 'an10-ai-crawler-analytics' ) );
				}
				$before = get_post_meta( $post_id, self::BEFORE_META . $id, true );
				if ( ! is_string( $before ) || '' === $before ) {
					return new WP_Error( 'an10_missing', __( 'The previous version of this post is no longer stored.', 'an10-ai-crawler-analytics' ) );
				}
				$result = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( $before ),
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				delete_post_meta( $post_id, self::BEFORE_META . $id );
				break;
			case 'robots':
				update_option( self::ROBOTS_OPTION, (string) $record['before'], false );
				break;
			case 'llms':
				update_option( self::LLMS_OPTION, (bool) $record['before'], false );
				break;
		}

		$record['status']    = 'undone';
		$record['undone_at'] = time();
		$record['undone_by'] = get_current_user_id();
		self::save_record( $record );
		return true;
	}

	/**
	 * The change log, newest first, keyed by id.
	 *
	 * @return array<string, array>
	 */
	public static function log(): array {
		return (array) get_option( self::LOG, array() );
	}

	/**
	 * Fingerprint of a preview's "after" state.
	 *
	 * @param mixed $after Preview after state.
	 */
	public static function fingerprint( $after ): string {
		return md5( is_string( $after ) ? $after : (string) wp_json_encode( $after ) );
	}

	/**
	 * Print JSON-LD added by this plugin, unless another plugin now owns schema.
	 */
	public static function print_schema(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || null !== AN10_Site::schema_owner( $post->post_type ) ) {
			return;
		}
		$node = get_post_meta( $post->ID, self::SCHEMA_META, true );
		if ( ! is_array( $node ) || ! $node ) {
			return;
		}
		wp_print_inline_script_tag(
			(string) wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ),
			array( 'type' => 'application/ld+json' )
		);
	}

	/**
	 * Append the rules added through this plugin to WordPress's robots.txt.
	 *
	 * @param string $output Generated robots.txt.
	 * @param string $is_public Site visibility setting.
	 */
	public static function filter_robots( $output, $is_public ) {
		$rules = (string) get_option( self::ROBOTS_OPTION, '' );
		if ( '' === $rules || '0' === (string) $is_public ) {
			return $output;
		}
		return rtrim( (string) $output ) . "\n\n# Added by " . AN10_SLUG . "\n" . $rules . "\n";
	}

	/**
	 * Serve /llms.txt when it is switched on and no file exists on disk.
	 */
	public static function serve_llms_txt(): void {
		if ( ! get_option( self::LLMS_OPTION ) ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( (string) wp_parse_url( home_url( '/llms.txt' ), PHP_URL_PATH ) !== $path ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo self::llms_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text built from escaped titles and URLs.
		exit;
	}

	/**
	 * llms.txt for this site: the homepage, published pages, then recent posts,
	 * leaving out anything set to noindex.
	 */
	public static function llms_content(): string {
		$entries = array(
			array(
				'title'       => get_bloginfo( 'name' ),
				'url'         => home_url( '/' ),
				'description' => '',
				'is_home'     => true,
			),
		);
		$front   = (int) get_option( 'page_on_front' );
		$posts   = array_merge(
			get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => 100,
					'orderby'        => 'menu_order title',
					'order'          => 'ASC',
				)
			),
			get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => 100,
				)
			)
		);
		foreach ( $posts as $p ) {
			if ( $p->ID === $front || AN10_Site::noindex( $p ) ) {
				continue;
			}
			$entries[] = array(
				'title'       => wp_strip_all_tags( get_the_title( $p ) ),
				'url'         => (string) get_permalink( $p ),
				'description' => has_excerpt( $p ) ? wp_strip_all_tags( get_the_excerpt( $p ) ) : '',
			);
		}
		return AN10_Llms::render( wp_strip_all_tags( get_bloginfo( 'name' ) ), wp_strip_all_tags( get_bloginfo( 'description' ) ), $entries );
	}

	/**
	 * May the current user make this change?
	 *
	 * @param string $type    Change type.
	 * @param int    $post_id Post.
	 */
	private static function can( string $type, int $post_id ): bool {
		if ( in_array( $type, array( 'schema', 'headings' ), true ) ) {
			return $post_id > 0 && current_user_can( 'edit_post', $post_id );
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Preview JSON-LD for a post.
	 *
	 * @param int $post_id Post.
	 * @return array|WP_Error
	 */
	private static function preview_schema( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'an10_post', __( 'That post is not published.', 'an10-ai-crawler-analytics' ) );
		}
		$owner = AN10_Site::schema_owner( $post->post_type, true );
		if ( null !== $owner ) {
			/* translators: %s: plugin name. */
			return new WP_Error( 'an10_owner', sprintf( __( '%s already outputs structured data for this page, so nothing is added.', 'an10-ai-crawler-analytics' ), $owner ) );
		}
		$author = get_userdata( (int) $post->post_author );
		$image  = get_the_post_thumbnail_url( $post, 'full' );
		$icon   = get_site_icon_url( 512 );
		$node   = AN10_Schema::build(
			array(
				'kind'           => 'page' === $post->post_type ? 'webpage' : 'article',
				'title'          => wp_strip_all_tags( get_the_title( $post ) ),
				'url'            => (string) get_permalink( $post ),
				'date_published' => get_post_time( 'c', true, $post ),
				'date_modified'  => get_post_modified_time( 'c', true, $post ),
				'author_name'    => $author ? $author->display_name : null,
				'image_url'      => $image ? $image : null,
				'site_name'      => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'site_logo_url'  => $icon ? $icon : null,
			)
		);
		if ( null === $node ) {
			return new WP_Error( 'an10_facts', __( 'This page has no title, so there are not enough facts to describe it.', 'an10-ai-crawler-analytics' ) );
		}
		$before = get_post_meta( $post_id, self::SCHEMA_META, true );
		return array(
			'type'    => 'schema',
			'post_id' => $post_id,
			/* translators: 1: schema type, 2: post title. */
			'summary' => sprintf( __( 'Added %1$s structured data to "%2$s"', 'an10-ai-crawler-analytics' ), $node['@type'], wp_strip_all_tags( get_the_title( $post ) ) ),
			'before'  => is_array( $before ) ? $before : null,
			'after'   => $node,
		);
	}

	/**
	 * Preview a heading structure.
	 *
	 * @param int $post_id Post.
	 * @return array|WP_Error
	 */
	private static function preview_headings( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'an10_post', __( 'That post does not exist.', 'an10-ai-crawler-analytics' ) );
		}
		$content  = (string) $post->post_content;
		$headings = AN10_Content::headings( $content );
		$levels   = array_column( $headings, 'level' );
		$proposed = AN10_Content::propose_levels( $levels );
		if ( $proposed === $levels ) {
			return new WP_Error( 'an10_none', __( 'The headings on this page already follow one another without skipping a level.', 'an10-ai-crawler-analytics' ) );
		}
		$after = AN10_Content::rewrite_headings( $content, $proposed );
		if ( null === $after ) {
			return new WP_Error( 'an10_parse', __( 'The headings could not be read reliably, so no change is proposed.', 'an10-ai-crawler-analytics' ) );
		}
		$outline = static function ( array $lv ) use ( $headings ): array {
			$out = array();
			foreach ( $headings as $i => $h ) {
				$out[] = array(
					'level' => (int) $lv[ $i ],
					'text'  => $h['text'],
				);
			}
			return $out;
		};
		return array(
			'type'           => 'headings',
			'post_id'        => $post_id,
			/* translators: %s: post title. */
			'summary'        => sprintf( __( 'Restructured the headings of "%s"', 'an10-ai-crawler-analytics' ), wp_strip_all_tags( get_the_title( $post ) ) ),
			'before'         => md5( $content ),
			'after'          => $after,
			'before_outline' => $outline( $levels ),
			'after_outline'  => $outline( $proposed ),
		);
	}

	/**
	 * Write the new heading structure, keeping the previous content.
	 *
	 * @param int    $post_id Post.
	 * @param string $id      Change id.
	 * @param array  $preview Approved preview.
	 * @return string|WP_Error Hash of the saved content.
	 */
	private static function write_headings( int $post_id, string $id, array $preview ) {
		$post = get_post( $post_id );
		update_post_meta( $post_id, self::BEFORE_META . $id, wp_slash( (string) $post->post_content ) );
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $preview['after'] ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			delete_post_meta( $post_id, self::BEFORE_META . $id );
			return $result;
		}
		$saved = (string) get_post( $post_id )->post_content;
		if ( $saved !== $preview['after'] ) {
			// WordPress filtered the content for this account (for example, it may
			// not post unfiltered HTML). Put the post back exactly as it was.
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( (string) get_post_meta( $post_id, self::BEFORE_META . $id, true ) ),
				)
			);
			delete_post_meta( $post_id, self::BEFORE_META . $id );
			return new WP_Error( 'an10_filtered', __( 'WordPress changed other parts of this post when saving it from your account, so the post was restored as it was. An administrator can apply this change.', 'an10-ai-crawler-analytics' ) );
		}
		return md5( $saved );
	}

	/**
	 * Preview robots.txt rules that let blocked answer crawlers in.
	 *
	 * Training crawlers are left as they are: blocking them is a legitimate
	 * choice and has no effect on today's answers.
	 *
	 * @return array|WP_Error
	 */
	private static function preview_robots() {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return new WP_Error( 'an10_private', __( 'Settings > Reading asks search engines not to index this site. That is a deliberate choice, so no robots.txt rule is added.', 'an10-ai-crawler-analytics' ) );
		}
		$blocked = array();
		foreach ( AN10_Site::crawler_access() as $c ) {
			if ( ! $c['allowed'] && 'training' !== $c['purpose'] ) {
				$blocked[] = $c['token'];
			}
		}
		if ( ! $blocked ) {
			return new WP_Error( 'an10_none', __( 'Every AI search and user crawler is already allowed by robots.txt.', 'an10-ai-crawler-analytics' ) );
		}
		$current = (string) get_option( self::ROBOTS_OPTION, '' );
		$lines   = '';
		foreach ( $blocked as $token ) {
			$lines .= "User-agent: {$token}\n";
		}
		$lines .= "Allow: /\n";
		$after  = trim( $current . "\n" . $lines );
		if ( 'file' === AN10_Site::robots_txt()['source'] ) {
			return new WP_Error(
				'an10_file',
				/* translators: %s: robots.txt lines. */
				sprintf( __( 'robots.txt is a file on your server, which WordPress cannot change. Add these lines to it: %s', 'an10-ai-crawler-analytics' ), "\n" . $lines )
			);
		}
		return array(
			'type'    => 'robots',
			'post_id' => 0,
			/* translators: %s: crawler names. */
			'summary' => sprintf( __( 'Allowed %s in robots.txt', 'an10-ai-crawler-analytics' ), implode( ', ', $blocked ) ),
			'before'  => $current,
			'after'   => $after,
		);
	}

	/**
	 * Preview switching on llms.txt.
	 *
	 * @return array|WP_Error
	 */
	private static function preview_llms() {
		$source = AN10_Site::llms_txt( true );
		if ( 'other' === $source || 'other-invalid' === $source ) {
			return new WP_Error( 'an10_other', __( 'Another plugin already serves /llms.txt, so this plugin leaves it alone rather than compete for the same address.', 'an10-ai-crawler-analytics' ) );
		}
		if ( 'file' === $source ) {
			return new WP_Error( 'an10_file', __( 'An llms.txt file already exists on your server, so this plugin leaves it alone.', 'an10-ai-crawler-analytics' ) );
		}
		if ( 'plugin' === $source ) {
			return new WP_Error( 'an10_none', __( 'llms.txt is already switched on.', 'an10-ai-crawler-analytics' ) );
		}
		return array(
			'type'    => 'llms',
			'post_id' => 0,
			'summary' => __( 'Switched on llms.txt', 'an10-ai-crawler-analytics' ),
			'before'  => false,
			'after'   => self::llms_content(),
		);
	}

	/**
	 * Save a record, newest first, keeping the log bounded.
	 *
	 * @param array $record Change record.
	 */
	private static function save_record( array $record ): void {
		$log = self::log();
		unset( $log[ $record['id'] ] );
		$log = array( $record['id'] => $record ) + $log;
		if ( count( $log ) > self::MAX ) {
			foreach ( array_slice( $log, self::MAX, null, true ) as $old ) {
				if ( 'headings' === $old['type'] ) {
					delete_post_meta( (int) $old['post_id'], self::BEFORE_META . $old['id'] );
				}
			}
			$log = array_slice( $log, 0, self::MAX, true );
		}
		update_option( self::LOG, $log, false );
	}
}
