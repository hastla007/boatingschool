<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\InvitationAcceptController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    // Sicherheitsreview N2: throttle greift hier pro IP (kein eingeloggter
    // Nutzer vorhanden) und ergänzt damit das bestehende, pro E-Mail+IP
    // greifende Login-Lockout (LoginRequest) bzw. das pro E-Mail greifende
    // 60s-Limit von Password::sendResetLink -- sonst bleibt
    // Credential-Stuffing/Massenregistrierung über viele E-Mails von
    // derselben IP ungebremst.
    //
    // Bug-Review R4-H: ThrottleRequests::resolveRequestSignature() baut den
    // Zähler für einen Gast allein aus Domain+IP, für einen eingeloggten
    // Nutzer allein aus dessen Nutzer-ID -- ohne einen eigenen Prefix (3.
    // Argument) teilten sich register/login/forgot-password hier bisher
    // EINEN gemeinsamen Zähler pro IP: ein paar Fehlversuche beim Login
    // blockierten damit zusätzlich genau die Funktion, die man danach
    // bräuchte (Passwort vergessen), und umgekehrt. Jede Stelle bekommt
    // deshalb einen eigenen Prefix.
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:10,1,register');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1,login');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:10,1,forgot-password')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    // Sicherheitsreview S2: stand bisher ganz ohne throttle-Middleware da,
    // anders als jede andere sensible Route in dieser Gruppe -- siehe
    // Bug-Review R4-H oben für den eigenen Prefix pro Route.
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:10,1,reset-password')
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1,verify-email'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1,verification-send')
        ->name('verification.send');

    // Eine im Profil geänderte E-Mail-Adresse wird erst hierüber live
    // geschaltet (siehe Sicherheitsreview H2, ProfileController).
    Route::get('profile/confirm-email/{id}/{hash}', [ProfileController::class, 'confirmEmail'])
        ->middleware(['signed', 'throttle:6,1,profile-email-confirm'])
        ->name('profile.email.confirm');

    // Einladung zu einer Bootsschule annehmen: nur wirksam, wenn der
    // eingeloggte Nutzer dieselbe (bestätigte) E-Mail-Adresse hat wie die
    // Einladung (siehe Sicherheitsreview H2, InvitationAcceptController).
    Route::get('invitations/{invitation}/accept/{hash}', [InvitationAcceptController::class, 'accept'])
        ->middleware(['signed', 'throttle:6,1,invitation-accept'])
        ->name('invitations.accept');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    // Bug-Review (Passwort-Throttle eingeloggter Nutzer): beide Routen
    // prüfen das aktuelle Passwort eines bereits eingeloggten Nutzers
    // (Hash::check intern über current_password bzw.
    // Auth::guard()->validate()) -- ohne throttle ließe sich das Passwort
    // hinter einer gestohlenen/entführten Session beliebig oft erraten,
    // derselbe Risikofall wie bei /reset-password vor Sicherheitsreview S2
    // oben, nur für eingeloggte Nutzer statt Gäste.
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:10,1,confirm-password');

    Route::put('password', [PasswordController::class, 'update'])
        ->middleware('throttle:10,1,password-update')
        ->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
