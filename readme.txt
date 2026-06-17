=== RH Editor ===
Contributors: robinherbeck
Tags: editor, gutenberg, svg, block patterns, block styles
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.1
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

The block whitelist for the category is extensible via the rh-blueprint/editor/category_blocks filter. The category slug and block list come from PHP and are mirrored into the editor (single source).

Part of the rh-blueprint collection. Settings live under RH Blueprint > Editor.

== Changelog ==

= 0.1.0 =
* Initial release: inserter cleanup, curated block category, opt-in SVG upload with sanitisation, idempotent block-style helper.
