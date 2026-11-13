<?php
/**
 * JSON-LD built only from facts WordPress already holds.
 *
 * Every field comes from the post or the site settings: the title, the
 * permalink, the dates, the author's display name, the featured image, the
 * site name and icon. A field with no value is left out, never filled with a
 * guess. Shipping incomplete structured data is better than inventing a
 * fact, and a missing optional field is not an error to any parser.
 *
 * Pure: no WordPress functions. The caller gathers the facts.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSON-LD builder.
 */
final class AN10_Schema {

	/**
	 * Build JSON-LD for one post or page.
	 *
	 * @param array $facts {
	 *     Facts read from WordPress.
	 *
	 *     @type string      $kind           'article' for posts, 'webpage' for pages.
	 *     @type string      $title          Post title.
	 *     @type string      $url            Permalink.
	 *     @type string|null $date_published ISO 8601.
	 *     @type string|null $date_modified  ISO 8601.
	 *     @type string|null $author_name    Display name of the post author.
	 *     @type string|null $image_url      Featured image URL.
	 *     @type string|null $site_name      Site title.
	 *     @type string|null $site_logo_url  Site icon URL.
	 * }
	 * @return array|null Null when the facts cannot support a valid object.
	 */
	public static function build( array $facts ): ?array {
		$title = trim( (string) ( $facts['title'] ?? '' ) );
		$url   = trim( (string) ( $facts['url'] ?? '' ) );
		if ( '' === $title || '' === $url ) {
			return null;
		}

		if ( 'article' === ( $facts['kind'] ?? '' ) ) {
			$node = array(
				'@context'         => 'https://schema.org',
				'@type'            => 'BlogPosting',
				'headline'         => $title,
				'url'              => $url,
				'mainEntityOfPage' => $url,
			);
			self::put( $node, 'datePublished', $facts['date_published'] ?? null );
			self::put( $node, 'dateModified', $facts['date_modified'] ?? null );
			self::put( $node, 'image', $facts['image_url'] ?? null );
			if ( ! empty( $facts['author_name'] ) ) {
				$node['author'] = array(
					'@type' => 'Person',
					'name'  => (string) $facts['author_name'],
				);
			}
			if ( ! empty( $facts['site_name'] ) ) {
				$publisher = array(
					'@type' => 'Organization',
					'name'  => (string) $facts['site_name'],
				);
				if ( ! empty( $facts['site_logo_url'] ) ) {
					$publisher['logo'] = array(
						'@type' => 'ImageObject',
						'url'   => (string) $facts['site_logo_url'],
					);
				}
				$node['publisher'] = $publisher;
			}
			return $node;
		}

		$node = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebPage',
			'name'     => $title,
			'url'      => $url,
		);
		self::put( $node, 'datePublished', $facts['date_published'] ?? null );
		self::put( $node, 'dateModified', $facts['date_modified'] ?? null );
		self::put( $node, 'primaryImageOfPage', $facts['image_url'] ?? null );
		return $node;
	}

	/**
	 * Set a field only when it has a value.
	 *
	 * @param array       $node  Object being built.
	 * @param string      $key   Field.
	 * @param string|null $value Value.
	 */
	private static function put( array &$node, string $key, ?string $value ): void {
		if ( null !== $value && '' !== trim( $value ) ) {
			$node[ $key ] = $value;
		}
	}
}
