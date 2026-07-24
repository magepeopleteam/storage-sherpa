/**
 * Shared admin JS for every server-rendered Storage Sherpa screen.
 *
 * Two small, generic mechanisms cover every screen's needs:
 *  - [data-ss-action]: a button that POSTs/DELETEs to a REST path, optionally
 *    confirms first, then reloads the page (or a subset via data-ss-reload).
 *  - [data-ss-scan]: starts a Module 24 background-scan job and polls
 *    /scan/step until status is "complete", updating a progress element.
 *  - "select all" checkboxes for the WP_List_Table-style bulk-action forms.
 *
 * No build step: this is plain ES2017, loaded with wp-api-fetch as a
 * dependency so `wp.apiFetch` is already configured (nonce + REST root) by
 * the inline script SS_Admin::enqueue_assets() adds.
 */
( function () {
	'use strict';

	function apiFetch( path, options ) {
		return wp.apiFetch( Object.assign( { path: path }, options || {} ) );
	}

	function formatBytes( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var value = Math.max( 0, bytes || 0 );
		var power = value > 0 ? Math.floor( Math.log( value ) / Math.log( 1024 ) ) : 0;
		power = Math.min( power, units.length - 1 );
		return ( value / Math.pow( 1024, power ) ).toFixed( 2 ) + ' ' + units[ power ];
	}

	window.StorageSherpaApi = { fetch: apiFetch, formatBytes: formatBytes };

	function setStatus( el, text ) {
		if ( el ) {
			el.textContent = text;
		}
	}

	function handleActionClick( e ) {
		var btn = e.target.closest( '[data-ss-action]' );
		if ( ! btn ) {
			return;
		}

		e.preventDefault();

		var confirmMsg = btn.getAttribute( 'data-ss-confirm' );
		if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
			return;
		}

		var path   = btn.getAttribute( 'data-ss-action' );
		var method = btn.getAttribute( 'data-ss-method' ) || 'POST';
		var bodyAttr = btn.getAttribute( 'data-ss-body' );
		var statusEl = btn.parentElement ? btn.parentElement.querySelector( '.ss-status' ) : null;

		var original = btn.textContent;
		btn.disabled = true;
		setStatus( statusEl, StorageSherpa.i18n.working );

		apiFetch( path, {
			method: method,
			data: bodyAttr ? JSON.parse( bodyAttr ) : undefined,
		} )
			.then( function () {
				setStatus( statusEl, StorageSherpa.i18n.done );
				if ( 'false' !== btn.getAttribute( 'data-ss-reload' ) ) {
					window.location.reload();
				} else {
					btn.disabled = false;
					btn.textContent = original;
				}
			} )
			.catch( function ( err ) {
				setStatus( statusEl, ( err && err.message ) || StorageSherpa.i18n.error );
				btn.disabled = false;
				btn.textContent = original;
			} );
	}

	function handleBulkAction( e ) {
		var form = e.target.closest( 'form[data-ss-bulk-action]' );
		if ( ! form ) {
			return;
		}

		var select = form.querySelector( 'select[name="ss_bulk_action"]' );
		var action = select ? select.value : '';
		if ( ! action || '-1' === action ) {
			return;
		}

		var checked = Array.prototype.slice
			.call( form.querySelectorAll( 'input[type="checkbox"][name="ss_ids[]"]:checked' ) )
			.map( function ( cb ) {
				return parseInt( cb.value, 10 );
			} );

		if ( ! checked.length ) {
			e.preventDefault();
			return;
		}

		if ( ! window.confirm( StorageSherpa.i18n.confirmDelete ) ) {
			e.preventDefault();
			return;
		}

		e.preventDefault();

		apiFetch( form.getAttribute( 'data-ss-bulk-action' ), {
			method: 'POST',
			data: { ids: checked },
		} ).then( function ( res ) {
			if ( res && res.batch_id ) {
				showUndoToast( res.batch_id );
			} else {
				window.location.reload();
			}
		} );
	}

	/**
	 * A bulk trash action creates several Safe Trash rows per item (a post
	 * row, its postmeta, its base file, every thumbnail size) sharing one
	 * batch_id — this toast's Undo button restores all of them in one call
	 * via /trash/restore-batch rather than sending the admin to Recovery
	 * Center to restore each row by hand. Auto-dismisses after 8s if left
	 * untouched, same as a typical "action taken" toast pattern.
	 *
	 * onDone (optional) runs after a successful undo, and also after the
	 * auto-dismiss timeout — defaults to a full page reload (the original
	 * behavior every existing call site still gets), but callers that
	 * already know how to refresh just their own screen in place — the
	 * Media Findings chunked bulk-delete flow, for one — can pass their own
	 * instead of forcing a full navigation.
	 */
	function showUndoToast( batchId, onDone ) {
		onDone = onDone || function () {
			window.location.reload();
		};

		var toast = document.createElement( 'div' );
		toast.className = 'ss-toast';

		var text = document.createElement( 'span' );
		text.textContent = StorageSherpa.i18n.movedToTrash;

		var undoBtn = document.createElement( 'button' );
		undoBtn.type = 'button';
		undoBtn.textContent = StorageSherpa.i18n.undo;
		undoBtn.addEventListener( 'click', function () {
			window.clearTimeout( dismissTimer );
			undoBtn.disabled = true;
			apiFetch( '/storage-sherpa/v1/trash/restore-batch', {
				method: 'POST',
				data: { batch_id: batchId },
			} ).then( function () {
				toast.remove();
				onDone();
			} );
		} );

		toast.appendChild( text );
		toast.appendChild( undoBtn );
		document.body.appendChild( toast );

		var dismissTimer = window.setTimeout( function () {
			toast.remove();
			onDone();
		}, 8000 );
	}

	window.StorageSherpaApi.showUndoToast = showUndoToast;

	function pollScan( startBtn ) {
		var progressEl = document.getElementById( startBtn.getAttribute( 'data-ss-progress-target' ) || 'ss-scan-progress' );
		startBtn.disabled = true;

		apiFetch( '/storage-sherpa/v1/scan/start', { method: 'POST' } ).then( function ( state ) {
			step( state.job_id );
		} );

		function step( jobId ) {
			apiFetch( '/storage-sherpa/v1/scan/step', { method: 'POST', data: { job_id: jobId } } ).then( function ( state ) {
				var pct = Math.round( ( state.current / state.steps.length ) * 100 );
				if ( progressEl ) {
					progressEl.textContent = pct + '% (' + state.current + '/' + state.steps.length + ')';
				}

				if ( 'complete' === state.status ) {
					startBtn.disabled = false;
					window.location.reload();
				} else {
					step( jobId );
				}
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.addEventListener( 'click', handleActionClick );
		document.addEventListener( 'click', function ( e ) {
			var scanBtn = e.target.closest( '[data-ss-scan]' );
			if ( scanBtn ) {
				e.preventDefault();
				pollScan( scanBtn );
			}
		} );
		document.addEventListener( 'submit', handleBulkAction );

		// Delegated (not bound per-element) so a "select all" checkbox
		// rendered later by an AJAX-swapped region — e.g. the Media Findings
		// search results — keeps working without needing its own rebind.
		document.addEventListener( 'change', function ( e ) {
			var master = e.target.closest( '[data-ss-select-all]' );
			if ( ! master ) {
				return;
			}
			var form = master.closest( 'form' );
			if ( ! form ) {
				return;
			}
			form.querySelectorAll( 'input[type="checkbox"][name="ss_ids[]"]' ).forEach( function ( cb ) {
				cb.checked = master.checked;
			} );
		} );
	} );
} )();
