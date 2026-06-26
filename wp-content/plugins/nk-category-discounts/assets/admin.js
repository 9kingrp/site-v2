( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var container = document.getElementById( 'nk-cd-rules' );
		var addBtn    = document.getElementById( 'nk-cd-add' );
		var template  = document.querySelector( '.nk-cd-template' );

		if ( ! container || ! addBtn || ! template ) {
			return;
		}

		// Unique-enough index for newly added rows (server reindexes on save).
		var counter = 100000;

		function bindRemove( row ) {
			var btn = row.querySelector( '.nk-cd-remove' );
			if ( ! btn ) {
				return;
			}
			btn.addEventListener( 'click', function () {
				var rows = container.querySelectorAll( '.nk-cd-rule' );
				if ( rows.length <= 1 ) {
					// Keep at least one row; just clear it instead of removing.
					clearRow( row );
					return;
				}
				row.parentNode.removeChild( row );
			} );
		}

		function clearRow( row ) {
			row.querySelectorAll( 'input[type="text"], input[type="number"], input[type="datetime-local"]' ).forEach( function ( el ) {
				el.value = '';
			} );
			var sel = row.querySelector( 'select' );
			if ( sel ) {
				Array.prototype.forEach.call( sel.options, function ( o ) {
					o.selected = false;
				} );
			}
		}

		addBtn.addEventListener( 'click', function () {
			counter++;
			var html = template.outerHTML
				.replace( /nk-cd-template/g, '' )
				.replace( /style="display:none;"/g, '' )
				.replace( /__INDEX__/g, String( counter ) );

			var wrapper = document.createElement( 'div' );
			wrapper.innerHTML = html.trim();
			var newRow = wrapper.firstChild;

			container.appendChild( newRow );
			bindRemove( newRow );
			newRow.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		} );

		// Bind remove on existing rows.
		container.querySelectorAll( '.nk-cd-rule' ).forEach( bindRemove );
	} );
} )();
