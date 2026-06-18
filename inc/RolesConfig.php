<?php

declare(strict_types=1);

namespace RhEditor;

use WP_Role;

/**
 * Pro-Rolle-Konfiguration der Editor-Erfahrung.
 *
 * Zwei Achsen pro Rolle:
 *  - Editor-Modus, gewichtet von wenig zu viel Einschränkung:
 *      full     = voller Editor (Default, nichts ändert sich)
 *      patterns = nur Muster einfügen (Blöcke-Tab weg), danach frei bearbeiten
 *      content  = nur Inhalt (contentOnly), nichts einfügen/verschieben
 *  - Stile: darf die Rolle site-weite Stile im Site-Editor bearbeiten (edit_theme_options).
 *
 * Bespoke Option (kein SettingField, weil pro-Rolle-Matrix). Default je Rolle =
 * voller Modus + Stile aus, also greift nichts, solange nichts gespeichert wurde.
 * Verwaltet werden nur Rollen, die Beiträge bearbeiten dürfen (edit_posts) und
 * nicht admin-äquivalent sind (kein manage_options), der Administrator bleibt voll.
 */
final class RolesConfig
{
    public const OPTION = 'rheditor_roles';

    public const MODE_FULL = 'full';
    public const MODE_PATTERNS = 'patterns';
    public const MODE_CONTENT = 'content';

    public const KEY_MODE = 'mode';
    public const KEY_STYLES = 'styles';

    /**
     * @var array<string, array{mode: string, styles: bool}>|null
     */
    private ?array $data = null;

    /**
     * @var array<int, string>
     */
    public const MODES = [self::MODE_FULL, self::MODE_PATTERNS, self::MODE_CONTENT];

    /**
     * Verwaltbare Rollen als slug => WP_Role. Alles was Beiträge bearbeiten darf,
     * ohne den Administrator (manage_options).
     *
     * @return array<string, WP_Role>
     */
    public function managedRoles(): array
    {
        $roles = [];
        $wpRoles = wp_roles();

        foreach ($wpRoles->role_objects as $slug => $role) {
            if (! $role instanceof WP_Role) {
                continue;
            }
            if (empty($role->capabilities['edit_posts'])) {
                continue;
            }
            if (! empty($role->capabilities['manage_options'])) {
                continue;
            }
            $roles[(string) $slug] = $role;
        }

        return $roles;
    }

    /**
     * Anzeigename einer Rolle (übersetzt), Fallback auf den Slug.
     */
    public function roleLabel(string $slug): string
    {
        $names = wp_roles()->get_names();
        $name = $names[$slug] ?? $slug;

        return translate_user_role((string) $name);
    }

    public function mode(string $roleSlug): string
    {
        $entry = $this->all()[$roleSlug] ?? null;
        $mode = is_array($entry) ? (string) ($entry[self::KEY_MODE] ?? self::MODE_FULL) : self::MODE_FULL;

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_FULL;
    }

    public function stylesEnabled(string $roleSlug): bool
    {
        $entry = $this->all()[$roleSlug] ?? null;

        return is_array($entry) && ! empty($entry[self::KEY_STYLES]);
    }

    /**
     * Rollen-Slugs, deren Modus eine bestimmte Stufe hat.
     *
     * @return array<int, string>
     */
    public function rolesWithMode(string $mode): array
    {
        $slugs = [];
        foreach (array_keys($this->managedRoles()) as $slug) {
            if ($this->mode($slug) === $mode) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Rollen-Slugs, die site-weite Stile bearbeiten dürfen.
     *
     * @return array<int, string>
     */
    public function rolesWithStyles(): array
    {
        $slugs = [];
        foreach (array_keys($this->managedRoles()) as $slug) {
            if ($this->stylesEnabled($slug)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @return array<string, array{mode: string, styles: bool}>
     */
    private function all(): array
    {
        if ($this->data === null) {
            $stored = get_option(self::OPTION, []);
            $clean = [];
            if (is_array($stored)) {
                foreach ($stored as $slug => $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $mode = (string) ($entry[self::KEY_MODE] ?? self::MODE_FULL);
                    $clean[(string) $slug] = [
                        self::KEY_MODE => in_array($mode, self::MODES, true) ? $mode : self::MODE_FULL,
                        self::KEY_STYLES => ! empty($entry[self::KEY_STYLES]),
                    ];
                }
            }
            $this->data = $clean;
        }

        return $this->data;
    }

    /**
     * Speichert Modus + Stile-Flag pro Rolle. Nur verwaltbare Rollen und gültige
     * Modi landen in der Option (kein Müll). Eine Rolle im Default-Zustand
     * (voll + Stile aus) wird weggelassen, damit die Option schlank bleibt.
     *
     * @param array<string, string> $modes  roleSlug => mode
     * @param array<string, bool>   $styles roleSlug => enabled
     */
    public function save(array $modes, array $styles): void
    {
        $clean = [];
        foreach (array_keys($this->managedRoles()) as $slug) {
            $mode = $modes[$slug] ?? self::MODE_FULL;
            if (! in_array($mode, self::MODES, true)) {
                $mode = self::MODE_FULL;
            }
            $stylesOn = ! empty($styles[$slug]);

            if ($mode === self::MODE_FULL && ! $stylesOn) {
                continue;
            }

            $clean[$slug] = [
                self::KEY_MODE => $mode,
                self::KEY_STYLES => $stylesOn,
            ];
        }

        update_option(self::OPTION, $clean);
        $this->data = null;
    }

    /**
     * Aktualisiert genau eine Rolle (für den per-Rolle-Modal-Save), die anderen
     * bleiben unberührt. Default-Zustand (voll + Stile aus) wird wieder entfernt.
     */
    public function saveRole(string $slug, string $mode, bool $styles): void
    {
        if (! isset($this->managedRoles()[$slug])) {
            return;
        }
        if (! in_array($mode, self::MODES, true)) {
            $mode = self::MODE_FULL;
        }

        $all = $this->all();
        if ($mode === self::MODE_FULL && ! $styles) {
            unset($all[$slug]);
        } else {
            $all[$slug] = [self::KEY_MODE => $mode, self::KEY_STYLES => $styles];
        }

        update_option(self::OPTION, $all);
        $this->data = null;
    }
}
