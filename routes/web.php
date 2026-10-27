<?php

use App\Http\Controllers\Admin\AddonController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\CoursePromoBannerController;
use App\Http\Controllers\Admin\CourseSelectionController;
use App\Http\Controllers\Admin\EntitlementController;
use App\Http\Controllers\Admin\ParticipantController;
use App\Http\Controllers\Admin\PraxisExamAppointmentController as AdminPraxisExamAppointmentController;
use App\Http\Controllers\Admin\PraxisFormSubmissionController as AdminPraxisFormSubmissionController;
use App\Http\Controllers\Admin\SupportEmailVerificationController;
use App\Http\Controllers\Admin\TenantCourseLocationController;
use App\Http\Controllers\Admin\WillkommensboxLabelController;
use App\Http\Controllers\Admin\WillkommensboxOrderController;
use App\Http\Controllers\CouponRedeemController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\FavoriteCollectionController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\NavigationTaskController;
use App\Http\Controllers\OffersController;
use App\Http\Controllers\PraxisExamAppointmentController;
use App\Http\Controllers\PraxisFormSubmissionController;
use App\Http\Controllers\PraxisPruefungController;
use App\Http\Controllers\PraxisTrainerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\TipController;
use App\Http\Controllers\VideoCourseController;
use App\Http\Controllers\VideoQuizController;
use App\Http\Controllers\WillkommensboxController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route(auth()->user()->is_superadmin ? 'superadmin.dashboard' : 'dashboard');
});

Route::middleware(['auth', 'tenant.member', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
    Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');

    Route::get('/tipps-tricks', [TipController::class, 'index'])->name('tips.index');

    Route::get('/angebote', [OffersController::class, 'index'])->name('offers.index');

    Route::get('/willkommensbox', [WillkommensboxController::class, 'index'])->name('willkommensbox.index');
    Route::post('/willkommensbox', [WillkommensboxController::class, 'store'])->name('willkommensbox.store');

    Route::get('/courses/{course}/learn', [LearningController::class, 'show'])->name('learning.show');
    Route::get('/courses/{course}/learn/overview', [LearningController::class, 'overview'])->name('learning.overview');
    Route::post('/courses/{course}/learn/attempts', [LearningController::class, 'storeAttempt'])->name('learning.attempts.store');
    // Bug-Review B20: storeAttempt() rendert die Antwort jetzt nicht mehr
    // direkt als POST-Antwort, sondern leitet hierhin weiter (POST/Redirect/
    // GET) -- ein Neuladen (F5) ruft diese GET-Route erneut auf statt die
    // Antwort samt Attempt-Anlage ein zweites Mal einzureichen.
    Route::get('/courses/{course}/learn/attempts/{attempt}', [LearningController::class, 'showAttempt'])->name('learning.attempts.show');

    Route::get('/courses/{course}/favorites/smart-learning', [FavoriteController::class, 'smartLearning'])->name('favorites.smart-learning');
    Route::get('/courses/{course}/favorites/exam', [FavoriteController::class, 'exam'])->name('favorites.exam');
    Route::post('/favorites/{question}', [FavoriteController::class, 'store'])->name('favorites.store');
    Route::delete('/favorites/{question}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');

    Route::post('/courses/{course}/favorites/collections', [FavoriteCollectionController::class, 'store'])->name('favorite-collections.store');
    Route::patch('/favorites/collections/{favoriteCollection}', [FavoriteCollectionController::class, 'update'])->name('favorite-collections.update');
    Route::delete('/favorites/collections/{favoriteCollection}', [FavoriteCollectionController::class, 'destroy'])->name('favorite-collections.destroy');
    Route::put('/courses/{course}/favorites/{question}/collections', [FavoriteCollectionController::class, 'sync'])->name('favorite-collections.sync');

    Route::get('/courses/{course}/progress', [ProgressController::class, 'show'])->name('progress.show');

    Route::get('/courses/{course}/video', [VideoCourseController::class, 'index'])->name('video.index');
    Route::get('/courses/{course}/video/{lesson}', [VideoCourseController::class, 'show'])->name('video.show');
    Route::post('/courses/{course}/video/{lesson}/complete', [VideoCourseController::class, 'complete'])->name('video.complete');

    Route::get('/courses/{course}/video/{lesson}/questions/result', [VideoQuizController::class, 'result'])->name('video.quiz.result');
    Route::get('/courses/{course}/video/{lesson}/questions/{position}', [VideoQuizController::class, 'show'])->name('video.quiz.show')->where('position', '[0-9]+');
    Route::post('/courses/{course}/video/{lesson}/questions/{position}', [VideoQuizController::class, 'answer'])->name('video.quiz.answer')->where('position', '[0-9]+');

    Route::get('/courses/{course}/exam', [ExamController::class, 'intro'])->name('exam.intro');
    Route::post('/courses/{course}/exam', [ExamController::class, 'start'])->name('exam.start');
    Route::post('/courses/{course}/exam/papers/{paper}', [ExamController::class, 'startPaper'])->name('exam.papers.start');
    Route::get('/exam-sessions/{examSession}', [ExamController::class, 'show'])->name('exam.show');
    Route::post('/exam-sessions/{examSession}/answers', [ExamController::class, 'answer'])->name('exam.answer');
    Route::post('/exam-sessions/{examSession}/finish', [ExamController::class, 'finish'])->name('exam.finish');
    Route::get('/exam-sessions/{examSession}/result', [ExamController::class, 'result'])->name('exam.result');

    Route::get('/courses/{course}/exam/navigation', [NavigationTaskController::class, 'index'])->name('exam.navigation.index');
    Route::get('/courses/{course}/exam/navigation/{task}', [NavigationTaskController::class, 'show'])->name('exam.navigation.show');

    Route::get('/courses/{course}/praxistrainer', [PraxisTrainerController::class, 'index'])->name('praxistrainer.index');
    Route::get('/courses/{course}/praxistrainer/{task}', [PraxisTrainerController::class, 'show'])->name('praxistrainer.show');
    Route::post('/courses/{course}/praxistrainer/{task}/complete', [PraxisTrainerController::class, 'complete'])->name('praxistrainer.complete');

    Route::get('/courses/{course}/praxis-pruefung', [PraxisPruefungController::class, 'index'])->name('praxis-pruefung.index');
    Route::post('/courses/{course}/praxis-pruefung', [PraxisFormSubmissionController::class, 'store'])->name('praxis-pruefung.store');
    Route::post('/courses/{course}/praxis-pruefung/exam-appointments/{examAppointment}/respond', [PraxisExamAppointmentController::class, 'respond'])->name('praxis-pruefung.exam-appointments.respond');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // Bug-Review R4-B: ProfileUpdateRequest prüft die neue E-Mail-Adresse
    // bewusst plattformweit über alle Bootsschulen hinweg (Bug-Review R3-6
    // -- der echte UNIQUE-Constraint greift sonst erst unverständlich beim
    // Bestätigen), die Fehlermeldung "wird bereits verwendet" ist damit
    // selbst ein Enumerations-Orakel: jeder eingeloggte Nutzer kann über
    // sein eigenes Profil beliebige Adressen durchprobieren und an dieser
    // Antwort ablesen, ob sie IRGENDWO auf der Plattform schon vergeben
    // sind. throttle begrenzt die Anfragerate dagegen, analog zu den
    // bereits bestehenden Limits bei /participants/invite (Bug-Review
    // R3-8) und der Registrierung (Sicherheitsreview M3/N2).
    //
    // Bug-Review R4-H: ThrottleRequests::resolveRequestSignature() baut den
    // Zähler für einen eingeloggten Nutzer allein aus dessen Nutzer-ID --
    // ohne einen eigenen Prefix (3. Argument) teilen sich ALLE Routen mit
    // der (hier wie an den übrigen throttle:10,1-Stellen) bloßen Zahlenform
    // denselben Zähler, unabhängig von der Route. Ein Owner/Admin, der
    // z. B. 10 Teilnehmer einlädt, blockierte sich damit zusätzlich selbst
    // beim Profil-Update, Coupon-Import oder Code-Einlösen (und umgekehrt).
    // Jede Stelle bekommt deshalb einen eigenen Prefix.
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:10,1,profile-update')->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // DSGVO-Review D3: Auskunfts-/Mitnahmerecht (Art. 15/20).
    Route::get('/profile/export-data', [ProfileController::class, 'exportData'])->name('profile.export');

    // Sicherheitsreview H3: ein eigener Code ist für den Kursbesuch gedacht
    // -- ein Instructor ist eine Lehrkraft, kein Kursteilnehmer, und sollte
    // deshalb keine Codes für sich selbst einlösen können (verbraucht sonst
    // u. a. den begrenzten Coupon-Bestand der Bootsschule ohne Gegenwert).
    Route::middleware('tenant.role:owner,admin,learner')->group(function () {
        Route::get('/coupons/redeem', [CouponRedeemController::class, 'show'])->name('coupons.redeem');
        // Sicherheitsreview M8: ohne Limit ließe sich über die Fehlermeldung
        // ("ungültig" vs. intern schon unterschieden nach eingelöst/unbekannt im
        // Admin-Import, hier bewusst einheitlich) die Existenz von Codes gegen
        // die ~59 Bit Entropie durchprobieren.
        Route::post('/coupons/redeem', [CouponRedeemController::class, 'redeem'])->middleware('throttle:10,1,coupons-redeem')->name('coupons.redeem.store');
    });
});

// Bug-Review: 'verified' fehlte auf beiden Admin-Gruppen (anders als auf
// der regulären Lerner-Gruppe oben) -- ein Owner/Admin/Instructor mit noch
// nicht bestätigter E-Mail-Adresse konnte trotzdem Teilnehmer einladen,
// Entitlements/Coupons verwalten und Branding ändern (Phishing-Risiko über
// das Schul-Branding), ohne je nachgewiesen zu haben, dass die Adresse
// tatsächlich ihm gehört.
Route::middleware(['auth', 'tenant.role:owner,admin,instructor', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');
    // Bug-Review R3-8: ohne Throttle ließ sich dieser Endpoint beliebig oft
    // mit wechselnden E-Mail-Adressen aufrufen -- trotz identischer
    // Antwort für "Konto existiert bereits" vs. "neu" (Bug-Review M3,
    // Folgefehler) bleibt bei unbegrenzter Anfragerate ein statistischer
    // Timing-Seitenkanal realistisch nutzbar, und jede beliebige echte
    // Adresse ließ sich unbegrenzt mit Einladungs-/Reset-Mails zuspammen.
    Route::post('/participants/invite', [ParticipantController::class, 'invite'])->middleware('throttle:10,1,participants-invite')->name('participants.invite');
    Route::get('/participants/{user}', [ParticipantController::class, 'show'])->name('participants.show');
    Route::patch('/participants/{user}', [ParticipantController::class, 'update'])->name('participants.update');
    Route::get('/branding/support-email/verify/{tenant}/{hash}', [SupportEmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1,support-email-verify'])->name('branding.support-email.verify');

    Route::get('/praxis-form-submissions/{submission}', [AdminPraxisFormSubmissionController::class, 'show'])->name('praxis-form-submissions.show');
    Route::post('/praxis-form-submissions/{submission}/exam-appointments', [AdminPraxisExamAppointmentController::class, 'store'])->name('praxis-form-submissions.exam-appointments.store');
    // Bug-Review F4: es gab bisher keine Möglichkeit, einen eigenen,
    // noch offenen Vorschlag zurückzuziehen oder einen bereits
    // angenommenen Termin wieder abzusagen.
    Route::patch('/praxis-form-submissions/{submission}/exam-appointments/{examAppointment}/withdraw', [AdminPraxisExamAppointmentController::class, 'withdraw'])->name('praxis-form-submissions.exam-appointments.withdraw');
    Route::patch('/praxis-form-submissions/{submission}/exam-appointments/{examAppointment}/cancel', [AdminPraxisExamAppointmentController::class, 'cancel'])->name('praxis-form-submissions.exam-appointments.cancel');

    Route::patch('/willkommensbox-orders/{willkommensboxOrder}/ship', [WillkommensboxOrderController::class, 'ship'])->name('willkommensbox-orders.ship');
    Route::get('/willkommensbox-orders/{willkommensboxOrder}/label/{size}', [WillkommensboxLabelController::class, 'show'])->name('willkommensbox-orders.label');
});

// Finanz-/Vertrauens-relevante Stellschrauben bleiben Owner/Admin
// vorbehalten -- ein Instructor kann weder Kurse kostenlos freischalten
// oder widerrufen, noch Branding/Support-Kontakt (Phishing-Risiko über das
// Schul-Branding), Webshop-Links/Kursauswahl, Addon-Konfiguration,
// Kurs-Empfehlungsbanner, Praxis-/Prüfungsorte oder den Coupon-Bestand
// ändern (Sicherheitsreview H3). Instructor sieht diese Tabs im
// Admin-Bereich entsprechend auch nicht bzw. nur lesend (siehe
// admin/dashboard.blade.php, admin/tabs/orte.blade.php, $canManageTenant).
Route::middleware(['auth', 'tenant.role:owner,admin', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('/entitlements', [EntitlementController::class, 'store'])->name('entitlements.store');
    Route::patch('/entitlements/{entitlement}', [EntitlementController::class, 'update'])->name('entitlements.update');

    Route::post('/tenant-course-locations', [TenantCourseLocationController::class, 'store'])->name('tenant-course-locations.store');
    Route::patch('/tenant-course-locations/{tenantCourseLocation}', [TenantCourseLocationController::class, 'update'])->name('tenant-course-locations.update');
    Route::delete('/tenant-course-locations/{tenantCourseLocation}', [TenantCourseLocationController::class, 'destroy'])->name('tenant-course-locations.destroy');
    Route::patch('/branding', [BrandingController::class, 'update'])->name('branding.update');
    Route::post('/branding/support-email/verification-notification', [SupportEmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1,support-email-send')->name('branding.support-email.send');

    Route::patch('/participants/{user}/membership', [ParticipantController::class, 'updateMembership'])->name('participants.membership.update');

    Route::patch('/addons', [AddonController::class, 'update'])->name('addons.update');

    Route::post('/course-promo-banners', [CoursePromoBannerController::class, 'store'])->name('course-promo-banners.store');
    Route::patch('/course-promo-banners/{coursePromoBanner}', [CoursePromoBannerController::class, 'update'])->name('course-promo-banners.update');
    Route::delete('/course-promo-banners/{coursePromoBanner}', [CoursePromoBannerController::class, 'destroy'])->name('course-promo-banners.destroy');

    Route::patch('/courses', [CourseSelectionController::class, 'update'])->name('courses.update');

    // Sicherheitsreview M8: begrenzt, wie oft pro Minute auf freie Codes
    // (tenant_id NULL) getestet werden kann -- die Rückmeldung
    // unterscheidet bewusst "bereits eingelöst"/"unbekannt", ist also ein
    // Oracle für Code-Existenz.
    Route::post('/coupons/import', [AdminCouponController::class, 'import'])->middleware('throttle:10,1,coupons-import')->name('coupons.import');
    Route::post('/coupons/{coupon}/assign', [AdminCouponController::class, 'assign'])->name('coupons.assign');
});

require __DIR__.'/superadmin.php';
require __DIR__.'/auth.php';
