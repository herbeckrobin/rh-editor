# RH Editor

Editor-Souveränität für den White-Label-Handover. Teil der rh-blueprint Kollektion.

Räumt den Block-Editor für den Endkunden auf und ergänzt das wenige, was der Core nicht kann.

## Was es macht

- **Inserter aufräumen**: blendet die WordPress-Standard-Vorlagen und die Remote-Vorlagen aus, im Vorlagen-Tab bleiben nur die theme-eigenen.
- **Eigene Block-Kategorie**: gruppiert ausgewählte Blöcke in eine eigene Kategorie oben im Inserter. Inhalt frei konfigurierbar: ganze Kategorien übernehmen (auch künftige Blöcke automatisch) und/oder einzelne Blöcke, andere Kategorien pro Stück ausblenden.
- **SVG-Upload** (opt-in): erlaubt SVG in der Mediathek mit einfacher Sanitisierung (script/on*/javascript: raus). Nur für vertrauenswürdige Redakteure.
- **Block-Style-Helper**: eine globale Funktion, um Block-Styles idempotent zu registrieren.

## Einstellungen

Im Backend unter **RH Blueprint → Editor** (eine Form, ein Speichern-Button): Inserter-Cleanup, SVG-Upload, eigene Block-Kategorie an/aus + Name, sowie die Inhalts-Auswahl (Kategorien übernehmen/ausblenden, einzelne Blöcke).

## Für Entwickler

```php
// Block-Style idempotent registrieren (entregistriert vorher, falls Slug belegt):
rh_editor_register_block_style( 'core/group', 'card', __( 'Karte', 'theme' ) );
```

Filter: `rh-blueprint/editor/category_blocks` (array) erweitert die in der UI auswählbaren Blöcke.

Das „Ausblenden" einer Kategorie macht deren Blöcke per `allowed_block_types_all` nicht-einfügbar (nur im Seiten-Editor, nicht im Site-Editor), die Kategorie verschwindet sauber statt Blöcke heimatlos zu machen.

## Installation

ZIP hochladen und aktivieren. Der geteilte Core ist gebündelt.

## Voraussetzungen

WordPress 6.5+, PHP 8.1+.
