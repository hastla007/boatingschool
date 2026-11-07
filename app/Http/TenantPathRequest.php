<?php

namespace App\Http;

use App\Support\TenantPath;
use Illuminate\Http\Request;

/**
 * Request einer Schule im Pfad-Modus (/schule/…): der Basis-Pfad ist immer
 * „/schule“. Symfony leitet ihn sonst aus SCRIPT_NAME und REQUEST_URI ab --
 * das scheitert, wenn Laravels Routen-Cache für den Abgleich den
 * abschließenden Schrägstrich entfernt („/schule/“ → „/schule“): die
 * Startseite der Schule lieferte dann 405 statt der Anmeldung.
 */
class TenantPathRequest extends Request
{
    protected function prepareBaseUrl(): string
    {
        $segment = $this->attributes->get(TenantPath::ATTRIBUTE);

        return is_string($segment) && $segment !== '' ? '/'.$segment : parent::prepareBaseUrl();
    }
}
