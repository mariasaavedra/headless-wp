<?php
/**
 * Protected file delivery.
 *
 * Problem: WordPress serves everything in wp-content/uploads/ directly through
 * the web server, bypassing PHP — so a raw URL to a Template PDF or brief is
 * downloadable by anyone, even logged out. That breaks the "authenticated
 * platform" promise.
 *
 * Solution (industry-standard pattern):
 *   1. Files attached to CLE content are stored in a dedicated
 *      uploads/pcle-protected/ subdirectory.
 *   2. That directory carries an .htaccess deny (Apache) + index.php guard, and
 *      hosts on nginx add the documented location rule (see docs/DEVELOPMENT.md).
 *   3. All access goes through a PHP endpoint (?pcle_download=<id>) that checks
 *      per-program access before streaming the file.
 *   4. Attachment URLs for protected files are rewritten to that endpoint, so
 *      the raw path is never surfaced.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Name of the protected uploads subdirectory. */
const PCLE_PROTECTED_SUBDIR = 'pcle-protected';

/**
 * Absolute path to the protected uploads directory.
 *
 * @return string
 */
function pcle_protected_basedir() {
	$uploads = wp_get_upload_dir();
	return trailingslashit( $uploads['basedir'] ) . PCLE_PROTECTED_SUBDIR;
}

/**
 * Creates the protected directory with deny rules. Idempotent.
 *
 * Called on plugin activation (and defensively before routing an upload there).
 */
function pcle_ensure_protected_dir() {
	$dir = pcle_protected_basedir();
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	// Apache: block direct HTTP access. (Harmless on nginx; see nginx docs.)
	$htaccess = trailingslashit( $dir ) . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		$rules = "# Platform CLE — block direct access to protected files.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
		@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	// Silence directory listing on any server.
	$index = trailingslashit( $dir ) . 'index.php';
	if ( ! file_exists( $index ) ) {
		@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}
}

/* =========================================================================
 * 1) ROUTE CLE ATTACHMENT UPLOADS INTO THE PROTECTED DIRECTORY
 * ========================================================================= */

/**
 * When a file is uploaded while attached to a protected CLE post, store it in
 * the protected subdirectory instead of the public uploads root.
 *
 * WordPress passes the parent post via $_REQUEST['post_id'] during the
 * async-upload / block-editor media flow.
 *
 * @param array $upload upload_dir() data.
 * @return array
 */
function pcle_route_protected_upload( $upload ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WP core handles the upload nonce.
	$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
	if ( ! $post_id ) {
		return $upload;
	}

	if ( ! in_array( get_post_type( $post_id ), pcle_protected_post_types(), true ) ) {
		return $upload;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return $upload;
	}

	$subdir = '/' . PCLE_PROTECTED_SUBDIR . $upload['subdir'];
	$upload['path']   = $upload['basedir'] . $subdir;
	$upload['url']    = $upload['baseurl'] . $subdir;
	$upload['subdir'] = $subdir;

	pcle_ensure_protected_dir();
	if ( ! is_dir( $upload['path'] ) ) {
		wp_mkdir_p( $upload['path'] );
	}

	return $upload;
}
add_filter( 'upload_dir', 'pcle_route_protected_upload' );

/**
 * Is this attachment stored in the protected directory?
 *
 * @param int $attachment_id Attachment ID.
 * @return bool
 */
function pcle_is_protected_attachment( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file ) {
		return false;
	}
	$base = wp_normalize_path( pcle_protected_basedir() );
	return 0 === strpos( wp_normalize_path( $file ), $base );
}

/* =========================================================================
 * 2) SECURE DOWNLOAD ENDPOINT
 * ========================================================================= */

/**
 * The guarded URL for a protected attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return string
 */
function pcle_protected_file_url( $attachment_id ) {
	return add_query_arg( 'pcle_download', (int) $attachment_id, home_url( '/' ) );
}

/**
 * Rewrites protected attachments' public URLs to the guarded endpoint, so the
 * raw uploads path is never exposed in content or templates.
 *
 * @param string $url           Attachment URL.
 * @param int    $attachment_id Attachment ID.
 * @return string
 */
function pcle_rewrite_protected_attachment_url( $url, $attachment_id ) {
	if ( pcle_is_protected_attachment( $attachment_id ) ) {
		return pcle_protected_file_url( $attachment_id );
	}
	return $url;
}
add_filter( 'wp_get_attachment_url', 'pcle_rewrite_protected_attachment_url', 10, 2 );

/**
 * Handles ?pcle_download=<id>: checks per-program access, then streams the file.
 */
function pcle_handle_protected_download() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, auth is by capability below.
	$attachment_id = isset( $_GET['pcle_download'] ) ? absint( $_GET['pcle_download'] ) : 0;
	if ( ! $attachment_id ) {
		return;
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		pcle_download_deny_404();
	}

	// Only files that physically live in the protected dir are servable here.
	if ( ! pcle_is_protected_attachment( $attachment_id ) ) {
		pcle_download_deny_404();
	}

	// Decide access from the CLE post the file is attached to.
	$parent  = (int) $attachment->post_parent;
	$allowed = false;

	if ( $parent && in_array( get_post_type( $parent ), pcle_protected_post_types(), true ) ) {
		$allowed = pcle_can_access_post( $parent );
	} else {
		// Not attached to CLE content: only staff may fetch it.
		$allowed = pcle_user_is_staff();
	}

	if ( ! $allowed ) {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( pcle_protected_file_url( $attachment_id ) ) );
			exit;
		}
		status_header( 403 );
		wp_die(
			esc_html__( 'You do not have access to this file.', 'platform-cle' ),
			esc_html__( 'Access denied', 'platform-cle' ),
			array( 'response' => 403 )
		);
	}

	$file = get_attached_file( $attachment_id );

	// Path-traversal guard: the resolved path must stay inside the protected dir.
	$real_file = $file ? realpath( $file ) : false;
	$real_base = realpath( pcle_protected_basedir() );
	if ( ! $real_file || ! $real_base || 0 !== strpos( wp_normalize_path( $real_file ), wp_normalize_path( $real_base ) ) || ! is_readable( $real_file ) ) {
		pcle_download_deny_404();
	}

	pcle_stream_file( $real_file, $attachment_id );
}
add_action( 'template_redirect', 'pcle_handle_protected_download' );

/**
 * Sends a 404 and stops (used for invalid/forbidden download requests).
 */
function pcle_download_deny_404() {
	status_header( 404 );
	nocache_headers();
	wp_die(
		esc_html__( 'File not found.', 'platform-cle' ),
		esc_html__( 'Not found', 'platform-cle' ),
		array( 'response' => 404 )
	);
}

/**
 * Streams a file to the browser with appropriate headers.
 *
 * @param string $path          Absolute, validated file path.
 * @param int    $attachment_id Attachment ID (for the download filename).
 */
function pcle_stream_file( $path, $attachment_id ) {
	$mime = get_post_mime_type( $attachment_id );
	$name = basename( $path );
	$size = filesize( $path );

	nocache_headers();
	header( 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) );
	header( 'Content-Disposition: inline; filename="' . rawurlencode( $name ) . '"' );
	if ( false !== $size ) {
		header( 'Content-Length: ' . $size );
	}
	header( 'X-Content-Type-Options: nosniff' );

	// Flush any buffering so readfile streams cleanly.
	while ( ob_get_level() ) {
		ob_end_clean();
	}

	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}
