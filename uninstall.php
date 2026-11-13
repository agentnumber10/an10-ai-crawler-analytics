<?php
/**
 * Uninstall. Data is removed only when the site owner asked for it in
 * Settings; otherwise the crawler log and change history are kept so that
 * reinstalling does not lose them.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$an10_settings = (array) get_option( 'an10_settings', array() );
if ( empty( $an10_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'an10_events' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange

foreach ( array( 'an10_settings', 'an10_installed_at', 'an10_ip_lists', 'an10_scan_state', 'an10_changes', 'an10_robots_rules', 'an10_llms_enabled', 'an10_first_verified_at', 'an10_db_version' ) as $an10_option ) {
	delete_option( $an10_option );
}
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_an10\\_%'" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'an10_dismissed_first'" ); // phpcs:ignore WordPress.DB
