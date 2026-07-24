/**
 * Media Findings screen only (enqueued conditionally by
 * SS_Admin::enqueue_assets() when $plugin_page === 'storage-sherpa-media').
 * Adds three things on top of the generic storage-sherpa-admin.js helpers:
 *
 *  - Debounced AJAX file-name search: re-fetches this same admin.php URL
 *    with an updated `s` param and swaps in just the table region, instead
 *    of a full page reload per keystroke.
 *  - Selection tracking across the current page's checkboxes, plus a
 *    "select all N items matching this filter" upgrade (via a dedicated
 *    /media/{type}/ids lookup) for selecting beyond what's paginated
 *    on-screen.
 *  - Chunked bulk delete: whatever is selected — a handful of checked rows
 *    or every item matching the filter — is walked in small batches against
 *    /media/trash rather than one request for the whole selection, so a
 *    very large cleanup can't time out or exhaust memory. All chunks share
 *    one batch_id so the resulting Undo toast restores the entire
 *    selection, not just the last chunk.
 *
 * No build step, same plain-ES2017 style as storage-sherpa-admin.js.
 */
( function () {
	'use strict';

	var CHUNK_SIZE = 25;
	var SEARCH_DEBOUNCE_MS = 350;

	var region = document.getElementById( 'ss-media-table-region' );
	if ( ! region ) {
		return;
	}

	var searchInput      = document.getElementById( 'ss-media-search' );
	var searchForm       = document.getElementById( 'ss-media-search-form' );
	var selectionBar     = document.getElementById( 'ss-media-selection-bar' );
	var selectionText    = document.getElementById( 'ss-media-selection-text' );
	var selectAllMatchBtn = document.getElementById( 'ss-media-select-all-matching' );
	var clearSelectionBtn = document.getElementById( 'ss-media-clear-selection' );
	var progressEl        = document.getElementById( 'ss-media-progress' );
	var progressFill       = progressEl ? progressEl.querySelector( '.ss-media-progress-fill' ) : null;
	var progressLabel      = document.getElementById( 'ss-media-progress-label' );
	var progressCancelBtn  = document.getElementById( 'ss-media-progress-cancel' );

	// mode: 'none' | 'page' | 'all-matching'
	var selection = { mode: 'none', cancelRequested: false };

	function apiFetch( path, options ) {
		return window.StorageSherpaApi.fetch( path, options );
	}

	function findingType() {
		return region.getAttribute( 'data-tab' );
	}

	function currentStatus() {
		return region.getAttribute( 'data-status' ) || '';
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
			selectionText.textContent = StorageSherpaMedia.i18n.allSelected.replace( '%d', totalItems() );
			selectAllMatchBtn.hidden = true;
			selectionBar.hidden = false;
			return;
		}

		var ids = checkedIds();

		if ( ! ids.length ) {
			selectionBar.hidden = true;
			return;
		}

		selectionText.textContent = StorageSherpaMedia.i18n.nSelected.replace( '%d', ids.length );

		var allOnPageChecked = ids.length === checkedCheckboxes().length && checkedCheckboxes().length > 0;
		if ( allOnPageChecked && totalItems() > ids.length ) {
			selectAllMatchBtn.textContent = StorageSherpaMedia.i18n.selectAllMatching.replace( '%d', totalItems() );
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
		// Any manual checkbox change while in "all matching" mode means the
		// selection is no longer literally "everything" — fall back to a
		// plain page-level selection re-computed from the DOM.
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
	// and swaps in only the table region, preserving tab/status/scroll.
	// -----------------------------------------------------------------

	function buildRegionUrl( overrides ) {
		var params = new URLSearchParams( window.location.search );
		params.set( 'page', 'storage-sherpa-media' );
		params.set( 'tab', findingType() );

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
				var fresh = doc.getElementById( 'ss-media-table-region' );
				if ( ! fresh ) {
					window.location.href = url;
					return;
				}

				region.setAttribute( 'data-status', fresh.getAttribute( 'data-status' ) || '' );
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

	// Delegated on region (not bound directly to the <select>) — the file-type
	// filter lives inside WP_List_Table's own tablenav (via extra_tablenav()),
	// which is part of #ss-media-table-region and gets replaced wholesale on
	// every AJAX search/filter swap. A listener bound to the original select
	// node would stop firing the moment that node is replaced; region itself
	// is never replaced (only its innerHTML), so delegating here survives
	// every swap without needing to rebind anything.
	region.addEventListener( 'change', function ( e ) {
		var select = e.target.closest( '#ss-media-filetype' );
		if ( ! select ) {
			return;
		}
		reloadTableRegion( buildRegionUrl( { file_type: select.value, paged: '' } ) );
	} );

	// -----------------------------------------------------------------
	// Chunked bulk delete with a progress bar
	// -----------------------------------------------------------------

	function generateBatchId() {
		return 'ss' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 10 );
	}

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
			progressLabel.textContent = StorageSherpaMedia.i18n.progress
				.replace( '%1$d', done )
				.replace( '%2$d', total );
		}
	}

	function hideProgress() {
		if ( progressEl ) {
			progressEl.hidden = true;
		}
	}

	/**
	 * Walks `ids` in small CHUNK_SIZE batches against /media/trash, one
	 * request at a time — never the whole list at once — so a selection of
	 * any size stays well inside normal PHP time/memory limits per request.
	 * Every chunk shares batchId so the whole run restores as one Undo.
	 */
	function deleteChunked( ids, batchId ) {
		var total = ids.length;
		var done = 0;
		selection.cancelRequested = false;
		showProgress( 0, total );

		function next() {
			if ( selection.cancelRequested || done >= total ) {
				return Promise.resolve();
			}

			var chunk = ids.slice( done, done + CHUNK_SIZE );

			return apiFetch( '/storage-sherpa/v1/media/trash', {
				method: 'POST',
				data: { ids: chunk, batch_id: batchId },
			} ).then( function () {
				done += chunk.length;
				showProgress( done, total );
				return next();
			} );
		}

		return next().then( function () {
			hideProgress();
			resetSelection();
			return reloadTableRegion().then( function () {
				if ( done > 0 ) {
					window.StorageSherpaApi.showUndoToast( batchId, function () {
						reloadTableRegion();
					} );
				}
			} );
		} );
	}

	if ( progressCancelBtn ) {
		progressCancelBtn.addEventListener( 'click', function () {
			selection.cancelRequested = true;
		} );
	}

	var bulkForm = document.getElementById( 'ss-media-bulk-form' );
	if ( bulkForm ) {
		// Delegated on the region (the form is replaced wholesale on every
		// search/refresh, so a listener bound to the original node would stop
		// firing after the first AJAX swap).
		region.addEventListener( 'submit', function ( e ) {
			var form = e.target.closest( 'form[data-ss-bulk-trash]' );
			if ( ! form ) {
				return;
			}

			e.preventDefault();

			var select = form.querySelector( 'select[name="ss_bulk_action"]' );
			if ( ! select || '-1' === select.value ) {
				return;
			}

			var batchId = generateBatchId();

			if ( 'all-matching' === selection.mode ) {
				if ( ! window.confirm( StorageSherpaMedia.i18n.confirmAll.replace( '%d', totalItems() ) ) ) {
					return;
				}

				if ( progressLabel ) {
					progressLabel.textContent = StorageSherpaMedia.i18n.fetchingIds;
				}
				if ( progressEl ) {
					progressEl.hidden = false;
				}

				apiFetch(
					'/storage-sherpa/v1/media/' + findingType() + '/ids?' +
						new URLSearchParams( {
							status: currentStatus(),
							search: searchInput ? searchInput.value : '',
							file_type: currentFileType(),
						} ).toString()
				).then( function ( res ) {
					deleteChunked( res.ids, batchId );
				} );

				return;
			}

			var ids = checkedIds();
			if ( ! ids.length ) {
				return;
			}
			if ( ! window.confirm( StorageSherpa.i18n.confirmDelete ) ) {
				return;
			}

			deleteChunked( ids, batchId );
		} );
	}
} )();
