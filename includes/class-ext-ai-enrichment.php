<?php
/**
 * Extroverting R2 Media — AI Enrichment
 *
 * Handles AI-powered metadata enrichment for media files using Cloudflare
 * Workers AI. Requests image descriptions, titles, and tags from the
 * Cloudflare Worker, then writes the metadata back to source files using
 * exiftool (Ext_Exif_Writer).
 *
 * @package Extroverting_R2_Media
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Ext_AI_Enrichment
 */
class Ext_AI_Enrichment {

	/**
	 * Cloudflare Worker URL
	 *
	 * @var string
	 */
	private $worker_url;

	/**
	 * Worker authentication token
	 *
	 * @var string
	 */
	private $worker_token;

	/**
	 * ExifWriter instance
	 *
	 * @var Ext_Exif_Writer
	 */
	private $exif_writer;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->worker_url   = $this->get_option( 'ext_ai_worker_url', 'https://extroverting-media-enrichment.steve-8da.workers.dev' );
		$this->worker_token = $this->get_option( 'ext_ai_worker_token', '' );
		$this->exif_writer  = new Ext_Exif_Writer();

		// Hook into WordPress media upload.
		add_action( 'add_attachment', [ $this, 'handle_new_attachment' ] );
		add_action( 'edit_attachment', [ $this, 'handle_attachment_update' ] );

		// Async single-item processing (scheduled in schedule_enrichment()).
		add_action( 'ext_ai_enrich_single', [ $this, 'enrich_attachment' ] );

		// Add admin menu for manual enrichment.
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );

		// WP-Cron for batch processing.
		add_action( 'ext_ai_enrichment_cron', [ $this, 'batch_enrichment_job' ] );
	}

	/**
	 * Handle new attachment upload
	 *
	 * @param int $attachment_id WordPress attachment ID
	 */
	public function handle_new_attachment( $attachment_id ) {
		// Only process images.
		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! $this->is_supported_image( $mime_type ) ) {
			return;
		}

		// Schedule async enrichment.
		$this->schedule_enrichment( $attachment_id );
	}

	/**
	 * Handle attachment update
	 *
	 * @param int $attachment_id WordPress attachment ID
	 */
	public function handle_attachment_update( $attachment_id ) {
		// Check if enrichment is needed.
		$needs_enrichment = get_post_meta( $attachment_id, '_ext_ai_needs_enrichment', true );
		if ( $needs_enrichment ) {
			$this->schedule_enrichment( $attachment_id );
		}
	}

	/**
	 * Schedule AI enrichment for an attachment
	 *
	 * @param int $attachment_id WordPress attachment ID
	 */
	public function schedule_enrichment( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return;
		}

		// Mark as pending enrichment.
		update_post_meta( $attachment_id, '_ext_ai_status', 'pending' );
		update_post_meta( $attachment_id, '_ext_ai_enqueued', time() );

		// Use WP-Cron for async processing.
		wp_schedule_single_event( time(), 'ext_ai_enrich_single', [ $attachment_id ] );
	}

	/**
	 * Enrich a single attachment
	 *
	 * @param int $attachment_id WordPress attachment ID
	 * @return array Enrichment result
	 */
	public function enrich_attachment( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		$filename  = basename( $file_path );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return [ 'error' => 'File not found' ];
		}

		// Prepare image: downscale large originals before the API call.
		$image_data = $this->prepare_image_data( $file_path );
		if ( false === $image_data ) {
			return [ 'error' => 'Failed to read file' ];
		}

		// Encode as base64 for API request.
		$image_base64 = base64_encode( $image_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		// Request enrichment from Cloudflare Worker.
		$enrichment = $this->request_worker_enrichment( $image_base64, $filename, $attachment_id );

		if ( is_wp_error( $enrichment ) ) {
			update_post_meta( $attachment_id, '_ext_ai_status', 'failed' );
			update_post_meta( $attachment_id, '_ext_ai_error', $enrichment->get_error_message() );
			return [ 'error' => $enrichment->get_error_message() ];
		}

		// Save enrichment metadata to WordPress.
		$this->save_enrichment_meta( $attachment_id, $enrichment );

		// Write metadata back to source file using exiftool.
		$write_result = $this->exif_writer->write_metadata_to_file( $file_path, $enrichment );

		if ( is_wp_error( $write_result ) ) {
			error_log( 'extroverting R2 media: exiftool write failed: ' . $write_result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			update_post_meta( $attachment_id, '_ext_ai_exif_status', 'failed' );
		} else {
			update_post_meta( $attachment_id, '_ext_ai_exif_status', 'success' );
			update_post_meta( $attachment_id, '_ext_ai_exif_written', current_time( 'mysql' ) );
		}

		// Update status.
		update_post_meta( $attachment_id, '_ext_ai_status', 'completed' );
		update_post_meta( $attachment_id, '_ext_ai_enriched', current_time( 'mysql' ) );
		delete_post_meta( $attachment_id, '_ext_ai_needs_enrichment' );

		return $enrichment;
	}

	/**
	 * Request enrichment from Cloudflare Worker
	 *
	 * @param string $image_base64 Base64-encoded image data
	 * @param string $filename     Original filename
	 * @param int    $media_id     WordPress attachment ID
	 * @return array|WP_Error Enrichment result or error
	 */
	private function request_worker_enrichment( $image_base64, $filename, $media_id ) {
		$body = wp_json_encode(
			[
				'image'    => $image_base64,
				'filename' => $filename,
				'media_id' => $media_id,
			]
		);

		$args = [
			'method'  => 'POST',
			'body'    => $body,
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'timeout' => 120, // AI requests on large originals can take a while.
		];

		if ( ! empty( $this->worker_token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->worker_token;
		}

		$response = wp_remote_post( $this->worker_url . '/enrich', $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error( 'worker_error', 'Worker returned status ' . $status_code );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['enrichment'] ) ) {
			return new WP_Error( 'worker_error', 'Invalid worker response' );
		}

		return $data['enrichment'];
	}

	/**
	 * Save enrichment metadata to WordPress post meta
	 *
	 * @param int   $attachment_id WordPress attachment ID
	 * @param array $enrichment    Enrichment data from Worker
	 */
	private function save_enrichment_meta( $attachment_id, $enrichment ) {
		// Save AI-generated description.
		if ( ! empty( $enrichment['description'] ) ) {
			update_post_meta( $attachment_id, '_ext_ai_description', $enrichment['description'] );
			// Also update WordPress post content for media.
			wp_update_post(
				[
					'ID'           => $attachment_id,
					'post_content' => $enrichment['description'],
				]
			);
		}

		// Save AI-generated title.
		if ( ! empty( $enrichment['title'] ) ) {
			update_post_meta( $attachment_id, '_ext_ai_title', $enrichment['title'] );
			// Also update WordPress post title.
			wp_update_post(
				[
					'ID'         => $attachment_id,
					'post_title' => $enrichment['title'],
				]
			);
		}

		// Save tags.
		if ( ! empty( $enrichment['tags'] ) ) {
			update_post_meta( $attachment_id, '_ext_ai_tags', $enrichment['tags'] );
			// Add as WordPress tags.
			wp_set_post_tags( $attachment_id, $enrichment['tags'] );
		}

		// Save categories.
		if ( ! empty( $enrichment['categories'] ) ) {
			update_post_meta( $attachment_id, '_ext_ai_categories', $enrichment['categories'] );
		}

		// Save alt text.
		if ( ! empty( $enrichment['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_ext_ai_alt_text', $enrichment['alt_text'] );
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $enrichment['alt_text'] );
		}

		// Save objects detected.
		if ( ! empty( $enrichment['objects'] ) ) {
			update_post_meta( $attachment_id, '_ext_ai_objects', $enrichment['objects'] );
		}

		// Save full enrichment data.
		update_post_meta( $attachment_id, '_ext_ai_enrichment', $enrichment );
	}

	/**
	 * Prepare the image payload for the Worker: full-resolution originals
	 * (multi-MB screenshots) inflate ~33% in base64 and make vision inference
	 * slow. Downscale to max 1024px JPEG q82 in a temp file; fall back to the
	 * raw bytes if the editor is unavailable.
	 *
	 * @param string $file_path Absolute path to the media file.
	 * @return string|false Image bytes, or false on failure.
	 */
	private function prepare_image_data( $file_path ) {
		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$size = $editor->get_size();
		if ( ! is_wp_error( $size ) && max( $size['width'], $size['height'] ) > 1024 ) {
			$editor->resize( 1024, 1024, false );
		}
		$editor->set_quality( 82 );

		$tmp   = trailingslashit( get_temp_dir() ) . uniqid( 'ext-ai-' ) . '.jpg';
		$saved = $editor->save( $tmp, 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$data = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unlink( $saved['path'] );

		return $data;
	}

	/**
	 * Check if MIME type is a supported image format
	 *
	 * @param string $mime_type MIME type
	 * @return bool
	 */
	private function is_supported_image( $mime_type ) {
		return in_array(
			$mime_type,
			[
				'image/jpeg',
				'image/png',
				'image/gif',
				'image/webp',
			],
			true
		);
	}

	/**
	 * Batch enrichment job (WP-Cron)
	 */
	public function batch_enrichment_job() {
		$attachments = get_posts(
			[
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 10, // Process 10 at a time.
				'meta_key'    => '_ext_ai_status', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		foreach ( $attachments as $attachment ) {
			$this->enrich_attachment( $attachment->ID );
		}
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'upload.php',
			'AI Enrichment',
			'AI Enrichment',
			'manage_options',
			'ext-ai-enrichment',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		// Handle connection self-test (nonce-checked) before any output.
		$test_notice = '';
		if ( isset( $_GET['ext_test_worker'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( current_user_can( 'manage_options' ) && wp_verify_nonce( $nonce, 'ext_test_worker' ) ) {
				$test_notice = $this->run_connection_test();
			} else {
				$test_notice = 'Connection test failed: invalid request.';
			}
		}

		// Handle retry action (nonce-checked) before any output.
		$retry_notice = '';
		if ( isset( $_GET['ext_retry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$retry_id = absint( $_GET['ext_retry'] );
			$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( $retry_id && current_user_can( 'manage_options' ) && wp_verify_nonce( $nonce, 'ext_retry_' . $retry_id ) ) {
				delete_post_meta( $retry_id, '_ext_ai_error' );
				update_post_meta( $retry_id, '_ext_ai_status', 'pending' );
				wp_schedule_single_event( time() + 5, 'ext_ai_enrich_single', [ $retry_id ] );
				spawn_cron();
				$retry_notice = 'Attachment ' . $retry_id . ' re-queued for enrichment — refresh in a moment to see the result.';
			} else {
				$retry_notice = 'Retry failed: invalid request.';
			}
		}
		?>
		<div class="wrap">
			<h1>AI Media Enrichment
				<a class="button button-secondary" style="vertical-align:middle" href="<?php echo esc_url( wp_nonce_url( admin_url( 'upload.php?page=ext-ai-enrichment&ext_test_worker=1' ), 'ext_test_worker' ) ); ?>">Test Worker Connection</a>
			</h1>
			<?php if ( $retry_notice || $test_notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( trim( $retry_notice . ' ' . $test_notice ) ); ?></p></div>
			<?php endif; ?>
			<p>Cloudflare Workers AI enrichment status for media library.</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>Media</th>
						<th>Status</th>
						<th>Description</th>
						<th>Tags</th>
						<th>EXIF Written</th>
						<th>Error</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$attachments = get_posts(
						[
							'post_type'   => 'attachment',
							'post_status' => 'inherit',
							'numberposts' => 50,
							'meta_key'    => '_ext_ai_status', // phpcs:ignore WordPress.DB.SlowDBQuery
						]
					);

					foreach ( $attachments as $attachment ) {
						$status      = get_post_meta( $attachment->ID, '_ext_ai_status', true );
						$description = get_post_meta( $attachment->ID, '_ext_ai_description', true );
						$tags        = get_post_meta( $attachment->ID, '_ext_ai_tags', true );
						$exif_status = get_post_meta( $attachment->ID, '_ext_ai_exif_status', true );
						$error       = get_post_meta( $attachment->ID, '_ext_ai_error', true );

						$status_label = 'Not processed';
						if ( 'pending' === $status ) {
							$status_label = 'Pending';
						}
						if ( 'completed' === $status ) {
							$status_label = 'Completed';
						}
						if ( 'failed' === $status ) {
							$status_label = 'Failed';
						}

						$retry_link = '';
						if ( 'failed' === $status ) {
							$retry_link = ' <a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'upload.php?page=ext-ai-enrichment&ext_retry=' . $attachment->ID ), 'ext_retry_' . $attachment->ID ) ) . '">Retry</a>';
						}

						$exif_label = 'No';
						if ( 'success' === $exif_status ) {
							$exif_label = 'Yes';
						}
						if ( 'failed' === $exif_status ) {
							$exif_label = 'Failed';
						}

						echo '<tr>';
						echo '<td>' . esc_html( $attachment->ID ) . '</td>';
						echo '<td>' . esc_html( $attachment->post_title ) . '</td>';
						echo '<td>' . esc_html( $status_label ) . $retry_link . '</td>';
						echo '<td>' . esc_html( substr( $description, 0, 50 ) ) . '</td>';
						echo '<td>' . esc_html( is_array( $tags ) ? implode( ', ', $tags ) : '' ) . '</td>';
						echo '<td>' . esc_html( $exif_label ) . '</td>';
						echo '<td>' . esc_html( mb_substr( (string) $error, 0, 200 ) ) . '</td>';
						echo '</tr>';
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * One-click diagnostic: is the Worker reachable, and does the stored token
	 * authenticate? GET /metadata/<test> must return 404 (reachable); POST
	 * /enrich with an empty image must return 400 (token accepted) — a 401
	 * means the stored token does not match the deployed WORKER_TOKEN secret.
	 *
	 * @return string Human-readable verdict.
	 */
	private function run_connection_test() {
		$lines = [];

		$get = wp_remote_get( trailingslashit( $this->worker_url ) . 'metadata/__ext_conn_test__', [ 'timeout' => 15 ] );
		if ( is_wp_error( $get ) ) {
			return 'UNREACHABLE: ' . $get->get_error_message() . ' — the host cannot reach the Worker URL.';
		}
		$get_code = wp_remote_retrieve_response_code( $get );
		$lines[]  = 'Reachability: HTTP ' . $get_code . ( 404 === $get_code ? ' (expected — Worker reachable)' : ' (unexpected)' );

		$headers = [ 'Content-Type' => 'application/json' ];
		if ( ! empty( $this->worker_token ) ) {
			$headers['Authorization'] = 'Bearer ' . $this->worker_token;
		}
		$post = wp_remote_post(
			$this->worker_url . '/enrich',
			[
				'timeout' => 15,
				'headers' => $headers,
				'body'    => wp_json_encode(
					[
						'image'    => '',
						'filename' => '__ext_conn_test__',
						'media_id' => 0,
					]
				),
			]
		);
		if ( is_wp_error( $post ) ) {
			$lines[] = 'Enrich/auth: ' . $post->get_error_message();
		} else {
			$post_code = wp_remote_retrieve_response_code( $post );
			$verdicts  = [
				400 => 'OK — token accepted (route and auth working)',
				401 => 'TOKEN MISMATCH — stored Worker Token does not match the deployed WORKER_TOKEN secret',
				404 => 'Route not found — check the Worker URL is exactly the workers.dev URL with no extra path',
			];
			$lines[]  = 'Auth check: HTTP ' . $post_code . ' — ' . ( $verdicts[ $post_code ] ?? 'unexpected response: ' . substr( (string) wp_remote_retrieve_body( $post ), 0, 120 ) );
		}

		return implode( ' | ', $lines );
	}

	/**
	 * Get option from WordPress
	 *
	 * @param string $key     Option key
	 * @param mixed  $default Default value
	 * @return mixed
	 */
	private function get_option( $key, $default = '' ) {
		return get_option( $key, $default );
	}
}