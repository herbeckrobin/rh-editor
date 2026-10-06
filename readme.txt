=== RH Editor ===
Contributors: robinherbeck
Tags: editor, gutenberg, svg, block patterns, block styles
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Editor sovereignty for white-label handover: SVG upload with sanitisation, inserter cleanup, a curated block category and a block-style helper.

== Description ==

RH Editor tidies the block editor for the end customer and adds the few things WordPress core does not do out of the box.

= Features =

* Inserter cleanup: hide the WordPress core patterns and the remote pattern directory, so only the theme's own patterns remain
* Curated block category: group the common core blocks into one category at the top of the inserter, with a configurable label
* SVG upload (opt-in): allow SVG files in the media library with a basic sanitisation pass (script tags, on* handlers and javascript: URLs are stripped). Only enable for trusted editors
* Block-style helper: a global function rh_editor_register_block_style( $block, $name, $label ) that registers a block style idempotently (unregister first), for use from the theme
* Break points: insert a soft hyphen (U+00AD) or an invisible line break opportunity (U+200B) from the text format menu or with Ctrl+Option+S / Ctrl+Option+U (Alt+Shift+S / Alt+Shift+U on Windows). A small blue arrow marks each one in the editor only, a click on it removes the character. Only the character is saved

The block whitelist for the category is extensible via the rh-blueprint/editor/category_blocks filter. The category slug and block list come from PHP and are mirrored into the editor (single source).

Part of the rh-blueprint collection. Settings live under RH Blueprint > Editor.

== Changelog ==

= 0.2.7 =
* White label: when the core (2.9 or later) has a brand set, the module name in notices uses the brand instead of "RH".
* Author URI points to robinherbeck.com. Adds the GPLv2 LICENSE file.

= 0.2.6 =
* Bundles rh-blueprint-core 2.8.0. Same features as 0.2.5, which got no release build.

= 0.2.5 =
* New: break points for long words. Soft hyphen and invisible line break via button or keyboard shortcut, marked in the editor with a small arrow that removes the character on click. Works for content-only editors. Saved content contains only the character, no wrapper.

= 0.2.4 =
* New role option "Vorlagen und Logo bearbeiten": the role can edit templates, template parts, navigation and patterns in the site editor, plus the site logo and site icon. Settings access is limited to logo and icon, every other site setting stays locked. Off by default.
* Security: roles with only "Site-weite Stile bearbeiten" can no longer write templates, template parts, navigation, menus or widgets through the REST API, and lose access to the Customizer, the menus screen and the widgets screen. Before, only the site editor interface hid these areas.

= 0.2.3 =
* Update checks: use a GitHub token from RH_GITHUB_TOKEN (environment variable or wp-config constant) when one is set, which lifts the API limit from 60 to 5,000 requests per hour. Without a token nothing changes.
* Update checks: after a GitHub rate-limit response all rh modules on the site pause their checks until GitHub resets the limit, and the last known update is kept. Bundles core 2.7.1.

= 0.2.2 =
* Fix: bundle core 2.6.1. The 2.6.0 release bundled an incomplete core.

= 0.2.1 =
* Internal: shared building blocks from core 2.6.0. The update check no longer loads on regular front-end requests.

= 0.2.1 =
* Internal: shared building blocks from core 2.6.0. The update check no longer loads on regular front-end requests.

= 0.1.0 =
* Initial release: inserter cleanup, curated block category, opt-in SVG upload with sanitisation, idempotent block-style helper.
