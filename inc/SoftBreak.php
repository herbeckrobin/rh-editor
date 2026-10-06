<?php

declare(strict_types=1);

namespace RhEditor;

/**
 * Umbruchstellen im Editor setzen: weiches Trennzeichen (U+00AD) und
 * unsichtbarer Umbruch (U+200B), je per Knopf in der Text-Werkzeugleiste und
 * per Tastenkürzel. Gespeichert wird nur das Zeichen, die Markierung existiert
 * nur im Editor (Logik in assets/js/soft-break.js).
 */
final class SoftBreak
{
    public function boot(): void
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueueScript']);
        // enqueue_block_assets erreicht auch das Editor-iframe, ist aber
        // ebenso Frontend-Hook. Darum nur im Admin.
        add_action('enqueue_block_assets', [$this, 'enqueueStyle']);
    }

    public function enqueueScript(): void
    {
        $rel = 'assets/js/soft-break.js';
        wp_enqueue_script(
            'rh-editor-soft-break',
            RHEDITOR_PLUGIN_URL . $rel,
            ['wp-rich-text', 'wp-block-editor', 'wp-element', 'wp-i18n', 'wp-data'],
            (string) filemtime(RHEDITOR_PLUGIN_DIR . $rel),
            true
        );
    }

    public function enqueueStyle(): void
    {
        if (! is_admin()) {
            return;
        }

        $rel = 'assets/soft-break-editor.css';
        wp_enqueue_style(
            'rh-editor-soft-break',
            RHEDITOR_PLUGIN_URL . $rel,
            [],
            (string) filemtime(RHEDITOR_PLUGIN_DIR . $rel)
        );
    }
}
