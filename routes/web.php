<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\CourseSelectionController;
use App\Http\Controllers\Admin\EntitlementController;
use App\Http\Controllers\Admin\ParticipantController;
use App\Http\Controllers\Admin\SupportEmailVerificationController;
use App\Http\Controllers\Admin\WebshopLinkController;
use App\Http\Controllers\CouponRedeemController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\NavigationTaskController;
use App\Http\Controllers\PraxisPruefungController;
use App\Http\Controllers\PraxisTrainerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\VideoCourseController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route(auth()->user()->is_superadmin ? 'superadmin.dashboard' : 'dashboard');
});

Route::middleware(['auth', 'tenant.member'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
    Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');

    Route::get('/courses/{course}/learn', [LearningController::class, 'show'])->name('learning.show');
    Route::get('/courses/{course}/learn/overview', [LearningController::class, 'overview'])->name('learning.overview');
    Route::post('/courses/{course}/learn/attempts', [LearningController::class, 'storeAttempt'])->name('learning.attempts.store');

    Route::get('/courses/{course}/favorites/smart-learning', [FavoriteController::class, 'smartLearning'])->name('favorites.smart-learning');
    Route::get('/courses/{course}/favorites/exam', [FavoriteController::class, 'exam'])->name('favorites.exam');
    Route::post('/favorites/{question}', [FavoriteController::class, 'store'])->name('favorites.store');
    Route::delete('/favorites/{question}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');

    Route::get('/courses/{course}/progress', [ProgressController::class, 'show'])->name('progress.show');

    Route::get('/courses/{course}/video', [VideoCourseController::class, 'index'])->name('video.index');
    Route::get('/courses/{course}/video/{lesson}', [VideoCourseController::class, 'show'])->name('video.show');
    Route::post('/courses/{course}/video/{lesson}/complete', [VideoCourseController::class, 'complete'])->name('video.complete');

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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/coupons/redeem', [CouponRedeemController::class, 'show'])->name('coupons.redeem');
    Route::post('/coupons/redeem', [CouponRedeemController::class, 'redeem'])->name('coupons.redeem.store');
});

Route::middleware(['auth', 'tenant.role:owner,admin,instructor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');
    Route::post('/participants/invite', [ParticipantController::class, 'invite'])->name('participants.invite');
    Route::post('/entitlements', [EntitlementController::class, 'store'])->name('entitlements.store');
    Route::patch('/entitlements/{entitlement}', [EntitlementController::class, 'update'])->name('entitlements.update');
    Route::patch('/branding', [BrandingController::class, 'update'])->name('branding.update');
    Route::post('/branding/support-email/verification-notification', [SupportEmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('branding.support-email.send');
    Route::get('/branding/support-email/verify/{tenant}/{hash}', [SupportEmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('branding.support-email.verify');

    Route::patch('/webshop-links', [WebshopLinkController::class, 'update'])->name('webshop-links.update');

    Route::patch('/courses', [CourseSelectionController::class, 'update'])->name('courses.update');

    Route::post('/coupons/import', [AdminCouponController::class, 'import'])->name('coupons.import');
    Route::post('/coupons/{coupon}/assign', [AdminCouponController::class, 'assign'])->name('coupons.assign');
});

require __DIR__.'/superadmin.php';
require __DIR__.'/auth.php';
