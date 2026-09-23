<?php
/**
 * A signed-in person's own account: their username and their password.
 *
 * Routes (all for the signed-in user, about themselves only):
 *   GET  /platform-cle/v1/account           — who am I
 *   POST /platform-cle/v1/account/username  — change my username
 *   POST /platform-cle/v1/account/password  — change my password
 *
 * Both changes ask for the current password. A session left open on a shared
 * machine should not be enough to lock its owner out of their own account.
 *
 * Changing the password signs out every other session. The JWT plugin's
 * tokens are self-contained and live for seven days, and it has no notion of
 * revoking one; without this, someone changing a password *because* it was
 * stolen would leave the thief signed in for a week. Each user carries a
 * token generation, stamped into every token issued; a password change moves
 * it on, and a token from an older generation no longer identifies anyone.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** User meta holding the current token generation. Absent means 0. */
const PCLE_TOKEN_GENERATION_META = '_pcle_token_generation';

/** The shortest password this accepts. */
const PCLE_MIN_PASSWORD_LENGTH = 12;

/**
 * The token generation a user's tokens must carry to be honoured.
 *
 * @param int $user_id User ID.
 * @return int
 */
function pcle_token_generation( $user_id ) {
	return (int) get_user_meta( $user_id, PCLE_TOKEN_GENERATION_META, true );
}

/**
 * Stamps the user's current generation into every token as it is issued.
 *
 * @param array   $token Claims about to be signed.
 * @param WP_User $user  The user signing in.
 * @return array
 */
function pcle_stamp_token_generation( $token, $user ) {
	if ( $user instanceof WP_User && isset( $token['data']['user'] ) ) {
		$token['data']['user']['gen'] = pcle_token_generation( $user->ID );
	}

	return $token;
}
add_filter( 'jwt_auth_token_before_sign', 'pcle_stamp_token_generation', 10, 2 );

/**
 * Refuses a token from a generation the user has since moved past.
 *
 * Runs after the JWT plugin (priority 10) has checked the signature and turned
 * the token into a user ID. The payload is read without verifying it again:
 * this only ever withdraws an identity the plugin granted from this very
 * token, never grants one, so a forged payload can do no more than sign its
 * bearer out.
 *
 * @param int|false $user_id What earlier filters decided.
 * @return int|false
 */
function pcle_refuse_stale_tokens( $user_id ) {
	if ( ! $user_id ) {
		return $user_id;
	}

	$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';

	if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
	}

	if ( 0 !== strpos( $header, 'Bearer ' ) ) {
		return $user_id;
	}

	$claims = pcle_token_claims( substr( $header, 7 ) );

	// Not this token's user: the identity came from somewhere else.
	if ( ! $claims || (int) ( $claims['data']['user']['id'] ?? 0 ) !== (int) $user_id ) {
		return $user_id;
	}

	$generation = (int) ( $claims['data']['user']['gen'] ?? 0 );

	return $generation === pcle_token_generation( $user_id ) ? $user_id : false;
}
add_filter( 'determine_current_user', 'pcle_refuse_stale_tokens', 20 );

/**
 * The claims of a JWT, unverified.
 *
 * @param string $token Compact JWT.
 * @return array|null
 */
function pcle_token_claims( $token ) {
	$parts = explode( '.', trim( $token ) );

	if ( 3 !== count( $parts ) ) {
		return null;
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT payloads are base64url.
	$json   = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
	$claims = false === $json ? null : json_decode( $json, true );

	return is_array( $claims ) ? $claims : null;
}

/**
 * The account as the web app shows it.
 *
 * @param WP_User $user User.
 * @return array
 */
function pcle_rest_shape_account( $user ) {
	return array(
		'id'           => (int) $user->ID,
		'username'     => $user->user_login,
		'display_name' => $user->display_name,
		'email'        => $user->user_email,
	);
}

/**
 * Checks the current password, the price of either change.
 *
 * @param WP_User $user     User.
 * @param string  $password What they typed.
 * @return true|WP_Error
 */
function pcle_account_confirm_password( $user, $password ) {
	if ( '' === $password || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
		return new WP_Error(
			'pcle_wrong_password',
			__( 'Your current password is not right.', 'platform-cle' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Why this username cannot be had, or null if it can.
 *
 * Deliberately narrower than WordPress's own rules: letters, digits and
 * . _ - only. No "@", because WordPress also signs people in by email address
 * and a username shaped like someone else's email is a way to confuse the
 * two. No spaces, which sanitize_user() allows and which nobody types
 * correctly twice.
 *
 * @param string  $username Proposed username.
 * @param WP_User $user     Who is asking.
 * @return WP_Error|null
 */
function pcle_username_refusal( $username, $user ) {
	$length = strlen( $username );

	if ( $length < 3 || $length > 60 ) {
		return new WP_Error( 'pcle_username_length', __( 'A username is 3 to 60 characters long.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $username ) ) {
		return new WP_Error( 'pcle_username_characters', __( 'A username may use letters, numbers, dots, dashes and underscores — nothing else.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	/** This filter is documented in wp-includes/user.php */
	$illegal = array_map( 'strtolower', (array) apply_filters( 'illegal_user_logins', array() ) );

	if ( in_array( strtolower( $username ), $illegal, true ) ) {
		return new WP_Error( 'pcle_username_taken', __( 'That username is taken.', 'platform-cle' ), array( 'status' => 409 ) );
	}

	$holder = username_exists( $username );

	if ( $holder && (int) $holder !== (int) $user->ID ) {
		return new WP_Error( 'pcle_username_taken', __( 'That username is taken.', 'platform-cle' ), array( 'status' => 409 ) );
	}

	return null;
}

/**
 * GET /account.
 *
 * @return WP_REST_Response
 */
function pcle_rest_get_account() {
	return rest_ensure_response( pcle_rest_shape_account( wp_get_current_user() ) );
}

/**
 * POST /account/username.
 *
 * WordPress treats usernames as permanent — wp_update_user() silently ignores
 * user_login — so the row is written directly and the caches cleared. The
 * nicename and display name follow only where they were still the old
 * username, i.e. where nobody chose them on purpose.
 *
 * Sessions are left alone. Tokens name the user by ID, not by username, so
 * nothing issued before this stops working, and nothing should.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_rest_change_username( $request ) {
	global $wpdb;

	$user     = wp_get_current_user();
	$username = trim( (string) $request['username'] );

	$confirmed = pcle_account_confirm_password( $user, (string) $request['current_password'] );
	if ( is_wp_error( $confirmed ) ) {
		return $confirmed;
	}

	if ( $username === $user->user_login ) {
		return rest_ensure_response( pcle_rest_shape_account( $user ) );
	}

	$refusal = pcle_username_refusal( $username, $user );
	if ( $refusal ) {
		return $refusal;
	}

	$old_login = $user->user_login;
	$changes   = array( 'user_login' => $username );

	if ( $user->user_nicename === sanitize_title( $old_login ) ) {
		$nicename = sanitize_title( $username );
		$taken    = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_nicename = %s AND ID != %d", $nicename, $user->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $taken ) {
			$changes['user_nicename'] = $nicename;
		}
	}

	if ( $user->display_name === $old_login ) {
		$changes['display_name'] = $username;
	}

	$updated = $wpdb->update( $wpdb->users, $changes, array( 'ID' => $user->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( false === $updated ) {
		return new WP_Error( 'pcle_username_failed', __( 'The username could not be changed.', 'platform-cle' ), array( 'status' => 500 ) );
	}

	/*
	 * clean_user_cache() keys the login lookup by the login it is given, so
	 * the old one has to be cleared by hand or it would keep resolving.
	 */
	clean_user_cache( $user->ID );
	wp_cache_delete( $old_login, 'userlogins' );
	wp_cache_delete( sanitize_title( $old_login ), 'userslugs' );

	// The signed-in user object is its own copy; reload it past the old login.
	wp_set_current_user( 0 );
	wp_set_current_user( $user->ID );

	return rest_ensure_response( pcle_rest_shape_account( get_userdata( $user->ID ) ) );
}

/**
 * POST /account/password.
 *
 * Through wp_update_user(), so WordPress sends its own "your password was
 * changed" email — the one warning a person gets if it was not them.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_rest_change_password( $request ) {
	$user     = wp_get_current_user();
	$current  = (string) $request['current_password'];
	$password = (string) $request['new_password'];

	$confirmed = pcle_account_confirm_password( $user, $current );
	if ( is_wp_error( $confirmed ) ) {
		return $confirmed;
	}

	if ( strlen( $password ) < PCLE_MIN_PASSWORD_LENGTH ) {
		return new WP_Error(
			'pcle_password_short',
			/* translators: %d: minimum length */
			sprintf( __( 'A password is at least %d characters.', 'platform-cle' ), PCLE_MIN_PASSWORD_LENGTH ),
			array( 'status' => 400 )
		);
	}

	if ( $password === $current ) {
		return new WP_Error( 'pcle_password_unchanged', __( 'That is your current password. Choose a new one.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	if ( 0 === strcasecmp( $password, $user->user_login ) || 0 === strcasecmp( $password, $user->user_email ) ) {
		return new WP_Error( 'pcle_password_guessable', __( 'A password cannot be your username or email address.', 'platform-cle' ), array( 'status' => 400 ) );
	}

	/*
	 * wp_update_user() re-issues wp-admin's login cookies when someone changes
	 * their own password. Here that would put WordPress session cookies on an
	 * API response the web app's server reads and throws away — and every
	 * session is about to be ended anyway.
	 */
	add_filter( 'send_auth_cookies', '__return_false' );
	$updated = wp_update_user(
		array(
			'ID'        => $user->ID,
			'user_pass' => $password,
		)
	);
	remove_filter( 'send_auth_cookies', '__return_false' );

	if ( is_wp_error( $updated ) ) {
		return new WP_Error( 'pcle_password_failed', __( 'The password could not be changed.', 'platform-cle' ), array( 'status' => 500 ) );
	}

	// Every token issued so far, this request's included, stops working.
	update_user_meta( $user->ID, PCLE_TOKEN_GENERATION_META, pcle_token_generation( $user->ID ) + 1 );

	// And wp-admin sessions, which live in cookies rather than tokens.
	WP_Session_Tokens::get_instance( $user->ID )->destroy_all();

	return rest_ensure_response( array( 'changed' => true ) );
}

/**
 * Registers the account routes.
 */
function pcle_register_account_routes() {
	$signed_in = static function () {
		return is_user_logged_in()
			? true
			: new WP_Error( 'pcle_not_authenticated', __( 'You must be signed in.', 'platform-cle' ), array( 'status' => 401 ) );
	};

	register_rest_route(
		'platform-cle/v1',
		'/account',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_rest_get_account',
			'permission_callback' => $signed_in,
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/account/username',
		array(
			'methods'             => 'POST',
			'callback'            => 'pcle_rest_change_username',
			'permission_callback' => $signed_in,
			'args'                => array(
				'username'         => array(
					'required' => true,
					'type'     => 'string',
				),
				'current_password' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/account/password',
		array(
			'methods'             => 'POST',
			'callback'            => 'pcle_rest_change_password',
			'permission_callback' => $signed_in,
			'args'                => array(
				'current_password' => array(
					'required' => true,
					'type'     => 'string',
				),
				'new_password'     => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_account_routes' );
