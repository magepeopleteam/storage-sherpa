/**
 * Dashboard (Overview) — a real wp.element (React, bundled with WordPress
 * core) app, hand-written with createElement rather than JSX/a build step,
 * same choice the sibling passpress plugin made for its Gutenberg blocks.
 * Charts are plain inline SVG/CSS, not Chart.js — nothing here needed a
 * charting library's interaction features (tooltips, zoom, etc.), and
 * pulling one in just for a pie + a sparkline wasn't worth the extra
 * dependency. See CLAUDE.md → "Frontend: React without a build step".
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;

	function formatBytes( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var value = Math.max( 0, bytes || 0 );
		var power = value > 0 ? Math.floor( Math.log( value ) / Math.log( 1024 ) ) : 0;
		power = Math.min( power, units.length - 1 );
		return ( value / Math.pow( 1024, power ) ).toFixed( 2 ) + ' ' + units[ power ];
	}

	var PALETTE = [ '#4F46E5', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899' ];

	function DonutChart( props ) {
		var totals = props.totals;
		var keys = Object.keys( totals ).filter( function ( k ) {
			return totals[ k ].size > 0;
		} );
		var sum = keys.reduce( function ( acc, k ) {
			return acc + totals[ k ].size;
		}, 0 );

		if ( ! sum ) {
			return el( 'p', { className: 'ss-muted' }, __( 'No scan data yet — run a scan to populate this chart.', 'storage-sherpa' ) );
		}

		var circumference = 2 * Math.PI * 15.9155;
		var offset = 0;
		var segments = keys.map( function ( key, i ) {
			var fraction = totals[ key ].size / sum;
			var dash = fraction * circumference;
			var seg = el( 'circle', {
				key: key,
				cx: '18',
				cy: '18',
				r: '15.9155',
				fill: 'transparent',
				stroke: PALETTE[ i % PALETTE.length ],
				strokeWidth: '4',
				strokeDasharray: dash + ' ' + ( circumference - dash ),
				strokeDashoffset: -offset,
			} );
			offset += dash;
			return seg;
		} );

		var legend = keys.map( function ( key, i ) {
			return el(
				'li',
				{ key: key },
				el( 'span', { className: 'ss-swatch', style: { background: PALETTE[ i % PALETTE.length ] } } ),
				totals[ key ].label + ': ' + formatBytes( totals[ key ].size )
			);
		} );

		return el(
			'div',
			{ className: 'ss-donut-wrap' },
			el( 'svg', { viewBox: '0 0 36 36', className: 'ss-donut' }, segments ),
			el( 'ul', { className: 'ss-legend' }, legend )
		);
	}

	function TrendChart( props ) {
		var history = props.history || [];
		if ( history.length < 2 ) {
			return el( 'p', { className: 'ss-muted' }, __( 'Not enough scan history yet for a trend line.', 'storage-sherpa' ) );
		}

		var max = Math.max.apply( null, history.map( function ( h ) { return h.size; } ) );
		var w = 100;
		var h = 40;
		var points = history
			.map( function ( row, i ) {
				var x = ( i / ( history.length - 1 ) ) * w;
				var y = h - ( max > 0 ? ( row.size / max ) * h : 0 );
				return x.toFixed( 2 ) + ',' + y.toFixed( 2 );
			} )
			.join( ' ' );

		return el(
			'svg',
			{ viewBox: '0 0 ' + w + ' ' + h, className: 'ss-trend', preserveAspectRatio: 'none' },
			el( 'polyline', { points: points, fill: 'none', stroke: '#4F46E5', strokeWidth: '1.5' } )
		);
	}

	function StatCard( props ) {
		return el(
			'div',
			{ className: 'ss-card' },
			el( 'div', { className: 'ss-card-label' }, props.label ),
			el( 'div', { className: 'ss-card-value' }, props.value )
		);
	}

	function App() {
		var stateData = useState( null );
		var data = stateData[ 0 ];
		var setData = stateData[ 1 ];

		var stateScanning = useState( false );
		var scanning = stateScanning[ 0 ];
		var setScanning = stateScanning[ 1 ];

		var stateProgress = useState( '' );
		var progress = stateProgress[ 0 ];
		var setProgress = stateProgress[ 1 ];

		function load() {
			wp.apiFetch( { path: '/storage-sherpa/v1/overview' } ).then( setData );
		}

		useEffect( function () {
			load();
		}, [] );

		function runScan() {
			setScanning( true );
			wp.apiFetch( { path: '/storage-sherpa/v1/scan/start', method: 'POST' } ).then( function ( state ) {
				step( state.job_id );
			} );

			function step( jobId ) {
				wp.apiFetch( { path: '/storage-sherpa/v1/scan/step', method: 'POST', data: { job_id: jobId } } ).then( function ( state ) {
					setProgress( state.current + ' / ' + state.steps.length );
					if ( 'complete' === state.status ) {
						setScanning( false );
						setProgress( '' );
						load();
					} else {
						step( jobId );
					}
				} );
			}
		}

		if ( ! data ) {
			return el( 'p', null, __( 'Loading…', 'storage-sherpa' ) );
		}

		var totalBytes = Object.keys( data.totals ).reduce( function ( acc, k ) {
			return acc + data.totals[ k ].size;
		}, 0 );

		return el(
			'div',
			{ className: 'ss-dashboard' },
			el(
				'div',
				{ className: 'ss-toolbar' },
				el(
					wp.components.Button,
					{ variant: 'primary', isBusy: scanning, disabled: scanning, onClick: runScan },
					scanning ? __( 'Scanning…', 'storage-sherpa' ) + ' ' + progress : __( 'Run Full Scan', 'storage-sherpa' )
				),
				data.last_scan
					? el( 'span', { className: 'ss-muted ss-last-scan' }, __( 'Last scan: ', 'storage-sherpa' ) + data.last_scan )
					: null
			),
			el(
				'div',
				{ className: 'ss-stat-grid' },
				el( StatCard, { label: __( 'Total Storage', 'storage-sherpa' ), value: formatBytes( totalBytes ) } ),
				el( StatCard, { label: __( 'Recoverable Space', 'storage-sherpa' ), value: formatBytes( data.recoverable ) } ),
				el( StatCard, { label: __( 'Health Score', 'storage-sherpa' ), value: data.health_score + ' / 100' } ),
				el( StatCard, { label: __( 'Items in Safe Trash', 'storage-sherpa' ), value: data.trash_pending } )
			),
			el(
				'div',
				{ className: 'ss-panel-grid' },
				el(
					'div',
					{ className: 'ss-panel' },
					el( 'h2', null, __( 'Storage Overview', 'storage-sherpa' ) ),
					el( DonutChart, { totals: data.totals } )
				),
				el(
					'div',
					{ className: 'ss-panel' },
					el( 'h2', null, __( 'Storage Trend — Last 30 Days', 'storage-sherpa' ) ),
					el( TrendChart, { history: data.growth_history } )
				),
				el(
					'div',
					{ className: 'ss-panel' },
					el( 'h2', null, __( 'Largest Directories', 'storage-sherpa' ) ),
					data.largest_dirs.length
						? el(
							'table',
							{ className: 'ss-table' },
							el(
								'tbody',
								null,
								data.largest_dirs.map( function ( dir ) {
									return el(
										'tr',
										{ key: dir.path },
										el( 'td', null, dir.label ),
										el( 'td', null, formatBytes( dir.size ) )
									);
								} )
							)
						)
						: el( 'p', { className: 'ss-muted' }, __( 'Run a scan to see the largest directories.', 'storage-sherpa' ) )
				),
				el(
					'div',
					{ className: 'ss-panel' },
					el( 'h2', null, __( 'Quick Links', 'storage-sherpa' ) ),
					el(
						'ul',
						{ className: 'ss-quicklinks' },
						[
							[ __( 'Media Findings', 'storage-sherpa' ), 'storage-sherpa-media' ],
							[ __( 'Database Cleanup', 'storage-sherpa' ), 'storage-sherpa-database' ],
							[ __( 'Backups', 'storage-sherpa' ), 'storage-sherpa-backups' ],
							[ __( 'Recovery Center', 'storage-sherpa' ), 'storage-sherpa-recovery' ],
						].map( function ( link ) {
							return el(
								'li',
								{ key: link[ 1 ] },
								el( 'a', { href: 'admin.php?page=' + link[ 1 ] }, link[ 0 ] )
							);
						} )
					)
				)
			)
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.getElementById( 'storage-sherpa-dashboard-root' );
		if ( root ) {
			wp.element.render( el( App ), root );
		}
	} );
} )( window.wp );
