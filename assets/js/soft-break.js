/**
 * Umbruchstellen im Text: weiches Trennzeichen (U+00AD, Trennstrich nur beim
 * Umbruch) und unsichtbarer Umbruch (U+200B, ohne Trennstrich).
 *
 * Gespeichert wird nur das Zeichen. Die Markierung im Editor ist ein Format, das
 * ausschliesslich über __experimentalCreatePrepareEditableTree in den
 * Editor-DOM gelegt wird. Ohne __experimentalCreateOnChangeEditableValue liest
 * RichText das Element beim Parsen nicht zurück (rich-text createElement gibt
 * dann null), der Wrapper landet also nie im Wert und nie im Frontend.
 *
 * <wbr> scheidet aus: RichText verwirft es beim Einlesen (gemessen in WP 7.1,
 * create({html:'a<wbr>b'}) ergibt 'ab'), auch als registriertes Objekt-Format.
 */
( function ( wp ) {
	if ( ! wp || ! wp.richText || ! wp.blockEditor || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var R = wp.richText;
	var BE = wp.blockEditor;

	var MARK = 'rh-editor/break-mark';
	var CHARS = { '\u00ad': 'shy', '\u200b': 'zwsp' };

	// Kürzel mit access (Ctrl+Option auf dem Mac, Alt+Shift unter Windows), wie
	// Core-Formate (Durchgestrichen access+d, Code access+x). Belegt und darum
	// gemieden: primaryShift+h (Block-Sichtbarkeit), access+h (Kürzel-Hilfe).
	// s = Silbentrennung, u = unsichtbar.
	var variants = [
		{
			name: 'rh-editor/soft-hyphen',
			char: '\u00ad',
			title: __( 'Weiches Trennzeichen', 'rh-editor' ),
			key: 's',
			tag: 'shy',
			icon: 'editor-insertmore',
		},
		{
			name: 'rh-editor/zero-width-space',
			char: '\u200b',
			title: __( 'Unsichtbarer Umbruch', 'rh-editor' ),
			key: 'u',
			tag: 'zwsp',
			icon: 'editor-break',
		},
	];

	// Reine Anzeige-Markierung, nur im Editor-DOM.
	R.registerFormatType( MARK, {
		title: __( 'Umbruchstelle', 'rh-editor' ),
		tagName: 'span',
		className: 'rh-break-mark',
		attributes: { variant: 'data-rh-break' },
		edit: function () {
			return null;
		},
		__experimentalCreatePrepareEditableTree: function () {
			return function ( formats, text ) {
				if ( text.indexOf( '\u00ad' ) === -1 && text.indexOf( '\u200b' ) === -1 ) {
					return formats;
				}
				var record = { formats: formats, text: text, replacements: [] };
				for ( var i = 0; i < text.length; i++ ) {
					var variant = CHARS[ text[ i ] ];
					if ( variant ) {
						record = R.applyFormat(
							record,
							{ type: MARK, attributes: { variant: variant } },
							i,
							i + 1
						);
					}
				}
				return record.formats;
			};
		},
	} );

	variants.forEach( function ( v ) {
		R.registerFormatType( v.name, {
			title: v.title,
			tagName: 'rh-break-' + v.tag,
			className: null,
			edit: function ( props ) {
				var insertChar = function () {
					props.onChange( R.insert( props.value, v.char ) );
				};
				return el(
					Fragment,
					null,
					el( BE.RichTextShortcut, {
						type: 'access',
						character: v.key,
						onUse: insertChar,
					} ),
					el( BE.RichTextToolbarButton, {
						icon: v.icon,
						title: v.title,
						onClick: insertChar,
						shortcutType: 'access',
						shortcutCharacter: v.key,
					} )
				);
			},
		} );
	} );

	// Klick auf die Markierung löscht genau dieses Zeichen. Über execCommand, damit
	// RichText die Änderung wie eine normale Eingabe übernimmt (Undo inklusive).
	function onMouseDown( event ) {
		var mark = event.target && event.target.closest && event.target.closest( '.rh-break-mark' );
		if ( ! mark || ! mark.firstChild || mark.firstChild.nodeType !== 3 ) {
			return;
		}
		event.preventDefault();
		var doc = mark.ownerDocument;
		var editable = mark.closest( '[contenteditable="true"]' );
		if ( editable ) {
			editable.focus();
		}
		// Nur ein Zeichen: direkt benachbarte gleiche Zeichen teilen sich einen Wrapper.
		var range = doc.createRange();
		range.setStart( mark.firstChild, 0 );
		range.setEnd( mark.firstChild, 1 );
		var sel = doc.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
		doc.execCommand( 'delete' );
	}

	var bound = new WeakSet();
	function bind( doc ) {
		if ( doc && ! bound.has( doc ) ) {
			bound.add( doc );
			doc.addEventListener( 'mousedown', onMouseDown, true );
		}
	}

	// Editor-Canvas ist meist ein iframe, das neu entstehen kann.
	bind( document );
	wp.data.subscribe( function () {
		var frame = document.querySelector( 'iframe[name="editor-canvas"]' );
		if ( frame && frame.contentDocument && frame.contentDocument.body ) {
			bind( frame.contentDocument );
		}
	} );
} )( window.wp );
