<?php

use App\Http\Controllers\Superadmin\CourseEditorController;
use App\Http\Controllers\Superadmin\CouponController;
use App\Http\Controllers\Superadmin\DashboardController;
use App\Http\Controllers\Superadmin\ModuleController;
use App\Http\Controllers\Superadmin\ProductController;
use App\Http\Controllers\Superadmin\QuestionController;
use App\Http\Controllers\Superadmin\SettingsController;
use App\Http\Controllers\Superadmin\TenantController;
use App\Http\Controllers\Superadmin\UserController;
use Illuminate\Support\Facades\Route;

/**
 * Plattformweiter Superadmin-Bereich: bewusst mandantenunabhängig (siehe
 * ResolveTenant::isTenantAgnosticRoute()) und läuft über die
 * "admin-db"-Middleware auf der Owner-DB-Verbindung, damit er RLS-frei
 * über alle Bootsschulen hinweg lesen/schreiben kann.
 */
Route::middleware(['auth', 'superadmin', 'admin-db'])->prefix('superadmin')->name('superadmin.')->group(function () {
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

    Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
    Route::post('/modules', [ModuleController::class, 'store'])->name('modules.store');

    Route::get('/modules/{module}/questions', [QuestionController::class, 'index'])->name('questions.index');
    Route::get('/modules/{module}/questions/create', [QuestionController::class, 'create'])->name('questions.create');
    Route::post('/modules/{module}/questions', [QuestionController::class, 'store'])->name('questions.store');
    Route::get('/questions/{question}/edit', [QuestionController::class, 'edit'])->name('questions.edit');
    Route::patch('/questions/{question}', [QuestionController::class, 'update'])->name('questions.update');
    Route::post('/questions/{question}/toggle-active', [QuestionController::class, 'toggleActive'])->name('questions.toggle-active');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::post('/products/{product}/toggle-active', [ProductController::class, 'toggleActive'])->name('products.toggle-active');

    Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('/coupons', [CouponController::class, 'store'])->name('coupons.store');
    Route::post('/coupons/export', [CouponController::class, 'export'])->name('coupons.export');
});
