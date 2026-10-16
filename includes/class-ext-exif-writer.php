<?php
/**
 * Extroverting R2 Media — ExifTool metadata writer.
 *
 * Writes AI-generated enrichment metadata back into the source media file
 * using the exiftool command-line utility:
 *
 *   PNG   : Description, Title, Collection (stored as PNG tEXt chunks)
 *   JPEG  : EXIF ImageDescription
 *   XMP   : Description, ImageTitle, Categories, ModifyDate,
 *           AltTextAccessibility-en-US (mirrors the Description value)
 *
 * Every argument is escaped individually (escapeshellarg) — enrichment values
 * are never interpolated into a raw command string.
 *
 * @package Extroverting_R2_Media
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Ext_Exif_Writer
 */
class Ext_Exif_Writer {

	/**
	 * ExifTool binary name or absolute path.
	 *
	 * @var string
	 */
	private $binary;

	/**
	 * Constructor.
	 *
	 * @param string|null $binary Optional binary override; falls back to the
	 *                            ext_exiftool_binary option, then PATH lookup.
	 */
	public function __construct( $binary = null ) {
		if ( null === $binary ) {
			$binary = get_option( 'ext_exiftool_binary', 'exiftool' );
			$binary = apply_filters( 'ext_r2_media_exiftool_binary', $binary );
		}
		$this->binary = $binary;
	}

	/**
	 * Write enrichment metadata into the source file.
	 *
	 * @param string $file_path  Absolute path to the media file.
	 * @param array  $enrichment Enrichment payload from the Worker
	 *                           (description, title, tags, categories, alt_text).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function write_metadata_to_file( $file_path, $enrichment ) {
		$real_path = $this->validate_file( $file_path );
		if ( is_wp_error( $real_path ) ) {
			return $real_path;
		}

		$binary = $this->resolve_binary();
		if ( is_wp_error( $binary ) ) {
			return $binary;
		}

		$args = $this->build_args( $real_path, $enrichment );
		if ( empty( $args ) ) {
			return new WP_Error(
				'ext_exif_nothing_to_write',
				'Enrichment payload contained no writable metadata for this file type.'
			);
		}

		// Each element escaped individually; nothing is interpolated raw.
		$cmd = escapeshellarg( $binary );
		foreach ( $args as $arg ) {
			$cmd .= ' ' . escapeshellarg( $arg );
		}
		$cmd .= ' ' . escapeshellarg( $real_path ) . ' 2>&1';

		exec( $cmd, $output, $exit_code );

		if ( 0 === $exit_code ) {
			return true;
		}

		// Exit code 2 = warnings only. Partial success if the file was still
		// updated (e.g. PNG-only tags skipped on a JPEG while XMP was written).
		if ( 2 === $exit_code && $this->output_says_updated( $output ) ) {
			return true;
		}

		return new WP_Error(
			'ext_exif_write_failed',
			'exiftool exited ' . $exit_code . ': ' . implode( "\n", (array) $output )
		);
	}

	/**
	 * Validate the target file.
	 *
	 * @param string $file_path Candidate path.
	 * @return string|WP_Error Realpath, or WP_Error.
	 */
	private function validate_file( $file_path ) {
		if ( ! is_string( $file_path ) || '' === $file_path ) {
			return new WP_Error( 'ext_exif_no_file', 'No file path provided.' );
		}

		$real_path = realpath( $file_path );
		if ( false === $real_path || ! is_file( $real_path ) ) {
			return new WP_Error( 'ext_exif_missing_file', 'File does not exist: ' . $file_path );
		}

		if ( ! is_writable( $real_path ) ) {
			return new WP_Error( 'ext_exif_not_writable', 'File is not writable: ' . $real_path );
		}

		return $real_path;
	}

	/**
	 * Resolve the exiftool binary.
	 *
	 * @return string|WP_Error Executable path, or WP_Error.
	 */
	private function resolve_binary() {
		$bin = trim( (string) $this->binary );

		if ( '' === $bin ) {
			return new WP_Error( 'ext_exif_binary_missing', 'No exiftool binary configured.' );
		}

		// Absolute path: verify directly.
		if ( 0 === strpos( $bin, '/' ) ) {
			if ( is_executable( $bin ) ) {
				return $bin;
			}
			return new WP_Error( 'ext_exif_binary_missing', 'exiftool binary not executable at: ' . $bin );
		}

		// Bare name: resolve against PATH.
		exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null', $resolved, $code );
		if ( 0 !== $code || empty( $resolved[0] ) ) {
			return new WP_Error( 'ext_exif_binary_missing', 'exiftool binary not found on PATH: ' . $bin );
		}

		return $resolved[0];
	}

	/**
	 * Build the exiftool argument list for the enrichment payload.
	 *
	 * @param string $real_path  Validated file path (used for format detection).
	 * @param array  $enrichment Enrichment payload.
	 * @return string[] exiftool arguments (before and after the filename).
	 */
	private function build_args( $real_path, $enrichment ) {
		$description = $this->string_field( $enrichment, 'description' );
		$alt_text    = $this->string_field( $enrichment, 'alt_text' );
		$title       = $this->string_field( $enrichment, 'title' );
		$tags        = $this->list_field( $enrichment, 'tags' );
		$categories  = $this->list_field( $enrichment, 'categories' );

		// The Description value doubles as the accessibility alt text when the
		// Worker did not return a dedicated alt_text.
		$accessibility_alt = '' !== $alt_text ? $alt_text : $description;

		$args      = [];
		$extension = strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) );

		// PNG native fields (exiftool stores these as tEXt chunks).
		if ( in_array( $extension, [ 'png', 'apng' ], true ) ) {
			if ( '' !== $description ) {
				$args[] = '-PNG:Description=' . $description;
			}
			if ( '' !== $title ) {
				$args[] = '-PNG:Title=' . $title;
			}
			if ( ! empty( $tags ) ) {
				$args[] = '-PNG:Collection=' . implode( ', ', $tags );
			}
		}

		// EXIF description for JPEG/TIFF sources.
		if ( in_array( $extension, [ 'jpg', 'jpeg', 'tif', 'tiff' ], true ) && '' !== $description ) {
			$args[] = '-EXIF:ImageDescription=' . $description;
		}

		// XMP fields embedded in the file itself.
		if ( in_array( $extension, [ 'png', 'apng', 'jpg', 'jpeg', 'tif', 'tiff', 'webp' ], true ) ) {
			if ( '' !== $description ) {
				$args[] = '-XMP:Description=' . $description;
			}
			if ( '' !== $title ) {
				$args[] = '-XMP:ImageTitle=' . $title;
			}
			if ( '' !== $accessibility_alt ) {
				$args[] = '-XMP:AltTextAccessibility-en-US=' . $accessibility_alt;
			}
			// XMP Categories is a plain string tag — one joined, escaped assignment
			// replaces cleanly, keeping re-enrichment idempotent.
			$category_values = array_unique( array_merge( $categories, $tags ) );
			if ( ! empty( $category_values ) ) {
				$args[] = '-XMP:Categories=' . implode( ', ', $category_values );
			}
			$args[] = '-XMP:ModifyDate=' . $this->current_xmp_date();
		}

		return $args;
	}

	/**
	 * Current datetime in XMP (ISO 8601) format.
	 *
	 * @return string
	 */
	private function current_xmp_date() {
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( 'Y-m-d\TH:i:sP' );
		}

		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Read a single string field from the enrichment payload.
	 *
	 * @param array  $enrichment Enrichment payload.
	 * @param string $key        Field key.
	 * @return string Trimmed value, or '' when absent.
	 */
	private function string_field( $enrichment, $key ) {
		if ( empty( $enrichment[ $key ] ) || ! is_string( $enrichment[ $key ] ) ) {
			return '';
		}

		return trim( $enrichment[ $key ] );
	}

	/**
	 * Read a list field from the enrichment payload.
	 *
	 * @param array  $enrichment Enrichment payload.
	 * @param string $key        Field key.
	 * @return string[] Non-empty trimmed items.
	 */
	private function list_field( $enrichment, $key ) {
		if ( empty( $enrichment[ $key ] ) || ! is_array( $enrichment[ $key ] ) ) {
			return [];
		}

		$items = [];
		foreach ( $enrichment[ $key ] as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$items[] = trim( $item );
			}
		}

		return $items;
	}

	/**
	 * Whether exiftool output reports the file was updated or created.
	 *
	 * @param array $output exiftool stdout/stderr lines.
	 * @return bool
	 */
	private function output_says_updated( $output ) {
		return (bool) preg_grep(
			'/[1-9]\d* (image files?|files?) (updated|created)/',
			(array) $output
		);
	}
}