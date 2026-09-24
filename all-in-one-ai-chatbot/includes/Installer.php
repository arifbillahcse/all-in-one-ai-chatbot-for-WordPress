<?php
/**
 * Database schema, activation and upgrades.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 *
 * Schema changes go through dbDelta, keyed by DB_VERSION, and are re-checked on
 * every load rather than only on activation — WordPress does not run the
 * activation hook when a plugin is updated in place.
 */
final class Installer {

	public const DB_VERSION        = '2';
	private const DB_VERSION_OPTION = 'softorio_ai_db_version';

	/**
	 * Table names, with the site's prefix applied.
	 *
	 * @return array<string, string>
	 */
	public static function tables(): array {
		global $wpdb;

		return array(
			'chunks'        => $wpdb->prefix . 'softorio_ai_chunks',
			'conversations' => $wpdb->prefix . 'softorio_ai_conversations',
			'messages'      => $wpdb->prefix . 'softorio_ai_messages',
			'limits'        => $wpdb->prefix . 'softorio_ai_limits',
			'jobs'          => $wpdb->prefix . 'softorio_ai_jobs',
			'logs'          => $wpdb->prefix . 'softorio_ai_logs',
		);
	}

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::install_schema();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		// A freshly activated plugin has an empty index. Flag it so the admin
		// screens can point the owner at the "Build index" button, and let
		// cron start on it in the background meanwhile.
		update_option( 'softorio_ai_index_state', array( 'status' => 'pending' ), false );

		PostTypes::register();
		flush_rewrite_rules();

		Cron::schedule();
	}

	/**
	 * Deactivation hook. Data is kept; only scheduled work stops.
	 */
	public static function deactivate(): void {
		Cron::unschedule();
	}

	/**
	 * Run pending schema upgrades. Cheap when there are none: one option read.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install_schema();
		}
	}

	/**
	 * Create or update every table.
	 */
	public static function install_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$t       = self::tables();

		/*
		 * dbDelta is strict about formatting: two spaces after PRIMARY KEY, one
		 * field per line, KEY rather than INDEX. Deviating silently skips the
		 * change on upgrade.
		 */
		dbDelta(
			"CREATE TABLE {$t['chunks']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  chunk_index int(11) unsigned NOT NULL DEFAULT 0,
  title text NOT NULL,
  url varchar(2048) NOT NULL DEFAULT '',
  content longtext NOT NULL,
  search_text longtext NOT NULL,
  token_count int(11) unsigned NOT NULL DEFAULT 0,
  content_hash char(64) NOT NULL DEFAULT '',
  embedding longtext NULL,
  indexed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id)
) $charset;

CREATE TABLE {$t['conversations']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(32) NOT NULL,
  visitor_hash char(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page_url varchar(2048) NOT NULL DEFAULT '',
  title varchar(191) NOT NULL DEFAULT '',
  message_count int(11) unsigned NOT NULL DEFAULT 0,
  total_cost decimal(12,6) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY updated_at (updated_at)
) $charset;

CREATE TABLE {$t['messages']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  role varchar(16) NOT NULL,
  content longtext NOT NULL,
  sources longtext NULL,
  provider varchar(32) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL DEFAULT '',
  input_tokens int(11) unsigned NOT NULL DEFAULT 0,
  output_tokens int(11) unsigned NOT NULL DEFAULT 0,
  cost decimal(12,6) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation_id (conversation_id),
  KEY created_at (created_at)
) $charset;

CREATE TABLE {$t['limits']} (
  bucket char(64) NOT NULL,
  hits int(11) unsigned NOT NULL DEFAULT 0,
  expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket),
  KEY expires_at (expires_at)
) $charset;

CREATE TABLE {$t['jobs']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(64) NOT NULL,
  payload longtext NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  run_at bigint(20) unsigned NOT NULL DEFAULT 0,
  last_error text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_run_at (status,run_at)
) $charset;

CREATE TABLE {$t['logs']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  level varchar(10) NOT NULL,
  channel varchar(32) NOT NULL,
  message varchar(500) NOT NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY channel (channel)
) $charset;"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
