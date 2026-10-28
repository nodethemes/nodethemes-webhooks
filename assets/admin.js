/* NodeThemes Webhooks admin: copy buttons, delete confirmation, repeatable rows. */
( function () {
	'use strict';
	var i18n = window.ntwhAdmin || { copied: 'Copied', confirm: 'Are you sure?' };

	document.addEventListener( 'click', function ( e ) {
		var copy = e.target.closest( '[data-ntwh-copy]' );
		if ( copy ) {
			var field = document.querySelector( copy.getAttribute( 'data-ntwh-copy' ) );
			if ( field ) {
				var text = field.value || field.textContent;
				var done = function () {
					var old = copy.textContent;
					copy.textContent = i18n.copied;
					setTimeout( function () { copy.textContent = old; }, 1500 );
				};
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( text ).then( done );
				} else {
					field.select && field.select();
					try { document.execCommand( 'copy' ); done(); } catch ( err ) {}
				}
			}
			return;
		}
		var add = e.target.closest( '[data-ntwh-add]' );
		if ( add ) {
			e.preventDefault();
			var tpl = document.getElementById( add.getAttribute( 'data-ntwh-add' ) );
			var box = document.querySelector( add.getAttribute( 'data-ntwh-into' ) );
			if ( tpl && box ) {
				var n = Date.now();
				box.insertAdjacentHTML( 'beforeend', tpl.innerHTML.replace( /__i__/g, n ) );
			}
			return;
		}
		var del = e.target.closest( '[data-ntwh-remove]' );
		if ( del ) {
			e.preventDefault();
			var row = del.closest( del.getAttribute( 'data-ntwh-remove' ) );
			if ( row ) { row.parentNode.removeChild( row ); }
		}
	} );

	// Conditions (Pro): the "path" box is only needed for "Any field (path)".
	function syncRule( sel ) {
		var row = sel.closest( '.ntwh-rule' );
		var path = row && row.querySelector( 'input[name$="[path]"]' );
		if ( path ) { path.style.display = 'path' === sel.value ? '' : 'none'; }
	}
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches && e.target.matches( '.ntwh-rule select[name$="[field]"]' ) ) { syncRule( e.target ); }
	} );
	function syncAll() { document.querySelectorAll( '.ntwh-rule select[name$="[field]"]' ).forEach( syncRule ); }
	document.addEventListener( 'DOMContentLoaded', syncAll );
	document.addEventListener( 'click', function ( e ) { if ( e.target.closest( '[data-ntwh-add]' ) ) { setTimeout( syncAll, 0 ); } } );

	document.addEventListener( 'submit', function ( e ) {
		if ( e.target.hasAttribute( 'data-ntwh-confirm' ) && ! window.confirm( e.target.getAttribute( 'data-ntwh-confirm' ) || i18n.confirm ) ) {
			e.preventDefault();
		}
	} );
} )();
