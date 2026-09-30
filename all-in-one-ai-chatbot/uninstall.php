<?php
/**
 * Uninstall: remove data only if the site owner asked for it.
 *
 * Deleting a plugin is often a step in reinstalling it, and Knowledge
 * Articles are content the owner wrote by hand. So data is kept unless
 * "Delete all plugin data" was ticked under Settings → Limits & Privacy.
 * Scheduled tasks are always removed.
 *
 * On multisite every site is cleaned, since each has its own tables.
 *
 * @package Softorio\AiAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The table list comes from the class that creates the tables, so the two
// cannot drift apart. It has no dependencies of its own.
require_once __DIR__ . '/includes/Installer.php';

if ( ! function_exists( 'softorio_ai_uninstall_site' ) ) :

	/**
	 * Clean the current site.
	 */
	function softorio_ai_uninstall_site(): void {
		global $wpdb;

		foreach ( array( 'softorio_ai_daily', 'softorio_ai_build_index', 'softorio_ai_index_post', 'softorio_ai_queue', 'softorio_ai_web_resync' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		$settings = get_option( 'softorio_ai_settings', array() );

		if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- removing the plugin's own data.
		foreach ( \Softorio\AiAssistant\Installer::tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own tables.
		}

		// Knowledge Articles, including imported documents, FAQs and web pages.
		do {
			$ids = get_posts(
				array(
					'post_type'        => 'softorio_ai_doc',
					'post_status'      => 'any',
					'numberposts'      => 100,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);

			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		} while ( array() !== $ids );

		// Per-post settings on the site's own pages ("hide from the assistant",
		// "members only") and source details.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_softorio_ai_' ) . '%' ) );

		// Agents' "away" switch.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'softorio_ai_' ) . '%' ) );

		// Settings, state, quick replies, and every transient (cached robots.txt,
		// Telegram message map, typing flags…).
		foreach ( array( 'softorio_ai_', '_transient_softorio_ai_', '_transient_timeout_softorio_ai_' ) as $prefix ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		}
		// phpcs:enable

		wp_cache_flush();
	}

endif;

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $softorio_ai_site ) {
		switch_to_blog( (int) $softorio_ai_site );
		softorio_ai_uninstall_site();
		restore_current_blog();
	}
} else {
	softorio_ai_uninstall_site();
}
