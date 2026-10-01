<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\InviteCodeCheckController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    // Paid Institution Onboarding & Registration Flow
    Route::get('onboarding/register', [\App\Http\Controllers\Auth\PaidInstitutionRegistrationController::class, 'create'])
        ->name('onboarding.institution.register');
    Route::post('onboarding/register', [\App\Http\Controllers\Auth\PaidInstitutionRegistrationController::class, 'store'])
        ->name('onboarding.institution.store');
    Route::get('onboarding/gateway/{reference}', [\App\Http\Controllers\Auth\PaidInstitutionRegistrationController::class, 'gateway'])
        ->name('onboarding.payment.gateway');
    Route::post('onboarding/gateway/{reference}/complete', [\App\Http\Controllers\Auth\PaidInstitutionRegistrationController::class, 'completePayment'])
        ->name('onboarding.payment.complete');

    /*
     * LIVE INVITE-CODE VALIDATION.
     *
     * The registration screen must not merely require the field to be
     * non-empty - it must CONFIRM the code resolves to a real, active
     * institution BEFORE it unlocks the SSO/OAuth buttons. This endpoint is that
     * confirmation.
     *
     * It is public (a guest by definition), so it is throttled to slow code
     * guessing, and it deliberately returns ONLY the institution's display NAME
     * - never its id, settings or any tenant data - so it cannot be used to
     * enumerate a workspace.
     */
    Route::post('register/validate-invite-code', InviteCodeCheckController::class)
        ->middleware('throttle:12,1')
        ->name('register.invite-code.check');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

    /*
     * SSO / OAUTH (Google Workspace + Microsoft Entra).
     *
     * A guest starts at /auth/{provider}/redirect, which bounces them to the
     * provider; the provider returns them to /auth/{provider}/callback. Both sit
     * behind `guest` (a signed-in user has no reason to re-authenticate), and
     * `{provider}` is validated against the supported list in the controller.
     */
    Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->name('oauth.redirect');
    Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->name('oauth.callback');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
