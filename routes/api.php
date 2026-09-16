<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\DepositApiController;
use App\Http\Controllers\Api\MealApiController;
use App\Http\Controllers\Api\MemberApiController;
use App\Http\Controllers\Api\ReportApiController;
use App\Http\Controllers\Api\SubsidyApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API
|--------------------------------------------------------------------------
|
| Token-authenticated JSON API backing the mobile application. Authentication
| uses Laravel Sanctum personal access tokens: the client exchanges email +
| password (or a device name) for a bearer token, then sends it on every call.
|
| Conventions, applied consistently across every endpoint:
|
|   * Responses are wrapped in { data, meta } where a collection is returned.
|   * Money is returned as a number (float); currency metadata is served once
|     by /meta so the client formats locally.
|   * Errors use the standard HTTP codes with a { message, errors } body.
|   * Dates are ISO-8601 (YYYY-MM-DD) except month filters, which use YYYY-MM.
|
| See docs/API.md for the full reference with example payloads.
|
*/

// ---- Public ---------------------------------------------------------------
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Server + contract metadata. Useful for the mobile client to discover
// capabilities and confirm the currency/terminology it should render.
Route::get('/meta', [AuthController::class, 'meta']);

// ---- Authenticated --------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    // Session / identity
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/devices', [AuthController::class, 'registerDevice']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);

    // Dashboard summary
    Route::get('/dashboard', [DashboardApiController::class, 'index']);

    // Members (the roster - "students" internally)
    Route::get('/members', [MemberApiController::class, 'index']);
    Route::get('/members/{member}', [MemberApiController::class, 'show']);
    Route::post('/members', [MemberApiController::class, 'store']);
    Route::patch('/members/{member}', [MemberApiController::class, 'update']);
    Route::delete('/members/{member}', [MemberApiController::class, 'destroy']);

    // Deposits (Cash In) and Expenses (Cash Out)
    Route::get('/deposits', [DepositApiController::class, 'index']);
    Route::post('/deposits', [DepositApiController::class, 'store']);
    Route::get('/deposits/export', [DepositApiController::class, 'export']);

    // Meal entries
    Route::get('/meals', [MealApiController::class, 'index']);
    Route::get('/meals/day', [MealApiController::class, 'day']);
    Route::post('/meals/day', [MealApiController::class, 'storeDay']);

    // Subsidies
    Route::get('/subsidies', [SubsidyApiController::class, 'index']);
    Route::post('/subsidies', [SubsidyApiController::class, 'store']);
    Route::get('/subsidies/sources', [SubsidyApiController::class, 'sources']);

    // Reports + analytics (includes the forecast)
    Route::get('/reports/meal', [ReportApiController::class, 'meal']);
    Route::get('/reports/analytics', [ReportApiController::class, 'analytics']);
    Route::get('/reports/forecast', [ReportApiController::class, 'forecast']);
    Route::get('/reports/per-meal-rate', [ReportApiController::class, 'perMealRate']);
});
