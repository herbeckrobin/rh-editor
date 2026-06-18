/**
 * Setzt die pro-Rolle-Editor-Erfahrung im Frontend des Editors durch.
 * Konfiguration kommt aus PHP (wp_localize_script -> window.rhEditorRoles):
 *   mode:        'full' | 'patterns' | 'content'
 *   stylesOnly:  boolean (Site-Editor auf Stile reduzieren)
 *
 * "Nur Inhalt" (content) wird über die offizielle setBlockEditingMode-API
 * umgesetzt: Root -> 'disabled' (Struktur gesperrt, kein Einfügen), bestehende
 * Blöcke -> 'contentOnly' (nur Text/Medien editierbar, Design-Tools weg). Das
 * `templateLock`-Editor-Setting ignoriert der Post-Editor, darum dieser Weg.
 */
( function () {
	var cfg = window.rhEditorRoles || {};

	if ( ! window.wp || ! wp.domReady || ! wp.data ) {
		return;
	}

	var isSiteEditor = location.pathname.indexOf( 'site-editor.php' ) !== -1;

	if ( isSiteEditor ) {
		// Im Site-Editor NUR die Stile-Reduktion, niemals den Post-Editor-Lock
		// (der würde sonst Templates sperren).
		if ( cfg.stylesOnly ) {
			reduceSiteEditor();
		}
	} else if ( cfg.mode === 'content' ) {
		enforceContentOnly();
	} else if ( cfg.mode === 'patterns' ) {
		hideBlocksTab();
	}

	/**
	 * Root sperren, alle Top-Level-Blöcke auf contentOnly. Initial sobald die
	 * Blöcke geladen sind, plus ein leichtes Re-Apply bei Block-Änderungen als
	 * Sicherheitsnetz (greift praktisch nie, weil Einfügen serverseitig aus ist).
	 */
	function enforceContentOnly() {
		wp.domReady( function () {
			// Leeren Inserter-Button und Block-Appender ausblenden (Einfügen ist
			// serverseitig aus, der Button öffnet sonst nur eine leere Liste).
			var style = document.createElement( 'style' );
			style.textContent =
				'.editor-document-tools__inserter-toggle,' +
				'.edit-post-header-toolbar__inserter-toggle,' +
				'.block-editor-button-block-appender,' +
				'.block-editor-default-block-appender,' +
				'.block-editor-inserter{display:none!important;}';
			document.head.appendChild( style );

			var applied = false;

			var apply = function () {
				var sel = wp.data.select( 'core/block-editor' );
				var dispatch = wp.data.dispatch( 'core/block-editor' );
				if ( ! sel || ! sel.getBlocks || ! dispatch.setBlockEditingMode ) {
					return false;
				}
				var blocks = sel.getBlocks();
				if ( ! blocks.length ) {
					return false;
				}
				if ( sel.getBlockEditingMode( '' ) !== 'disabled' ) {
					dispatch.setBlockEditingMode( '', 'disabled' );
				}
				blocks.forEach( function ( block ) {
					if ( sel.getBlockEditingMode( block.clientId ) !== 'contentOnly' ) {
						dispatch.setBlockEditingMode( block.clientId, 'contentOnly' );
					}
				} );
				return true;
			};

			// Auf das Laden der Blöcke warten (Editor hydriert asynchron).
			var unsub = wp.data.subscribe( function () {
				var ok = apply();
				if ( ok && ! applied ) {
					applied = true;
				}
			} );

			// Erster Versuch sofort (falls schon hydriert).
			apply();
		} );
	}

	/**
	 * Vorlagen-Modus: den "Blöcke"-Tab im Inserter ausblenden, nur Muster bleiben.
	 * Reiner UI-Eingriff (das Einfügen freier Blöcke ist serverseitig zusätzlich
	 * auf die Muster-Bausteine begrenzt). Selektoren werden live verifiziert.
	 */
	function hideBlocksTab() {
		wp.domReady( function () {
			// Den "Blöcke"-Tab-Button ausblenden (IDs sind dynamisch: tabs-N-blocks).
			var style = document.createElement( 'style' );
			style.textContent =
				'.block-editor-tabbed-sidebar__tab[aria-controls$="blocks-view"]{display:none!important;}';
			document.head.appendChild( style );

			// Blöcke ist der Default-View. Solange er (versteckt) aktiv ist, auf
			// "Patterns" umschalten, damit der Kunde nur Muster einfügt.
			var obs = new MutationObserver( function () {
				var blocksView = document.querySelector( '[id$="-blocks-view"]' );
				if ( ! blocksView || blocksView.offsetParent === null ) {
					return;
				}
				var patternsTab = document.querySelector(
					'.block-editor-tabbed-sidebar__tab[aria-controls$="patterns-view"]'
				);
				if ( patternsTab ) {
					patternsTab.click();
				}
			} );
			obs.observe( document.body, { childList: true, subtree: true } );
		} );
	}

	/**
	 * Stile-Freigabe: im Site-Editor alle Navigations-Bereiche außer "Stile"
	 * ausblenden (Templates, Seiten, Muster, Navigation). Der Kunde dreht nur an
	 * den site-weiten Stilen. Reine UI-Reduktion (edit_theme_options ist server-
	 * seitig voll, das ist bewusst und für Endkunden-Pflege ausreichend).
	 */
	function reduceSiteEditor() {
		wp.domReady( function () {
			var style = document.createElement( 'style' );
			style.textContent =
				'.edit-site-sidebar-navigation-item:not([href*="styles"]){display:none!important;}';
			document.head.appendChild( style );
		} );
	}
} )();
