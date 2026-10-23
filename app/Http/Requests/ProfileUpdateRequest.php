<?php

namespace App\Http\Requests;

use App\Models\User;
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
            // Bug-Review R3-6: Rule::unique() lief bisher über die reguläre,
            // RLS-beschränkte App-Verbindung -- ein Nutzer einer ANDEREN
            // Bootsschule mit derselben Adresse ist unter dem aktuellen
            // Tenant-Kontext unsichtbar, die Prüfung hier sah also fälschlich
            // "frei". Der echte, plattformweite UNIQUE-Constraint auf
            // app_user.email greift beim späteren Bestätigen (siehe
            // ProfileController::confirmEmail()) aber trotzdem -- bisher
            // ungefangen, also ein HTTP 500 statt einer verständlichen
            // Meldung. Dieselbe RLS-freie Owner-Verbindung wie bei der
            // analogen Prüfung in RegisteredUserController/
            // ParticipantController::invite() (Sicherheitsreview M3/M7).
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (User::on('pgsql_admin')->where('email', $value)->where('id', '!=', $this->user()->id)->exists()) {
                        $fail('Diese E-Mail-Adresse wird bereits verwendet.');
                    }
                },
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
