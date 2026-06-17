<?php

declare(strict_types=1);

namespace RhEditor\Admin;

use RhBlueprint\Core\Settings\SettingsHub;
use RhBlueprint\Core\Settings\SettingsPage;
use RhEditor\BlockCategoryConfig;

/**
 * Komplette Editor-Tab-UI in EINEM Formular (ein Speichern-Button).
 *
 * Bewusst kein auto-gerendertes SettingField-Schema: das kann keine Checkbox-Listen,
 * und zwei getrennte Formulare (Settings-API + bespoke) hätten zwei Speichern-Buttons,
 * was zu "ich hake an und es speichert nicht" führt (falscher Button). Darum hängt
 * der ganze Tab an `tab_content_after` (rendert ohne registrierte Gruppe) und speichert
 * über einen eigenen admin-post-Handler: die einfachen Toggles in die Editor-Option,
 * die Kategorie-Auswahl in die Kategorie-Option.
 */
final class BlockCategoryPage
{
    public const TAB = 'editor';
    private const ACTION = 'rheditor_save';
    private const NONCE = 'rheditor_nonce';

    public function __construct(private readonly BlockCategoryConfig $config)
    {
    }

    public function boot(): void
    {
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render']);
        add_action('admin_post_' . self::ACTION, [$this, 'save']);
    }

    public function render(string $tab): void
    {
        if ($tab !== self::TAB || ! current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['rheditor_saved'])) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Editor-Einstellungen gespeichert.', 'rh-editor') . '</p></div>';
        }

        $inserter = (bool) rhbp_setting(EditorGroup::GROUP_ID, EditorGroup::FIELD_INSERTER_CLEANUP, true);
        $svg = (bool) rhbp_setting(EditorGroup::GROUP_ID, EditorGroup::FIELD_SVG_UPLOAD, false);
        $catEnabled = (bool) rhbp_setting(EditorGroup::GROUP_ID, EditorGroup::FIELD_BLOCK_CATEGORY, true);
        $label = (string) rhbp_setting(EditorGroup::GROUP_ID, EditorGroup::FIELD_CATEGORY_LABEL, 'Bausteine');

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        wp_nonce_field(self::ACTION, self::NONCE);

        // --- Allgemeine Toggles ---
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->toggleRow('inserter_cleanup', $inserter, __('Inserter aufräumen', 'rh-editor'), __('Blendet die WordPress-Standard- und Remote-Vorlagen aus, nur theme-eigene Vorlagen bleiben.', 'rh-editor'));
        $this->toggleRow('svg_upload', $svg, __('SVG-Upload erlauben', 'rh-editor'), __('Erlaubt SVG in der Mediathek (mit einfacher Sanitisierung). Nur für vertrauenswürdige Redakteure.', 'rh-editor'));
        $this->toggleRow('block_category', $catEnabled, __('Eigene Block-Kategorie', 'rh-editor'), __('Gruppiert ausgewählte Blöcke in eine eigene Kategorie oben im Inserter.', 'rh-editor'));
        echo '<tr><th scope="row"><label for="rheditor_label">' . esc_html__('Name der Kategorie', 'rh-editor') . '</label></th>';
        echo '<td><input type="text" id="rheditor_label" name="category_label" class="regular-text" value="' . esc_attr($label) . '"></td></tr>';
        echo '</tbody></table>';

        // --- Inhalt der Kategorie ---
        echo '<hr style="margin:1.5rem 0">';
        echo '<h2>' . esc_html__('Inhalt der eigenen Block-Kategorie', 'rh-editor') . '</h2>';
        echo '<p class="description">' . esc_html__('Greift nur, wenn "Eigene Block-Kategorie" oben aktiviert ist.', 'rh-editor') . '</p>';

        $this->renderCategoryTable();
        $this->renderBlockList();

        submit_button(__('Editor-Einstellungen speichern', 'rh-editor'));
        echo '</form>';
    }

    private function toggleRow(string $name, bool $checked, string $label, string $help): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($checked, true, false) . '> ' . esc_html($help) . '</label>';
        echo '</td></tr>';
    }

    private function renderCategoryTable(): void
    {
        $categories = $this->config->availableCategories();
        $includeCats = $this->config->includeCategories();
        $hiddenCats = $this->config->hiddenCategories();

        echo '<h3>' . esc_html__('Kategorien', 'rh-editor') . '</h3>';
        echo '<p class="description">' . esc_html__('"Übernehmen" zieht alle Blöcke der Kategorie in deine eigene (auch künftige). "Ausblenden" entfernt die Kategorie aus dem Inserter.', 'rh-editor') . '</p>';
        echo '<table class="widefat striped" style="max-width:680px"><thead><tr>';
        echo '<th>' . esc_html__('Kategorie', 'rh-editor') . '</th>';
        echo '<th style="width:130px">' . esc_html__('Übernehmen', 'rh-editor') . '</th>';
        echo '<th style="width:130px">' . esc_html__('Ausblenden', 'rh-editor') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($categories as $slug => $label) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($label) . '</strong> <code style="opacity:.6">' . esc_html($slug) . '</code></td>';
            echo '<td><input type="checkbox" name="include_categories[]" value="' . esc_attr($slug) . '" ' . checked(in_array($slug, $includeCats, true), true, false) . '></td>';
            echo '<td><input type="checkbox" name="hidden_categories[]" value="' . esc_attr($slug) . '" ' . checked(in_array($slug, $hiddenCats, true), true, false) . '></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function renderBlockList(): void
    {
        $categories = $this->config->availableCategories();
        $blocks = $this->config->availableBlocks();
        $includeCats = $this->config->includeCategories();
        $includeBlocks = $this->config->includeBlocks();
        $excludeBlocks = $this->config->excludeBlocks();

        echo '<h3 style="margin-top:1.5rem">' . esc_html__('Einzelne Blöcke', 'rh-editor') . '</h3>';
        echo '<p class="description">' . esc_html__('Angehakt = Block ist in deiner Kategorie. Bei einer übernommenen Kategorie sind die Blöcke schon an, du kannst hier einzelne wieder rausnehmen.', 'rh-editor') . '</p>';

        $byCategory = [];
        foreach ($blocks as $name => $block) {
            $byCategory[$block['category']][$name] = $block['title'];
        }
        ksort($byCategory);

        foreach ($byCategory as $cat => $items) {
            $catLabel = $categories[$cat] ?? ($cat !== '' ? $cat : __('Ohne Kategorie', 'rh-editor'));
            echo '<details style="margin:.5rem 0;border:1px solid #dcdcde;border-radius:6px;padding:.5rem .8rem">';
            echo '<summary style="cursor:pointer;font-weight:600">' . esc_html($catLabel) . ' <span style="opacity:.5;font-weight:400">(' . count($items) . ')</span></summary>';
            echo '<div style="display:flex;flex-wrap:wrap;gap:.4rem 1.5rem;margin-top:.6rem">';
            foreach ($items as $name => $title) {
                $catIncluded = in_array($cat, $includeCats, true);
                $checked = ($catIncluded || in_array($name, $includeBlocks, true)) && ! in_array($name, $excludeBlocks, true);
                echo '<label style="display:block;min-width:200px"><input type="checkbox" name="block[' . esc_attr($name) . ']" value="1" ' . checked($checked, true, false) . '> ' . esc_html($title) . '</label>';
            }
            echo '</div></details>';
        }
    }

    public function save(): void
    {
        if (! isset($_POST[self::NONCE]) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::ACTION)) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rh-editor'));
        }
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-editor'));
        }

        // Einfache Toggles in die Editor-Option (dieselbe, die rhbp_setting('editor', ...) liest).
        update_option(SettingsHub::optionName(EditorGroup::GROUP_ID), [
            EditorGroup::FIELD_INSERTER_CLEANUP => isset($_POST['inserter_cleanup']),
            EditorGroup::FIELD_SVG_UPLOAD => isset($_POST['svg_upload']),
            EditorGroup::FIELD_BLOCK_CATEGORY => isset($_POST['block_category']),
            EditorGroup::FIELD_CATEGORY_LABEL => isset($_POST['category_label'])
                ? sanitize_text_field(wp_unslash($_POST['category_label']))
                : 'Bausteine',
        ]);

        // Kategorie-Auswahl.
        $includeCategories = $this->postSlugList('include_categories');
        $hiddenCategories = $this->postSlugList('hidden_categories');

        $checkedBlocks = isset($_POST['block']) && is_array($_POST['block']) ? array_map('strval', array_keys($_POST['block'])) : [];
        $includeBlocks = [];
        $excludeBlocks = [];

        foreach ($this->config->availableBlocks() as $name => $block) {
            $isChecked = in_array($name, $checkedBlocks, true);
            $catIncluded = in_array($block['category'], $includeCategories, true);

            if ($isChecked && ! $catIncluded) {
                $includeBlocks[] = $name;
            } elseif (! $isChecked && $catIncluded) {
                $excludeBlocks[] = $name;
            }
        }

        $this->config->save($includeCategories, $includeBlocks, $excludeBlocks, $hiddenCategories);

        wp_safe_redirect(admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=' . self::TAB . '&rheditor_saved=1'));
        exit;
    }

    /**
     * @return array<int, string>
     */
    private function postSlugList(string $key): array
    {
        if (! isset($_POST[$key]) || ! is_array($_POST[$key])) {
            return [];
        }

        $values = array_map(static fn ($v): string => sanitize_key((string) $v), $_POST[$key]);

        return array_values(array_filter($values));
    }
}
