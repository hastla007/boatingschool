<?php

namespace App\Http\Requests;

use App\Support\Countries;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // Bug-Review R3-6 (Folgefehler R4-B): eine synchrone
            // Eindeutigkeitsprüfung hier bestätigte dem einloggten Nutzer
            // direkt, ob eine BELIEBIGE Adresse irgendwo auf der Plattform
            // bereits einem ANDEREN Konto gehört -- ein Enumerations-Orakel
            // über das eigene Profil, nur noch durch das R4-B-Throttle auf
            // 10 Anfragen/Minute begrenzt statt geschlossen (dasselbe Muster
            // wie M3 bei Login/Registrierung/Einladung). Die neue Adresse
            // wird (wie bisher) erst nach Bestätigung über den zugeschickten
            // Link übernommen -- genau dieser Bestätigungsschritt
            // (ProfileController::confirmEmail()) fängt einen inzwischen
            // anderweitig vergebenen Konflikt bereits sicher und ohne
            // Disclosure gegenüber dem ursprünglich anfragenden Nutzer ab
            // (die Bestätigungsmail selbst geht an die NEUE Adresse, der
            // signierte Link ist an die ursprüngliche Nutzer-ID gebunden --
            // wer ihn woanders eingeloggt öffnet, bekommt nur 403, siehe
            // confirmEmail()). Die Antwort beim Speichern des Profils ist
            // dadurch jetzt unabhängig davon identisch, ob die eingegebene
            // Adresse bereits irgendwo vergeben ist.
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', Rule::in(Countries::OPTIONS)],
            'birthdate' => ['nullable', 'date', 'before:today'],
        ];
    }
}
