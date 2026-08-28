<?php
/**
 * Platform CLE REST API.
 *
 * General-purpose REST routes for the headless frontend. Callbacks only
 * shape responses; all domain logic (enrollment, access, progress) lives in
 * enrollment.php / access-control.php / progress.php and is reused here.
 *
 * Routes:
 *   - GET /platform-cle/v1/my-training     : programs available to the current user.
 *   - GET /platform-cle/v1/programs/<id>   : one program with its units and modules.
 *   - GET /platform-cle/v1/units/<id>      : one unit with its modules and events.
 *   - GET /platform-cle/v1/modules/<id>    : one module with its scenarios, templates and quizzes.
 *   - GET /platform-cle/v1/quizzes/<id>    : one quiz to sit, with the answers stripped out.
 *   - POST /platform-cle/v1/quizzes/<id>/attempts : sit it; the server marks and records.
 *
 * These exist instead of walking /wp/v2/pcle_* from the client for two
 * reasons. The client would otherwise need one request per level and would
 * have to filter children by meta itself; and, more importantly, every one of
 * those requests is a separate place where per-program access has to be
 * enforced correctly. Here it is enforced once, in the permission callback,
 * against the post being asked for.
 *
 * @package Platform_CLE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "my-training" REST route.
 */
function pcle_register_my_training_route() {
	register_rest_route(
		'platform-cle/v1',
		'/my-training',
		array(
			'methods'             => 'GET',
			'callback'            => 'pcle_rest_get_my_training',
			'permission_callback' => function () {
				return is_user_logged_in() && current_user_can( 'view_cle_content' );
			},
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_my_training_route' );

/**
 * REST callback: programs available to the current user, with progress.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_rest_get_my_training( $request ) {
	$programs = pcle_get_visible_programs();

	$out = array();
	foreach ( $programs as $program ) {
		$out[] = array(
			'id'       => (int) $program->ID,
			'title'    => get_the_title( $program ),
			'progress' => pcle_rest_shape_progress( pcle_get_program_progress( $program->ID ) ),
		);
	}

	return rest_ensure_response( array( 'programs' => $out ) );
}

/* =========================================================================
 * Curriculum routes
 * ========================================================================= */

/**
 * Renders a post's content the way the front end would.
 *
 * The global post has to be in place: [pcle_model_answer] resolves the post
 * it is rendering inside via get_the_ID() to check program access, and fails
 * closed when it cannot. Without this, model answers would silently never
 * reach the headless client, not even for an enrolled reader.
 *
 * @param WP_Post $post Post to render.
 * @return string Rendered HTML.
 */
function pcle_rest_rendered_content( $post ) {
	$previous        = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$GLOBALS['post'] = $post;
	setup_postdata( $post );

	$html = apply_filters( 'the_content', $post->post_content );

	$GLOBALS['post'] = $previous;

	return $html;
}

/**
 * A short plain-text summary, safe to show in a listing.
 *
 * Shortcodes are stripped rather than rendered, which matters more than it
 * looks: an excerpt built from a scenario's raw content would otherwise carry
 * the model answer in plain text to every reader of the list.
 *
 * @param WP_Post $post Post to summarise.
 * @return string
 */
function pcle_rest_excerpt( $post ) {
	if ( '' !== trim( (string) $post->post_excerpt ) ) {
		return wp_strip_all_tags( $post->post_excerpt );
	}

	return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 25 );
}

/**
 * Shapes a progress struct for the API.
 *
 * Internally the struct is {completed, total, percent}; the API has always
 * spelled the last one `percentage`, via /my-training. Everything goes
 * through here so the client never has to know which spelling a given route
 * happens to use.
 *
 * @param array{completed:int, total:int, percent:int} $progress Progress struct.
 * @return array{completed:int, total:int, percentage:int}
 */
function pcle_rest_shape_progress( $progress ) {
	return array(
		'completed'  => (int) $progress['completed'],
		'total'      => (int) $progress['total'],
		'percentage' => (int) $progress['percent'],
	);
}

/**
 * Shapes a post as a bare reference (for breadcrumbs and links).
 *
 * @param WP_Post|null $post Post.
 * @return array{id:int, title:string}|null
 */
function pcle_rest_shape_ref( $post ) {
	if ( ! $post ) {
		return null;
	}

	return array(
		'id'    => (int) $post->ID,
		'title' => get_the_title( $post ),
	);
}

/**
 * Shapes a module for a listing.
 *
 * @param WP_Post $module Module.
 * @return array
 */
function pcle_rest_shape_module( $module ) {
	return array(
		'id'        => (int) $module->ID,
		'title'     => get_the_title( $module ),
		'excerpt'   => pcle_rest_excerpt( $module ),
		'completed' => pcle_is_module_complete( $module->ID ),
	);
}

/**
 * Shapes a schedule event.
 *
 * Exposes the session time both as ISO 8601 (for the client to format in the
 * reader's locale) and as the site's own formatted string.
 *
 * @param WP_Post $event Event.
 * @return array
 */
function pcle_rest_shape_event( $event ) {
	$raw = pcle_get_event_datetime( $event->ID );
	$iso = '';

	if ( $raw ) {
		$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $raw, wp_timezone() );
		$iso      = $datetime ? $datetime->format( DATE_ATOM ) : '';
	}

	return array(
		'id'        => (int) $event->ID,
		'title'     => get_the_title( $event ),
		'starts_at' => $iso,
		'formatted' => $raw ? pcle_format_event_datetime( $event->ID ) : '',
	);
}

/**
 * Shapes a unit, optionally with its modules and events.
 *
 * @param WP_Post $unit     Unit.
 * @param bool    $children Whether to include modules and events.
 * @return array
 */
function pcle_rest_shape_unit( $unit, $children = true ) {
	$shaped = array(
		'id'       => (int) $unit->ID,
		'title'    => get_the_title( $unit ),
		'excerpt'  => pcle_rest_excerpt( $unit ),
		'progress' => pcle_rest_shape_progress( pcle_get_unit_progress( $unit->ID ) ),
	);

	if ( $children ) {
		$shaped['modules'] = array_map( 'pcle_rest_shape_module', pcle_get_modules( $unit->ID ) );
		$shaped['events']  = array_map( 'pcle_rest_shape_event', pcle_get_events( $unit->ID ) );
	}

	return $shaped;
}

/**
 * Permission callback for the single-item curriculum routes.
 *
 * Anonymous → 401, wrong post type → 404, no access to that program → 403.
 * This is the ONE place per-program access is decided for these routes.
 *
 * @param WP_REST_Request $request   Request.
 * @param string          $post_type Post type the route expects.
 * @return true|WP_Error
 */
function pcle_rest_guard_item( $request, $post_type ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'pcle_not_authenticated',
			__( 'You must be signed in.', 'platform-cle' ),
			array( 'status' => 401 )
		);
	}

	$id = (int) $request['id'];

	if ( $post_type !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
		return new WP_Error(
			'pcle_not_found',
			__( 'Not found.', 'platform-cle' ),
			array( 'status' => 404 )
		);
	}

	if ( ! pcle_can_access_post( $id ) ) {
		return new WP_Error(
			'pcle_rest_forbidden',
			__( 'You must be enrolled to access this content.', 'platform-cle' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Registers the curriculum routes.
 */
function pcle_register_curriculum_routes() {
	$routes = array(
		'programs' => array( 'pcle_program', 'pcle_rest_get_program' ),
		'units'    => array( 'pcle_unit', 'pcle_rest_get_unit' ),
		'modules'  => array( 'pcle_module', 'pcle_rest_get_module' ),
		'quizzes'  => array( 'pcle_quiz', 'pcle_rest_get_quiz' ),
	);

	foreach ( $routes as $slug => list( $post_type, $callback ) ) {
		register_rest_route(
			'platform-cle/v1',
			'/' . $slug . '/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => $callback,
				'permission_callback' => function ( $request ) use ( $post_type ) {
					return pcle_rest_guard_item( $request, $post_type );
				},
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/*
	 * Submitting goes through the same per-programme guard as reading. It is
	 * registered separately only because it is the one write among them.
	 */
	register_rest_route(
		'platform-cle/v1',
		'/quizzes/(?P<id>\d+)/attempts',
		array(
			'methods'             => 'POST',
			'callback'            => 'pcle_rest_submit_quiz',
			'permission_callback' => function ( $request ) {
				return pcle_rest_guard_item( $request, 'pcle_quiz' );
			},
			'args'                => array(
				'id'      => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'answers' => array(
					'required' => true,
					'type'     => 'object',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'pcle_register_curriculum_routes' );

/**
 * GET /programs/<id> — a program with every unit and module under it.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_rest_get_program( $request ) {
	$program = get_post( (int) $request['id'] );

	return rest_ensure_response(
		array(
			'id'       => (int) $program->ID,
			'title'    => get_the_title( $program ),
			'content'  => pcle_rest_rendered_content( $program ),
			'progress' => pcle_rest_shape_progress( pcle_get_program_progress( $program->ID ) ),
			// Approved hours per jurisdiction. Not summable — see
			// pcle_get_credit_hours().
			'credits'  => pcle_rest_shape_credit_hours( $program->ID ),
			'units'    => array_map( 'pcle_rest_shape_unit', pcle_get_units( $program->ID ) ),
		)
	);
}

/**
 * GET /units/<id> — a unit with its modules and sessions.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_rest_get_unit( $request ) {
	$unit    = get_post( (int) $request['id'] );
	$program = get_post( pcle_get_program_for_post( $unit->ID ) );

	$shaped            = pcle_rest_shape_unit( $unit );
	$shaped['content'] = pcle_rest_rendered_content( $unit );
	$shaped['program'] = pcle_rest_shape_ref( $program );

	return rest_ensure_response( $shaped );
}

/**
 * GET /modules/<id> — a module with its practice scenarios and templates.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_rest_get_module( $request ) {
	$module  = get_post( (int) $request['id'] );
	$unit    = get_post( pcle_get_parent_id( $module->ID ) );
	$program = get_post( pcle_get_program_for_post( $module->ID ) );

	$shape_child = function ( $child ) {
		return array(
			'id'      => (int) $child->ID,
			'title'   => get_the_title( $child ),
			'content' => pcle_rest_rendered_content( $child ),
		);
	};

	return rest_ensure_response(
		array(
			'id'        => (int) $module->ID,
			'title'     => get_the_title( $module ),
			'content'   => pcle_rest_rendered_content( $module ),
			'completed' => pcle_is_module_complete( $module->ID ),
			'unit'      => pcle_rest_shape_ref( $unit ),
			'program'   => pcle_rest_shape_ref( $program ),
			'scenarios' => array_map( $shape_child, pcle_get_scenarios( $module->ID ) ),
			'templates' => array_map( $shape_child, pcle_get_templates( $module->ID ) ),
			/*
			 * Deliberately not $shape_child: a quiz's content is its
			 * questions, and this listing is only meant to say that a quiz
			 * exists and where the reader stands with it.
			 */
			'quizzes'   => array_map( 'pcle_rest_shape_quiz_summary', pcle_get_children( $module->ID, 'pcle_quiz' ) ),
		)
	);
}

/**
 * Shapes a quiz for a listing: enough to link to it, nothing to answer it with.
 *
 * @param WP_Post $quiz Quiz.
 * @return array
 */
function pcle_rest_shape_quiz_summary( $quiz ) {
	return array(
		'id'        => (int) $quiz->ID,
		'title'     => get_the_title( $quiz ),
		'questions' => count( pcle_get_quiz_questions( $quiz->ID ) ),
		'required'  => pcle_quiz_gates_completion( $quiz->ID ),
		'passed'    => pcle_user_passed_quiz( $quiz->ID ),
	);
}

/**
 * GET /quizzes/<id> — a quiz to sit.
 *
 * The questions come from pcle_quiz_questions_for_taking(), which is the only
 * shape of this data that may reach a participant: no correct flags, no
 * per-question feedback. Marking happens on submission, in the server, and the
 * result of that is where feedback comes from.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function pcle_rest_get_quiz( $request ) {
	$quiz    = get_post( (int) $request['id'] );
	$module  = get_post( pcle_get_parent_id( $quiz->ID ) );
	$program = get_post( pcle_get_program_for_post( $quiz->ID ) );

	return rest_ensure_response(
		array(
			'id'        => (int) $quiz->ID,
			'title'     => get_the_title( $quiz ),
			'content'   => pcle_rest_rendered_content( $quiz ),
			'questions' => pcle_quiz_questions_for_taking( $quiz->ID ),
			'pass_mark' => pcle_quiz_pass_mark( $quiz->ID ),
			'required'  => pcle_quiz_gates_completion( $quiz->ID ),
			'passed'    => pcle_user_passed_quiz( $quiz->ID ),
			'attempts'  => pcle_get_quiz_attempts( $quiz->ID ),
			'module'    => pcle_rest_shape_ref( $module ),
			'program'   => pcle_rest_shape_ref( $program ),
		)
	);
}

/**
 * POST /quizzes/<id>/attempts — sit the quiz.
 *
 * Always for the current user, never another: marking somebody else's paper is
 * not something this route can be asked to do.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pcle_rest_submit_quiz( $request ) {
	$result = pcle_record_quiz_attempt( (int) $request['id'], $request->get_param( 'answers' ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	/*
	 * The module's own state travels back with the result. Passing a required
	 * quiz is the moment a module becomes completable, and the screen that
	 * asked the question is the one that has to stop saying otherwise.
	 */
	$module_id = pcle_get_parent_id( (int) $request['id'] );

	$result['module'] = array(
		'id'        => (int) $module_id,
		'blockers'  => pcle_module_completion_blockers( $module_id ),
		'completed' => pcle_is_module_complete( $module_id ),
	);

	return rest_ensure_response( $result );
}
