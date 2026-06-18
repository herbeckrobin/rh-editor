<?php

declare(strict_types=1);

namespace RhEditor\Admin;

use RhBlueprint\Core\Settings\SettingsHub;
use RhBlueprint\Core\Settings\SettingsPage;
use RhEditor\BlockCategoryConfig;
use RhEditor\RolesConfig;

/**
 * Editor-Tab im Reihen-Look der Sync-/Tracking-/Monitor-Seiten.
 *
 * Pro Eingriff bzw. Rolle eine schlanke rhbp-card-Zeile mit An/Aus-Schalter
 * (auto-submit) und Zahnrad-Modal für die Detailfelder. Keine GroupInterface
 * (der Core würde sonst eine flache Feldliste rendern), stattdessen eigener
 * Content über die tab_content-Hooks und getrennte admin-post-Handler, die in
 * dieselben Optionen schreiben, aus denen Editor/RoleRestrictions lesen.
 */
final class BlockCategoryPage
{
    public const TAB = 'editor';
    private const CAP = 'manage_options';

    private const ACTION_TOGGLE = 'rheditor_toggle';
    private const ACTION_SAVE_CATEGORY = 'rheditor_save_category';
    private const ACTION_SAVE_ROLE = 'rheditor_save_role';
    private const NONCE_TOGGLE = 'rheditor_toggle_nonce';
    private const NONCE_CATEGORY = 'rheditor_category_nonce';
    private const NONCE_ROLE = 'rheditor_role_nonce';

    public function __construct(
        private readonly BlockCategoryConfig $config,
        private readonly RolesConfig $roles
    ) {
    }

    public function boot(): void
    {
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderMessage']);
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render']);
        add_action('admin_post_' . self::ACTION_TOGGLE, [$this, 'handleToggle']);
        add_action('admin_post_' . self::ACTION_SAVE_CATEGORY, [$this, 'handleSaveCategory']);
        add_action('admin_post_' . self::ACTION_SAVE_ROLE, [$this, 'handleSaveRole']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== SettingsPage::MENU_SLUG) {
            return;
        }
        $abs = RHEDITOR_PLUGIN_DIR . 'assets/admin.css';
        if (! file_exists($abs)) {
            return;
        }
        wp_enqueue_style(
            'rh-editor-admin',
            RHEDITOR_PLUGIN_URL . 'assets/admin.css',
            ['rh-blueprint-settings'],
            (string) filemtime($abs)
        );
    }

    public function renderMessage(string $tab): void
    {
        if ($tab !== self::TAB) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige nach Redirect.
        $message = isset($_GET['rheditor_message']) ? sanitize_key(wp_unslash($_GET['rheditor_message'])) : '';
        $map = [
            'saved' => __('Einstellungen gespeichert.', 'rh-editor'),
            'toggled' => __('Einstellung umgeschaltet.', 'rh-editor'),
            'role_saved' => __('Rolle gespeichert.', 'rh-editor'),
        ];
        if (! isset($map[$message])) {
            return;
        }
        echo '<div class="rhbp-callout rhbp-callout--success">' . esc_html($map[$message]) . '</div>';
    }

    public function render(string $tab): void
    {
        if ($tab !== self::TAB || ! current_user_can(self::CAP)) {
            return;
        }

        echo '<p class="rhed-intro">' . esc_html__('Den Block-Editor für den Endkunden aufräumen, erweitern und pro Rolle einschränken. Details jeweils über das Zahnrad.', 'rh-editor') . '</p>';

        $this->renderEditorRows();
        $this->renderRoleRows();

        // Modals (eigene Overlay-Ebene, außerhalb der Reihen).
        $this->renderCategoryModal();
        foreach (array_keys($this->roles->managedRoles()) as $slug) {
            $this->renderRoleModal($slug);
        }
    }

    // --- Reihen ---

    private function renderEditorRows(): void
    {
        $inserter = (bool) rhbp_setting(EditorGroup::GROUP_ID, EditorGroup::FIELD_INSERTER_CLEANUP, true);
        $svg = (bool) rhbp_setting(EditorGroup::GROUP_ID, EditorGroup::FIELD_SVG_UPLOAD, false);
        $catEnabled = (bool) rhbp_setting(EditorGroup::GROUP_ID, EditorGroup::FIELD_BLOCK_CATEGORY, true);

        echo '<div class="rhed-list">';

        $this->row(
            'layout',
            __('Inserter aufräumen', 'rh-editor'),
            __('WordPress-Standard- und Remote-Vorlagen ausblenden.', 'rh-editor'),
            $this->switchForm(EditorGroup::FIELD_INSERTER_CLEANUP, $inserter)
        );

        $this->row(
            'image',
            __('SVG-Upload erlauben', 'rh-editor'),
            __('SVG in der Mediathek (mit einfacher Sanitisierung). Nur für vertrauenswürdige Redakteure.', 'rh-editor'),
            $this->switchForm(EditorGroup::FIELD_SVG_UPLOAD, $svg)
        );

        $this->row(
            'grid',
            __('Eigene Block-Kategorie', 'rh-editor'),
            __('Ausgewählte Blöcke in einer eigenen Kategorie oben im Inserter bündeln.', 'rh-editor'),
            $this->switchForm(EditorGroup::FIELD_BLOCK_CATEGORY, $catEnabled)
                . $this->gearButton('rheditor-modal-category', __('Inhalt der Kategorie', 'rh-editor'))
        );

        echo '</div>';
    }

    private function renderRoleRows(): void
    {
        $roles = $this->roles->managedRoles();
        if ($roles === []) {
            return;
        }

        echo '<p class="rhed-section-title">' . esc_html__('Rollen', 'rh-editor') . '</p>';
        echo '<div class="rhed-list">';

        foreach (array_keys($roles) as $slug) {
            $this->row(
                'user',
                $this->roles->roleLabel($slug),
                $this->roleSummary($slug),
                $this->modePills($slug)
                    . $this->gearButton('rheditor-modal-role-' . $slug, __('Rolle bearbeiten', 'rh-editor'))
            );
        }

        echo '</div>';
    }

    private function roleSummary(string $slug): string
    {
        $map = [
            RolesConfig::MODE_FULL => __('Voller Editor', 'rh-editor'),
            RolesConfig::MODE_PATTERNS => __('Nur Vorlagen einfügen', 'rh-editor'),
            RolesConfig::MODE_CONTENT => __('Nur Inhalt bearbeiten', 'rh-editor'),
        ];

        return $map[$this->roles->mode($slug)] ?? '';
    }

    /**
     * @param string $actionsHtml Bereits escaptes Aktions-Markup (Switch/Zahnrad/Pills).
     */
    private function row(string $icon, string $name, string $sub, string $actionsHtml): void
    {
        echo '<div class="rhbp-card rhed-row">';
        echo '<div class="rhed-row__brand">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internes SVG aus festen Konstanten.
        echo $this->icon($icon, 'rhed-row__icon');
        echo '<div class="rhed-row__text"><strong>' . esc_html($name) . '</strong><span>' . esc_html($sub) . '</span></div>';
        echo '</div>';
        echo '<div class="rhed-row__actions">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup in den Buildern bereits escapt.
        echo $actionsHtml;
        echo '</div>';
        echo '</div>';
    }

    private function switchForm(string $field, bool $on): string
    {
        ob_start();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-toggle-form">';
        wp_nonce_field(self::ACTION_TOGGLE, self::NONCE_TOGGLE);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_TOGGLE) . '">';
        echo '<input type="hidden" name="field" value="' . esc_attr($field) . '">';
        printf(
            '<label class="rhbp-switch"><input type="checkbox" name="enabled" value="1" %s onchange="this.form.submit()"><span class="rhbp-switch__track" aria-hidden="true"></span></label>',
            checked($on, true, false)
        );
        echo '</form>';

        return (string) ob_get_clean();
    }

    private function gearButton(string $modalId, string $label): string
    {
        return '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-open="' . esc_attr($modalId) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '">' . $this->icon('gear') . '</button>';
    }

    private function modePills(string $slug): string
    {
        $mode = $this->roles->mode($slug);
        $modeLabels = [
            RolesConfig::MODE_FULL => [__('Voll', 'rh-editor'), ''],
            RolesConfig::MODE_PATTERNS => [__('Nur Vorlagen', 'rh-editor'), ' rhbp-pill--accent'],
            RolesConfig::MODE_CONTENT => [__('Nur Inhalt', 'rh-editor'), ' rhbp-pill--accent'],
        ];
        [$label, $variant] = $modeLabels[$mode] ?? [__('Voll', 'rh-editor'), ''];

        $html = '<span class="rhbp-pill' . esc_attr($variant) . '">' . esc_html($label) . '</span>';
        if ($this->roles->stylesEnabled($slug)) {
            $html .= '<span class="rhbp-pill rhbp-pill--ok">' . esc_html__('Stile', 'rh-editor') . '</span>';
        }

        return $html;
    }

    // --- Modals ---

    private function renderCategoryModal(): void
    {
        $label = (string) rhbp_setting(EditorGroup::GROUP_ID, EditorGroup::FIELD_CATEGORY_LABEL, 'Bausteine');

        echo '<div class="rhbp-modal-backdrop" id="rheditor-modal-category" data-rhbp-modal-backdrop>';
        echo '<div class="rhbp-modal" role="dialog" aria-modal="true" aria-label="' . esc_attr__('Inhalt der Block-Kategorie', 'rh-editor') . '">';

        echo '<div class="rhbp-modal__head"><div class="rhbp-modal__head-l">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internes SVG.
        echo $this->icon('grid');
        echo '<div><h3 class="rhbp-modal__title">' . esc_html__('Eigene Block-Kategorie', 'rh-editor') . '</h3><p class="rhbp-modal__sub">' . esc_html__('Name und welche Blöcke gebündelt werden.', 'rh-editor') . '</p></div>';
        echo '</div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internes SVG.
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-close aria-label="' . esc_attr__('Schließen', 'rh-editor') . '">' . $this->icon('close') . '</button>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION_SAVE_CATEGORY, self::NONCE_CATEGORY);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE_CATEGORY) . '">';

        echo '<div class="rhbp-modal__body">';
        echo '<div class="rhbp-field"><label for="rheditor_label">' . esc_html__('Name der Kategorie', 'rh-editor') . '</label>';
        echo '<input type="text" id="rheditor_label" name="category_label" class="regular-text" value="' . esc_attr($label) . '"></div>';

        echo '<h4 class="rhed-modal-section">' . esc_html__('Kategorien', 'rh-editor') . '</h4>';
        echo '<p class="rhbp-hint">' . esc_html__('"Übernehmen" zieht alle Blöcke der Kategorie in deine eigene (auch künftige). "Ausblenden" entfernt sie aus dem Inserter.', 'rh-editor') . '</p>';
        $this->renderCategoryTable();

        echo '<h4 class="rhed-modal-section">' . esc_html__('Einzelne Blöcke', 'rh-editor') . '</h4>';
        $this->renderBlockList();
        echo '</div>';

        $this->modalFoot();
        echo '</form></div></div>';
    }

    private function renderRoleModal(string $slug): void
    {
        $currentMode = $this->roles->mode($slug);
        $stylesOn = $this->roles->stylesEnabled($slug);
        $modes = [
            RolesConfig::MODE_FULL => [__('Alles dürfen', 'rh-editor'), __('Voller Editor, keine Einschränkung.', 'rh-editor')],
            RolesConfig::MODE_PATTERNS => [__('Nur Vorlagen', 'rh-editor'), __('Nur Muster einfügbar, der Blöcke-Tab ist weg.', 'rh-editor')],
            RolesConfig::MODE_CONTENT => [__('Nur Inhalt', 'rh-editor'), __('Nur Texte und Bilder ändern, nichts einfügen oder umbauen.', 'rh-editor')],
        ];

        echo '<div class="rhbp-modal-backdrop" id="rheditor-modal-role-' . esc_attr($slug) . '" data-rhbp-modal-backdrop>';
        echo '<div class="rhbp-modal" role="dialog" aria-modal="true">';

        echo '<div class="rhbp-modal__head"><div class="rhbp-modal__head-l">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internes SVG.
        echo $this->icon('user');
        echo '<div><h3 class="rhbp-modal__title">' . esc_html($this->roles->roleLabel($slug)) . '</h3><p class="rhbp-modal__sub">' . esc_html__('Wie viel diese Rolle im Editor darf.', 'rh-editor') . '</p></div>';
        echo '</div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internes SVG.
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-close aria-label="' . esc_attr__('Schließen', 'rh-editor') . '">' . $this->icon('close') . '</button>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION_SAVE_ROLE, self::NONCE_ROLE);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE_ROLE) . '">';
        echo '<input type="hidden" name="role" value="' . esc_attr($slug) . '">';

        echo '<div class="rhbp-modal__body">';
        echo '<div class="rhbp-option-grid">';
        foreach ($modes as $value => [$label, $desc]) {
            $checked = $currentMode === $value;
            echo '<label class="rhbp-option' . ($checked ? ' is-checked' : '') . '">';
            echo '<input type="radio" name="role_mode" value="' . esc_attr($value) . '"' . checked($checked, true, false) . '>';
            echo '<span class="rhbp-option__text"><span class="rhbp-option__label">' . esc_html($label) . '</span><span class="rhbp-option__desc">' . esc_html($desc) . '</span></span>';
            echo '</label>';
        }
        echo '</div>';

        echo '<label class="rhbp-check-row" style="margin-top:.8rem">';
        echo '<input type="checkbox" name="role_styles" value="1"' . checked($stylesOn, true, false) . '>';
        echo '<span class="rhbp-check-row__text"><span class="rhbp-check-row__label">' . esc_html__('Site-weite Stile bearbeiten', 'rh-editor') . '</span><span class="rhbp-check-row__desc">' . esc_html__('Zugang zum Stile-Bereich im Site-Editor (nur Stile, keine Templates).', 'rh-editor') . '</span></span>';
        echo '</label>';
        echo '</div>';

        $this->modalFoot();
        echo '</form></div></div>';
    }

    private function modalFoot(): void
    {
        echo '<div class="rhbp-modal__foot">';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost" data-rhbp-modal-close>' . esc_html__('Abbrechen', 'rh-editor') . '</button>';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--primary">' . esc_html__('Speichern', 'rh-editor') . '</button>';
        echo '</div>';
    }

    private function renderCategoryTable(): void
    {
        $categories = $this->config->availableCategories();
        $includeCats = $this->config->includeCategories();
        $hiddenCats = $this->config->hiddenCategories();

        echo '<table class="rhed-cat-table"><thead><tr>';
        echo '<th>' . esc_html__('Kategorie', 'rh-editor') . '</th>';
        echo '<th class="rhed-cat-check">' . esc_html__('Übernehmen', 'rh-editor') . '</th>';
        echo '<th class="rhed-cat-check">' . esc_html__('Ausblenden', 'rh-editor') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($categories as $slug => $label) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($label) . '</strong></td>';
            echo '<td class="rhed-cat-check"><input type="checkbox" name="include_categories[]" value="' . esc_attr($slug) . '" ' . checked(in_array($slug, $includeCats, true), true, false) . '></td>';
            echo '<td class="rhed-cat-check"><input type="checkbox" name="hidden_categories[]" value="' . esc_attr($slug) . '" ' . checked(in_array($slug, $hiddenCats, true), true, false) . '></td>';
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

        $byCategory = [];
        foreach ($blocks as $name => $block) {
            $byCategory[$block['category']][$name] = $block['title'];
        }
        ksort($byCategory);

        foreach ($byCategory as $cat => $items) {
            $catLabel = $categories[$cat] ?? ($cat !== '' ? $cat : __('Ohne Kategorie', 'rh-editor'));
            echo '<details class="rhed-block-group">';
            echo '<summary>' . esc_html($catLabel) . ' <span style="opacity:.5;font-weight:400">(' . count($items) . ')</span></summary>';
            echo '<div class="rhed-block-group__items">';
            foreach ($items as $name => $title) {
                $catIncluded = in_array($cat, $includeCats, true);
                $checked = ($catIncluded || in_array($name, $includeBlocks, true)) && ! in_array($name, $excludeBlocks, true);
                echo '<label><input type="checkbox" name="block[' . esc_attr($name) . ']" value="1" ' . checked($checked, true, false) . '> ' . esc_html($title) . '</label>';
            }
            echo '</div></details>';
        }
    }

    // --- Handler ---

    public function handleToggle(): void
    {
        $this->guard(self::NONCE_TOGGLE, self::ACTION_TOGGLE);

        $valid = [
            EditorGroup::FIELD_INSERTER_CLEANUP,
            EditorGroup::FIELD_SVG_UPLOAD,
            EditorGroup::FIELD_BLOCK_CATEGORY,
        ];
        $field = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : '';
        if (in_array($field, $valid, true)) {
            rhbp_update_setting(EditorGroup::GROUP_ID, $field, isset($_POST['enabled']));
        }

        $this->redirect('toggled');
    }

    public function handleSaveCategory(): void
    {
        $this->guard(self::NONCE_CATEGORY, self::ACTION_SAVE_CATEGORY);

        rhbp_update_setting(
            EditorGroup::GROUP_ID,
            EditorGroup::FIELD_CATEGORY_LABEL,
            isset($_POST['category_label']) ? sanitize_text_field(wp_unslash($_POST['category_label'])) : 'Bausteine'
        );

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
        $this->redirect('saved');
    }

    public function handleSaveRole(): void
    {
        $this->guard(self::NONCE_ROLE, self::ACTION_SAVE_ROLE);

        $slug = isset($_POST['role']) ? sanitize_key(wp_unslash($_POST['role'])) : '';
        $mode = isset($_POST['role_mode']) ? sanitize_key(wp_unslash($_POST['role_mode'])) : RolesConfig::MODE_FULL;
        $styles = isset($_POST['role_styles']);

        if ($slug !== '') {
            $this->roles->saveRole($slug, $mode, $styles);
        }

        $this->redirect('role_saved');
    }

    private function guard(string $nonceField, string $action): void
    {
        if (! isset($_POST[$nonceField]) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonceField])), $action)) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rh-editor'));
        }
        if (! current_user_can(self::CAP)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-editor'));
        }
    }

    private function redirect(string $message): never
    {
        wp_safe_redirect(add_query_arg(
            ['page' => SettingsPage::MENU_SLUG, 'tab' => self::TAB, 'rheditor_message' => $message],
            admin_url('admin.php')
        ));
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

    private function icon(string $name, string $extraClass = ''): string
    {
        $paths = [
            'layout' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
            'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
            'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        ];
        $path = $paths[$name] ?? '';
        $class = 'rhbp-ico' . ($extraClass !== '' ? ' ' . $extraClass : '');

        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}
