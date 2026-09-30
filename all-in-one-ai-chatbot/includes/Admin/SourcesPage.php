<?php
/**
 * Knowledge Sources screen.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Knowledge\Audience;
use Softorio\AiAssistant\Sources\FaqImporter;
use Softorio\AiAssistant\Sources\FileImporter;
use Softorio\AiAssistant\Sources\SourceException;
use Softorio\AiAssistant\Sources\SourceStore;
use Softorio\AiAssistant\Sources\WebImporter;
use Softorio\AiAssistant\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Upload documents, import FAQs from a spreadsheet, import web pages, and
 * see (and manage) everything imported.
 */
final class SourcesPage {

	private const PER_PAGE = 30;

	private const ACTIONS = array(
		'files'  => 'softorio_ai_import_files',
		'faq'    => 'softorio_ai_import_faq',
		'web'    => 'softorio_ai_import_web',
		'sample' => 'softorio_ai_faq_sample',
		'resync' => 'softorio_ai_resync_web',
		'delete' => 'softorio_ai_delete_source',
		'purge'  => 'softorio_ai_purge_sources',
	);

	/**
	 * Hook the form handlers.
	 */
	public static function init(): void {
		foreach ( self::ACTIONS as $key => $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle_' . $key ) );
		}
	}

	/**
	 * Permission and nonce check for every handler.
	 *
	 * @param string $key Action key.
	 */
	private static function guard( string $key ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'all-in-one-ai-chatbot' ), 403 );
		}

		check_admin_referer( self::ACTIONS[ $key ] );
	}

	/**
	 * Back to the screen with a message.
	 *
	 * @param array<int, array{0: string, 1: string}> $notices [type, text] pairs.
	 * @param array<string, string>                   $args    Extra query args.
	 */
	private static function done( array $notices, array $args = array() ): void {
		set_transient( 'softorio_ai_sources_notice_' . get_current_user_id(), $notices, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( Menu::url( 'sources', $args ) );
		exit;
	}

	/**
	 * The audience chosen in a form (only when members-only knowledge is on).
	 */
	private static function posted_audience(): string {
		if ( ! Audience::enabled() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); sanitised by from_picker().
		return Audience::from_picker( isset( $_POST['audience'] ) ? wp_unslash( $_POST['audience'] ) : null );
	}

	/**
	 * Uploaded files, normalised from PHP's per-field arrays.
	 *
	 * @param string $field Input name.
	 * @return array<int, array{name: string, tmp: string, error: int}>
	 */
	private static function uploads( string $field ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$raw = $_FILES[ $field ] ?? null;

		if ( ! is_array( $raw ) || ! isset( $raw['name'] ) ) {
			return array();
		}

		$names = (array) $raw['name'];
		$files = array();

		foreach ( $names as $i => $name ) {
			$files[] = array(
				'name'  => sanitize_file_name( wp_unslash( (string) $name ) ),
				'tmp'   => (string) ( (array) $raw['tmp_name'] )[ $i ],
				'error' => (int) ( (array) $raw['error'] )[ $i ],
			);
		}

		return array_values( array_filter( $files, static fn( array $f ): bool => '' !== $f['name'] || UPLOAD_ERR_NO_FILE !== $f['error'] ) );
	}

	/**
	 * Why an upload failed, in plain words.
	 *
	 * @param int $code PHP upload error.
	 */
	private static function upload_error( int $code ): string {
		return match ( $code ) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf( /* translators: %s: size */ __( 'The file is larger than this server accepts (%s).', 'all-in-one-ai-chatbot' ), size_format( wp_max_upload_size() ) ),
			UPLOAD_ERR_PARTIAL => __( 'The upload was interrupted. Please try again.', 'all-in-one-ai-chatbot' ),
			UPLOAD_ERR_NO_FILE => __( 'Choose a file first.', 'all-in-one-ai-chatbot' ),
			default            => __( 'The server could not store the upload (temporary folder missing or not writable).', 'all-in-one-ai-chatbot' ),
		};
	}

	/**
	 * Import uploaded documents.
	 */
	public static function handle_files(): void {
		self::guard( 'files' );

		$notices  = array();
		$audience = self::posted_audience();
		$files    = self::uploads( 'files' );

		if ( array() === $files ) {
			self::done( array( array( 'error', __( 'Choose at least one file.', 'all-in-one-ai-chatbot' ) ) ) );
		}

		foreach ( array_slice( $files, 0, 20 ) as $file ) {
			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				$notices[] = array( 'error', $file['name'] . ': ' . self::upload_error( $file['error'] ) );
				continue;
			}

			if ( ! is_uploaded_file( $file['tmp'] ) ) {
				continue;
			}

			try {
				[ $post_id, $state, $chars ] = FileImporter::import( $file['tmp'], $file['name'], $audience );

				$notices[] = array(
					'success',
					sprintf(
						/* translators: 1: file name, 2: created/updated/unchanged, 3: number of characters */
						__( '%1$s: %2$s (%3$s characters of text).', 'all-in-one-ai-chatbot' ),
						$file['name'],
						self::state_label( $state ),
						number_format_i18n( $chars )
					),
				);

				Log::info(
					'sources',
					'Imported a document',
					array(
						'file'  => $file['name'],
						'post'  => $post_id,
						'state' => $state,
					)
				);
			} catch ( SourceException $e ) {
				$notices[] = array( 'error', $file['name'] . ': ' . $e->getMessage() );
			}
		}

		self::done( $notices, array( 'type' => 'file' ) );
	}

	/**
	 * Import an FAQ spreadsheet.
	 */
	public static function handle_faq(): void {
		self::guard( 'faq' );

		$file = self::uploads( 'faq' )[0] ?? null;

		if ( null === $file || UPLOAD_ERR_OK !== $file['error'] ) {
			self::done( array( array( 'error', self::upload_error( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) ) );
		}

		if ( ! is_uploaded_file( $file['tmp'] ) ) {
			self::done( array( array( 'error', self::upload_error( UPLOAD_ERR_NO_FILE ) ) ) );
		}

		if ( ! in_array( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ), array( 'csv', 'txt', 'tsv' ), true ) ) {
			self::done( array( array( 'error', __( 'Upload a .csv file. In Excel or Google Sheets use "Save as / Download → CSV (UTF-8)".', 'all-in-one-ai-chatbot' ) ) ) );
		}

		try {
			$parsed = FaqImporter::parse( (string) file_get_contents( $file['tmp'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file.
			$counts = FaqImporter::import( $parsed['rows'], self::posted_audience(), ! empty( $_POST['replace'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		} catch ( SourceException $e ) {
			self::done( array( array( 'error', $e->getMessage() ) ) );
		}

		$text = sprintf(
			/* translators: 1-4: numbers */
			__( 'FAQs imported: %1$d new, %2$d updated, %3$d unchanged, %4$d removed.', 'all-in-one-ai-chatbot' ),
			$counts['created'],
			$counts['updated'],
			$counts['unchanged'],
			$counts['removed']
		);

		if ( $parsed['skipped'] > 0 ) {
			/* translators: %d: number of rows */
			$text .= ' ' . sprintf( _n( '%d row was skipped (empty question or answer).', '%d rows were skipped (empty question or answer).', $parsed['skipped'], 'all-in-one-ai-chatbot' ), $parsed['skipped'] );
		}

		Log::info( 'sources', 'Imported FAQs', $counts );

		self::done( array( array( 'success', $text ) ), array( 'type' => 'faq' ) );
	}

	/**
	 * Queue web pages for import.
	 */
	public static function handle_web(): void {
		self::guard( 'web' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$input   = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';
		$include = isset( $_POST['include'] ) ? sanitize_textarea_field( wp_unslash( $_POST['include'] ) ) : '';
		// phpcs:enable

		$found   = WebImporter::discover( $input, $include );
		$notices = array_map( static fn( string $e ): array => array( 'error', $e ), $found['errors'] );

		if ( array() === $found['urls'] ) {
			$notices[] = array( 'error', __( 'No pages to import. Enter page addresses, a sitemap, or a site address.', 'all-in-one-ai-chatbot' ) );
			self::done( $notices );
		}

		$queued = WebImporter::queue( $found['urls'], self::posted_audience() );

		$notices[] = array(
			'success',
			sprintf(
				/* translators: 1: number of pages, 2: minutes */
				_n( '%1$d page is being imported in the background. This takes about %2$d minute(s); you can leave this screen.', '%1$d pages are being imported in the background. This takes about %2$d minute(s); you can leave this screen.', $queued, 'all-in-one-ai-chatbot' ),
				$queued,
				max( 1, (int) ceil( $queued / 15 ) )
			),
		);

		self::done( $notices, array( 'type' => 'url' ) );
	}

	/**
	 * Download an example FAQ CSV.
	 */
	public static function handle_sample(): void {
		self::guard( 'sample' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="faq-example.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8.

		foreach (
			array(
				array( 'Question', 'Answer' ),
				array( 'How long does delivery take?', 'Inside Dhaka 1–2 days, outside Dhaka 3–5 days.' ),
				array( 'Do you offer cash on delivery?', "Yes, everywhere in Bangladesh.\nPay the rider when your order arrives." ),
				array( 'ডেলিভারি চার্জ কত?', 'ঢাকার ভিতরে ৬০ টাকা, ঢাকার বাইরে ১২০ টাকা।' ),
			) as $row
		) {
			fputcsv( $out, $row, ',', '"', '' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a download.
		exit;
	}

	/**
	 * Re-fetch imported pages now (all, or one).
	 */
	public static function handle_resync(): void {
		self::guard( 'resync' );

		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().

		if ( $post_id > 0 ) {
			$url    = (string) get_post_meta( $post_id, SourceStore::URL, true );
			$queued = '' !== $url ? WebImporter::queue( array( $url ), null ) : 0;
		} else {
			$queued = WebImporter::resync();
		}

		self::done(
			array(
				array(
					'success',
					/* translators: %d: number of pages */
					sprintf( _n( '%d page will be checked again in the background.', '%d pages will be checked again in the background.', $queued, 'all-in-one-ai-chatbot' ), $queued ),
				),
			),
			array( 'type' => 'url' )
		);
	}

	/**
	 * Delete one imported article.
	 */
	public static function handle_delete(): void {
		self::guard( 'delete' );

		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().

		if ( $post_id > 0 && '' !== (string) get_post_meta( $post_id, SourceStore::TYPE, true ) ) {
			wp_delete_post( $post_id, true );
		}

		self::done( array( array( 'success', __( 'Deleted.', 'all-in-one-ai-chatbot' ) ) ) );
	}

	/**
	 * Delete everything imported from one kind of source.
	 */
	public static function handle_purge(): void {
		self::guard( 'purge' );

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().

		if ( ! in_array( $type, SourceStore::TYPES, true ) ) {
			self::done( array() );
		}

		$ids = SourceStore::ids( $type );

		foreach ( $ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		/* translators: %d: number of articles */
		self::done( array( array( 'success', sprintf( _n( '%d article deleted.', '%d articles deleted.', count( $ids ), 'all-in-one-ai-chatbot' ), count( $ids ) ) ) ) );
	}

	/**
	 * Created/updated/unchanged in words.
	 *
	 * @param string $state State.
	 */
	private static function state_label( string $state ): string {
		return match ( $state ) {
			'created' => __( 'added', 'all-in-one-ai-chatbot' ),
			'updated' => __( 'updated', 'all-in-one-ai-chatbot' ),
			default   => __( 'unchanged', 'all-in-one-ai-chatbot' ),
		};
	}

	/**
	 * A small POST button form.
	 *
	 * @param string               $key     Action key.
	 * @param string               $label   Button text.
	 * @param array<string, mixed> $fields  Hidden fields.
	 * @param string               $css_class   Button class.
	 * @param string               $confirm Confirmation question.
	 */
	private static function button( string $key, string $label, array $fields = array(), string $css_class = 'button-link', string $confirm = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sai-inline-form"<?php echo '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ')"' : ''; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTIONS[ $key ] ); ?>">
			<?php wp_nonce_field( self::ACTIONS[ $key ] ); ?>
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="<?php echo esc_attr( $css_class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notices = get_transient( 'softorio_ai_sources_notice_' . get_current_user_id() );
		delete_transient( 'softorio_ai_sources_notice_' . get_current_user_id() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$type  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$type = in_array( $type, SourceStore::TYPES, true ) ? $type : '';

		$counts  = SourceStore::counts();
		$pending = WebImporter::pending();
		$members = Audience::enabled();
		$labels  = array(
			'file' => __( 'Documents', 'all-in-one-ai-chatbot' ),
			'faq'  => __( 'FAQs', 'all-in-one-ai-chatbot' ),
			'url'  => __( 'Web pages', 'all-in-one-ai-chatbot' ),
		);
		?>
		<div class="wrap sai-admin sai-sources">
			<h1><?php esc_html_e( 'Knowledge Sources', 'all-in-one-ai-chatbot' ); ?></h1>
			<p class="description" style="max-width:780px">
				<?php esc_html_e( 'Teach the assistant from documents, FAQ spreadsheets and web pages. Everything you import becomes a Knowledge Article you can read and edit, and is searchable as soon as it is imported. Your published posts and pages are read automatically.', 'all-in-one-ai-chatbot' ); ?>
			</p>

			<?php foreach ( is_array( $notices ) ? $notices : array() as [ $kind, $text ] ) : ?>
				<div class="notice notice-<?php echo 'error' === $kind ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $text ); ?></p></div>
			<?php endforeach; ?>

			<?php if ( $pending > 0 ) : ?>
				<div class="notice notice-info"><p>
					<?php
					/* translators: %d: number of pages */
					echo esc_html( sprintf( _n( '%d web page is still being imported in the background. Reload this screen to see progress.', '%d web pages are still being imported in the background. Reload this screen to see progress.', $pending, 'all-in-one-ai-chatbot' ), $pending ) );
					?>
				</p></div>
			<?php endif; ?>

			<div class="sai-source-grid">
				<div class="sai-card">
					<h2><span class="dashicons dashicons-media-document"></span> <?php esc_html_e( 'Upload documents', 'all-in-one-ai-chatbot' ); ?></h2>
					<p class="description"><?php esc_html_e( 'PDF, Word (.docx), OpenDocument (.odt), text, Markdown or HTML. Price lists, policies, manuals, brochures. Scanned PDFs (pictures of text) cannot be read.', 'all-in-one-ai-chatbot' ); ?></p>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTIONS['files'] ); ?>">
						<?php wp_nonce_field( self::ACTIONS['files'] ); ?>
						<p><input type="file" name="files[]" multiple required accept=".<?php echo esc_attr( implode( ',.', array_keys( FileImporter::TYPES ) ) ); ?>"></p>
						<p class="description">
							<?php
							/* translators: 1: size, 2: number */
							echo esc_html( sprintf( __( 'Up to %1$s per file, %2$d files at a time. Uploading a file with the same name again updates it.', 'all-in-one-ai-chatbot' ), size_format( min( FileImporter::MAX_BYTES, wp_max_upload_size() ) ), 20 ) );
							?>
						</p>
						<?php self::audience_field( $members ); ?>
						<?php submit_button( __( 'Upload and import', 'all-in-one-ai-chatbot' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

				<div class="sai-card">
					<h2><span class="dashicons dashicons-editor-help"></span> <?php esc_html_e( 'Import FAQs', 'all-in-one-ai-chatbot' ); ?></h2>
					<p class="description"><?php esc_html_e( 'A spreadsheet with questions in column A and answers in column B, saved as CSV (UTF-8). Each question becomes its own article. Import it again after editing to update the answers.', 'all-in-one-ai-chatbot' ); ?></p>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTIONS['faq'] ); ?>">
						<?php wp_nonce_field( self::ACTIONS['faq'] ); ?>
						<p><input type="file" name="faq" required accept=".csv,.tsv,.txt"></p>
						<p><label><input type="checkbox" name="replace" value="1"> <?php esc_html_e( 'Remove imported FAQs that are not in this file', 'all-in-one-ai-chatbot' ); ?></label></p>
						<?php self::audience_field( $members ); ?>
						<?php submit_button( __( 'Import FAQs', 'all-in-one-ai-chatbot' ), 'primary', 'submit', false ); ?>
					</form>
					<?php self::button( 'sample', __( 'Download an example CSV', 'all-in-one-ai-chatbot' ) ); ?>
				</div>

				<div class="sai-card">
					<h2><span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'Import from websites', 'all-in-one-ai-chatbot' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Page addresses, a sitemap (.xml), or a site address to import its whole sitemap. Useful for a help centre on another site, or pages built with a page builder. Answers link back to these pages.', 'all-in-one-ai-chatbot' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTIONS['web'] ); ?>">
						<?php wp_nonce_field( self::ACTIONS['web'] ); ?>
						<p><textarea name="urls" rows="4" class="large-text code" required placeholder="https://help.example.com/&#10;https://example.com/sitemap.xml&#10;https://example.com/pricing/"></textarea></p>
						<p>
							<label for="sai-include"><?php esc_html_e( 'Only pages matching (optional)', 'all-in-one-ai-chatbot' ); ?></label>
							<textarea id="sai-include" name="include" rows="2" class="large-text code" placeholder="/docs/*&#10;/help/*"></textarea>
						</p>
						<?php self::audience_field( $members ); ?>
						<?php submit_button( __( 'Import pages', 'all-in-one-ai-chatbot' ), 'primary', 'submit', false ); ?>
					</form>
					<p class="description">
						<?php
						/* translators: %d: number of pages */
						echo esc_html( sprintf( __( 'Up to %d pages per import. robots.txt is respected.', 'all-in-one-ai-chatbot' ), WebImporter::MAX_PAGES ) );
						?>
						<a href="<?php echo esc_url( Menu::url( 'settings', array( 'tab' => 'knowledge' ) ) ); ?>"><?php esc_html_e( 'Automatic re-check', 'all-in-one-ai-chatbot' ); ?></a>
					</p>
				</div>
			</div>

			<h2 class="sai-sources-heading"><?php esc_html_e( 'Imported knowledge', 'all-in-one-ai-chatbot' ); ?></h2>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( Menu::url( 'sources' ) ); ?>" class="<?php echo '' === $type ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'all-in-one-ai-chatbot' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( array_sum( $counts ) ) ); ?>)</span></a> |</li>
				<?php foreach ( $labels as $slug => $label ) : ?>
					<li><a href="<?php echo esc_url( Menu::url( 'sources', array( 'type' => $slug ) ) ); ?>" class="<?php echo $slug === $type ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $slug ] ) ); ?>)</span></a><?php echo 'url' !== $slug ? ' |' : ''; ?></li>
				<?php endforeach; ?>
			</ul>

			<div class="sai-sources-tools">
				<?php
				if ( 'url' === $type && $counts['url'] > 0 ) {
					self::button( 'resync', __( 'Check all pages again now', 'all-in-one-ai-chatbot' ), array(), 'button' );
				}
				if ( '' !== $type && $counts[ $type ] > 0 ) {
					/* translators: %s: Documents / FAQs / Web pages */
					self::button( 'purge', sprintf( __( 'Delete all %s', 'all-in-one-ai-chatbot' ), mb_strtolower( $labels[ $type ] ) ), array( 'type' => $type ), 'button sai-danger', __( 'Delete all of these articles permanently? The assistant will stop using them.', 'all-in-one-ai-chatbot' ) );
				}
				?>
			</div>

			<?php self::table( $type, $paged, $labels, $members ); ?>
		</div>
		<?php
	}

	/**
	 * The audience picker for an import form, when the feature is on.
	 *
	 * @param bool $members Members-only knowledge is on.
	 */
	private static function audience_field( bool $members ): void {
		if ( ! $members ) {
			return;
		}
		?>
		<div class="sai-audience-wrap">
			<strong><?php esc_html_e( 'Who can the assistant share this with?', 'all-in-one-ai-chatbot' ); ?></strong>
			<?php Audience::render_picker( 'audience', '' ); ?>
		</div>
		<?php
	}

	/**
	 * The list of imported articles.
	 *
	 * @param string                $type    Filter.
	 * @param int                   $paged   Page.
	 * @param array<string, string> $labels  Type labels.
	 * @param bool                  $members Show the audience column.
	 */
	private static function table( string $type, int $paged, array $labels, bool $members ): void {
		$result = SourceStore::page( $type, $paged, self::PER_PAGE );

		if ( array() === $result['rows'] ) {
			echo '<p class="sai-empty">' . esc_html__( 'Nothing imported yet.', 'all-in-one-ai-chatbot' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped sai-sources-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'all-in-one-ai-chatbot' ); ?></th>
					<th><?php esc_html_e( 'Source', 'all-in-one-ai-chatbot' ); ?></th>
					<?php if ( $members ) : ?>
						<th><?php esc_html_e( 'Who can see it', 'all-in-one-ai-chatbot' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Updated', 'all-in-one-ai-chatbot' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['rows'] as $post ) : ?>
					<?php
					$kind   = (string) get_post_meta( $post->ID, SourceStore::TYPE, true );
					$url    = (string) get_post_meta( $post->ID, SourceStore::URL, true );
					$status = (string) get_post_meta( $post->ID, SourceStore::STATUS, true );
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></strong>
							<?php if ( 'publish' !== $post->post_status ) : ?>
								<span class="sai-badge sai-badge-muted"><?php echo 'gone' === $status ? esc_html__( 'Page not found — not used', 'all-in-one-ai-chatbot' ) : esc_html__( 'Draft — not used', 'all-in-one-ai-chatbot' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span class="sai-badge"><?php echo esc_html( $labels[ $kind ] ?? $kind ); ?></span>
							<?php if ( 'url' === $kind && '' !== $url ) : ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" class="sai-source-url"><?php echo esc_html( preg_replace( '#^https?://#', '', $url ) ); ?></a>
							<?php elseif ( 'file' === $kind ) : ?>
								<span class="sai-source-url"><?php echo esc_html( (string) get_post_meta( $post->ID, SourceStore::NAME, true ) ); ?></span>
							<?php endif; ?>
						</td>
						<?php if ( $members ) : ?>
							<td><?php echo esc_html( Audience::label( Audience::for_post( $post->ID ) ) ); ?></td>
						<?php endif; ?>
						<td><?php echo esc_html( sprintf( /* translators: %s: time difference */ __( '%s ago', 'all-in-one-ai-chatbot' ), human_time_diff( (int) get_post_modified_time( 'U', true, $post ) ) ) ); ?></td>
						<td class="sai-row-actions">
							<?php
							if ( 'url' === $kind ) {
								self::button( 'resync', __( 'Check again', 'all-in-one-ai-chatbot' ), array( 'post' => $post->ID ) );
							}
							self::button( 'delete', __( 'Delete', 'all-in-one-ai-chatbot' ), array( 'post' => $post->ID ), 'button-link sai-danger-link', __( 'Delete this article permanently?', 'all-in-one-ai-chatbot' ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $result['total'] / self::PER_PAGE );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', Menu::url( 'sources', '' !== $type ? array( 'type' => $type ) : array() ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}
}
