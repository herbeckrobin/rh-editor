<?php

declare(strict_types=1);

namespace RhEditor;

use RhBlueprint\Core\Core;
use RhBlueprint\Core\Settings\SettingsPage;
use RhEditor\Admin\BlockCategoryPage;

/**
 * Bootstrap von rh-editor.
 *
 * Zwei Timing-Ebenen: der Inserter-Cleanup wird früh (auf `after_setup_theme`)
 * registriert, weil `remove_theme_support('core-block-patterns')` vor init
 * greifen muss. SVG-Upload, Block-Kategorie und Settings hängen am Core-Hook
 * `rh-blueprint/core/booted` (init). Braucht nur den Core, keine db-engine.
 */
final class Plugin
{
    public static function boot(): void
    {
        if (class_exists(UpdateChecker::class)) {
            (new UpdateChecker())->boot();
        }

        // Früh, vor after_setup_theme/init.
        add_action('after_setup_theme', [Editor::class, 'onAfterSetupTheme'], 11);
        add_filter('should_load_remote_block_patterns', [Editor::class, 'filterRemotePatterns']);

        add_action('rh-blueprint/core/booted', [self::class, 'onCoreBooted']);
    }

    public static function onCoreBooted(Core $core): void
    {
        // Kein registerGroup: der ganze Editor-Tab ist eine bespoke Single-Form-UI
        // (BlockCategoryPage), damit es nur einen Speichern-Button gibt.
        $core->settings()->registerTab('editor', __('Editor', 'rh-editor'), 45);

        $config = new BlockCategoryConfig();
        (new Editor($config))->boot();

        $roles = new RolesConfig();
        (new RoleRestrictions($roles))->boot();

        (new BlockCategoryPage($config, $roles))->boot();

        add_filter('rh-blueprint/dashboard/quick_links', static function (array $links): array {
            $links[] = [
                'label' => __('Editor', 'rh-editor'),
                'url' => admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=editor'),
                'icon' => 'edit',
            ];
            return $links;
        });
    }
}
