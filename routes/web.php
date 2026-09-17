<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\EntitlementController;
use App\Http\Controllers\Admin\ParticipantController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProgressController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::middleware(['auth', 'tenant.member'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
    Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');

    Route::get('/courses/{course}/learn', [LearningController::class, 'show'])->name('learning.show');
    Route::post('/courses/{course}/learn/attempts', [LearningController::class, 'storeAttempt'])->name('learning.attempts.store');

    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('/favorites/{question}', [FavoriteController::class, 'store'])->name('favorites.store');
    Route::delete('/favorites/{question}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');

    Route::get('/progress', [ProgressController::class, 'index'])->name('progress.index');

    Route::get('/courses/{course}/exam', [ExamController::class, 'intro'])->name('exam.intro');
    Route::post('/courses/{course}/exam', [ExamController::class, 'start'])->name('exam.start');
    Route::get('/exam-sessions/{examSession}', [ExamController::class, 'show'])->name('exam.show');
    Route::post('/exam-sessions/{examSession}/answers', [ExamController::class, 'answer'])->name('exam.answer');
    Route::post('/exam-sessions/{examSession}/finish', [ExamController::class, 'finish'])->name('exam.finish');
    Route::get('/exam-sessions/{examSession}/result', [ExamController::class, 'result'])->name('exam.result');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'tenant.role:owner,admin,instructor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');
    Route::get('/participants', [ParticipantController::class, 'index'])->name('participants.index');
    Route::post('/participants/invite', [ParticipantController::class, 'invite'])->name('participants.invite');
    Route::post('/entitlements', [EntitlementController::class, 'store'])->name('entitlements.store');
    Route::patch('/entitlements/{entitlement}', [EntitlementController::class, 'update'])->name('entitlements.update');
    Route::get('/branding', [BrandingController::class, 'edit'])->name('branding.edit');
    Route::patch('/branding', [BrandingController::class, 'update'])->name('branding.update');
});

require __DIR__.'/auth.php';
