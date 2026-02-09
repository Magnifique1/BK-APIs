<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CallBackController extends Controller
{
    public function paymentNotification(Request $request)
    {
        $logger = Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/payment_notifications.log'),
            'level' => 'info',
        ]);

        $logger->info('Payment notification received', [
            'received_at' => now()->toDateTimeString(),
            'ip' => $request->ip(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function paymentCallback(Request $request)
    {
        $logger = Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/payment_callbacks.log'),
            'level' => 'info',
        ]);

        $logger->info('Payment callback received', [
            'received_at' => now()->toDateTimeString(),
            'ip' => $request->ip(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
        ]);

        return response()->json(['status' => 'ok']);
    }
}
