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
 *  - user_has_cap: gibt Stile- und Vorlagen-Rollen edit_theme_options
 *    (Site-Editor). Per JS wird der Site-Editor auf das Freigegebene reduziert:
 *    nur Stile, oder Vorlagen ohne den Stile-Bereich.
 *  - rest_request_before_callbacks + map_meta_cap: reine Stile-Rollen bekommen
 *    serverseitig keinen Schreibzugriff auf Vorlagen, Navigation, Menüs und
 *    Widgets, und keinen Customizer. edit_theme_options öffnet im Core all das
 *    auf einmal, die Grenze "nur Stile" hält sonst nur die Oberfläche.
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

    /**
     * REST-Routen, die eine reine Stile-Rolle nicht schreiben darf. Lesen bleibt
     * offen, die Stile-Vorschau im Site-Editor rendert die Vorlagen.
     *
     * @var array<int, string>
     */
    private const STYLES_ONLY_LOCKED_ROUTES = [
        '/wp/v2/templates',
        '/wp/v2/template-parts',
        '/wp/v2/navigation',
        '/wp/v2/menus',
        '/wp/v2/menu-items',
        '/wp/v2/menu-locations',
        '/wp/v2/widgets',
        '/wp/v2/sidebars',
    ];

    public function __construct(private readonly RolesConfig $config)
    {
    }

    public function boot(): void
    {
        add_filter('block_editor_settings_all', [$this, 'filterEditorSettings'], 10, 2);
        add_filter('allowed_block_types_all', [$this, 'filterAllowedBlocks'], 20, 2);
        add_filter('user_has_cap', [$this, 'grantSiteEditorCap'], 10, 4);
        add_filter('map_meta_cap', [$this, 'denyCustomizer'], 10, 2);
        add_filter('rest_request_before_callbacks', [$this, 'lockStylesOnlyWrites'], 10, 3);
        add_action('load-nav-menus.php', [$this, 'blockClassicThemeScreens']);
        add_action('load-widgets.php', [$this, 'blockClassicThemeScreens']);
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
     * Stile- und Vorlagen-Rollen bekommen edit_theme_options (Zugang zum
     * Site-Editor). Mehr nicht: wp_template, wp_template_part, wp_navigation und
     * wp_global_styles mappen im Core alle auf genau diese Capability. Was im
     * Site-Editor sichtbar ist, reduziert role-editor.js.
     *
     * @param array<string, bool> $allcaps
     * @param array<int, string>  $caps
     * @param array<int, mixed>   $args
     * @param WP_User             $user
     * @return array<string, bool>
     */
    public function grantSiteEditorCap(array $allcaps, array $caps, array $args, $user): array
    {
        if (! $user instanceof WP_User || ! empty($allcaps['manage_options'])) {
            return $allcaps;
        }

        if ($this->config->userHasStyles($user) || $this->config->userHasTemplates($user)) {
            $allcaps['edit_theme_options'] = true;
        }

        return $allcaps;
    }

    /**
     * Kein Customizer über die Freigaben: er käme über edit_theme_options mit und
     * öffnet Menüs, Widgets und Zusatz-CSS. Der Site-Editor deckt beide Freigaben ab.
     *
     * @param array<int, string> $caps
     * @return array<int, string>
     */
    public function denyCustomizer(array $caps, string $cap): array
    {
        if ($cap !== 'customize') {
            return $caps;
        }

        [$styles, $templates] = $this->currentUserSiteEditorAccess();
        if (($styles || $templates) && ! $this->userHasOwnThemeOptions()) {
            return ['do_not_allow'];
        }

        return $caps;
    }

    /**
     * Schreibende REST-Requests reiner Stile-Rollen auf Vorlagen, Navigation,
     * Menüs und Widgets abweisen. Greift auch in Batch-Requests.
     *
     * @param mixed         $response
     * @param array<mixed>  $handler
     * @return mixed
     */
    public function lockStylesOnlyWrites($response, $handler, \WP_REST_Request $request)
    {
        if ($response instanceof \WP_Error) {
            return $response;
        }
        if (in_array($request->get_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }
        if (! $this->currentUserStylesOnly()) {
            return $response;
        }

        $route = $request->get_route();
        foreach (self::STYLES_ONLY_LOCKED_ROUTES as $prefix) {
            if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
                return new \WP_Error(
                    'rheditor_styles_only',
                    __('Mit dieser Rolle lassen sich im Site-Editor nur die Stile ändern.', 'rh-editor'),
                    ['status' => 403]
                );
            }
        }

        return $response;
    }

    /**
     * Klassische Menü- und Widget-Seiten für reine Stile-Rollen sperren.
     */
    public function blockClassicThemeScreens(): void
    {
        if ($this->currentUserStylesOnly()) {
            wp_die(
                esc_html__('Mit dieser Rolle lassen sich nur die Stile der Website ändern.', 'rh-editor'),
                '',
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    /**
     * Editor-JS für Vorlagen-Modus (Blöcke-Tab weg) und die Reduktion des
     * Site-Editors. Konfiguration kommt aus dem aktuellen User.
     */
    public function enqueueAssets(): void
    {
        $mode = $this->currentUserMode();
        [$styles, $templates] = $this->currentUserSiteEditorAccess();
        $stylesOnly = $styles && ! $templates;
        $hideStyles = $templates && ! $styles;

        if ($mode === RolesConfig::MODE_FULL && ! $stylesOnly && ! $hideStyles) {
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
            'hideStyles' => $hideStyles,
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
     * Site-Editor-Freigaben des aktuellen Users als [Stile, Vorlagen]. Admins
     * und Nicht-Eingeloggte laufen nie über die Freigaben.
     *
     * @return array{0: bool, 1: bool}
     */
    private function currentUserSiteEditorAccess(): array
    {
        if (current_user_can('manage_options')) {
            return [false, false];
        }

        $user = wp_get_current_user();
        if (! $user instanceof WP_User || $user->ID === 0) {
            return [false, false];
        }

        return [$this->config->userHasStyles($user), $this->config->userHasTemplates($user)];
    }

    private function currentUserStylesOnly(): bool
    {
        [$styles, $templates] = $this->currentUserSiteEditorAccess();

        return $styles && ! $templates && ! $this->userHasOwnThemeOptions();
    }

    /**
     * Hat der User edit_theme_options schon über seine Rolle selbst (z.B. eine
     * eigene Rolle mit Design-Rechten)? Dann schränken die Freigaben nichts ein.
     */
    private function userHasOwnThemeOptions(): bool
    {
        $user = wp_get_current_user();
        if (! $user instanceof WP_User || $user->ID === 0) {
            return false;
        }
        foreach ($user->roles as $slug) {
            $role = get_role($slug);
            if ($role !== null && ! empty($role->capabilities['edit_theme_options'])) {
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
