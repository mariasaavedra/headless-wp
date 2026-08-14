/**
 * Platform CLE — frontend progress.
 *
 * Handles the "mark as complete" button click, sends it to the REST endpoint,
 * and updates the UI (button + any unit progress bar).
 */
( function () {
	'use strict';

	if ( typeof window.pcleProgress === 'undefined' ) {
		return;
	}

	var cfg = window.pcleProgress;

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.pcle-complete-btn' );
		if ( ! btn ) {
			return;
		}

		event.preventDefault();

		var moduleId = parseInt( btn.getAttribute( 'data-module-id' ), 10 );
		if ( ! moduleId ) {
			return;
		}

		// Desired state = the opposite of the current one.
		var willComplete = btn.getAttribute( 'aria-pressed' ) !== 'true';

		btn.setAttribute( 'disabled', 'disabled' );

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( {
				module_id: moduleId,
				completed: willComplete
			} )
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'Request failed: ' + res.status );
				}
				return res.json();
			} )
			.then( function ( data ) {
				updateButton( btn, data.completed );
				if ( data.unit_progress ) {
					updateUnitBars( data.unit_progress );
				}
			} )
			.catch( function () {
				// If something fails, we leave the button as it was.
			} )
			.finally( function () {
				btn.removeAttribute( 'disabled' );
			} );
	} );

	/**
	 * Reflects the new state on the button.
	 */
	function updateButton( btn, completed ) {
		btn.setAttribute( 'aria-pressed', completed ? 'true' : 'false' );
		btn.classList.toggle( 'is-complete', completed );
		btn.textContent = completed ? cfg.i18n.complete : cfg.i18n.incomplete;
	}

	/**
	 * Updates all visible progress bars with the unit's data.
	 */
	function updateUnitBars( progress ) {
		var bars = document.querySelectorAll( '.pcle-progress' );
		bars.forEach( function ( bar ) {
			var fill = bar.querySelector( '.pcle-progress__fill' );
			var track = bar.querySelector( '.pcle-progress__track' );
			if ( fill ) {
				fill.style.width = progress.percent + '%';
			}
			if ( track ) {
				track.setAttribute( 'aria-valuenow', progress.percent );
			}
		} );
	}
} )();
