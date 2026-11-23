/*
 * The partner panel's forms: the season rows, the strip that shows which days
 * of the year are covered, and the preview before a save (a car's screen); a
 * car's photos and their order; the list of cars a bulk change is for; the
 * size of a file before it is uploaded.
 *
 * Nothing here decides anything. The strip is a convenience: the server checks
 * the table again when it is previewed and when it is saved. Every word and
 * every number this script shows comes from the page (window.glcPartner) or
 * from the server's preview.
 */
( function () {
	'use strict';
	var cfg = window.glcPartner;
	if ( ! cfg ) {
		return;
	}
	/* As many season colours as partner.css names (.glc-strip__s0 …); a longer table starts over. */
	var SEASON_COLOURS = 6;
	var dayIndex = {};
	cfg.yearDays.forEach( function ( day, at ) { dayIndex[ day ] = at; } );

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}
	/** Fill %s, %d and %1$s-style places of a sentence that came from the server. */
	function fill( pattern, values ) {
		var next = 0;
		return pattern.replace( /%(?:(\d+)\$)?[sd]/g, function ( match, at ) {
			return values[ at ? at - 1 : next++ ];
		} );
	}
	function dayText( mmDd ) {
		var parts = mmDd.split( '-' );
		return fill( cfg.dayMonth, [ parseInt( parts[ 1 ], 10 ), cfg.months[ parseInt( parts[ 0 ], 10 ) - 1 ] ] );
	}
	function runText( run ) {
		return run.from === run.to ? dayText( run.from ) : fill( cfg.dateRange, [ dayText( run.from ), dayText( run.to ) ] );
	}

	/** The season rows of a form as the server reads them: from, to, then the prices in band order. */
	function readSeasons( form ) {
		return Array.prototype.map.call( form.querySelectorAll( 'tbody .glc-season' ), function ( row ) {
			var end = function ( name ) {
				return pad( parseInt( row.querySelector( '.glc-month[data-end="' + name + '"]' ).value, 10 ) ) + '-' +
					pad( parseInt( row.querySelector( '.glc-day[data-end="' + name + '"]' ).value, 10 ) );
			};
			return {
				row: row,
				from: end( 'from' ),
				to: end( 'to' ),
				rates: cfg.bands.map( function ( band ) {
					return row.querySelector( '.glc-rate[data-band="' + band + '"]' ).value.trim();
				} ),
			};
		} );
	}

	/** The whole table in the one field the server reads it from. */
	function packTable( form ) {
		form.querySelector( 'input[name="' + cfg.tableField + '"]' ).value = readSeasons( form ).map( function ( season ) {
			return [ season.from, season.to ].concat( season.rates ).join( cfg.cellJoin );
		} ).join( cfg.rowJoin );
	}

	/** Which seasons hold each day of the year; the same test the server makes, a season may run over New Year. */
	function coverage( seasons ) {
		var bad = false;
		var days = cfg.yearDays.map( function () { return []; } );
		seasons.forEach( function ( season, at ) {
			if ( ! ( season.from in dayIndex ) || ! ( season.to in dayIndex ) ) {
				bad = true;
				return;
			}
			cfg.yearDays.forEach( function ( day, index ) {
				var inside = season.from <= season.to ? ( day >= season.from && day <= season.to ) : ( day >= season.from || day <= season.to );
				if ( inside ) {
					days[ index ].push( at );
				}
			} );
		} );
		return { days: days, bad: bad };
	}

	/** Runs of days no season holds (want 0) or more than one holds (want 2). */
	function runs( days, want ) {
		var found = [];
		var open = null;
		days.forEach( function ( holders, index ) {
			var hit = want === 0 ? holders.length === 0 : holders.length > 1;
			if ( hit && open ) {
				open.to = cfg.yearDays[ index ];
			} else if ( hit ) {
				open = { from: cfg.yearDays[ index ], to: cfg.yearDays[ index ] };
				found.push( open );
			} else {
				open = null;
			}
		} );
		return found;
	}

	function drawStrip( form ) {
		var strip = form.querySelector( '.glc-strip' );
		var text = form.querySelector( '.glc-strip-text' );
		if ( ! strip ) {
			return;
		}
		var seasons = readSeasons( form );
		var cover = coverage( seasons );
		var cells = document.createDocumentFragment();
		cover.days.forEach( function ( holders ) {
			var cell = document.createElement( 'i' );
			cell.className = holders.length === 0 ? 'glc-strip__gap' : ( holders.length > 1 ? 'glc-strip__overlap' : 'glc-strip__s' + ( holders[ 0 ] % SEASON_COLOURS ) );
			cells.appendChild( cell );
		} );
		strip.textContent = '';
		strip.appendChild( cells );

		var lines = [];
		var gaps = runs( cover.days, 0 );
		var overlaps = runs( cover.days, 2 );
		if ( cover.bad ) {
			lines.push( cfg.text.badDate );
		}
		if ( gaps.length ) {
			lines.push( fill( cfg.text.gap, [ gaps.map( runText ).join( ', ' ) ] ) );
		}
		if ( overlaps.length ) {
			lines.push( fill( cfg.text.overlap, [ overlaps.map( runText ).join( ', ' ) ] ) );
		}
		if ( seasons.length >= cfg.maxSeasons ) {
			lines.push( fill( cfg.text.tooMany, [ cfg.maxSeasons ] ) );
		}
		text.textContent = lines.length ? lines.join( ' ' ) : cfg.text.covered;
		text.classList.toggle( 'glc-fault', cover.bad || gaps.length > 0 || overlaps.length > 0 );
		form.querySelector( '.glc-add-season' ).disabled = seasons.length >= cfg.maxSeasons;
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.glc-form' ), function ( form ) {
		var pricing = form.dataset.section === 'pricing';
		var save = form.querySelector( '.glc-save-button' );
		var hint = form.querySelector( '.glc-save-hint' );
		var out = form.querySelector( '.glc-preview-out' );
		/* The bulk form's preview is a part of the page below the form, with its own Apply button. */
		var below = form.dataset.stales ? document.querySelector( form.dataset.stales ) : null;
		var asked = 0;
		/* True while the preview on screen is of exactly what the form holds and had no errors: only then does Save send. */
		var fresh = false;

		/** Anything typed after a preview makes that preview about something else: the next Save previews again (Apply locks). */
		function changed() {
			if ( save ) {
				fresh = false;
				save.textContent = cfg.text.save;
				hint.hidden = false;
				out.classList.add( 'glc-preview-out--stale' );
			}
			if ( below ) {
				below.classList.add( 'glc-preview-out--stale' );
				Array.prototype.forEach.call( below.querySelectorAll( '.glc-apply-button' ), function ( button ) { button.disabled = true; } );
				Array.prototype.forEach.call( below.querySelectorAll( '.glc-stale-note' ), function ( note ) { note.hidden = false; } );
			}
			asked++;
			if ( pricing ) {
				drawStrip( form );
			}
		}
		form.addEventListener( 'input', changed );
		form.addEventListener( 'change', changed );

		if ( pricing ) {
			form.querySelector( '.glc-add-season' ).addEventListener( 'click', function () {
				var row = form.querySelector( '.glc-season-template' ).content.firstElementChild.cloneNode( true );
				form.querySelector( '.glc-seasons tbody' ).appendChild( row );
				row.querySelector( 'select' ).focus();
				changed();
			} );
			form.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '.glc-remove-season' );
				if ( button ) {
					button.closest( '.glc-season' ).remove();
					changed();
				}
			} );
			form.addEventListener( 'submit', function () { packTable( form ); } );
			drawStrip( form );
		}

		/* The cars a bulk change is for: ticked in a list, sent as one field however long the list is. */
		var picks = form.querySelectorAll( '.glc-pick' );
		if ( picks.length ) {
			var all = form.querySelector( '.glc-pick-all' );
			var filter = form.querySelector( '.glc-pick-partner' );
			var shown = function ( box ) { return ! box.closest( 'tr' ).hidden; };
			if ( all ) {
				all.addEventListener( 'change', function () {
					Array.prototype.forEach.call( picks, function ( box ) { box.checked = all.checked && shown( box ); } );
				} );
			}
			if ( filter ) {
				/* A car that is not shown is not ticked: what is sent is what the person can see. */
				filter.addEventListener( 'change', function () {
					Array.prototype.forEach.call( picks, function ( box ) {
						var row = box.closest( 'tr' );
						row.hidden = filter.value !== '' && row.dataset.partner !== filter.value;
						box.checked = box.checked && ! row.hidden;
					} );
				} );
			}
			form.addEventListener( 'submit', function () {
				form.querySelector( 'input[name="' + cfg.carsField + '"]' ).value = Array.prototype.filter.call( picks, function ( box ) { return box.checked; } ).map( function ( box ) { return box.value; } ).join( ',' );
			} );
		}

		if ( ! form.querySelector( '.glc-preview-button' ) ) {
			return; // a form whose preview is a page of its own
		}
		/* One button: a Save with no fresh preview shows the preview instead of sending, and turns into Confirm save. */
		form.addEventListener( 'submit', function ( event ) {
			if ( ! fresh ) {
				event.preventDefault();
				preview( true );
			}
		} );
		form.querySelector( '.glc-preview-button' ).addEventListener( 'click', function () { preview( false ); } );

		function preview( fromSave ) {
			if ( pricing ) {
				packTable( form );
			}
			var body = new FormData( form );
			var mine = ++asked;
			body.set( 'action', cfg.preview );
			body.set( '_wpnonce', form.dataset.previewNonce );
			body.set( 'section', form.dataset.section );
			out.classList.remove( 'glc-preview-out--stale' );
			out.textContent = cfg.text.working;
			// Sent as a plain form, not multipart: one field per value, as the save itself is sent.
			fetch( cfg.endpoint, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams( body ) } )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( String( response.status ) );
					}
					return response.text();
				} )
				.then( function ( html ) {
					if ( mine !== asked ) {
						return; // the form changed while the preview was on its way
					}
					out.innerHTML = html; // built and escaped by the server
					var outcome = out.querySelector( '.glc-outcome' );
					var ok = !! outcome && outcome.dataset.ok === '1';
					fresh = ok;
					save.textContent = ok ? cfg.text.saveConfirm : cfg.text.save;
					hint.hidden = ok;
					if ( ok && fromSave ) {
						save.focus();
					}
					if ( pricing ) {
						var marked = outcome && outcome.dataset.seasons ? outcome.dataset.seasons.split( ',' ) : [];
						Array.prototype.forEach.call( form.querySelectorAll( 'tbody .glc-season' ), function ( row, at ) {
							row.classList.toggle( 'glc-season--marked', marked.indexOf( String( at ) ) !== -1 );
						} );
					}
				} )
				.catch( function () {
					out.textContent = cfg.text.previewFail;
				} );
		}
	} );

	/*
	 * A car's photos: their order, which one is the main photo, and adding one
	 * through WordPress's own picker. The form sends the ids in page order in
	 * one field; the server decides whether each photo may be in this gallery.
	 */
	Array.prototype.forEach.call( document.querySelectorAll( '.glc-photos-form' ), function ( form ) {
		var list = form.querySelector( '.glc-photos' );
		var field = form.querySelector( 'input[name="' + cfg.galleryField + '"]' );
		var empty = form.querySelector( '.glc-photos-empty' );
		var dragged = null;
		var frame = null;

		function items() {
			return Array.prototype.slice.call( list.querySelectorAll( '.glc-photo' ) );
		}
		/** After any change: the ids in the order shown, a main photo while there is a photo, the ends of the list. */
		function settle() {
			var all = items();
			field.value = all.map( function ( item ) { return item.dataset.id; } ).join( cfg.galleryJoin );
			if ( all.length && ! list.querySelector( 'input[type="radio"]:checked' ) ) {
				all[ 0 ].querySelector( 'input[type="radio"]' ).checked = true;
			}
			all.forEach( function ( item, at ) {
				item.querySelector( '.glc-photo-up' ).disabled = at === 0;
				item.querySelector( '.glc-photo-down' ).disabled = at === all.length - 1;
			} );
			empty.hidden = all.length > 0;
		}
		function has( id ) {
			return items().some( function ( item ) { return item.dataset.id === String( id ); } );
		}
		/** One picked attachment as a row of the list, from the blank the server drew. */
		function add( photo ) {
			if ( ! photo || ! photo.id || has( photo.id ) ) {
				return;
			}
			var item = form.querySelector( '.glc-photo-template' ).content.firstElementChild.cloneNode( true );
			var sizes = photo.sizes || {};
			item.dataset.id = String( photo.id );
			item.querySelector( '.glc-photo-picture' ).src = ( sizes[ cfg.thumbSize ] || sizes.full || {} ).url || photo.url || '';
			item.querySelector( 'input[type="radio"]' ).value = String( photo.id );
			item.querySelector( '.glc-photo-alt input' ).name = cfg.altField + '[' + photo.id + ']';
			item.querySelector( '.glc-photo-alt input' ).value = photo.alt || '';
			if ( photo.width ) {
				item.querySelector( '.glc-photo-size' ).textContent = fill( cfg.text.photoSize, [ photo.width, photo.height ] );
				if ( cfg.minWidth > 0 && photo.width < cfg.minWidth ) {
					var narrow = item.querySelector( '.glc-photo-narrow' );
					narrow.textContent = fill( cfg.text.photoNarrow, [ photo.width, cfg.minWidth ] );
					narrow.hidden = false;
				}
			}
			list.appendChild( item );
		}

		form.addEventListener( 'click', function ( event ) {
			var item = event.target.closest( '.glc-photo' );
			if ( event.target.closest( '.glc-photo-add' ) ) {
				if ( ! frame ) {
					frame = window.wp.media( { title: cfg.text.photoPick, button: { text: cfg.text.photoUse }, multiple: 'add', library: { type: 'image' } } );
					frame.on( 'select', function () {
						frame.state().get( 'selection' ).each( function ( model ) { add( model.toJSON() ); } );
						settle();
					} );
				}
				frame.open();
			} else if ( item && event.target.closest( '.glc-photo-remove' ) ) {
				item.remove();
				settle();
			} else if ( item && event.target.closest( '.glc-photo-up' ) && item.previousElementSibling ) {
				list.insertBefore( item, item.previousElementSibling );
				settle();
				event.target.closest( 'button' ).focus();
			} else if ( item && event.target.closest( '.glc-photo-down' ) && item.nextElementSibling ) {
				list.insertBefore( item.nextElementSibling, item );
				settle();
				event.target.closest( 'button' ).focus();
			}
		} );

		/* Dragging a photo by its picture does what the two arrows do. (Only the picture drags, so text in the alt field can still be selected.) */
		list.addEventListener( 'dragstart', function ( event ) {
			dragged = event.target.closest( '.glc-photo' );
			if ( dragged ) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', dragged.dataset.id );
				dragged.classList.add( 'glc-photo--dragged' );
			}
		} );
		list.addEventListener( 'dragover', function ( event ) {
			var over = event.target.closest( '.glc-photo' );
			if ( ! dragged || ! over || over === dragged ) {
				return;
			}
			event.preventDefault();
			var box = over.getBoundingClientRect();
			list.insertBefore( dragged, event.clientY < box.top + box.height / 2 ? over : over.nextElementSibling );
		} );
		list.addEventListener( 'dragend', function () {
			if ( dragged ) {
				dragged.classList.remove( 'glc-photo--dragged' );
				dragged = null;
				settle();
			}
		} );
		form.addEventListener( 'submit', settle );
		settle();
	} );

	/*
	 * A file too large for the server is stopped here, with the server's own
	 * sentence, before it is sent. The server checks again: this only saves the wait.
	 */
	Array.prototype.forEach.call( document.querySelectorAll( '.glc-xlsx-upload' ), function ( form ) {
		var input = form.querySelector( 'input[type="file"]' );
		var out = form.querySelector( '.glc-upload-out' );
		function tooBig() {
			var file = input.files && input.files[ 0 ];
			var big = !! file && file.size > parseInt( form.dataset.maxBytes, 10 );
			out.hidden = ! big;
			out.textContent = big ? fill( form.dataset.tooBig, [ Math.ceil( file.size / 1024 ).toLocaleString() ] ) : '';
			return big;
		}
		input.addEventListener( 'change', tooBig );
		form.addEventListener( 'submit', function ( event ) {
			if ( tooBig() ) {
				event.preventDefault();
			}
		} );
	} );
}() );
