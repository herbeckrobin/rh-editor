/**
 * RH Editor, Block-Kategorie-Zuordnung (buildless).
 *
 * Ordnet Blöcke der eigenen Kategorie zu, aufgelöst live aus der Konfiguration
 * (window.rhEditorConfig, via wp_localize_script). Zugehörigkeit:
 *   member = (Block-Kategorie in includeCategories ODER Block in includeBlocks)
 *            UND NICHT (Block in excludeBlocks)
 * Dadurch landen auch künftige Blöcke einer übernommenen Kategorie automatisch
 * in der eigenen Kategorie, ohne dass die Konfiguration angefasst werden muss.
 *
 * Die Kategorie selbst (und das Ausblenden anderer) wird serverseitig über
 * block_categories_all geregelt. Nutzt window.wp.hooks (Dep wp-hooks).
 */
(function (wp, config) {
	'use strict';

	if (!wp || !wp.hooks || !config || !config.category) {
		return;
	}

	var curated = config.category;
	var includeCategories = config.includeCategories || [];
	var includeBlocks = config.includeBlocks || [];
	var excludeBlocks = config.excludeBlocks || [];

	function belongs(name, category) {
		if (excludeBlocks.indexOf(name) !== -1) {
			return false;
		}
		if (includeBlocks.indexOf(name) !== -1) {
			return true;
		}
		return category && includeCategories.indexOf(category) !== -1;
	}

	wp.hooks.addFilter(
		'blocks.registerBlockType',
		'rh-editor/block-category',
		function (settings, name) {
			if (belongs(name, settings && settings.category)) {
				settings.category = curated;
			}
			return settings;
		}
	);
})(window.wp, window.rhEditorConfig);
