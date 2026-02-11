<?php

use App\Http\Controllers\BKTransactionsController;
use App\Http\Controllers\CallBackController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthenticationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/auth/authenticate', [AuthenticationController::class, 'login']);

Route::post('/payment-notification', [CallBackController::class, 'paymentNotification'])->middleware('auth:sanctum');
Route::post('/callback-url', [CallBackController::class, 'paymentCallback'])->middleware('auth:sanctum');

Route::post('/payment/initiate', [BKTransactionsController::class, 'initiatePayment']);
Route::post('/transaction/status', [BKTransactionsController::class, 'transactionStatus']);

Route::post('/test-tg-bot', [BKTransactionsController::class, 'testTGBot']);
