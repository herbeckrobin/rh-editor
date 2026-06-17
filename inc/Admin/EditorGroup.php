<?php

declare(strict_types=1);

namespace RhEditor\Admin;

use RhBlueprint\Core\Settings\GroupInterface;
use RhBlueprint\Core\Settings\SettingField;

/**
 * Settings-Gruppe für die Editor-Eingriffe.
 *
 * Inserter-Cleanup und Block-Kategorie sind per Default an (sauberer Handover
 * für den Endkunden). SVG-Upload ist bewusst per Default AUS: er vergrößert die
 * Angriffsfläche und wird nur auf Sites gebraucht, die SVG-Icons als Medien nutzen.
 */
final class EditorGroup implements GroupInterface
{
    public const GROUP_ID = 'editor';

    public const FIELD_SVG_UPLOAD = 'svg_upload';
    public const FIELD_INSERTER_CLEANUP = 'inserter_cleanup';
    public const FIELD_BLOCK_CATEGORY = 'block_category';
    public const FIELD_CATEGORY_LABEL = 'category_label';

    public function id(): string
    {
        return self::GROUP_ID;
    }

    public function tab(): string
    {
        return 'editor';
    }

    public function title(): string
    {
        return __('Editor', 'rh-editor');
    }

    public function description(): string
    {
        return __('Den Block-Editor für den Endkunden aufräumen und erweitern.', 'rh-editor');
    }

    public function fields(): array
    {
        return [
            new SettingField(
                id: self::FIELD_INSERTER_CLEANUP,
                type: SettingField::TYPE_BOOLEAN,
                label: __('Inserter aufräumen', 'rh-editor'),
                description: __('Blendet die WordPress-Standard-Vorlagen und die Remote-Vorlagen aus dem Verzeichnis aus. Im Vorlagen-Tab bleiben nur die theme-eigenen Vorlagen.', 'rh-editor'),
                default: true,
                keywords: ['inserter', 'patterns', 'vorlagen', 'remote', 'aufraeumen'],
            ),
            new SettingField(
                id: self::FIELD_BLOCK_CATEGORY,
                type: SettingField::TYPE_BOOLEAN,
                label: __('Eigene Block-Kategorie', 'rh-editor'),
                description: __('Gruppiert die gängigen Core-Blöcke in eine eigene Kategorie ganz oben im Blöcke-Tab, damit der Kunde die relevanten Bausteine gebündelt findet.', 'rh-editor'),
                default: true,
                keywords: ['kategorie', 'bloecke', 'category', 'inserter'],
            ),
            new SettingField(
                id: self::FIELD_CATEGORY_LABEL,
                type: SettingField::TYPE_TEXT,
                label: __('Name der Block-Kategorie', 'rh-editor'),
                description: __('Beschriftung der eigenen Kategorie, z.B. der Projekt- oder Markenname.', 'rh-editor'),
                default: 'Bausteine',
                keywords: ['kategorie', 'label', 'name'],
            ),
            new SettingField(
                id: self::FIELD_SVG_UPLOAD,
                type: SettingField::TYPE_BOOLEAN,
                label: __('SVG-Upload erlauben', 'rh-editor'),
                description: __('Erlaubt das Hochladen von SVG-Dateien in die Mediathek (mit einfacher Sanitisierung). Nur aktivieren, wenn vertrauenswürdige Redakteure SVG-Icons brauchen.', 'rh-editor'),
                default: false,
                keywords: ['svg', 'upload', 'icon', 'mediathek'],
            ),
        ];
    }
}
