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
			const rows = json.data && json.data.collisions ? json.data.collisions : [];
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
} )();
