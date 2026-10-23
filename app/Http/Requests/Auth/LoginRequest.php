<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');

        // Bug-Review N17: Postgres vergleicht "email = ?" standardmäßig
        // groß-/kleinschreibungssensitiv -- Auth::attempt() lehnte die
        // eingegebene E-Mail deshalb schon dann ab, wenn nur deren
        // Groß-/Kleinschreibung vom gespeicherten Wert abwich (z. B. durch
        // die auf dem Handy übliche Autokapitalisierung des ersten
        // Buchstabens), selbst bei korrektem Passwort. Die E-Mail wird
        // hier case-insensitiv auf den tatsächlich gespeicherten Wert
        // aufgelöst, bevor Auth::attempt() sie prüft.
        $credentials['email'] = User::whereRaw('lower(email) = ?', [Str::lower($credentials['email'])])->value('email')
            ?? $credentials['email'];

        // 'status' => 'active' verweigert gesperrten Konten (suspended,
        // closed) auch mit korrektem Passwort das Anmelden (Sicherheitsreview
        // M1) -- vorher konnte eine Sperrung durch den Superadmin umgangen
        // werden, weil hier nur E-Mail/Passwort geprüft wurden.
        if (! Auth::attempt([...$credentials, 'status' => 'active'], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
