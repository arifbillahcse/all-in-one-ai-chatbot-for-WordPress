<?php
/**
 * Lead persistence.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Leads;

use Softorio\AiAssistant\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the leads table. All lead SQL lives here.
 */
final class LeadStore {

	public const STATUSES = array( 'new', 'contacted', 'won', 'closed' );

	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->table = Installer::tables()['leads'];
	}

	/**
	 * Save a lead.
	 *
	 * @param array<string, mixed> $data Validated fields.
	 * @return int Lead id.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->insert(
			$this->table,
			array(
				'conversation_id' => (int) ( $data['conversation_id'] ?? 0 ),
				'name'            => (string) ( $data['name'] ?? '' ),
				'email'           => (string) ( $data['email'] ?? '' ),
				'phone'           => (string) ( $data['phone'] ?? '' ),
				'message'         => (string) ( $data['message'] ?? '' ),
				'source'          => (string) ( $data['source'] ?? '' ),
				'status'          => 'new',
				'consent'         => ! empty( $data['consent'] ) ? 1 : 0,
				'consent_text'    => (string) ( $data['consent_text'] ?? '' ),
				'page_url'        => (string) ( $data['page_url'] ?? '' ),
				'user_id'         => (int) ( $data['user_id'] ?? 0 ),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * One lead.
	 *
	 * @param int $id Lead id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A page of leads, newest first, with optional search and status filter.
	 *
	 * @param string $search Matches name, email or phone.
	 * @param string $status Status, or '' for all.
	 * @param int    $limit  Rows.
	 * @param int    $offset Offset.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function search( string $search = '', string $status = '', int $limit = 25, int $offset = 0 ): array {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array( $this->table );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR message LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}

		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$sql_where = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- custom table; $sql_where holds fixed fragments.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $sql_where", $args ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $sql_where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Record one CRM's outcome for a lead (merged into the crm column).
	 *
	 * @param int                  $id    Lead id.
	 * @param string               $crm   Connector id.
	 * @param array<string, mixed> $state status, detail, ref, at.
	 */
	public function set_crm( int $id, string $crm, array $state ): void {
		global $wpdb;

		$row = $this->find( $id );

		if ( null === $row ) {
			return;
		}

		$all         = self::crm_state( $row );
		$all[ $crm ] = $state;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->update( $this->table, array( 'crm' => wp_json_encode( $all ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * CRM outcomes stored on a lead row.
	 *
	 * @param array<string, mixed> $row Lead row.
	 * @return array<string, array<string, mixed>>
	 */
	public static function crm_state( array $row ): array {
		$state = json_decode( (string) ( $row['crm'] ?? '' ), true );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Change a lead's status.
	 *
	 * @param int    $id     Lead id.
	 * @param string $status New status.
	 */
	public function set_status( int $id, string $status ): bool {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		return false !== $wpdb->update(
			$this->table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a lead.
	 *
	 * @param int $id Lead id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Every lead with this email address (privacy export/erase).
	 *
	 * @param string $email Email address.
	 * @return array<int, array<string, mixed>>
	 */
	public function by_email( string $email ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id', $this->table, $email ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every lead, oldest first, in batches — for CSV export.
	 *
	 * @param int $batch Rows per query.
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function each( int $batch = 500 ): \Generator {
		global $wpdb;

		$last = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id LIMIT %d', $this->table, $last, $batch ), ARRAY_A );

			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				yield $row;
			}
			$more = count( $rows ) === $batch;
		} while ( $more );
	}

	/**
	 * Count leads created since a moment.
	 *
	 * @param string $since_gmt MySQL datetime, UTC.
	 */
	public function count_since( string $since_gmt ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', $this->table, $since_gmt ) );
	}
}
