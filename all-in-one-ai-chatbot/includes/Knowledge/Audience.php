<?php
/**
 * Who a piece of knowledge is for, and who is asking.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Knowledge;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Members-only knowledge.
 *
 * Every indexed chunk carries an audience marker:
 *
 *   ''                   everyone
 *   'members'            any logged-in user
 *   ',customer,gold,'    logged-in users with one of these roles
 *
 * A search runs "as" an Audience, which turns into a SQL condition on that
 * marker. Filtering happens in the query itself, so a members-only passage
 * never reaches the prompt of a guest, whatever the ranking does.
 *
 * Guests only ever see '' chunks, whether or not the feature is on: switching
 * members-only knowledge off hides restricted content from everyone rather
 * than making it public.
 */
final class Audience {

	public const META = '_softorio_ai_audience';

	/**
	 * Constructor. Use the named constructors.
	 *
	 * @param bool               $all    Sees everything (admin previews).
	 * @param bool               $member Logged in.
	 * @param array<int, string> $roles  The user's roles.
	 */
	private function __construct(
		public readonly bool $all,
		public readonly bool $member,
		public readonly array $roles,
	) {
	}

	/**
	 * A visitor who is not logged in.
	 */
	public static function guest(): self {
		return new self( false, false, array() );
	}

	/**
	 * No filter at all: the admin's "what would the assistant find" tool.
	 */
	public static function everything(): self {
		return new self( true, true, array() );
	}

	/**
	 * The visitor behind a (server-verified) user id.
	 *
	 * @param int $user_id User id, 0 for guests.
	 */
	public static function for_user( int $user_id ): self {
		if ( $user_id <= 0 || ! self::enabled() ) {
			return self::guest();
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof \WP_User ) {
			return self::guest();
		}

		return new self( false, true, array_values( array_map( 'sanitize_key', (array) $user->roles ) ) );
	}

	/**
	 * Whether members-only knowledge is switched on.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'members_knowledge', false );
	}

	/**
	 * SQL condition (with its own placeholders) limiting chunks to what this
	 * visitor may see, or '' for no limit.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	public function where(): array {
		global $wpdb;

		if ( $this->all ) {
			return array( '', array() );
		}

		if ( ! $this->member ) {
			return array( "audience = ''", array() );
		}

		$clauses = array( "audience = ''", "audience = 'members'" );
		$args    = array();

		foreach ( $this->roles as $role ) {
			$clauses[] = 'audience LIKE %s';
			$args[]    = '%,' . $wpdb->esc_like( $role ) . ',%';
		}

		return array( '(' . implode( ' OR ', $clauses ) . ')', $args );
	}

	/**
	 * The stored marker for a post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function for_post( int $post_id ): string {
		return self::normalise( (string) get_post_meta( $post_id, self::META, true ) );
	}

	/**
	 * Clean a marker: '', 'members' or ',role,role,' (known roles only).
	 *
	 * @param mixed $value Marker, or an array of role slugs.
	 */
	public static function normalise( mixed $value ): string {
		if ( is_array( $value ) ) {
			$roles = $value;
		} else {
			$value = trim( (string) $value );

			if ( '' === $value || 'everyone' === $value ) {
				return '';
			}

			if ( 'members' === $value ) {
				return 'members';
			}

			$roles = explode( ',', $value );
		}

		$known = array_keys( wp_roles()->get_names() );
		$roles = array_values( array_unique( array_intersect( array_map( 'sanitize_key', array_map( 'strval', $roles ) ), $known ) ) );
		sort( $roles );

		return array() === $roles ? '' : ',' . implode( ',', $roles ) . ',';
	}

	/**
	 * Save a post's marker.
	 *
	 * @param int   $post_id Post id.
	 * @param mixed $value   Marker or role list.
	 */
	public static function set( int $post_id, mixed $value ): void {
		$marker = self::normalise( $value );

		if ( '' === $marker ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $marker );
		}
	}

	/**
	 * Role slugs in a marker.
	 *
	 * @param string $marker Marker.
	 * @return array<int, string>
	 */
	public static function roles( string $marker ): array {
		return 'members' === $marker || '' === $marker ? array() : array_values( array_filter( explode( ',', $marker ) ) );
	}

	/**
	 * A short label for admin screens.
	 *
	 * @param string $marker Marker.
	 */
	public static function label( string $marker ): string {
		if ( '' === $marker ) {
			return __( 'Everyone', 'all-in-one-ai-chatbot' );
		}

		if ( 'members' === $marker ) {
			return __( 'Logged-in users', 'all-in-one-ai-chatbot' );
		}

		$names = wp_roles()->get_names();

		return implode(
			', ',
			array_map( static fn( string $r ): string => translate_user_role( $names[ $r ] ?? $r ), self::roles( $marker ) )
		);
	}

	/**
	 * Render the audience picker (used in the editor box and the import forms).
	 *
	 * @param string $name   Form field base name.
	 * @param string $marker Current marker.
	 */
	public static function render_picker( string $name, string $marker ): void {
		$mode  = '' === $marker ? 'everyone' : ( 'members' === $marker ? 'members' : 'roles' );
		$roles = self::roles( $marker );
		?>
		<fieldset class="sai-audience">
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="everyone" <?php checked( 'everyone', $mode ); ?>> <?php esc_html_e( 'Everyone', 'all-in-one-ai-chatbot' ); ?></label><br>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="members" <?php checked( 'members', $mode ); ?>> <?php esc_html_e( 'Logged-in users only', 'all-in-one-ai-chatbot' ); ?></label><br>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="roles" <?php checked( 'roles', $mode ); ?>> <?php esc_html_e( 'Only these roles:', 'all-in-one-ai-chatbot' ); ?></label>
			<?php // Inline layout: the editor sidebar does not load the plugin's admin CSS. ?>
			<span class="sai-audience-roles" style="display:block;margin:2px 0 0 22px">
				<?php foreach ( wp_roles()->get_names() as $slug => $role_name ) : ?>
					<label style="display:inline-block;margin-right:10px;white-space:nowrap"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $roles, true ) ); ?>> <?php echo esc_html( translate_user_role( $role_name ) ); ?></label>
				<?php endforeach; ?>
			</span>
		</fieldset>
		<?php
	}

	/**
	 * Read the picker's submitted value.
	 *
	 * @param mixed $input The picker's array ($_POST[name]), already unslashed.
	 */
	public static function from_picker( mixed $input ): string {
		if ( ! is_array( $input ) ) {
			return '';
		}

		$mode = (string) ( $input['mode'] ?? 'everyone' );

		if ( 'members' === $mode ) {
			return 'members';
		}

		if ( 'roles' === $mode ) {
			$marker = self::normalise( is_array( $input['roles'] ?? null ) ? $input['roles'] : array() );

			// "Only these roles" with none ticked must not fall open to
			// everyone; the closest safe reading is "logged-in users".
			return '' === $marker ? 'members' : $marker;
		}

		return '';
	}
}
