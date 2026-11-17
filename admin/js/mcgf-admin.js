/* Multicorreo for Gravity Forms — admin (no dependencies) */
( function () {
	'use strict';

	// Confirm destructive links.
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '[data-mcgf-confirm]' ) : null;
		if ( link && ! window.confirm( link.getAttribute( 'data-mcgf-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	// Dashboard: show the date inputs only for a custom period.
	var period = document.getElementById( 'mcgf-period' );
	if ( period ) {
		period.addEventListener( 'change', function () {
			var custom = document.querySelector( '.mcgf-custom' );
			if ( custom ) {
				custom.hidden = period.value !== 'custom';
			}
		} );
	}

	// Lists form: live summary of slots per email.
	var area = document.getElementById( 'mcgf-emails' );
	if ( ! area ) {
		return;
	}

	var summary = document.getElementById( 'mcgf-summary' );
	var body    = document.querySelector( '#mcgf-preview tbody' );
	var invalid = document.getElementById( 'mcgf-invalid' );
	var re      = /^[^\s@<>()",;:]+@[^\s@<>()",;:]+\.[^\s@<>()",;:]{2,}$/;

	function clean( value ) {
		return value.replace( /^[<"']+|[>"']+$/g, '' ).replace( /^mailto:/i, '' ).toLowerCase();
	}

	function render() {
		var parts = area.value.split( /[\s,;]+/ ).filter( Boolean );
		var slots = {};
		var order = [];
		var bad   = [];
		var total = 0;

		parts.forEach( function ( part ) {
			var email = clean( part );
			if ( ! re.test( email ) ) {
				if ( bad.indexOf( part ) === -1 ) {
					bad.push( part );
				}
				return;
			}
			if ( ! slots[ email ] ) {
				slots[ email ] = 0;
				order.push( email );
			}
			slots[ email ]++;
			total++;
		} );

		summary.textContent = total
			? total + ( total === 1 ? ' cupo' : ' cupos' ) + ' por ronda, ' + order.length + ( order.length === 1 ? ' correo distinto' : ' correos distintos' )
			: 'Escribe los correos, uno por línea.';

		order.sort( function ( a, b ) {
			return slots[ b ] - slots[ a ];
		} );

		body.textContent = '';
		order.forEach( function ( email ) {
			var tr = document.createElement( 'tr' );
			[ email, '×' + slots[ email ], ( slots[ email ] / total * 100 ).toFixed( 1 ).replace( '.', ',' ) + '%' ].forEach( function ( text, i ) {
				var td = document.createElement( 'td' );
				td.textContent = text;
				if ( i ) {
					td.className = 'num';
				}
				tr.appendChild( td );
			} );
			body.appendChild( tr );
		} );

		invalid.hidden      = ! bad.length;
		invalid.textContent = bad.length ? 'No son correos válidos: ' + bad.join( ', ' ) : '';
	}

	area.addEventListener( 'input', render );
	render();
}() );
