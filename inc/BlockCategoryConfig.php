<?php

declare(strict_types=1);

namespace RhEditor;

use WP_Block_Type_Registry;

/**
 * Konfiguration der eigenen Block-Kategorie: welche Kategorien/Blöcke in die
 * kuratierte Kategorie wandern und welche Standard-Kategorien ausgeblendet werden.
 *
 * Liest/schreibt eine eigene Option (bespoke, kein SettingField, weil das Core-
 * Schema keine Checkbox-Listen kennt). Enumeriert Kategorien und Blöcke aus der
 * WP-Block-Registry, sodass die Auswahl-UI live den echten Stand zeigt.
 *
 * Die finale Zugehörigkeit wird im Editor-JS aufgelöst:
 *   member = (Block-Kategorie in includeCategories ODER Block in includeBlocks)
 *            UND NICHT (Block in excludeBlocks)
 * Damit landen auch künftige Blöcke einer übernommenen Kategorie automatisch drin.
 */
final class BlockCategoryConfig
{
    public const OPTION = 'rheditor_block_category';
    public const CURATED_SLUG = 'rh-curated';

    public const KEY_INCLUDE_CATEGORIES = 'include_categories';
    public const KEY_INCLUDE_BLOCKS = 'include_blocks';
    public const KEY_EXCLUDE_BLOCKS = 'exclude_blocks';
    public const KEY_HIDDEN_CATEGORIES = 'hidden_categories';

    /**
     * Default-Auswahl, solange noch nichts gespeichert wurde: die alltäglichen
     * Inhalts-Kategorien sind ab Werk übernommen, damit die eigene Kategorie sofort
     * gefüllt ist UND die Tabelle das sichtbar zeigt (Häkchen in "Übernehmen").
     * Kategorie-Ebene statt einer festen Block-Liste, damit Default und Tabelle
     * konsistent sind. Sobald gespeichert wird, gilt die Auswahl des Kunden, auch leer.
     *
     * @var array<int, string>
     */
    private const DEFAULT_INCLUDE_CATEGORIES = [
        'text',
        'media',
        'design',
    ];

    /**
     * @var array<string, array<int, string>>|null
     */
    private ?array $data = null;

    /**
     * @return array<int, string>
     */
    public function get(string $key): array
    {
        if ($this->data === null) {
            $stored = get_option(self::OPTION, null);
            // Nie gespeichert -> Default-Auswahl. Gespeichert (auch leer) -> exakt das.
            if ($stored === null || ! is_array($stored)) {
                $this->data = [self::KEY_INCLUDE_CATEGORIES => self::DEFAULT_INCLUDE_CATEGORIES];
            } else {
                $this->data = $stored;
            }
        }

        $value = $this->data[$key] ?? [];

        return is_array($value) ? array_values(array_filter(array_map('strval', $value))) : [];
    }

    public function includeCategories(): array
    {
        return $this->get(self::KEY_INCLUDE_CATEGORIES);
    }

    public function includeBlocks(): array
    {
        return $this->get(self::KEY_INCLUDE_BLOCKS);
    }

    public function excludeBlocks(): array
    {
        return $this->get(self::KEY_EXCLUDE_BLOCKS);
    }

    public function hiddenCategories(): array
    {
        return $this->get(self::KEY_HIDDEN_CATEGORIES);
    }

    /**
     * Speichert die geprüfte Konfiguration. Nur bekannte Slugs/Block-Namen werden
     * abgelegt (Intersection gegen die Registry), damit kein Müll in die Option kommt.
     *
     * @param array<int, string> $includeCategories
     * @param array<int, string> $includeBlocks
     * @param array<int, string> $excludeBlocks
     * @param array<int, string> $hiddenCategories
     */
    public function save(array $includeCategories, array $includeBlocks, array $excludeBlocks, array $hiddenCategories): void
    {
        $validCategories = array_keys($this->availableCategories());
        $validBlocks = array_keys($this->availableBlocks());

        update_option(self::OPTION, [
            self::KEY_INCLUDE_CATEGORIES => array_values(array_intersect($includeCategories, $validCategories)),
            self::KEY_INCLUDE_BLOCKS => array_values(array_intersect($includeBlocks, $validBlocks)),
            self::KEY_EXCLUDE_BLOCKS => array_values(array_intersect($excludeBlocks, $validBlocks)),
            self::KEY_HIDDEN_CATEGORIES => array_values(array_intersect($hiddenCategories, $validCategories)),
        ]);

        $this->data = null;
    }

    /**
     * Verfügbare Block-Kategorien als slug => Label. Aus den registrierten Blöcken
     * abgeleitet (die echten, in Benutzung), die kuratierte Kategorie selbst raus.
     *
     * @return array<string, string>
     */
    public function availableCategories(): array
    {
        $labels = [];
        foreach ($this->defaultCategoryLabels() as $slug => $label) {
            $labels[$slug] = $label;
        }

        $used = [];
        foreach ($this->availableBlocks() as $block) {
            $cat = $block['category'];
            if ($cat === '' || $cat === self::CURATED_SLUG) {
                continue;
            }
            $used[$cat] = $labels[$cat] ?? $cat;
        }

        ksort($used);

        return $used;
    }

    /**
     * Verfügbare Blöcke als name => [title, category]. Inner-Blöcke (parent gesetzt)
     * werden weggelassen, die erscheinen nur im Parent-Kontext.
     *
     * @return array<string, array{title: string, category: string}>
     */
    public function availableBlocks(): array
    {
        $blocks = [];
        $registry = WP_Block_Type_Registry::get_instance();

        foreach ($registry->get_all_registered() as $name => $type) {
            // Echte Inner-Blöcke überspringen (z.B. core/column in core/columns),
            // ABER Content-Blöcke behalten, die nur an core/post-content gebunden sind
            // (z.B. core/nextpage), die sind auf Seitenebene normal einfügbar.
            $parent = $type->parent ?? null;
            if (! empty($parent) && ! in_array('core/post-content', (array) $parent, true)) {
                continue;
            }
            $category = is_string($type->category ?? null) ? $type->category : '';
            if ($category === self::CURATED_SLUG) {
                continue;
            }
            $title = is_string($type->title ?? null) && $type->title !== '' ? $type->title : $name;
            $blocks[$name] = ['title' => $title, 'category' => $category];
        }

        uasort($blocks, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $blocks;
    }

    /**
     * @return array<string, string>
     */
    private function defaultCategoryLabels(): array
    {
        $labels = [];
        if (function_exists('get_default_block_categories')) {
            foreach (get_default_block_categories() as $category) {
                if (isset($category['slug'], $category['title'])) {
                    $labels[(string) $category['slug']] = (string) $category['title'];
                }
            }
        }

        return $labels;
    }
}
