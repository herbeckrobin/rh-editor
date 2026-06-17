<?php

declare(strict_types=1);

namespace RhEditor;

use RhEditor\Admin\EditorGroup;
use WP_Block_Type_Registry;

/**
 * Editor-Eingriffe: Inserter-Cleanup, eigene Block-Kategorie, SVG-Upload.
 *
 * Timing: der Inserter-Cleanup (`remove_theme_support('core-block-patterns')`)
 * muss auf `after_setup_theme` laufen, also bevor der Core-`booted`-Hook (init)
 * feuert. Darum hängen diese beiden Hooks als statische Methoden früh in
 * Plugin::boot(), während SVG + Kategorie erst auf init (core/booted) registriert
 * werden, weil ihre Hooks ohnehin später greifen.
 *
 * Die Block-Kategorie ist konfigurierbar (BlockCategoryConfig): welche Kategorien
 * und Blöcke übernommen und welche Standard-Kategorien ausgeblendet werden. Die
 * finale Zuordnung passiert im Editor-JS live (künftige Blöcke automatisch).
 */
final class Editor
{
    public function __construct(private readonly BlockCategoryConfig $config)
    {
    }

    // --- Früh registriert (Plugin::boot, vor after_setup_theme) ---

    public static function onAfterSetupTheme(): void
    {
        if (self::setting(EditorGroup::FIELD_INSERTER_CLEANUP, true)) {
            remove_theme_support('core-block-patterns');
        }
    }

    public static function filterRemotePatterns(bool $load): bool
    {
        return self::setting(EditorGroup::FIELD_INSERTER_CLEANUP, true) ? false : $load;
    }

    // --- Spät registriert (onCoreBooted) ---

    public function boot(): void
    {
        if ($this->enabled(EditorGroup::FIELD_SVG_UPLOAD, false)) {
            add_filter('upload_mimes', [$this, 'allowSvgMime']);
            add_filter('wp_check_filetype_and_ext', [$this, 'fixSvgFiletype'], 10, 3);
            add_filter('wp_handle_upload_prefilter', [$this, 'sanitizeSvgUpload']);
        }

        if ($this->enabled(EditorGroup::FIELD_BLOCK_CATEGORY, true)) {
            add_filter('block_categories_all', [$this, 'filterBlockCategories']);
            add_filter('allowed_block_types_all', [$this, 'filterAllowedBlocks'], 10, 2);
            add_action('enqueue_block_editor_assets', [$this, 'enqueueCategoryScript']);
        }
    }

    public function categoryLabel(): string
    {
        $label = (string) rhbp_setting(EditorGroup::GROUP_ID, EditorGroup::FIELD_CATEGORY_LABEL, 'Bausteine');

        return trim($label) !== '' ? $label : 'Bausteine';
    }

    /**
     * Eigene Kategorie ganz oben einsortieren.
     *
     * WICHTIG: ausgeblendete Kategorien werden NICHT aus der Liste entfernt. Das
     * würde ihre Blöcke heimatlos machen, Gutenberg sammelt sie dann unter
     * "Allgemein". Das Ausblenden passiert stattdessen über allowed_block_types_all
     * (Blöcke nicht einfügbar), leere Kategorien zeigt Gutenberg dann gar nicht.
     *
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    public function filterBlockCategories(array $categories): array
    {
        array_unshift($categories, [
            'slug' => BlockCategoryConfig::CURATED_SLUG,
            'title' => $this->categoryLabel(),
            'icon' => null,
        ]);

        return $categories;
    }

    /**
     * Blöcke ausgeblendeter Kategorien aus dem Inserter nehmen (nicht einfügbar).
     * Eingebundene Blöcke (in der eigenen Kategorie) bleiben verfügbar.
     *
     * Nur im Seiten-/Beitrags-Editor (`core/edit-post`), NICHT im Site-Editor:
     * dort würden fehlende Theme-Blöcke (Navigation, Template-Teile) die
     * Template-Bearbeitung brechen.
     *
     * @param bool|array<int, string> $allowed
     * @param mixed                   $context
     * @return bool|array<int, string>
     */
    public function filterAllowedBlocks($allowed, $context)
    {
        $ctxName = is_object($context) && isset($context->name) ? (string) $context->name : '';
        if ($ctxName !== '' && $ctxName !== 'core/edit-post') {
            return $allowed;
        }

        $hidden = $this->config->hiddenCategories();
        if ($hidden === []) {
            return $allowed;
        }

        $hide = [];
        foreach ($this->config->availableBlocks() as $name => $block) {
            if (! in_array($block['category'], $hidden, true)) {
                continue;
            }
            if ($this->isCuratedMember($name, $block['category'])) {
                continue;
            }
            $hide[] = $name;
        }

        if ($hide === []) {
            return $allowed;
        }

        $all = $allowed === true
            ? array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered())
            : array_map('strval', (array) $allowed);

        return array_values(array_diff($all, $hide));
    }

    /**
     * Spiegelt die JS-Auflösung: gehört der Block in die eigene Kategorie?
     */
    private function isCuratedMember(string $name, string $category): bool
    {
        if (in_array($name, $this->config->excludeBlocks(), true)) {
            return false;
        }
        if (in_array($name, $this->config->includeBlocks(), true)) {
            return true;
        }

        return in_array($category, $this->config->includeCategories(), true);
    }

    /**
     * Editor-JS, das die Blöcke der kuratierten Kategorie zuordnet. Die Auswahl
     * (Kategorien + einzelne Blöcke) kommt aus PHP und wird live aufgelöst.
     */
    public function enqueueCategoryScript(): void
    {
        $jsRel = 'assets/js/block-category.js';
        $jsAbs = RHEDITOR_PLUGIN_DIR . $jsRel;
        if (! file_exists($jsAbs)) {
            return;
        }

        wp_enqueue_script(
            'rh-editor-block-category',
            RHEDITOR_PLUGIN_URL . $jsRel,
            ['wp-hooks'],
            (string) filemtime($jsAbs),
            true
        );

        wp_localize_script('rh-editor-block-category', 'rhEditorConfig', [
            'category' => BlockCategoryConfig::CURATED_SLUG,
            'includeCategories' => array_values($this->config->includeCategories()),
            'includeBlocks' => array_values($this->config->includeBlocks()),
            'excludeBlocks' => array_values($this->config->excludeBlocks()),
        ]);
    }

    // --- SVG ---

    /**
     * @param array<string, string> $mimes
     * @return array<string, string>
     */
    public function allowSvgMime(array $mimes): array
    {
        if (current_user_can('upload_files') || (defined('WP_CLI') && \WP_CLI)) {
            $mimes['svg'] = 'image/svg+xml';
        }

        return $mimes;
    }

    /**
     * @param array<string, mixed> $data
     * @param mixed                $file
     * @return array<string, mixed>
     */
    public function fixSvgFiletype(array $data, $file, string $filename): array
    {
        if (str_ends_with(strtolower($filename), '.svg')) {
            $data['type'] = 'image/svg+xml';
            $data['ext'] = 'svg';
        }

        return $data;
    }

    /**
     * Einfache Sanitisierung beim Upload: script-Tags, on*-Handler und
     * javascript:-URLs raus. Reicht für vertrauenswürdige Redakteure, ist aber
     * kein vollständiger Sanitizer (kein DOM-Allowlist-Parsing).
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function sanitizeSvgUpload(array $file): array
    {
        if (($file['type'] ?? '') !== 'image/svg+xml') {
            return $file;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || ! is_readable($tmp)) {
            return $file;
        }

        $svg = (string) file_get_contents($tmp);
        $svg = (string) preg_replace('#<script[\s\S]*?</script>#i', '', $svg);
        $svg = (string) preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $svg);
        $svg = (string) preg_replace('#(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*\2#i', '', $svg);
        file_put_contents($tmp, $svg);

        return $file;
    }

    private function enabled(string $field, bool $default): bool
    {
        return (bool) rhbp_setting(EditorGroup::GROUP_ID, $field, $default);
    }

    private static function setting(string $field, bool $default): bool
    {
        if (! function_exists('rhbp_setting')) {
            return $default;
        }

        return (bool) rhbp_setting(EditorGroup::GROUP_ID, $field, $default);
    }
}
