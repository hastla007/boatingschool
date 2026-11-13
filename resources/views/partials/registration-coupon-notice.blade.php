{{-- Ergebnis des Zugangscodes aus der Registrierung, einmalig nach der Anmeldung (App\Support\RegistrationCouponNotice). --}}
@php($registrationCouponNotice = auth()->check() ? \App\Support\RegistrationCouponNotice::pull() : null)
@if ($registrationCouponNotice)
    <div role="status" @class([
        'mb-4 rounded-lg border px-4 py-3 text-sm',
        'bg-emerald-50 border-emerald-200 text-emerald-700 dark:bg-emerald-900/30 dark:border-emerald-800 dark:text-emerald-300' => $registrationCouponNotice['type'] === 'success',
        'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-100' => $registrationCouponNotice['type'] !== 'success',
    ])>
        {{ $registrationCouponNotice['message'] }}
    </div>
@endif
