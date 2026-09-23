<?php
/**
 * Embeds WordPress does not know about on its own.
 *
 * An authored "@ url" line becomes a core embed block (authoring-content.php),
 * and WordPress turns that into a player only for providers on its oEmbed
 * list. Anything else falls back to a bare link. Google Drive is not on the
 * list — it has no oEmbed endpoint — but instructors keep their recordings
 * there, so its file links are matched here and given Drive's own preview
 * player.
 *
 * Only the file ID is taken from the address. The iframe points at a URL we
 * build, so whatever else an author pastes after the ID never reaches the
 * markup.
 *
 * Drive decides who may watch. A file not shared as "Anyone with the link"
 * shows participants a sign-in or "no access" screen inside the frame; that
 * is Drive's permission, not ours to override.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Drive addresses an author is likely to paste.
 *
 *   https://drive.google.com/file/d/<id>/view?usp=sharing
 *   https://drive.google.com/file/d/<id>/preview
 *   https://drive.google.com/open?id=<id>
 */
const PCLE_DRIVE_EMBED_PATTERN = '#https?://drive\.google\.com/(?:file/d/([A-Za-z0-9_-]+)|open\?id=([A-Za-z0-9_-]+))[^\s]*#i';

/**
 * Registers the Drive handler with WordPress's embed machinery.
 */
function pcle_register_embed_handlers() {
	wp_embed_register_handler( 'pcle-google-drive', PCLE_DRIVE_EMBED_PATTERN, 'pcle_embed_google_drive' );
}
add_action( 'init', 'pcle_register_embed_handlers' );

/**
 * Renders a Drive file as Drive's preview player.
 *
 * @param array<int,string> $matches Regex matches; [1] or [2] is the file ID.
 * @return string Iframe markup.
 */
function pcle_embed_google_drive( $matches ) {
	$id = '' !== ( $matches[1] ?? '' ) ? $matches[1] : ( $matches[2] ?? '' );

	if ( '' === $id ) {
		return '';
	}

	return sprintf(
		'<iframe class="pcle-embed-drive" src="%s" width="640" height="360" allow="autoplay; fullscreen" allowfullscreen loading="lazy" title="%s"></iframe>',
		esc_url( 'https://drive.google.com/file/d/' . rawurlencode( $id ) . '/preview' ),
		esc_attr__( 'Google Drive video', 'platform-cle' )
	);
}
