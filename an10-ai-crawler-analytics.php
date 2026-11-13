<?php
/**
 * Plugin Name:       AN10 – AI Crawler Analytics
 * Description:       See which AI crawlers really visit, verified against each operator's published IP list; which pages they have never fetched; the visits AI answers send you; and what stands between your pages and an AI citation. Nothing phones home.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            agentnumber10.com
 * Author URI:        https://agentnumber10.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       an10-ai-crawler-analytics
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

define( 'AN10_VERSION', '0.1.0' );
// The plugin slug. It must equal this file's name, the folder name and the
// text domain; changing it is a search and replace across the plugin.
define( 'AN10_SLUG', 'an10-ai-crawler-analytics' );
define( 'AN10_FILE', __FILE__ );
define( 'AN10_DIR', plugin_dir_path( __FILE__ ) );
define( 'AN10_URL', plugin_dir_url( __FILE__ ) );

require_once AN10_DIR . 'includes/class-an10-plugin.php';

register_activation_hook( __FILE__, array( 'AN10_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AN10_Plugin', 'deactivate' ) );

AN10_Plugin::boot();
