<?php

/**
 * Plugin Name:       RH Editor
 * Plugin URI:        https://github.com/herbeckrobin/rh-editor
 * Update URI:        https://github.com/herbeckrobin/rh-editor
 * Description:       Editor-Souveränität: SVG-Upload mit Sanitisierung, Inserter aufräumen, genutzte Core-Blöcke in eine eigene Kategorie gruppieren, Block-Style-Helper. Teil der rh-blueprint Kollektion.
 * Version:           0.1.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Robin Herbeck
 * Author URI:        https://robinherbeck.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rh-editor
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RHEDITOR_VERSION', '0.1.3');
define('RHEDITOR_PLUGIN_FILE', __FILE__);
define('RHEDITOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RHEDITOR_PLUGIN_URL', plugin_dir_url(__FILE__));

$rheditor_autoload = RHEDITOR_PLUGIN_DIR . 'vendor/autoload.php';

if (! is_readable($rheditor_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>RH Editor:</strong> Composer-Dependencies fehlen. Bitte <code>composer install</code> im Plugin-Verzeichnis ausführen.</p></div>';
    });
    return;
}

require_once $rheditor_autoload;

RhEditor\Plugin::boot();
