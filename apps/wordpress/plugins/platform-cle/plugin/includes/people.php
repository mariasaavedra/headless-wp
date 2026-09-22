<?php
/**
 * People: the accounts the platform teaches and the accounts that teach.
 *
 * Everything here is administration rather than teaching, and the capability
 * split says so: an instructor enrols people who already have accounts, an
 * administrator decides who has one and what they may do. The frontend offers
 * this screen only to the second, but the decisions are made here — a screen
 * that hides a control is a courtesy, not a boundary.
 *
 * Four rules this module exists to keep, none of which the roles API gives
 * you for free:
 *
 *   1. Only two roles are grantable from the app: CLE Student and CLE
 *      Instructor. Administrator is not among them at any capability level.
 *   2. Nobody changes their own role. The most common way to lock everyone
 *      out of a system is to demote yourself while alone in it.
 *   3. Nobody changes an administrator's role. Demoting a colleague is a
 *      decision that belongs where the account itself lives.
 *   4. Nothing here deletes an account. Progress, attendance and quiz
 *      attempts hang off a user id, and a credit claim may depend on them.
 *
 * @package PlatformCLE
 */

defined( 'ABSPATH' ) || exit;

/**
 * The roles this API will grant, in the order a chooser should offer them.
 *
 * Deliberately not derived from wp_roles(): that list grows when any plugin
 * adds a role, and a list of "roles the app may grant" that grows by itself
 * is how an editor role nobody discussed becomes grantable from a dropdown.
 *
 * @return array<string,string> Role slug => its label.
 */
function pcle_grantable_roles() {
	return array(
		'pcle_student'    => __( 'CLE Student', 'platform-cle' ),
		'pcle_instructor' => __( 'CLE Instructor', 'platform-cle' ),
	);
}

/**
 * Shapes one person for the API.
 *
 * Name, address and role, and nothing else. A staff screen needs to tell
 * people apart and see what they may do; it does not need the rest of what
 * WordPress knows about an account.
 *
 * @param WP_User $user User.
 * @return array<string,mixed>
 */
function pcle_shape_person( $user ) {
	$roles = array_values( (array) $user->roles );

	return array(
		'id'         => (int) $user->ID,
		'name'       => $user->display_name,
		'email'      => $user->user_email,
		'role'       => $roles ? $roles[0] : '',
		'role_label' => pcle_role_label( $roles ? $roles[0] : '' ),
		/*
		 * Whether this app may change this person's role at all. The frontend
		 * needs it to decide between a chooser and a plain word, and working
		 * it out there would mean teaching the frontend the rules above.
		 */
		'editable'   => pcle_role_is_editable( $user ),
	);
}

/**
 * A role's human name, whether or not the app may grant it.
 *
 * @param string $role Role slug.
 * @return string
 */
function pcle_role_label( $role ) {
	$grantable = pcle_grantable_roles();

	if ( isset( $grantable[ $role ] ) ) {
		return $grantable[ $role ];
	}

	$names = wp_roles()->get_names();

	return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;
}

/**
 * May the current user change this person's role?
 *
 * @param WP_User $user The person whose role is in question.
 * @return bool
 */
function pcle_role_is_editable( $user ) {
	if ( ! current_user_can( 'promote_users' ) ) {
		return false;
	}

	// Rule 2: not your own.
	if ( (int) $user->ID === get_current_user_id() ) {
		return false;
	}

	// Rule 3: not an administrator's.
	if ( user_can( $user->ID, 'manage_options' ) ) {
		return false;
	}

	return true;
}

/**
 * GET /people — the accounts staff can see.
 *
 * Ordered by display name, because this list is read by someone looking for a
 * person they can already name.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_rest_list_people( $request ) {
	$search = trim( (string) $request['search'] );

	$users = get_users(
		array(
			'search'         => '' !== $search ? '*' . $search . '*' : '',
			'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'number'         => 200,
		)
	);

	return rest_ensure_response(
		array(
			'people' => array_map( 'pcle_shape_person', $users ),
			'roles'  => array_map(
				static function ( $slug, $label ) {
					return array(
						'role'  => $slug,
						'label' => $label,
					);
				},
				array_keys( pcle_grantable_roles() ),
				array_values( pcle_grantable_roles() )
			),
		)
	);
}

/**
 * PATCH /people/{id} — change what someone may do.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_rest_update_person( $request ) {
	$user = get_userdata( (int) $request['id'] );
	$role = (string) $request['role'];

	if ( ! $user ) {
		return new WP_Error( 'pcle_no_such_user', __( 'That person could not be found.', 'platform-cle' ), array( 'status' => 404 ) );
	}

	if ( ! isset( pcle_grantable_roles()[ $role ] ) ) {
		return new WP_Error(
			'pcle_role_not_grantable',
			__( 'That role cannot be granted from here.', 'platform-cle' ),
			array( 'status' => 400 )
		);
	}

	if ( (int) $user->ID === get_current_user_id() ) {
		return new WP_Error(
			'pcle_cannot_change_own_role',
			__( 'You cannot change your own role.', 'platform-cle' ),
			array( 'status' => 403 )
		);
	}

	if ( user_can( $user->ID, 'manage_options' ) ) {
		return new WP_Error(
			'pcle_cannot_change_administrator',
			__( "An administrator's role is changed in WordPress, not here.", 'platform-cle' ),
			array( 'status' => 403 )
		);
	}

	// set_role() replaces every role the user holds, which is what is meant
	// here: these accounts hold exactly one.
	$user->set_role( $role );

	return rest_ensure_response( pcle_shape_person( get_userdata( $user->ID ) ) );
}

/**
 * Permission callback for the people routes.
 *
 * Reading the list is staff work — an instructor enrolling a cohort needs to
 * see who exists. Changing a role is administration, and takes promote_users,
 * which teaching does not grant.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function pcle_rest_guard_people( $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'pcle_not_authenticated', __( 'You must be signed in.', 'platform-cle' ), array( 'status' => 401 ) );
	}

	if ( 'GET' === $request->get_method() ) {
		return pcle_user_is_staff()
			? true
			: new WP_Error( 'pcle_not_staff', __( 'Only teaching staff may see this.', 'platform-cle' ), array( 'status' => 403 ) );
	}

	return current_user_can( 'promote_users' )
		? true
		: new WP_Error( 'pcle_cannot_promote', __( 'Only an administrator may change a role.', 'platform-cle' ), array( 'status' => 403 ) );
}

/**
 * Registers the people routes.
 */
function pcle_register_people_routes() {
	register_rest_route(
		'platform-cle/v1',
		'/people',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_rest_list_people',
			'permission_callback' => 'pcle_rest_guard_people',
			'args'                => array(
				'search' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);

	register_rest_route(
		'platform-cle/v1',
		'/people/(?P<id>\d+)',
		array(
			'methods'             => 'PATCH',
			'callback'            => 'pcle_rest_update_person',
			'permission_callback' => 'pcle_rest_guard_people',
			'args'                => array(
				'id'   => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'role' => array(
					'required' => true,
					'type'     => 'string',
					// The allowed set is stated once, in pcle_grantable_roles().
					'enum'     => array_keys( pcle_grantable_roles() ),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_people_routes' );
