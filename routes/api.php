<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\CustomerFaqController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\LearningController;
use App\Http\Controllers\Api\V1\NavigationController;
use App\Http\Controllers\Api\V1\VideoController;
use Illuminate\Support\Facades\Route;

/*
| Mobile-API v1 für die Lernenden-App (Präfix /api/v1, siehe
| bootstrap/app.php). Dokumentation: docs/api/README.md, Vertrag:
| docs/api/openapi-mobile-v1.yaml. Fehler als application/problem+json.
*/

// Öffentlich (ohne Token).
Route::get('/app/config', [AccountController::class, 'appConfig'])->middleware('throttle:60,1,api-config');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1,api-login');
Route::post('/auth/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:10,1,api-two-factor');
// Review-Runde 13: je Refresh-Token begrenzt (viele Geräte hinter einer IP, z. B. Schul-WLAN).
Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:api-refresh');
Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1,api-password-forgot');
Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1,api-password-reset');

// Datenexport: kurzlebiger, signierter Link (ohne Token nutzbar, z. B. Teilen-Menü).
Route::get('/profile/export/{exportId}/download', [ExportController::class, 'download'])
    ->whereUuid('exportId')->middleware(['signed', 'throttle:20,1,api-export-download'])->name('api.v1.export.download');

Route::middleware('api.auth')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:6,1,api-verification');
    Route::get('/me', [AccountController::class, 'me']);
    Route::get('/me/sessions', [AccountController::class, 'sessions']);
    Route::delete('/me/sessions', [AccountController::class, 'revokeOtherSessions']);
    Route::delete('/me/sessions/{sessionId}', [AccountController::class, 'revokeSession'])->whereUuid('sessionId');

    // Ab hier im Kontext einer Schule (X-Tenant-Slug + aktive Mitgliedschaft).
    Route::middleware(['api.tenant', 'throttle:240,1,api-tenant'])->group(function () {
        Route::get('/courses', [CourseController::class, 'index']);
        Route::get('/courses/{courseId}', [CourseController::class, 'show'])->whereUuid('courseId');
        Route::get('/courses/{courseId}/overview', [CourseController::class, 'overview'])->whereUuid('courseId');
        Route::get('/courses/{courseId}/modules', [CourseController::class, 'modules'])->whereUuid('courseId');
        Route::get('/courses/{courseId}/progress', [CourseController::class, 'progress'])->whereUuid('courseId');
        Route::get('/courses/{courseId}/faqs', [CustomerFaqController::class, 'index'])->whereUuid('courseId');

        Route::get('/courses/{courseId}/trainer/next', [LearningController::class, 'next'])->whereUuid('courseId');
        Route::post('/courses/{courseId}/attempts', [LearningController::class, 'submit'])->whereUuid('courseId')->middleware('api.idempotent');
        Route::get('/courses/{courseId}/attempts/{attemptId}', [LearningController::class, 'showAttempt'])->whereUuid(['courseId', 'attemptId']);

        Route::get('/courses/{courseId}/favorites', [FavoriteController::class, 'index'])->whereUuid('courseId');
        Route::put('/courses/{courseId}/favorites/{questionId}', [FavoriteController::class, 'put'])->whereUuid(['courseId', 'questionId']);
        Route::delete('/courses/{courseId}/favorites/{questionId}', [FavoriteController::class, 'destroy'])->whereUuid(['courseId', 'questionId']);
        Route::get('/courses/{courseId}/favorite-collections', [FavoriteController::class, 'collections'])->whereUuid('courseId');
        Route::post('/courses/{courseId}/favorite-collections', [FavoriteController::class, 'storeCollection'])->whereUuid('courseId')->middleware('api.idempotent');
        Route::patch('/courses/{courseId}/favorite-collections/{collectionId}', [FavoriteController::class, 'updateCollection'])->whereUuid(['courseId', 'collectionId']);
        Route::delete('/courses/{courseId}/favorite-collections/{collectionId}', [FavoriteController::class, 'destroyCollection'])->whereUuid(['courseId', 'collectionId']);

        // Prüfungssimulation (Antworten werden festgeschrieben, Auflösung erst nach Abgabe).
        Route::get('/courses/{courseId}/exams', [ExamController::class, 'index'])->whereUuid('courseId');
        Route::post('/courses/{courseId}/exams', [ExamController::class, 'start'])->whereUuid('courseId')->middleware('api.idempotent');
        Route::get('/courses/{courseId}/exam-sessions', [ExamController::class, 'history'])->whereUuid('courseId');
        Route::get('/exams/{examId}', [ExamController::class, 'show'])->whereUuid('examId');
        Route::put('/exams/{examId}/answers/{questionId}', [ExamController::class, 'answer'])->whereUuid(['examId', 'questionId']);
        Route::post('/exams/{examId}/questions/{questionId}/reveal', [ExamController::class, 'reveal'])->whereUuid(['examId', 'questionId']);
        Route::put('/exams/{examId}/navigation', [ExamController::class, 'submitNavigation'])->whereUuid('examId');
        Route::post('/exams/{examId}/navigation/assessment', [ExamController::class, 'assessNavigation'])->whereUuid('examId')->middleware('api.idempotent');
        Route::post('/exams/{examId}/submit', [ExamController::class, 'submit'])->whereUuid('examId')->middleware('api.idempotent');
        Route::get('/exams/{examId}/result', [ExamController::class, 'result'])->whereUuid('examId');

        // Videokurs.
        Route::get('/courses/{courseId}/videos', [VideoController::class, 'index'])->whereUuid('courseId');
        Route::get('/videos/{lessonId}', [VideoController::class, 'show'])->whereUuid('lessonId');
        Route::put('/videos/{lessonId}/progress', [VideoController::class, 'progress'])->whereUuid('lessonId');

        // Navigationsaufgaben-Trainer.
        Route::get('/courses/{courseId}/navigation-tasks', [NavigationController::class, 'index'])->whereUuid('courseId');
        Route::get('/navigation-tasks/{taskId}', [NavigationController::class, 'show'])->whereUuid('taskId');
        Route::put('/navigation-tasks/{taskId}/parts/{partId}/answer', [NavigationController::class, 'answer'])->whereUuid(['taskId', 'partId']);

        // Zugangscode einlösen (nach dem Kauf im externen Webshop).
        Route::post('/coupons/redeem', [CouponController::class, 'redeem'])->middleware(['throttle:10,1,api-coupons', 'api.idempotent']);

        // DSGVO-Datenexport.
        // Ohne Idempotenz-Speicher: ein wiederholter Aufruf soll einen frischen Link liefern.
        Route::post('/profile/export', [ExportController::class, 'store'])->middleware('throttle:5,60,api-export');
        Route::get('/profile/export/{exportId}', [ExportController::class, 'show'])->whereUuid('exportId');
    });
});
