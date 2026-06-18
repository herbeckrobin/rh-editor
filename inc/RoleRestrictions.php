<?php

declare(strict_types=1);

namespace RhEditor;

use WP_Block_Patterns_Registry;
use WP_User;

/**
 * Setzt die pro-Rolle-Editor-Erfahrung durch (RolesConfig).
 *
 * Drei Hebel, alle zur Laufzeit per Filter, nichts wird persistent in die
 * Rollen-Option der DB geschrieben (reversibel, kein Müll beim Deaktivieren):
 *
 *  - block_editor_settings_all: contentOnly-Lock (Modus "content") + Code-Editor
 *    aus (Modus "content"/"patterns"). Nur im Seiten-/Beitrags-Editor.
 *  - allowed_block_types_all: im Vorlagen-Modus nur die Bausteine, die in den
 *    Theme-Mustern vorkommen, damit der Kunde aus Mustern baut statt frei.
 *  - user_has_cap: gibt Stile-Rollen edit_theme_options (Site-Editor). Per JS
 *    wird der Site-Editor für diese Rollen auf "Stile" reduziert.
 *
 * Der Administrator (manage_options) ist nie betroffen.
 */
final class RoleRestrictions
{
    /**
     * Restriktions-Rang der Modi: höher = stärker eingeschränkt. Hat ein User
     * mehrere verwaltete Rollen, gilt die am wenigsten einschränkende.
     *
     * @var array<string, int>
     */
    private const MODE_RANK = [
        RolesConfig::MODE_FULL => 0,
        RolesConfig::MODE_PATTERNS => 1,
        RolesConfig::MODE_CONTENT => 2,
    ];

    /**
     * Fallback-Bausteine für den Vorlagen-Modus, falls keine Theme-Muster
     * existieren, aus denen sich die erlaubten Blöcke ableiten lassen.
     *
     * @var array<int, string>
     */
    private const PATTERN_FALLBACK_BLOCKS = [
        'core/paragraph', 'core/heading', 'core/image', 'core/list', 'core/list-item',
        'core/group', 'core/columns', 'core/column', 'core/buttons', 'core/button',
        'core/quote', 'core/spacer', 'core/separator', 'core/cover', 'core/gallery',
    ];

    public function __construct(private readonly RolesConfig $config)
    {
    }

    public function boot(): void
    {
        add_filter('block_editor_settings_all', [$this, 'filterEditorSettings'], 10, 2);
        add_filter('allowed_block_types_all', [$this, 'filterAllowedBlocks'], 20, 2);
        add_filter('user_has_cap', [$this, 'grantStylesCap'], 10, 4);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueAssets']);
    }

    /**
     * Modus + Code-Editor-Sperre in die Editor-Settings, nur im Beitrags-Editor.
     *
     * @param array<string, mixed> $settings
     * @param mixed                $context
     * @return array<string, mixed>
     */
    public function filterEditorSettings(array $settings, $context): array
    {
        if (! $this->isPostEditorContext($context)) {
            return $settings;
        }

        $mode = $this->currentUserMode();

        // Code-Editor in beiden eingeschränkten Modi aus (sonst per HTML aushebelbar).
        // Den contentOnly-Lock setzt NICHT dieser Filter: das `templateLock`-Editor-
        // Setting wird vom Post-Editor ignoriert (gemessen). Der Lock kommt im
        // Frontend-JS über die setBlockEditingMode-API (role-editor.js).
        if ($mode === RolesConfig::MODE_CONTENT || $mode === RolesConfig::MODE_PATTERNS) {
            $settings['codeEditingEnabled'] = false;
        }

        return $settings;
    }

    /**
     * Vorlagen-Modus: erlaubte Blöcke auf die in den Theme-Mustern verwendeten
     * Bausteine begrenzen, damit der Kunde aus Mustern startet. Nur im
     * Beitrags-Editor (nicht im Site-Editor, dort würde FSE brechen).
     *
     * @param bool|array<int, string> $allowed
     * @param mixed                   $context
     * @return bool|array<int, string>
     */
    public function filterAllowedBlocks($allowed, $context)
    {
        if (! $this->isPostEditorContext($context)) {
            return $allowed;
        }

        $mode = $this->currentUserMode();

        // Nur Inhalt: gar keine Blöcke einfügbar. Der contentOnly-Lock auf die
        // bestehenden Blöcke kommt zusätzlich aus role-editor.js.
        if ($mode === RolesConfig::MODE_CONTENT) {
            return false;
        }

        if ($mode !== RolesConfig::MODE_PATTERNS) {
            return $allowed;
        }

        $patternBlocks = $this->patternBlockTypes();
        if ($patternBlocks === []) {
            $patternBlocks = self::PATTERN_FALLBACK_BLOCKS;
        }

        if ($allowed === false) {
            return $allowed;
        }
        if ($allowed === true) {
            return array_values($patternBlocks);
        }

        // Schnittmenge mit einer bereits einschränkenden Liste (z.B. Block-Kategorie).
        return array_values(array_intersect(array_map('strval', (array) $allowed), $patternBlocks));
    }

    /**
     * Stile-Rollen bekommen edit_theme_options (Zugang zum Site-Editor). Der
     * Site-Editor wird per JS auf den Stile-Bereich reduziert.
     *
     * @param array<string, bool> $allcaps
     * @param array<int, string>  $caps
     * @param array<int, mixed>   $args
     * @param WP_User             $user
     * @return array<string, bool>
     */
    public function grantStylesCap(array $allcaps, array $caps, array $args, $user): array
    {
        if (! $user instanceof WP_User || ! empty($allcaps['manage_options'])) {
            return $allcaps;
        }

        foreach ($this->config->rolesWithStyles() as $slug) {
            if (in_array($slug, $user->roles, true)) {
                $allcaps['edit_theme_options'] = true;
                break;
            }
        }

        return $allcaps;
    }

    /**
     * Editor-JS für Vorlagen-Modus (Blöcke-Tab weg) und Stile-Reduktion im
     * Site-Editor. Konfiguration kommt aus dem aktuellen User-Modus.
     */
    public function enqueueAssets(): void
    {
        $mode = $this->currentUserMode();
        $stylesOnly = $this->currentUserStylesOnly();

        if ($mode === RolesConfig::MODE_FULL && ! $stylesOnly) {
            return;
        }

        $jsRel = 'assets/js/role-editor.js';
        $jsAbs = RHEDITOR_PLUGIN_DIR . $jsRel;
        if (! file_exists($jsAbs)) {
            return;
        }

        wp_enqueue_script(
            'rh-editor-roles',
            RHEDITOR_PLUGIN_URL . $jsRel,
            ['wp-dom-ready', 'wp-data'],
            (string) filemtime($jsAbs),
            true
        );

        wp_localize_script('rh-editor-roles', 'rhEditorRoles', [
            'mode' => $mode,
            'stylesOnly' => $stylesOnly,
        ]);
    }

    /**
     * Effektiver Modus des aktuellen Users: der am wenigsten einschränkende über
     * alle seine verwalteten Rollen. Admin und Nicht-eingeloggt -> voll.
     */
    private function currentUserMode(): string
    {
        if (current_user_can('manage_options')) {
            return RolesConfig::MODE_FULL;
        }

        $user = wp_get_current_user();
        if (! $user instanceof WP_User || $user->ID === 0) {
            return RolesConfig::MODE_FULL;
        }

        $managed = $this->config->managedRoles();
        $best = null;
        foreach ($user->roles as $slug) {
            if (! isset($managed[$slug])) {
                continue;
            }
            $rank = self::MODE_RANK[$this->config->mode($slug)] ?? 0;
            $best = $best === null ? $rank : min($best, $rank);
        }

        if ($best === null) {
            return RolesConfig::MODE_FULL;
        }

        return array_search($best, self::MODE_RANK, true) ?: RolesConfig::MODE_FULL;
    }

    /**
     * Soll der Site-Editor für den aktuellen User auf Stile reduziert werden?
     * Nur wenn er die Stile-Freigabe über eine verwaltete Rolle hat und nicht
     * ohnehin Admin ist.
     */
    private function currentUserStylesOnly(): bool
    {
        if (current_user_can('manage_options')) {
            return false;
        }

        $user = wp_get_current_user();
        if (! $user instanceof WP_User || $user->ID === 0) {
            return false;
        }

        foreach ($this->config->rolesWithStyles() as $slug) {
            if (in_array($slug, $user->roles, true)) {
                return true;
            }
        }

        return false;
    }

    private function isPostEditorContext($context): bool
    {
        $name = is_object($context) && isset($context->name) ? (string) $context->name : '';

        return $name === 'core/edit-post';
    }

    /**
     * Block-Namen, die in allen im Inserter sichtbaren Mustern vorkommen.
     *
     * @return array<int, string>
     */
    private function patternBlockTypes(): array
    {
        $names = [];
        $registry = WP_Block_Patterns_Registry::get_instance();

        foreach ($registry->get_all_registered() as $pattern) {
            if (array_key_exists('inserter', $pattern) && $pattern['inserter'] === false) {
                continue;
            }
            $content = is_string($pattern['content'] ?? null) ? $pattern['content'] : '';
            if ($content === '') {
                continue;
            }
            foreach (parse_blocks($content) as $block) {
                $this->collectBlockNames($block, $names);
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, string>   $names
     */
    private function collectBlockNames(array $block, array &$names): void
    {
        $name = $block['blockName'] ?? null;
        if (is_string($name) && $name !== '') {
            $names[] = $name;
        }
        $inner = $block['innerBlocks'] ?? [];
        if (is_array($inner)) {
            foreach ($inner as $child) {
                if (is_array($child)) {
                    $this->collectBlockNames($child, $names);
                }
            }
        }
    }
}
