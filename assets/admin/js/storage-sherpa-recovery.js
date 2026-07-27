/**
 * Recovery Center screen only (enqueued conditionally by
 * SS_Admin::enqueue_assets() when $plugin_page === 'storage-sherpa-recovery').
 * Mirrors storage-sherpa-media.js's pattern closely — AJAX search, a
 * file-type filter, checkbox selection with "select all N items matching
 * this filter" (which respects both the search term and file-type filter),
 * chunked bulk actions with a progress bar — extended here for TWO bulk
 * actions (Restore, Delete Permanently) instead of one.
 *
 * No build step, same plain-ES2017 style as storage-sherpa-admin.js.
 */
( function () {
	'use strict';

	var CHUNK_SIZE = 25;
	var SEARCH_DEBOUNCE_MS = 350;

	var region = document.getElementById( 'ss-recovery-table-region' );
	if ( ! region ) {
		return;
	}

	var searchInput        = document.getElementById( 'ss-recovery-search' );
	var searchForm         = document.getElementById( 'ss-recovery-search-form' );
	var selectionBar       = document.getElementById( 'ss-recovery-selection-bar' );
	var selectionText      = document.getElementById( 'ss-recovery-selection-text' );
	var selectAllMatchBtn  = document.getElementById( 'ss-recovery-select-all-matching' );
	var clearSelectionBtn  = document.getElementById( 'ss-recovery-clear-selection' );
	var progressEl         = document.getElementById( 'ss-recovery-progress' );
	var progressFill       = progressEl ? progressEl.querySelector( '.ss-media-progress-fill' ) : null;
	var progressLabel      = document.getElementById( 'ss-recovery-progress-label' );
	var progressCancelBtn  = document.getElementById( 'ss-recovery-progress-cancel' );

	// mode: 'none' | 'page' | 'all-matching'
	var selection = { mode: 'none', cancelRequested: false };

	function apiFetch( path, options ) {
		return window.StorageSherpaApi.fetch( path, options );
	}

	function currentSearch() {
		return region.getAttribute( 'data-search' ) || '';
	}

	function currentFileType() {
		return region.getAttribute( 'data-file-type' ) || '';
	}

	function totalItems() {
		return parseInt( region.getAttribute( 'data-total-items' ), 10 ) || 0;
	}

	function checkedCheckboxes() {
		return Array.prototype.slice.call( region.querySelectorAll( 'input[name="ss_ids[]"]' ) );
	}

	function checkedIds() {
		return checkedCheckboxes()
			.filter( function ( cb ) {
				return cb.checked;
			} )
			.map( function ( cb ) {
				return parseInt( cb.value, 10 );
			} );
	}

	// -----------------------------------------------------------------
	// Selection bar
	// -----------------------------------------------------------------

	function resetSelection() {
		selection.mode = 'none';
		checkedCheckboxes().forEach( function ( cb ) {
			cb.checked = false;
		} );
		var master = region.querySelector( '[data-ss-select-all]' );
		if ( master ) {
			master.checked = false;
		}
		renderSelectionBar();
	}

	function renderSelectionBar() {
		if ( ! selectionBar ) {
			return;
		}

		if ( 'all-matching' === selection.mode ) {
			selectionText.textContent = StorageSherpaRecovery.i18n.allSelected.replace( '%d', totalItems() );
			selectAllMatchBtn.hidden = true;
			selectionBar.hidden = false;
			return;
		}

		var ids = checkedIds();

		if ( ! ids.length ) {
			selectionBar.hidden = true;
			return;
		}

		selectionText.textContent = StorageSherpaRecovery.i18n.nSelected.replace( '%d', ids.length );

		var allOnPageChecked = ids.length === checkedCheckboxes().length && checkedCheckboxes().length > 0;
		if ( allOnPageChecked && totalItems() > ids.length ) {
			selectAllMatchBtn.textContent = StorageSherpaRecovery.i18n.selectAllMatching.replace( '%d', totalItems() );
			selectAllMatchBtn.hidden = false;
		} else {
			selectAllMatchBtn.hidden = true;
		}

		selectionBar.hidden = false;
	}

	// Delegated on document (not region) and registered after
	// storage-sherpa-admin.js's own document-level [data-ss-select-all]
	// listener (script dependency order guarantees that), so by the time
	// this runs the "select all" checkbox has already (un)checked every row
	// checkbox and we're counting the final state, not a stale one.
	document.addEventListener( 'change', function ( e ) {
		if ( ! region.contains( e.target ) || ! e.target.matches( 'input[type="checkbox"]' ) ) {
			return;
		}
		selection.mode = 'page';
		renderSelectionBar();
	} );

	if ( selectAllMatchBtn ) {
		selectAllMatchBtn.addEventListener( 'click', function () {
			selection.mode = 'all-matching';
			renderSelectionBar();
		} );
	}

	if ( clearSelectionBtn ) {
		clearSelectionBtn.addEventListener( 'click', resetSelection );
	}

	// -----------------------------------------------------------------
	// AJAX search — fetches this same admin page with an updated `s` param
	// and swaps in only the table region, preserving scroll position.
	// -----------------------------------------------------------------

	function buildRegionUrl( overrides ) {
		var params = new URLSearchParams( window.location.search );
		params.set( 'page', 'storage-sherpa-recovery' );

		Object.keys( overrides || {} ).forEach( function ( key ) {
			if ( overrides[ key ] ) {
				params.set( key, overrides[ key ] );
			} else {
				params.delete( key );
			}
		} );

		return window.location.pathname + '?' + params.toString();
	}

	function reloadTableRegion( url ) {
		url = url || buildRegionUrl( {} );

		return window
			.fetch( url, { credentials: 'same-origin' } )
			.then( function ( res ) {
				return res.text();
			} )
			.then( function ( html ) {
				var doc = new window.DOMParser().parseFromString( html, 'text/html' );
				var fresh = doc.getElementById( 'ss-recovery-table-region' );
				if ( ! fresh ) {
					window.location.href = url;
					return;
				}

				region.setAttribute( 'data-search', fresh.getAttribute( 'data-search' ) || '' );
				region.setAttribute( 'data-file-type', fresh.getAttribute( 'data-file-type' ) || '' );
				region.setAttribute( 'data-total-items', fresh.getAttribute( 'data-total-items' ) || '0' );
				region.innerHTML = fresh.innerHTML;

				resetSelection();
				window.history.replaceState( null, '', url );
			} );
	}

	if ( searchInput ) {
		var debounceTimer = null;

		searchInput.addEventListener( 'input', function () {
			window.clearTimeout( debounceTimer );
			debounceTimer = window.setTimeout( function () {
				reloadTableRegion( buildRegionUrl( { s: searchInput.value, paged: '' } ) );
			}, SEARCH_DEBOUNCE_MS );
		} );
	}

	if ( searchForm ) {
		searchForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			window.clearTimeout( debounceTimer );
			reloadTableRegion( buildRegionUrl( { s: searchInput.value, paged: '' } ) );
		} );
	}

	// Delegated on region (not bound directly to the <select>) — the
	// file-type filter lives inside the table region's own tablenav, which
	// gets replaced wholesale on every AJAX search/filter swap. A listener
	// bound to the original select node would stop firing the moment that
	// node is replaced; region itself is never replaced (only its
	// innerHTML), so delegating here survives every swap without needing to
	// rebind anything. Mirrors storage-sherpa-media.js's identical pattern.
	region.addEventListener( 'change', function ( e ) {
		var select = e.target.closest( '#ss-recovery-filetype' );
		if ( ! select ) {
			return;
		}
		reloadTableRegion( buildRegionUrl( { file_type: select.value, paged: '' } ) );
	} );

	// -----------------------------------------------------------------
	// Chunked bulk restore / delete with a progress bar
	// -----------------------------------------------------------------

	function showProgress( done, total ) {
		if ( ! progressEl ) {
			return;
		}
		progressEl.hidden = false;
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		if ( progressFill ) {
			progressFill.style.width = pct + '%';
		}
		if ( progressLabel ) {
			progressLabel.textContent = StorageSherpaRecovery.i18n.progress
				.replace( '%1$d', done )
				.replace( '%2$d', total );
		}
	}

	function hideProgress() {
		if ( progressEl ) {
			progressEl.hidden = true;
		}
	}

	function bulkEndpointFor( action ) {
		return 'restore' === action
			? '/storage-sherpa/v1/trash/restore-bulk'
			: '/storage-sherpa/v1/trash/delete-bulk';
	}

	/**
	 * Walks `ids` in small CHUNK_SIZE batches against the bulk endpoint for
	 * `action`, one request at a time — never the whole list at once — so a
	 * selection of any size stays well inside normal PHP time/memory limits
	 * per request.
	 */
	function runChunked( ids, action ) {
		var total = ids.length;
		var done = 0;
		selection.cancelRequested = false;
		showProgress( 0, total );

		function next() {
			if ( selection.cancelRequested || done >= total ) {
				return Promise.resolve();
			}

			var chunk = ids.slice( done, done + CHUNK_SIZE );

			return apiFetch( bulkEndpointFor( action ), {
				method: 'POST',
				data: { ids: chunk },
			} ).then( function () {
				done += chunk.length;
				showProgress( done, total );
				return next();
			} );
		}

		return next().then( function () {
			hideProgress();
			resetSelection();
			return reloadTableRegion();
		} );
	}

	if ( progressCancelBtn ) {
		progressCancelBtn.addEventListener( 'click', function () {
			selection.cancelRequested = true;
		} );
	}

	var bulkForm = document.getElementById( 'ss-recovery-bulk-form' );
	if ( bulkForm ) {
		// Delegated on the region (the form is replaced wholesale on every
		// search/refresh, so a listener bound to the original node would stop
		// firing after the first AJAX swap).
		region.addEventListener( 'submit', function ( e ) {
			var form = e.target.closest( 'form[data-ss-bulk-recovery]' );
			if ( ! form ) {
				return;
			}

			e.preventDefault();

			var select = document.getElementById( 'ss-recovery-bulk-action' );
			var action = select ? select.value : '';
			if ( ! action || '-1' === action ) {
				return;
			}

			// Only "Delete Permanently" needs confirming — Restore matches
			// the single-row Restore button, which has never required one.
			var needsConfirm = 'delete' === action;

			if ( 'all-matching' === selection.mode ) {
				if ( needsConfirm && ! window.confirm( StorageSherpaRecovery.i18n.confirmDeleteAll.replace( '%d', totalItems() ) ) ) {
					return;
				}

				if ( progressLabel ) {
					progressLabel.textContent = StorageSherpaRecovery.i18n.fetchingIds;
				}
				if ( progressEl ) {
					progressEl.hidden = false;
				}

				apiFetch(
					'/storage-sherpa/v1/trash/ids?' + new URLSearchParams( {
						search: currentSearch(),
						file_type: currentFileType(),
					} ).toString()
				).then( function ( res ) {
					runChunked( res.ids, action );
				} );

				return;
			}

			var ids = checkedIds();
			if ( ! ids.length ) {
				return;
			}
			if ( needsConfirm && ! window.confirm( StorageSherpa.i18n.confirmPermanent ) ) {
				return;
			}

			runChunked( ids, action );
		} );
	}
} )();
