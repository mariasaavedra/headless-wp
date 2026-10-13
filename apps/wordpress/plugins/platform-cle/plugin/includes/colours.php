<?php
/**
 * Platform CLE colours.
 *
 * An author can give a programme, or anything inside it, a colour. What is
 * stored is the NAME of a swatch ("teal-strong"), never a colour value: the
 * web app owns what each name looks like (apps/web/src/lib/colours.ts), so a
 * shade can be retuned there without migrating anything here.
 *
 * Only an item's OWN colour is stored. An item without one shows its parent's,
 * and so on up to the programme; pcle_get_effective_colour() walks that chain
 * for the participant routes, and the builder works it out from the tree it
 * already has.
 *
 * @package Platform_CLE
 */

defined( 'ABSPATH' ) || exit;

/** Post meta holding an item's own colour. Absent means it inherits. */
const PCLE_COLOUR_META = '_pcle_colour';

/**
 * Every swatch name an item may be given.
 *
 * Must list the same hues as HUES in apps/web/src/lib/colours.ts. Change
 * both together: a name only the app knows is refused here, and a name only
 * this file knows is drawn as no colour there.
 *
 * @return string[]
 */
function pcle_colour_swatches() {
	static $swatches = null;

	if ( null === $swatches ) {
		$hues = array(
			'rose', 'coral', 'rust', 'amber', 'gold', 'olive', 'lime', 'fern',
			'emerald', 'teal', 'cyan', 'sky', 'cobalt', 'indigo', 'plum', 'magenta',
		);

		$swatches = array();

		foreach ( $hues as $hue ) {
			$swatches[] = "{$hue}-soft";
			$swatches[] = "{$hue}-strong";
		}
	}

	return $swatches;
}

/**
 * An item's own colour.
 *
 * A stored name that is no longer in the palette reads as none, so retiring a
 * swatch cannot leave the app asked to draw a colour it does not have.
 *
 * @param int $post_id Post ID.
 * @return string|null Swatch name, or null when it inherits.
 */
function pcle_get_colour( $post_id ) {
	$colour = (string) get_post_meta( (int) $post_id, PCLE_COLOUR_META, true );

	return in_array( $colour, pcle_colour_swatches(), true ) ? $colour : null;
}

/**
 * Gives an item its own colour, or clears it so it inherits again.
 *
 * @param int    $post_id Post ID.
 * @param string $colour  Swatch name, or '' to clear.
 * @return true|WP_Error
 */
function pcle_set_colour( $post_id, $colour ) {
	$colour = (string) $colour;

	if ( '' === $colour ) {
		delete_post_meta( (int) $post_id, PCLE_COLOUR_META );
		return true;
	}

	if ( ! in_array( $colour, pcle_colour_swatches(), true ) ) {
		return new WP_Error( 'pcle_invalid_colour', __( 'Unknown colour.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	update_post_meta( (int) $post_id, PCLE_COLOUR_META, $colour );

	return true;
}

/**
 * The colour an item shows: its own, else the nearest ancestor's.
 *
 * @param int $post_id Post ID.
 * @return string|null Swatch name, or null when nothing above it has one.
 */
function pcle_get_effective_colour( $post_id ) {
	$cursor = (int) $post_id;

	// The same depth guard as pcle_get_program_for_post(): the hierarchy is
	// a handful of levels, and a corrupt parent pointer must not loop.
	for ( $guard = 0; $cursor && $guard < 10; $guard++ ) {
		$colour = pcle_get_colour( $cursor );

		if ( $colour ) {
			return $colour;
		}

		$cursor = pcle_get_parent_id( $cursor );
	}

	return null;
}
