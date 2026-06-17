<?php

declare(strict_types=1);

namespace RhEditor;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * GitHub-basierter Auto-Update-Checker für rh-editor. Zieht das Release-ZIP aus den
 * GitHub Releases (Tags `v*`), inkl. gebundeltem Core und plugin-update-checker.
 */
final class UpdateChecker
{
    public const GITHUB_REPO = 'https://github.com/herbeckrobin/rh-editor/';
    public const PLUGIN_SLUG = 'rh-editor';

    public function boot(): void
    {
        if (! function_exists('add_filter') || ! class_exists(PucFactory::class)) {
            return;
        }

        $updateChecker = PucFactory::buildUpdateChecker(
            self::GITHUB_REPO,
            RHEDITOR_PLUGIN_FILE,
            self::PLUGIN_SLUG
        );

        $vcsApi = $updateChecker->getVcsApi();
        if ($vcsApi !== null && method_exists($vcsApi, 'enableReleaseAssets')) {
            $vcsApi->enableReleaseAssets();
        }
    }
}
