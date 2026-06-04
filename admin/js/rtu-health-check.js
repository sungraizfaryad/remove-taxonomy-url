( function () {
	'use strict';

	const button = document.getElementById( 'rtu-run-audit' );
	if ( ! button ) {
		return;
	}
	const out     = document.getElementById( 'rtu-audit-results' );
	const spinner = button.nextElementSibling;
	const labels  = ( window.rtuHealthCheckL10n || {} );

	function buildTable( rows ) {
		const table = document.createElement( 'table' );
		table.className = 'widefat striped';

		const thead = document.createElement( 'thead' );
		const headRow = document.createElement( 'tr' );
		[ labels.taxonomy, labels.termSlug, labels.conflictsWith ].forEach( function ( text ) {
			const th = document.createElement( 'th' );
			th.textContent = text || '';
			headRow.appendChild( th );
		} );
		thead.appendChild( headRow );
		table.appendChild( thead );

		const tbody = document.createElement( 'tbody' );
		rows.forEach( function ( row ) {
			const tr = document.createElement( 'tr' );

			const taxCell = document.createElement( 'td' );
			taxCell.textContent = row.taxonomy || '';
			tr.appendChild( taxCell );

			const slugCell = document.createElement( 'td' );
			slugCell.textContent = row.slug || '';
			tr.appendChild( slugCell );

			const conflictsCell = document.createElement( 'td' );
			( row.conflicts || [] ).forEach( function ( c, index ) {
				if ( index > 0 ) {
					conflictsCell.appendChild( document.createElement( 'br' ) );
				}
				const span = document.createElement( 'span' );
				span.textContent = ( c.type || '' ) + ': ' + ( c.label || '' );
				conflictsCell.appendChild( span );
			} );
			tr.appendChild( conflictsCell );

			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );

		return table;
	}

	function setMessage( text ) {
		while ( out.firstChild ) {
			out.removeChild( out.firstChild );
		}
		const p = document.createElement( 'p' );
		p.textContent = text;
		out.appendChild( p );
	}

	const statusBox = document.getElementById( 'rtu-hierarchy-status' );

	function renderHierarchyStatus( h ) {
		if ( ! statusBox || ! h ) {
			return;
		}
		while ( statusBox.firstChild ) {
			statusBox.removeChild( statusBox.firstChild );
		}
		const p = document.createElement( 'p' );
		const shape = h.child_url_shape === 'nested' ? '/parent/child/' : '/child/';
		p.textContent = ( labels.hierarchyLabel || 'Hierarchical term URLs' ) + ': '
			+ ( h.enabled ? ( labels.on || 'ON' ) : ( labels.off || 'OFF' ) )
			+ ' — ' + ( labels.childUrls || 'child terms use' ) + ' ' + shape
			+ ' (' + ( h.child_terms || 0 ) + ' ' + ( labels.childTerms || 'child terms' ) + ')';
		statusBox.appendChild( p );
	}

	function buildSelftestTable( rows ) {
		const table = document.createElement( 'table' );
		table.className = 'widefat striped';
		const thead = document.createElement( 'thead' );
		const hr = document.createElement( 'tr' );
		[ labels.taxonomy, labels.termSlug, 'URL', labels.result || 'Result' ].forEach( function ( t ) {
			const th = document.createElement( 'th' );
			th.textContent = t || '';
			hr.appendChild( th );
		} );
		thead.appendChild( hr );
		table.appendChild( thead );
		const tbody = document.createElement( 'tbody' );
		rows.forEach( function ( r ) {
			const tr = document.createElement( 'tr' );
			[ r.taxonomy, r.slug, r.url ].forEach( function ( v ) {
				const td = document.createElement( 'td' );
				td.textContent = v || '';
				tr.appendChild( td );
			} );
			const res = document.createElement( 'td' );
			res.textContent = ( r.pass ? '✓ 200' : '✗ ' + ( r.status || 'fail' ) )
				+ ( r.mode === 'internal' ? ' (' + ( labels.internal || 'internal check' ) + ')' : '' );
			res.style.color = r.pass ? '#1a7f37' : '#b32d2e';
			tr.appendChild( res );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		return table;
	}

	button.addEventListener( 'click', async function () {
		spinner.classList.add( 'is-active' );
		while ( out.firstChild ) {
			out.removeChild( out.firstChild );
		}

		const body = new URLSearchParams();
		body.append( 'action', button.dataset.action );
		body.append( 'nonce', button.dataset.nonce );

		try {
			const res = await fetch( window.ajaxurl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
			} );
			const json = await res.json();
			if ( ! json.success ) {
				setMessage( labels.failed || 'Audit failed.' );
				return;
			}
			const data = json.data || {};
			const rows = data.collisions ? data.collisions : [];
			renderHierarchyStatus( data.hierarchy );
			if ( rows.length === 0 ) {
				setMessage( labels.noConflicts || 'No collisions found.' );
				return;
			}
			while ( out.firstChild ) {
				out.removeChild( out.firstChild );
			}
			out.appendChild( buildTable( rows ) );
		} catch ( err ) {
			setMessage( labels.failed || 'Audit failed.' );
		} finally {
			spinner.classList.remove( 'is-active' );
		}
	} );
	const selftestBtn = document.getElementById( 'rtu-run-selftest' );
	const selftestOut = document.getElementById( 'rtu-selftest-results' );
	if ( selftestBtn && selftestOut ) {
		const sSpinner = selftestBtn.nextElementSibling;
		selftestBtn.addEventListener( 'click', async function () {
			sSpinner.classList.add( 'is-active' );
			while ( selftestOut.firstChild ) {
				selftestOut.removeChild( selftestOut.firstChild );
			}
			const body = new URLSearchParams();
			body.append( 'action', selftestBtn.dataset.action );
			body.append( 'nonce', selftestBtn.dataset.nonce );
			try {
				const res  = await fetch( window.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } );
				const json = await res.json();
				if ( ! json.success ) {
					const p = document.createElement( 'p' );
					p.textContent = labels.failed || 'Test failed.';
					selftestOut.appendChild( p );
					return;
				}
				const d = json.data || {};
				const summary = document.createElement( 'p' );
				summary.textContent = ( labels.tested || 'Tested' ) + ' ' + ( d.tested || 0 ) + ' / ' + ( d.total || 0 );
				selftestOut.appendChild( summary );
				selftestOut.appendChild( buildSelftestTable( d.rows || [] ) );
			} catch ( e ) {
				const p = document.createElement( 'p' );
				p.textContent = labels.failed || 'Test failed.';
				selftestOut.appendChild( p );
			} finally {
				sSpinner.classList.remove( 'is-active' );
			}
		} );
	}
} )();
