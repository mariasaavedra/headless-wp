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

/* =========================================================================
 * Site-wide colour settings (P16 Colours, in the web app)
 * ========================================================================= */

/** Option holding what an administrator changed. Absent keys use the app's defaults. */
const PCLE_COLOUR_SETTINGS_OPTION = 'pcle_colour_settings';

/**
 * The shape of the settings: each key, and what it may hold.
 *
 * Only what an administrator CHANGED is stored. The defaults live in the web
 * app (DEFAULT_SETTINGS in lib/colours.ts) with the rest of what a swatch
 * looks like, so they are written down once.
 *
 * Which hues each state may use (so a failed quiz is never coloured like a
 * completed one) is checked by the app before it saves, where the families
 * are defined. Only administrators can write here at all.
 *
 * @return array<string,string[]> Group => the keys it may contain.
 */
function pcle_colour_setting_keys() {
	return array(
		'kinds'   => array( 'pcle_unit', 'pcle_module', 'pcle_quiz', 'pcle_event', 'pcle_scenario', 'pcle_template' ),
		'formats' => array( 'webinar' ),
		'states'  => array( 'draft', 'published', 'notStarted', 'inProgress', 'complete', 'failed', 'required' ),
	);
}

/**
 * Checks and cleans a settings object.
 *
 * A swatch group maps each key to a swatch name, or to null for "no colour";
 * a key left out means "the default". Anything unknown is refused rather than
 * dropped, so a typo in a request comes back as an error instead of a save
 * that silently did less than asked.
 *
 * @param mixed $input Settings, as decoded from the request.
 * @return array|WP_Error
 */
function pcle_sanitize_colour_settings( $input ) {
	if ( ! is_array( $input ) ) {
		return new WP_Error( 'pcle_invalid_colour_settings', __( 'Colour settings must be an object.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	$clean = array();

	foreach ( $input as $group => $value ) {
		if ( isset( pcle_colour_setting_keys()[ $group ] ) ) {
			if ( ! is_array( $value ) ) {
				return new WP_Error( 'pcle_invalid_colour_settings', sprintf( 'Expected an object for %s.', $group ), array( 'status' => 400 ) );
			}

			foreach ( $value as $key => $swatch ) {
				if ( ! in_array( $key, pcle_colour_setting_keys()[ $group ], true ) ) {
					return new WP_Error( 'pcle_invalid_colour_settings', sprintf( 'Unknown setting %s.%s.', $group, $key ), array( 'status' => 400 ) );
				}

				if ( null !== $swatch && ! in_array( $swatch, pcle_colour_swatches(), true ) ) {
					return new WP_Error( 'pcle_invalid_colour', __( 'Unknown colour.', 'platform-cle' ), array( 'status' => 400 ) );
				}

				$clean[ $group ][ $key ] = $swatch;
			}
			continue;
		}

		switch ( $group ) {
			case 'mark':
				if ( ! in_array( $value, array( 'strip', 'tint' ), true ) ) {
					return new WP_Error( 'pcle_invalid_colour_settings', __( 'Unknown mark style.', 'platform-cle' ), array( 'status' => 400 ) );
				}
				$clean['mark'] = $value;
				break;

			case 'participants':
				if ( ! is_bool( $value ) ) {
					return new WP_Error( 'pcle_invalid_colour_settings', __( 'participants must be true or false.', 'platform-cle' ), array( 'status' => 400 ) );
				}
				$clean['participants'] = $value;
				break;

			default:
				return new WP_Error( 'pcle_invalid_colour_settings', sprintf( 'Unknown setting %s.', $group ), array( 'status' => 400 ) );
		}
	}

	return $clean;
}

/**
 * What an administrator has changed, cleaned on the way out too, so an
 * option edited by hand cannot hand the app something it cannot draw.
 *
 * @return array
 */
function pcle_get_colour_settings() {
	$stored = pcle_sanitize_colour_settings( get_option( PCLE_COLOUR_SETTINGS_OPTION, array() ) );

	return is_wp_error( $stored ) ? array() : $stored;
}

/**
 * Whether participants see programme and item colours. On unless turned off.
 *
 * @return bool
 */
function pcle_colours_shown_to_participants() {
	return false !== ( pcle_get_colour_settings()['participants'] ?? true );
}

/**
 * The colour a participant route reports.
 *
 * The reader's own colour for the programme, if they chose one; otherwise
 * the item's effective colour. None at all when an administrator has turned
 * colours off for participants.
 *
 * Decided here rather than in the app, so the setting holds for anyone
 * reading these routes, staff previewing as a participant included. A
 * preview shows no personal colours: it is the programme as somebody meets
 * it for the first time.
 *
 * @param int $post_id Post ID.
 * @return string|null
 */
function pcle_participant_colour( $post_id ) {
	if ( ! pcle_colours_shown_to_participants() ) {
		return null;
	}

	if ( ! pcle_previewing_as_participant() ) {
		$mine = pcle_get_my_colours()[ pcle_get_program_for_post( $post_id ) ] ?? null;

		if ( $mine ) {
			return $mine;
		}
	}

	return pcle_get_effective_colour( $post_id );
}

/* =========================================================================
 * A reader's own colours (Your account, P7)
 * ========================================================================= */

/** User meta: programme ID => swatch name. Seen by nobody but its owner. */
const PCLE_MY_COLOURS_META = 'pcle_my_colours';

/**
 * The colours a reader chose for their own programmes.
 *
 * A personal colour stands for the whole programme, items with their own
 * colour included: it is how this one person tells their programmes apart,
 * and a programme that is half their colour and half the author's would not
 * do that.
 *
 * @param int $user_id User ID (defaults to the current user).
 * @return array<int,string>
 */
function pcle_get_my_colours( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return array();
	}

	$stored = get_user_meta( $user_id, PCLE_MY_COLOURS_META, true );

	return is_array( $stored )
		? array_filter( $stored, fn( $colour ) => in_array( $colour, pcle_colour_swatches(), true ) )
		: array();
}

/**
 * GET /me/colours — each programme on the reader's My Training, with the
 * colour they chose for it and the colour it has otherwise.
 *
 * @return WP_REST_Response
 */
function pcle_rest_get_my_colours() {
	$mine = pcle_get_my_colours();
	$out  = array();

	foreach ( pcle_get_visible_programs() as $program ) {
		$out[] = array(
			'id'            => (int) $program->ID,
			'title'         => get_the_title( $program ),
			'color'         => $mine[ $program->ID ] ?? null,
			'program_color' => pcle_get_colour( $program->ID ),
		);
	}

	return rest_ensure_response(
		array(
			// Whether participants see colours at all, so the screen can say
			// why a choice made here is not showing.
			'shown'    => pcle_colours_shown_to_participants(),
			'programs' => $out,
		)
	);
}

/**
 * PUT /me/colours/<id> — choose, or clear with "", one programme's colour.
 *
 * Only for a programme on the reader's own My Training: anything else is
 * not theirs to colour, and storing it would only be clutter.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_rest_put_my_colour( $request ) {
	$program_id = (int) $request['id'];
	$colour     = (string) $request['color'];

	if ( ! in_array( $program_id, array_map( 'intval', wp_list_pluck( pcle_get_visible_programs(), 'ID' ) ), true ) ) {
		return new WP_Error( 'pcle_not_your_programme', __( 'You can only colour programmes on your own My Training.', 'platform-cle' ), array( 'status' => 403 ) );
	}

	if ( '' !== $colour && ! in_array( $colour, pcle_colour_swatches(), true ) ) {
		return new WP_Error( 'pcle_invalid_colour', __( 'Unknown colour.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	$mine = pcle_get_my_colours();

	if ( '' === $colour ) {
		unset( $mine[ $program_id ] );
	} else {
		$mine[ $program_id ] = $colour;
	}

	if ( $mine ) {
		update_user_meta( get_current_user_id(), PCLE_MY_COLOURS_META, $mine );
	} else {
		delete_user_meta( get_current_user_id(), PCLE_MY_COLOURS_META );
	}

	return pcle_rest_get_my_colours();
}

/** Registers the personal colour routes. Signed-in readers, for themselves only. */
function pcle_register_my_colour_routes() {
	$signed_in = static fn() => is_user_logged_in()
		? true
		: new WP_Error( 'pcle_not_authenticated', __( 'You must be signed in.', 'platform-cle' ), array( 'status' => 401 ) );

	register_rest_route(
		'platform-cle/v1',
		'/me/colours',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_rest_get_my_colours',
			'permission_callback' => $signed_in,
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/me/colours/(?P<id>\d+)',
		array(
			'methods'             => 'PUT',
			'callback'            => 'pcle_rest_put_my_colour',
			'permission_callback' => $signed_in,
			'args'                => array(
				'color' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_my_colour_routes' );

/**
 * GET /colour-settings — anyone, signed in or not: every screen draws its
 * badges from these, the sign-in screen included, and nothing in them is
 * private. PUT — administrators, replacing the whole object.
 */
function pcle_register_colour_routes() {
	register_rest_route(
		'platform-cle/v1',
		'/colour-settings',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => fn() => rest_ensure_response( (object) pcle_get_colour_settings() ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'PUT',
				'callback'            => 'pcle_rest_put_colour_settings',
				'permission_callback' => fn() => current_user_can( 'manage_options' )
					? true
					: new WP_Error( 'pcle_cannot_change_colours', __( 'Only an administrator may change the colour settings.', 'platform-cle' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) ),
			),
		)
	);
}

/**
 * PUT /colour-settings.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_rest_put_colour_settings( $request ) {
	$clean = pcle_sanitize_colour_settings( $request->get_json_params() );

	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	update_option( PCLE_COLOUR_SETTINGS_OPTION, $clean, false );

	return rest_ensure_response( (object) $clean );
}
add_action( 'rest_api_init', 'pcle_register_colour_routes' );
