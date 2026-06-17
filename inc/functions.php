<?php

declare(strict_types=1);

/**
 * Globale Helper von rh-editor. Über Composer (`autoload.files`) immer geladen,
 * damit Themes sie in ihrer functions.php aufrufen können (typisch auf `init`).
 */

if (! function_exists('rh_editor_register_block_style')) {
    /**
     * Block-Style idempotent registrieren: vorher entregistrieren, falls der Slug
     * schon belegt ist (sonst überschreibt register_block_style still ohne Warnung).
     *
     * @param array<string, mixed> $args Zusätzliche register_block_style-Argumente (z.B. inline_style).
     */
    function rh_editor_register_block_style(string $block, string $name, string $label, array $args = []): void
    {
        if (! function_exists('register_block_style') || ! class_exists('WP_Block_Styles_Registry')) {
            return;
        }

        $registry = WP_Block_Styles_Registry::get_instance();
        if ($registry->is_registered($block, $name)) {
            unregister_block_style($block, $name);
        }

        register_block_style($block, array_merge(['name' => $name, 'label' => $label], $args));
    }
}
