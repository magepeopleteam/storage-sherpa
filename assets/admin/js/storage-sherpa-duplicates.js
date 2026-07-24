/**
 * Duplicates tab only (enqueued conditionally by
 * SS_Admin::enqueue_assets() when on the Media Findings screen with
 * tab=duplicate). Two per-group actions, neither of which fit the generic
 * [data-ss-action]/[data-ss-bulk-trash] handlers in storage-sherpa-admin.js
 * since both need to read which radio is checked within their own group
 * before deciding what to send:
 *
 *  - [data-ss-merge-group]: POSTs /media/duplicate/merge with the group's
 *    hash and whichever attachment id's radio is checked ("keep this one").
 *  - [data-ss-trash-others]: POSTs the existing /media/trash with every
 *    *other* item's finding id — a plain delete, no re-pointing.
 *
 * No build step, same plain-ES2017 style as storage-sherpa-admin.js.
 */
( function () {
	'use strict';

	function apiFetch( path, options ) {
		return window.StorageSherpaApi.fetch( path, options );
	}

	function setStatus( group, text ) {
		var el = group.querySelector( '.ss-status' );
		if ( el ) {
			el.textContent = text;
		}
	}

	function checkedItem( group ) {
		var checked = group.querySelector( 'input[type="radio"]:checked' );
		return checked ? checked.closest( '.ss-dup-item' ) : null;
	}

	function allItems( group ) {
		return Array.prototype.slice.call( group.querySelectorAll( '.ss-dup-item' ) );
	}

	document.addEventListener( 'click', function ( e ) {
		var mergeBtn = e.target.closest( '[data-ss-merge-group]' );
		var trashBtn = e.target.closest( '[data-ss-trash-others]' );

		if ( ! mergeBtn && ! trashBtn ) {
			return;
		}

		var btn   = mergeBtn || trashBtn;
		var group = btn.closest( '.ss-dup-group' );
		if ( ! group ) {
			return;
		}

		var keepItem = checkedItem( group );
		if ( ! keepItem ) {
			return;
		}

		if ( mergeBtn ) {
			handleMerge( group, btn, keepItem );
		} else {
			handleTrashOthers( group, btn, keepItem );
		}
	} );

	function handleMerge( group, btn, keepItem ) {
		var groupHash = group.getAttribute( 'data-group-hash' );
		var keepId    = keepItem.getAttribute( 'data-attachment-id' );

		btn.disabled = true;
		setStatus( group, StorageSherpa.i18n.working );

		apiFetch( '/storage-sherpa/v1/media/duplicate/merge', {
			method: 'POST',
			data: { group_hash: groupHash, keep_id: keepId },
		} )
			.then( function () {
				setStatus( group, StorageSherpa.i18n.done );
				window.location.reload();
			} )
			.catch( function ( err ) {
				setStatus( group, ( err && err.message ) || StorageSherpa.i18n.error );
				btn.disabled = false;
			} );
	}

	function handleTrashOthers( group, btn, keepItem ) {
		var confirmMsg = btn.getAttribute( 'data-ss-confirm' );
		if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
			return;
		}

		var keepId = keepItem.getAttribute( 'data-attachment-id' );
		var ids = allItems( group )
			.filter( function ( item ) {
				return item.getAttribute( 'data-attachment-id' ) !== keepId;
			} )
			.map( function ( item ) {
				return parseInt( item.getAttribute( 'data-finding-id' ), 10 );
			} );

		if ( ! ids.length ) {
			return;
		}

		btn.disabled = true;
		setStatus( group, StorageSherpa.i18n.working );

		apiFetch( '/storage-sherpa/v1/media/trash', {
			method: 'POST',
			data: { ids: ids },
		} )
			.then( function () {
				setStatus( group, StorageSherpa.i18n.done );
				window.location.reload();
			} )
			.catch( function ( err ) {
				setStatus( group, ( err && err.message ) || StorageSherpa.i18n.error );
				btn.disabled = false;
			} );
	}
} )();
