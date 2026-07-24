/**
 * Storage Analyzer — Uploads folder-size treemap.
 *
 * Fetches the nested folder tree from /storage-sherpa/v1/uploads/treemap and
 * lays it out with a squarified treemap algorithm (Bruls et al.), rendered
 * as plain positioned <div> tiles — no charting library, matching this
 * plugin's no-build-step approach. Depth-1 folders each get a fixed slot
 * from the shared categorical palette; depth-2 folders inherit their
 * parent's hue at a lighter tint so nesting still reads as "part of X".
 * Rolled-up "Other folders" / "Files" buckets are always neutral gray, never
 * a hue, so they never look like a real category.
 *
 * No build step: plain ES2017, loaded with wp-api-fetch + this plugin's
 * shared admin.js as dependencies (see SS_Admin::enqueue_assets()).
 */
( function () {
	'use strict';

	// Fixed-order categorical palette (validated for CVD-safe adjacent pairs).
	var CATEGORY_COLORS = [
		'#2a78d6', // blue
		'#008300', // green
		'#e87ba4', // magenta
		'#eda100', // yellow
		'#1baf7a', // aqua
		'#eb6834', // orange
		'#4a3aa7', // violet
		'#e34948'  // red
	];

	var OTHER_FILL = '#b7b6b0';
	var FILES_FILL = '#d3d2cb';

	var tooltipEl = null;

	function parseColor( color ) {
		if ( '#' === color.charAt( 0 ) ) {
			var c = color.replace( '#', '' );
			return {
				r: parseInt( c.substring( 0, 2 ), 16 ),
				g: parseInt( c.substring( 2, 4 ), 16 ),
				b: parseInt( c.substring( 4, 6 ), 16 )
			};
		}
		var parts = color.replace( /[^\d,]/g, '' ).split( ',' );
		return { r: parseInt( parts[ 0 ], 10 ), g: parseInt( parts[ 1 ], 10 ), b: parseInt( parts[ 2 ], 10 ) };
	}

	// Lightens any supported color string (#hex or rgb()) toward white —
	// used to tint a depth-2 child tile with its parent's hue.
	function tint( color, amount ) {
		var rgb = parseColor( color );
		var r = Math.round( rgb.r + ( 255 - rgb.r ) * amount );
		var g = Math.round( rgb.g + ( 255 - rgb.g ) * amount );
		var b = Math.round( rgb.b + ( 255 - rgb.b ) * amount );
		return 'rgb(' + r + ',' + g + ',' + b + ')';
	}

	function relativeLuminance( r, g, b ) {
		function channel( v ) {
			v = v / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * channel( r ) + 0.7152 * channel( g ) + 0.0722 * channel( b );
	}

	function textColorFor( color ) {
		var rgb = parseColor( color );
		return relativeLuminance( rgb.r, rgb.g, rgb.b ) > 0.5 ? '#0b0b0b' : '#ffffff';
	}

	// Depth-1 tiles get their assigned categorical slot at full strength;
	// "Other folders" / "Files" rollups are always neutral, at any depth.
	function colorForTopLevel( node, index ) {
		if ( 'other' === node.kind ) {
			return OTHER_FILL;
		}
		if ( 'files' === node.kind ) {
			return FILES_FILL;
		}
		return CATEGORY_COLORS[ index % CATEGORY_COLORS.length ];
	}

	// Depth-2 tiles inherit the parent tile's fill, tinted lighter — nesting
	// reads as "part of the same top-level folder" rather than a new series.
	function colorForChild( node, parentFill ) {
		if ( 'other' === node.kind ) {
			return OTHER_FILL;
		}
		if ( 'files' === node.kind ) {
			return FILES_FILL;
		}
		return tint( parentFill, 0.4 );
	}

	// --- Squarified treemap layout (Bruls, Huizing, van Wijk) ----------------

	function worstRatio( row, side ) {
		var sum = 0, max = -Infinity, min = Infinity, i;
		for ( i = 0; i < row.length; i++ ) {
			sum += row[ i ].area;
			max = Math.max( max, row[ i ].area );
			min = Math.min( min, row[ i ].area );
		}
		if ( 0 === sum || 0 === min ) {
			return Infinity;
		}
		return Math.max( ( side * side * max ) / ( sum * sum ), ( sum * sum ) / ( side * side * min ) );
	}

	function layoutRow( items, x, y, w, h, results ) {
		if ( ! items.length ) {
			return;
		}

		if ( 1 === items.length ) {
			results.push( { item: items[ 0 ].item, x: x, y: y, w: w, h: h } );
			return;
		}

		var vertical = w >= h;
		var side = vertical ? h : w;

		var row = [ items[ 0 ] ];
		var i = 1;
		while ( i < items.length ) {
			var testRow = row.concat( [ items[ i ] ] );
			if ( worstRatio( testRow, side ) <= worstRatio( row, side ) ) {
				row = testRow;
				i++;
			} else {
				break;
			}
		}

		var rowArea = 0;
		row.forEach( function ( c ) {
			rowArea += c.area;
		} );
		var thickness = side > 0 ? rowArea / side : 0;

		var offset = 0;
		row.forEach( function ( c ) {
			var length = thickness > 0 ? c.area / thickness : 0;
			if ( vertical ) {
				results.push( { item: c.item, x: x, y: y + offset, w: thickness, h: length } );
			} else {
				results.push( { item: c.item, x: x + offset, y: y, w: length, h: thickness } );
			}
			offset += length;
		} );

		var remaining = items.slice( row.length );
		if ( vertical ) {
			layoutRow( remaining, x + thickness, y, w - thickness, h, results );
		} else {
			layoutRow( remaining, x, y + thickness, w, h - thickness, results );
		}
	}

	function squarify( nodes, x, y, w, h ) {
		var total = 0, i;
		for ( i = 0; i < nodes.length; i++ ) {
			total += Math.max( 0, nodes[ i ].size );
		}

		var results = [];
		if ( total <= 0 || 0 === w || 0 === h ) {
			return results;
		}

		var scale = ( w * h ) / total;
		var scaled = nodes
			.filter( function ( n ) {
				return n.size > 0;
			} )
			.map( function ( n ) {
				return { item: n, area: n.size * scale };
			} );

		layoutRow( scaled, x, y, w, h, results );
		return results;
	}

	// --- Rendering -------------------------------------------------------------

	function formatSize( bytes ) {
		return window.StorageSherpaApi.formatBytes( bytes );
	}

	function ensureTooltip() {
		if ( ! tooltipEl ) {
			tooltipEl = document.createElement( 'div' );
			tooltipEl.className = 'ss-treemap-tooltip';
			tooltipEl.setAttribute( 'role', 'tooltip' );
			tooltipEl.hidden = true;
			document.body.appendChild( tooltipEl );
		}
		return tooltipEl;
	}

	function showTooltip( node, x, y ) {
		var tip = ensureTooltip();
		tip.textContent = '';

		var title = document.createElement( 'strong' );
		title.textContent = node.name;

		var size = document.createElement( 'div' );
		size.textContent = formatSize( node.size ) + ' · ' + node.files.toLocaleString() + ' ' +
			( 1 === node.files ? StorageSherpaTreemap.i18n.file : StorageSherpaTreemap.i18n.files );

		tip.appendChild( title );
		tip.appendChild( size );

		if ( node.path ) {
			var path = document.createElement( 'div' );
			path.className = 'ss-treemap-tooltip-path';
			path.textContent = node.path;
			tip.appendChild( path );
		}

		tip.hidden = false;

		var vw = window.innerWidth;
		var vh = window.innerHeight;
		var left = x + 14;
		var top = y + 14;
		var tipWidth = 260;
		if ( left + tipWidth > vw ) {
			left = x - tipWidth - 14;
		}
		if ( top + 90 > vh ) {
			top = y - 90;
		}

		tip.style.left = Math.max( 4, left ) + 'px';
		tip.style.top = Math.max( 4, top ) + 'px';
	}

	function hideTooltip() {
		if ( tooltipEl ) {
			tooltipEl.hidden = true;
		}
	}

	function buildTileLabel( node, tileW, tileH ) {
		var wrap = document.createDocumentFragment();

		if ( tileW < 46 || tileH < 24 ) {
			return wrap;
		}

		var label = document.createElement( 'div' );
		label.className = 'ss-treemap-tile-label';
		label.textContent = node.name;
		wrap.appendChild( label );

		if ( tileH >= 38 ) {
			var size = document.createElement( 'div' );
			size.className = 'ss-treemap-tile-size';
			size.textContent = formatSize( node.size );
			wrap.appendChild( size );
		}

		return wrap;
	}

	function renderTile( container, node, rect, fill, depth ) {
		var tile = document.createElement( 'div' );
		tile.className = 'ss-treemap-tile ss-treemap-depth-' + depth;
		tile.style.left = rect.x + 'px';
		tile.style.top = rect.y + 'px';
		tile.style.width = Math.max( 0, rect.w ) + 'px';
		tile.style.height = Math.max( 0, rect.h ) + 'px';
		tile.style.background = fill;
		tile.style.color = textColorFor( fill );

		tile.tabIndex = 0;
		tile.setAttribute( 'role', 'button' );
		tile.setAttribute(
			'aria-label',
			node.name + ', ' + formatSize( node.size ) + ', ' +
				node.files.toLocaleString() + ' ' + ( 1 === node.files ? StorageSherpaTreemap.i18n.file : StorageSherpaTreemap.i18n.files ) +
				( node.path ? ', ' + node.path : '' )
		);

		// Nested children fill this tile's whole box with their own labels —
		// showing this tile's label too would overlap them, so it only gets
		// one when it renders as a plain (unsubdivided) leaf.
		var showsNestedChildren = node.children && node.children.length && rect.w >= 40 && rect.h >= 40;
		if ( ! showsNestedChildren ) {
			tile.appendChild( buildTileLabel( node, rect.w, rect.h ) );
		}

		tile.addEventListener( 'pointerenter', function ( e ) {
			showTooltip( node, e.clientX, e.clientY );
		} );
		tile.addEventListener( 'pointermove', function ( e ) {
			showTooltip( node, e.clientX, e.clientY );
		} );
		tile.addEventListener( 'pointerleave', hideTooltip );
		tile.addEventListener( 'focus', function () {
			var box = tile.getBoundingClientRect();
			showTooltip( node, box.left + box.width / 2, box.top + box.height / 2 );
		} );
		tile.addEventListener( 'blur', hideTooltip );

		container.appendChild( tile );

		// Nest depth-2 children inside their parent tile's own box, in local
		// (0,0)-(w,h) coordinates, only when there is meaningful room.
		if ( showsNestedChildren ) {
			var innerRects = squarify( node.children, 0, 0, rect.w, rect.h );
			innerRects.forEach( function ( ir ) {
				if ( ir.w < 2 || ir.h < 2 ) {
					return;
				}
				renderTile( tile, ir.item, ir, colorForChild( ir.item, fill ), depth + 1 );
			} );
		}
	}

	function renderLegend( legendEl, topLevel ) {
		legendEl.textContent = '';

		var hasOther = false;

		topLevel.forEach( function ( node, index ) {
			if ( 'other' === node.kind || 'files' === node.kind ) {
				hasOther = true;
				return;
			}

			var li = document.createElement( 'li' );
			var swatch = document.createElement( 'span' );
			swatch.className = 'ss-swatch';
			swatch.style.background = CATEGORY_COLORS[ index % CATEGORY_COLORS.length ];

			var label = document.createElement( 'span' );
			label.textContent = node.name + ' — ' + formatSize( node.size );

			li.appendChild( swatch );
			li.appendChild( label );
			legendEl.appendChild( li );
		} );

		if ( hasOther ) {
			var li = document.createElement( 'li' );
			var swatch = document.createElement( 'span' );
			swatch.className = 'ss-swatch';
			swatch.style.background = OTHER_FILL;

			var label = document.createElement( 'span' );
			label.textContent = StorageSherpaTreemap.i18n.other;

			li.appendChild( swatch );
			li.appendChild( label );
			legendEl.appendChild( li );
		}
	}

	function renderTable( tableEl, root ) {
		var tbody = tableEl.querySelector( 'tbody' );
		tbody.textContent = '';

		if ( ! root.children || ! root.children.length ) {
			var emptyRow = document.createElement( 'tr' );
			var emptyCell = document.createElement( 'td' );
			emptyCell.colSpan = 3;
			emptyCell.textContent = StorageSherpaTreemap.i18n.empty;
			emptyRow.appendChild( emptyCell );
			tbody.appendChild( emptyRow );
			return;
		}

		root.children.forEach( function ( node ) {
			tbody.appendChild( buildTableRow( node.name, node ) );

			( node.children || [] ).forEach( function ( child ) {
				tbody.appendChild( buildTableRow( '— ' + child.name, child ) );
			} );
		} );
	}

	function buildTableRow( label, node ) {
		var row = document.createElement( 'tr' );

		var nameCell = document.createElement( 'td' );
		nameCell.textContent = label;

		var filesCell = document.createElement( 'td' );
		filesCell.textContent = node.files.toLocaleString();

		var sizeCell = document.createElement( 'td' );
		sizeCell.textContent = formatSize( node.size );

		row.appendChild( nameCell );
		row.appendChild( filesCell );
		row.appendChild( sizeCell );

		return row;
	}

	function renderTreemap( root ) {
		var container = document.getElementById( 'ss-treemap-root' );
		var legendEl = document.getElementById( 'ss-treemap-legend' );
		var tableEl = document.getElementById( 'ss-treemap-table' );

		if ( ! container ) {
			return;
		}

		container.textContent = '';

		var topLevel = root.children || [];

		if ( ! topLevel.length || 0 === root.size ) {
			var empty = document.createElement( 'p' );
			empty.className = 'ss-muted';
			empty.textContent = StorageSherpaTreemap.i18n.empty;
			container.appendChild( empty );
			if ( legendEl ) {
				legendEl.textContent = '';
			}
			if ( tableEl ) {
				renderTable( tableEl, root );
			}
			return;
		}

		var rect = container.getBoundingClientRect();
		var placed = squarify( topLevel, 0, 0, rect.width, rect.height );

		placed.forEach( function ( p ) {
			if ( p.w < 1 || p.h < 1 ) {
				return;
			}
			var index = topLevel.indexOf( p.item );
			var color = colorForTopLevel( p.item, index );
			renderTile( container, p.item, p, color, 1 );
		} );

		if ( legendEl ) {
			renderLegend( legendEl, topLevel );
		}
		if ( tableEl ) {
			renderTable( tableEl, root );
		}
	}

	function loadTreemap() {
		var container = document.getElementById( 'ss-treemap-root' );
		if ( ! container ) {
			return;
		}

		window.StorageSherpaApi.fetch( '/storage-sherpa/v1/uploads/treemap' )
			.then( function ( root ) {
				renderTreemap( root );

				var resizeTimer = null;
				window.addEventListener( 'resize', function () {
					window.clearTimeout( resizeTimer );
					resizeTimer = window.setTimeout( function () {
						renderTreemap( root );
					}, 150 );
				} );
			} )
			.catch( function () {
				container.textContent = '';
				var error = document.createElement( 'p' );
				error.className = 'ss-muted';
				error.textContent = StorageSherpaTreemap.i18n.error;
				container.appendChild( error );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', loadTreemap );
} )();
