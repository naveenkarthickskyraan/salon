<?php

use App\Http\Controllers\Api\Partner\AuthController;
use App\Http\Controllers\Api\Partner\CategoryController;
use App\Http\Controllers\Api\Partner\SalonOtpController;
use App\Http\Controllers\Api\Partner\SalonSetupController;
use App\Http\Controllers\Api\Partner\ServiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('partner')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('forgot-password/verify', [AuthController::class, 'forgotVerify']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);

        // Salon setup
        Route::post('salon-setup', [SalonSetupController::class, 'store']);
        Route::post('salon/send-otp', [SalonOtpController::class, 'send']);
        Route::post('salon/verify-otp', [SalonOtpController::class, 'verify']);

        // Services and categories

        Route::prefix('salons/{salon}')->whereNumber('salon')->group(function () {
            Route::get('service-options', [ServiceController::class, 'options']);
            Route::get('services', [ServiceController::class, 'index']);
            Route::post('services', [ServiceController::class, 'store']);
            Route::get('services/{service}', [ServiceController::class, 'show']);
            Route::post('services/{service}', [ServiceController::class, 'update']);
            Route::patch('services/{service}/visibility', [ServiceController::class, 'visibility']);
            Route::delete('services/{service}', [ServiceController::class, 'destroy']);
            Route::post('services/{service}/move', [ServiceController::class, 'move']);

            Route::get('categories', [CategoryController::class, 'index']);
            Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
        });
    });
});