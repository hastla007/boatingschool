<?php

use App\Http\Controllers\Superadmin\AddonTemplateController;
use App\Http\Controllers\Superadmin\CouponController;
use App\Http\Controllers\Superadmin\CourseEditorController;
use App\Http\Controllers\Superadmin\DashboardController;
use App\Http\Controllers\Superadmin\ModuleController;
use App\Http\Controllers\Superadmin\PraxisFormTemplateController;
use App\Http\Controllers\Superadmin\ProductController;
use App\Http\Controllers\Superadmin\PruefungsausschussController;
use App\Http\Controllers\Superadmin\QuestionController;
use App\Http\Controllers\Superadmin\SettingsController;
use App\Http\Controllers\Superadmin\TenantController;
use App\Http\Controllers\Superadmin\TipCategoryController;
use App\Http\Controllers\Superadmin\TipController;
use App\Http\Controllers\Superadmin\UserController;
use App\Http\Controllers\Superadmin\VideoLessonController;
use Illuminate\Support\Facades\Route;

/**
 * Plattformweiter Superadmin-Bereich: bewusst mandantenunabhängig (siehe
 * ResolveTenant::isTenantAgnosticRoute()) und läuft über die
 * "admin-db"-Middleware auf der Owner-DB-Verbindung, damit er RLS-frei
 * über alle Bootsschulen hinweg lesen/schreiben kann.
 *
 * Bug-Review R4-J: 'verified' fehlte hier (anders als auf den
 * Bootsschul-Admin-Gruppen in web.php, siehe Bug-Review "verified fehlt
 * auf der Admin-Gruppe") -- EnsureSuperAdmin prüft nur is_superadmin,
 * nicht email_verified_at. app:create-superadmin verweigert zwar schon
 * die ERST-Beförderung eines unbestätigten Kontos (Bug-Review R3-4);
 * Superadmin\UserController::update() setzt email_verified_at aber auf
 * null, sobald sich die E-Mail-Adresse eines Nutzers ändert, UND erlaubt
 * is_superadmin im selben Request zu setzen -- ein so (neu oder erneut)
 * beförderter Superadmin behielt damit plattformweiten Vollzugriff auf
 * ALLE Bootsschulen, ohne je die aktuelle Adresse bestätigt zu haben.
 */
Route::middleware(['auth', 'superadmin', 'admin-db', 'verified'])->prefix('superadmin')->name('superadmin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::patch('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/memberships', [UserController::class, 'addMembership'])->name('users.memberships.store');

    Route::get('/courses', [CourseEditorController::class, 'index'])->name('courses.index');
    Route::get('/courses/create', [CourseEditorController::class, 'create'])->name('courses.create');
    Route::post('/courses', [CourseEditorController::class, 'store'])->name('courses.store');
    Route::get('/courses/{course}', [CourseEditorController::class, 'show'])->name('courses.show');
    Route::patch('/courses/{course}', [CourseEditorController::class, 'update'])->name('courses.update');
    Route::post('/courses/{course}/modules', [CourseEditorController::class, 'attachModule'])->name('courses.modules.attach');
    Route::delete('/courses/{course}/modules/{module}', [CourseEditorController::class, 'detachModule'])->name('courses.modules.detach');

    Route::get('/video-lessons/{lesson}', [VideoLessonController::class, 'show'])->name('video-lessons.show');
    Route::patch('/video-lessons/{lesson}', [VideoLessonController::class, 'update'])->name('video-lessons.update');
    Route::post('/video-lessons/{lesson}/questions', [VideoLessonController::class, 'attachQuestion'])->name('video-lessons.questions.attach');
    Route::delete('/video-lessons/{lesson}/questions/{question}', [VideoLessonController::class, 'detachQuestion'])->name('video-lessons.questions.detach');

    Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
    Route::post('/modules', [ModuleController::class, 'store'])->name('modules.store');

    Route::get('/modules/{module}/questions', [QuestionController::class, 'index'])->name('questions.index');
    Route::get('/modules/{module}/questions/create', [QuestionController::class, 'create'])->name('questions.create');
    Route::post('/modules/{module}/questions', [QuestionController::class, 'store'])->name('questions.store');
    Route::get('/questions/{question}/edit', [QuestionController::class, 'edit'])->name('questions.edit');
    Route::patch('/questions/{question}', [QuestionController::class, 'update'])->name('questions.update');
    Route::post('/questions/{question}/toggle-active', [QuestionController::class, 'toggleActive'])->name('questions.toggle-active');
    // Bug-Review F8: eine vom Content-Import angelegte Revision im Status
    // "review" erreichte Lerner bisher nie -- es gab keinen Weg, sie zu
    // veröffentlichen.
    Route::post('/question-revisions/{revision}/publish', [QuestionController::class, 'publishRevision'])->name('questions.revisions.publish');
    Route::post('/question-revisions/{revision}/discard', [QuestionController::class, 'discardRevision'])->name('questions.revisions.discard');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::post('/products/{product}/toggle-active', [ProductController::class, 'toggleActive'])->name('products.toggle-active');

    Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('/coupons', [CouponController::class, 'store'])->name('coupons.store');
    Route::post('/coupons/export', [CouponController::class, 'export'])->name('coupons.export');

    Route::get('/tips/categories', [TipCategoryController::class, 'index'])->name('tips.categories.index');
    Route::post('/tips/categories', [TipCategoryController::class, 'store'])->name('tips.categories.store');
    Route::patch('/tips/categories/{tipCategory}', [TipCategoryController::class, 'update'])->name('tips.categories.update');
    Route::delete('/tips/categories/{tipCategory}', [TipCategoryController::class, 'destroy'])->name('tips.categories.destroy');

    Route::get('/tips', [TipController::class, 'index'])->name('tips.index');
    Route::get('/tips/create', [TipController::class, 'create'])->name('tips.create');
    Route::post('/tips', [TipController::class, 'store'])->name('tips.store');
    Route::get('/tips/{tip}/edit', [TipController::class, 'edit'])->name('tips.edit');
    Route::patch('/tips/{tip}', [TipController::class, 'update'])->name('tips.update');
    Route::post('/tips/{tip}/toggle-active', [TipController::class, 'toggleActive'])->name('tips.toggle-active');
    Route::delete('/tips/{tip}', [TipController::class, 'destroy'])->name('tips.destroy');

    Route::get('/addon-templates', [AddonTemplateController::class, 'index'])->name('addon-templates.index');
    Route::get('/addon-templates/create', [AddonTemplateController::class, 'create'])->name('addon-templates.create');
    Route::post('/addon-templates', [AddonTemplateController::class, 'store'])->name('addon-templates.store');
    Route::get('/addon-templates/{addonTemplate}/edit', [AddonTemplateController::class, 'edit'])->name('addon-templates.edit');
    Route::patch('/addon-templates/{addonTemplate}', [AddonTemplateController::class, 'update'])->name('addon-templates.update');
    Route::post('/addon-templates/{addonTemplate}/toggle-active', [AddonTemplateController::class, 'toggleActive'])->name('addon-templates.toggle-active');
    Route::delete('/addon-templates/{addonTemplate}', [AddonTemplateController::class, 'destroy'])->name('addon-templates.destroy');

    Route::get('/praxis-form-templates', [PraxisFormTemplateController::class, 'index'])->name('praxis-form-templates.index');
    Route::get('/praxis-form-templates/create', [PraxisFormTemplateController::class, 'create'])->name('praxis-form-templates.create');
    Route::post('/praxis-form-templates', [PraxisFormTemplateController::class, 'store'])->name('praxis-form-templates.store');
    Route::get('/praxis-form-templates/{praxisFormTemplate}/edit', [PraxisFormTemplateController::class, 'edit'])->name('praxis-form-templates.edit');
    Route::patch('/praxis-form-templates/{praxisFormTemplate}', [PraxisFormTemplateController::class, 'update'])->name('praxis-form-templates.update');
    Route::post('/praxis-form-templates/{praxisFormTemplate}/toggle-active', [PraxisFormTemplateController::class, 'toggleActive'])->name('praxis-form-templates.toggle-active');
    Route::delete('/praxis-form-templates/{praxisFormTemplate}', [PraxisFormTemplateController::class, 'destroy'])->name('praxis-form-templates.destroy');

    Route::get('/pruefungsausschuesse', [PruefungsausschussController::class, 'index'])->name('pruefungsausschuesse.index');
    Route::get('/pruefungsausschuesse/create', [PruefungsausschussController::class, 'create'])->name('pruefungsausschuesse.create');
    Route::post('/pruefungsausschuesse', [PruefungsausschussController::class, 'store'])->name('pruefungsausschuesse.store');
    Route::get('/pruefungsausschuesse/{pruefungsausschuss}/edit', [PruefungsausschussController::class, 'edit'])->name('pruefungsausschuesse.edit');
    Route::patch('/pruefungsausschuesse/{pruefungsausschuss}', [PruefungsausschussController::class, 'update'])->name('pruefungsausschuesse.update');
    Route::delete('/pruefungsausschuesse/{pruefungsausschuss}', [PruefungsausschussController::class, 'destroy'])->name('pruefungsausschuesse.destroy');
});
