/**
 * Image Optimizer screen only (enqueued conditionally by
 * SS_Admin::enqueue_assets() when $plugin_page === 'storage-sherpa-images').
 * Per-row WebP/AVIF/Compress buttons already work via the generic
 * [data-ss-action] handler in storage-sherpa-admin.js — this file only adds
 * the bulk action: walk every checked image ONE AT A TIME against the same
 * per-image REST routes (there's no bulk REST endpoint for these — each
 * WebP/AVIF/compress call does real image encoding), so a selection of any
 * size stays inside normal PHP time/memory limits instead of one giant
 * request, with a progress bar and a Cancel button.
 *
 * No build step, same plain-ES2017 style as storage-sherpa-admin.js.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'ss-images-bulk-form' );
	if ( ! form ) {
		return;
	}

	var progressEl       = document.getElementById( 'ss-images-progress' );
	var progressFill      = progressEl ? progressEl.querySelector( '.ss-bulk-progress-fill' ) : null;
	var progressLabel     = document.getElementById( 'ss-images-progress-label' );
	var progressCancelBtn = document.getElementById( 'ss-images-progress-cancel' );

	var cancelRequested = false;

	function apiFetch( path, options ) {
		return window.StorageSherpaApi.fetch( path, options );
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
			progressLabel.textContent = StorageSherpaImages.i18n.progress
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
	 * One bulk action can mean more than one REST call per image (WebP +
	 * AVIF together is two calls) — chained sequentially per image so a
	 * failure on one format doesn't skip the other.
	 */
	function suffixesForAction( action ) {
		switch ( action ) {
			case 'webp':
				return [ 'webp' ];
			case 'avif':
				return [ 'avif' ];
			case 'webp_avif':
				return [ 'webp', 'avif' ];
			case 'compress':
				return [ 'compress' ];
			default:
				return [];
		}
	}

	function processOne( id, suffixes ) {
		return suffixes.reduce( function ( chain, suffix ) {
			return chain.then( function () {
				return apiFetch( '/storage-sherpa/v1/images/' + id + '/' + suffix, { method: 'POST' } );
			} );
		}, Promise.resolve() );
	}

	if ( progressCancelBtn ) {
		progressCancelBtn.addEventListener( 'click', function () {
			cancelRequested = true;
		} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var select = document.getElementById( 'ss-images-bulk-action' );
		var action = select ? select.value : '';
		if ( ! action || '-1' === action ) {
			return;
		}

		var suffixes = suffixesForAction( action );
		if ( ! suffixes.length ) {
			return;
		}

		var ids = Array.prototype.slice
			.call( form.querySelectorAll( 'input[name="ss_ids[]"]:checked' ) )
			.map( function ( cb ) {
				return parseInt( cb.value, 10 );
			} );

		if ( ! ids.length ) {
			return;
		}

		if ( 'compress' === action && ! window.confirm( StorageSherpaImages.i18n.confirmCompress ) ) {
			return;
		}

		cancelRequested = false;
		var total = ids.length;
		var done = 0;
		showProgress( 0, total );

		function next() {
			if ( cancelRequested || done >= total ) {
				return Promise.resolve();
			}

			return processOne( ids[ done ], suffixes )
				.catch( function () {
					// Keep going on a single image's failure (e.g. an
					// unsupported format for that file) rather than
					// abandoning the rest of the selection.
					return null;
				} )
				.then( function () {
					done++;
					showProgress( done, total );
					return next();
				} );
		}

		next().then( function () {
			hideProgress();
			window.location.reload();
		} );
	} );
} )();
