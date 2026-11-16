<?php

/**
 * Lizenziertes Feature `boatingschool_form.leads` (Entscheidung 17, BS-11): solange die Lizenz das Recht gewährt,
 * meldet sich der Connector bei Form Captain an (`form_captain_connectors_v1`) und die Einstellungsseite erscheint.
 * Ohne Recht ist der Connector für Form Captain nicht vorhanden: offene Jobs bleiben dort pausiert („Connector
 * fehlt“) und laufen weiter, sobald die Lizenz wieder gilt. Fail-closed.
 *
 * register_readonly(): Löschungen bereits zugestellter Leads (Datenschutzpflicht) laufen auch ohne Lizenz.
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector\Features;

use BoatingschoolFormConnector\Admin;
use BoatingschoolFormConnector\Kernel\Addon;
use BoatingschoolFormConnector\Kernel\BaseFeature;
use BoatingschoolFormConnector\Privacy;

defined('ABSPATH') || exit;

final class LeadDelivery extends BaseFeature
{
    public const KEY = 'boatingschool_form.leads';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Leads aus Form Captain an BoatingSchool senden', 'boatingschool-form-connector');
    }

    public function requires(): array
    {
        // Connector-Vertrag v1 (Major 1, Minor >= 0): Interface und Registrierungsfunktion.
        return [
            'symbols' => ['FormCaptain\\Integrations\\ConnectorInterface', 'form_captain_register_action_v1'],
            'constants' => ['FORM_CAPTAIN_CONTRACT_VERSION' => '1.0.0'],
        ];
    }

    public function register(Addon $addon): void
    {
        boatingschool_form_register_with_form_captain();
        Admin::register();
    }

    public function register_readonly(Addon $addon): void
    {
        Privacy::register();
    }
}
