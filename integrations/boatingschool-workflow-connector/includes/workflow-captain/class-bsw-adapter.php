<?php

use WorkflowCaptain\Adapter\Adapter;

/**
 * Adapter für Workflow Captain (WorkflowCaptain\Adapter\Adapter). Nur
 * geladen, wenn Workflow Captain aktiv ist (BSW_Workflow::load()).
 *
 * Die App stellt Ereignisse erst nach dem Commit zu (eigene Ausgangs-
 * tabelle), deshalb ist kein Gegenlesen nötig (after_commit: true im
 * Manifest, needs_reread() = false).
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Adapter implements Adapter
{
    public function product(): string
    {
        return BSW_Manifest::KEY;
    }

    public function core_version(): string
    {
        return BSW_CONNECTOR_VERSION;
    }

    public function contract_version(): ?string
    {
        return BSW_Manifest::CONTRACT_VERSION;
    }

    public function types(): array
    {
        return array_keys(BSW_Manifest::get(BSW_CONNECTOR_VERSION)['events']);
    }

    public function needs_reread(string $type): bool
    {
        return false;
    }

    public function reread(array $envelope): bool
    {
        return true;
    }
}
