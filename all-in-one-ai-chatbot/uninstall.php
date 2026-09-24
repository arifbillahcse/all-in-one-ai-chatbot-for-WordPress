<?php
/**
 * Uninstall: remove data only if the site owner asked for it.
 *
 * Deleting a plugin is often a step in reinstalling it, and Knowledge Articles
 * are content the owner wrote by hand. So data is kept unless "Delete all
 * plugin data" was ticked in the settings.
 *
 * @package Softorio\AiAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$softorio_ai_settings = get_option( 'softorio_ai_settings', array() );

wp_clear_scheduled_hook( 'softorio_ai_daily' );
wp_clear_scheduled_hook( 'softorio_ai_build_index' );
wp_clear_scheduled_hook( 'softorio_ai_index_post' );
wp_clear_scheduled_hook( 'softorio_ai_queue' );

if ( empty( $softorio_ai_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'softorio_ai_chunks', 'softorio_ai_conversations', 'softorio_ai_messages', 'softorio_ai_limits', 'softorio_ai_jobs', 'softorio_ai_logs' ) as $softorio_ai_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $softorio_ai_table ) );
}

$softorio_ai_docs = get_posts(
	array(
		'post_type'   => 'softorio_ai_doc',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);

foreach ( $softorio_ai_docs as $softorio_ai_doc ) {
	wp_delete_post( (int) $softorio_ai_doc, true );
}

delete_post_meta_by_key( '_softorio_ai_exclude' );

foreach ( array( 'softorio_ai_settings', 'softorio_ai_db_version', 'softorio_ai_index_state', 'softorio_ai_last_error', 'softorio_ai_queue_lock' ) as $softorio_ai_option ) {
	delete_option( $softorio_ai_option );
}

delete_transient( 'softorio_ai_index_stats' );
delete_transient( 'softorio_ai_usage_today' );
