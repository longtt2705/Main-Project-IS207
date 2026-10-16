<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\HospitalAdminController;
use App\Http\Controllers\Api\HospitalController;
use App\Http\Controllers\Api\PatientProfileController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

// -------------------------------------------------------------------------
// Public: không cần token
// -------------------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// -------------------------------------------------------------------------
// Protected: yêu cầu Bearer token (Sanctum)
// -------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // Profile
    Route::get('/user', [ProfileController::class, 'show']);
    Route::match(['put', 'patch'], '/user', [ProfileController::class, 'update']);

    // Bookings & patient profiles
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/my', [BookingController::class, 'index']);
    Route::get('/bookings/{id}', [BookingController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/bookings/{id}/cancel', [BookingController::class, 'cancel'])->whereNumber('id');
    Route::get('/bookings/{booking}/ticket', [TicketController::class, 'show'])->whereNumber('booking');
    Route::post('/tickets/check-in', [TicketController::class, 'checkIn'])->middleware('throttle:30,1');
    Route::post('/tickets/{ticket}/complete', [TicketController::class, 'complete'])->whereNumber('ticket');
    Route::patch('/hospital-departments/{department}', [HospitalAdminController::class, 'department']);
    Route::put('/hospitals/{hospital}/availability', [HospitalAdminController::class, 'availability']);
    Route::post('/hospitals/{hospital}/slots', [HospitalAdminController::class, 'createSlot']);
    Route::apiResource('patient-profiles', PatientProfileController::class);

    // Payments
    Route::prefix('payments')->group(function () {
        Route::post('/calculate-invoice', [PaymentController::class, 'calculateInvoice']);
        Route::post('/create', [PaymentController::class, 'createPayment']);
        Route::post('/confirm', [PaymentController::class, 'confirmPayment']);
        Route::post('/process', [PaymentController::class, 'confirmPayment']);
        Route::get('/{id}', [PaymentController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/cancel', [PaymentController::class, 'cancel'])->whereNumber('id');
    });
});

// Hospital discovery and booking.
Route::get('/hospitals', [HospitalController::class, 'index']);
Route::get('/hospitals/{id}', [HospitalController::class, 'show'])->whereNumber('id');
Route::post('/bookings/hospital', [HospitalController::class, 'book'])->middleware('auth:sanctum');
