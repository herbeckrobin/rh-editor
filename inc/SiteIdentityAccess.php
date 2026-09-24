<?php

declare(strict_types=1);

namespace RhEditor;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_User;

/**
 * Logo und Website-Icon für Rollen mit Vorlagen-Freigabe.
 *
 * Der Site-Logo-Block speichert über /wp/v2/settings, und diese Route verlangt
 * im Core manage_options. edit_theme_options allein reicht also nicht, der
 * Redakteur sähe das Logo in der Kopfzeile, könnte es aber nicht tauschen.
 *
 * Statt manage_options zu vergeben, öffnet diese Klasse nur die eine Route und
 * nur so weit wie nötig:
 *  - schreiben: ausschließlich site_logo und site_icon, jedes andere Feld im
 *    Request lässt den ganzen Request mit 403 scheitern (laut statt still),
 *  - lesen: nur Logo, Icon, Titel und Untertitel (die beiden letzten sind
 *    öffentlich und werden vom Site-Titel-Block im Editor gebraucht). Admin-Mail,
 *    Zeitzone, Startseite und alle übrigen Einstellungen bleiben verborgen.
 *
 * Administratoren laufen unverändert über die Core-Prüfung.
 */
final class SiteIdentityAccess
{
    private const ROUTE = '/wp/v2/settings';

    /** @var array<int, string> */
    private const WRITABLE = ['site_logo', 'site_icon'];

    /** @var array<int, string> */
    private const READABLE = ['site_logo', 'site_icon', 'title', 'description'];

    public function __construct(private readonly RolesConfig $config)
    {
    }

    public function boot(): void
    {
        add_filter('rest_endpoints', [$this, 'widenPermission']);
        add_filter('rest_request_before_callbacks', [$this, 'guardWrite'], 10, 3);
        // after_callbacks statt rest_post_dispatch: läuft auch bei rest_do_request,
        // also beim Preload im Editor, der sonst alle Einstellungen ins HTML schreibt.
        add_filter('rest_request_after_callbacks', [$this, 'narrowResponse'], 10, 3);
    }

    /**
     * Ergänzt die Core-Prüfung der Settings-Route um die Vorlagen-Freigabe.
     *
     * @param array<string, mixed> $endpoints
     * @return array<string, mixed>
     */
    public function widenPermission(array $endpoints): array
    {
        if (! isset($endpoints[self::ROUTE]) || ! is_array($endpoints[self::ROUTE])) {
            return $endpoints;
        }

        foreach ($endpoints[self::ROUTE] as $key => $handler) {
            if (! is_int($key) || ! is_array($handler) || ! isset($handler['permission_callback'])) {
                continue;
            }
            $original = $handler['permission_callback'];
            $endpoints[self::ROUTE][$key]['permission_callback'] = function (WP_REST_Request $request) use ($original) {
                $result = is_callable($original) ? call_user_func($original, $request) : false;
                if ($result === true) {
                    return true;
                }

                return $this->appliesToCurrentUser() ? true : $result;
            };
        }

        return $endpoints;
    }

    /**
     * Schreibende Requests der Freigabe-Rollen auf Logo und Icon begrenzen.
     *
     * @param mixed           $response
     * @param array<mixed>    $handler
     * @return mixed
     */
    public function guardWrite($response, $handler, WP_REST_Request $request)
    {
        if ($response instanceof WP_Error || $request->get_route() !== self::ROUTE) {
            return $response;
        }
        if (in_array($request->get_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }
        if (! $this->appliesToCurrentUser()) {
            return $response;
        }

        $sent = array_intersect(array_keys($request->get_params()), $this->restSettingNames());
        $forbidden = array_values(array_diff($sent, self::WRITABLE));

        if ($forbidden !== []) {
            return new WP_Error(
                'rheditor_setting_forbidden',
                __('Mit dieser Rolle lassen sich hier nur Logo und Website-Icon ändern.', 'rh-editor'),
                ['status' => 403, 'fields' => $forbidden]
            );
        }

        return $response;
    }

    /**
     * Antwort der Settings-Route für Freigabe-Rollen auf die lesbaren Felder
     * reduzieren.
     *
     * @param WP_HTTP_Response|WP_Error|mixed $result
     * @param array<mixed>                    $handler
     * @return WP_HTTP_Response|WP_Error|mixed
     */
    public function narrowResponse($result, $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::ROUTE || $request->get_method() === 'OPTIONS') {
            return $result;
        }
        if (! $this->appliesToCurrentUser()) {
            return $result;
        }

        if ($result instanceof WP_HTTP_Response) {
            $data = $result->get_data();
            if (is_array($data) && $result->get_status() < 300) {
                $result->set_data(array_intersect_key($data, array_flip(self::READABLE)));
            }
        } elseif (is_array($result)) {
            $result = array_intersect_key($result, array_flip(self::READABLE));
        }

        return $result;
    }

    /**
     * Greift nur für eingeloggte Nicht-Admins mit Vorlagen-Freigabe.
     */
    private function appliesToCurrentUser(): bool
    {
        if (current_user_can('manage_options')) {
            return false;
        }

        $user = wp_get_current_user();
        if (! $user instanceof WP_User || $user->ID === 0) {
            return false;
        }

        return $this->config->userHasTemplates($user);
    }

    /**
     * REST-Namen aller registrierten Einstellungen, so wie der Settings-Controller
     * sie aus dem Request liest.
     *
     * @return array<int, string>
     */
    private function restSettingNames(): array
    {
        $names = [];
        foreach (get_registered_settings() as $option => $args) {
            if (empty($args['show_in_rest'])) {
                continue;
            }
            $rest = is_array($args['show_in_rest']) ? $args['show_in_rest'] : [];
            $names[] = (string) ($rest['name'] ?? $option);
        }

        return $names;
    }
}
