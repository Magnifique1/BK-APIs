<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Random\RandomException;

class BKTransactionsController extends Controller
{
    /**
     * @throws RandomException
     * @throws ConnectionException
     */
    public function initiatePayment(Request $request)
    {

        $telegramToken = env('TELEGRAM_BOT_TOKEN');
        $telegramChatId = env('TELEGRAM_CHAT_ID');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'channel_name' => ['required', 'string'],
            'payer_code' => ['required', 'string'],
            'payer_names' => ['required', 'string'],
            'phone_number' => ['required', 'string'],
            'payer_email' => ['required', 'email'],
        ]);

        do {

            $transactionId = (string) random_int(1000000000, 9999999999);

            $checkTransID = DB::table('bk_transactions_init')
                ->where('external_transaction_ref_number', $transactionId)
                ->exists();

        } while ($checkTransID);

        $payload = [
            'amount' => (float) $validated['amount'],
            'channel_name' => $validated['channel_name'],
            'merchant_code' => env('BK_MERCHANT_CODE'),
            'payer_code' => $validated['payer_code'],
            'payer_names' => $validated['payer_names'],
            'phone_number' => $validated['phone_number'],
            'service_code' => env('BK_SERVICE_CODE'),
            'transaction_id' => $transactionId,
            'payer_email' => $validated['payer_email'],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => env('BK_AUTHORIZATION'), // API key from .env
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post('https://urubutopay.rw/api/v2/payment/initiate', $payload);

            // If the API returns 4xx/5xx, this will throw and land in the catch below
            $response->throw();

            $body = $response->json();
            $data = $body['data'] ?? [];

            DB::table('bk_transactions_init')->insert([
                'timestamp' => $body['timestamp'] ?? null,
                'status' => $body['status'] ?? null,
                'payment_channel' => $data['payment_channel'] ?? null,
                'payment_channel_name' => $data['payment_channel_name'] ?? null,
                'transaction_status' => $data['transaction_status'] ?? null,
                'internal_transaction_ref_number' => $data['internal_transaction_ref_number'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? null,
                'external_transaction_ref_number' => $data['external_transaction_ref_number'] ?? null,
                'merchant_code' => $data['merchant_code'] ?? null,
                'payer_code' => $data['payer_code'] ?? null,
                'paid_mount' => $data['paid_mount'] ?? null,
                'payer_names' => $data['payer_names'] ?? null,
                'payer_phone_number' => $data['payer_phone_number'] ?? null,
                'currency' => $data['currency'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!empty($telegramToken) && !empty($telegramChatId)) {
                $message = "✅ Payment initiated\n"
                    . "Amount: " . ($data['paid_mount'] ?? $payload['amount']) . " " . ($data['currency'] ?? 'RWF') . "\n"
                    . "Payer: " . ($data['payer_names'] ?? $validated['payer_names']) . "\n"
                    . "Phone: " . ($data['payer_phone_number'] ?? $validated['phone_number']) . "\n"
                    . "Status: " . ($data['transaction_status'] ?? 'INITIATED') . "\n"
                    . "External Ref: " . ($data['external_transaction_ref_number'] ?? $transactionId) . "\n"
                    . "Internal Ref: " . ($data['internal_transaction_ref_number'] ?? '-');

                Http::post("https://api.telegram.org/bot{$telegramToken}/sendMessage", [
                    'chat_id' => $telegramChatId,
                    'text' => $message,
                ]);
            }

            $payloadInsufficientFundsCheck = [
                'merchant_code' => env('BK_MERCHANT_CODE'),
                'transaction_id' => $data['internal_transaction_ref_number'],
            ];

            $checkInsufficientFunds = Http::withHeaders([
                'Authorization' => env('BK_AUTHORIZATION'), // API key from .env
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post('https://urubutopay.rw/api/v2/payment/transaction/status', $payloadInsufficientFundsCheck);

            $json = $checkInsufficientFunds->json();
            $transaction_statusIFC = data_get($json, 'data.transaction_status', 'UNKNOWN');

            if($transaction_statusIFC == 'FAILED'){

                $reasonIFC = $checkInsufficientFunds->json()['data']['reason'];

                DB::table('bk_transactions_init')
                    ->where('internal_transaction_ref_number', $data['internal_transaction_ref_number'])
                    ->update([
                        'transaction_status' => $transaction_statusIFC,
                        'message' => $reasonIFC,
                        'updated_at' => now(),
                    ]);

                $bodyIFC = $checkInsufficientFunds->json();
                $dataIFC = $bodyIFC['data'] ?? [];

                DB::table('bk_transactions_status')->insert([

                    'timestamp' => $bodyIFC['timestamp'],
                    'status' => $bodyIFC['status'],
                    'transaction_status' => $dataIFC['transaction_status'],
                    'payer_code' => $dataIFC['payer_code'],
                    'payment_channel' => $dataIFC['payment_channel'],
                    'payment_channel_name' => $dataIFC['payment_channel_name'],
                    'currency' => $dataIFC['currency'],
                    'merchant_code' => $dataIFC['merchant_code'],
                    'payer_names' => $dataIFC['payer_names'],
                    'payer_email' => $dataIFC['payer_email'],
                    'service_code' => $dataIFC['service_code'],
                    'payment_channel_transaction_ref_number' => $dataIFC['payment_channel_transaction_ref_number'],
                    'redirection_url' => $dataIFC['redirection_url'],
                    'reason' => $dataIFC['reason'],
                    'internal_transaction_id' => $dataIFC['internal_transaction_id'],
                    'transaction_id' => $dataIFC['transaction_id'],
                    'payment_date_time' => $dataIFC['payment_date_time'],
                    'amount' => $dataIFC['amount'],
                    'phone_number' => $dataIFC['phone_number'],
                    'created_at' => now(),

                ]);

                $message = "❌ Payment Initiated Failed\n"
                    . "Amount: " . ($dataIFC['amount']) . " " . ($dataIFC['currency'] ?? 'RWF') . "\n"
                    . "Payer: " . ($dataIFC['payer_names'] ) . "\n"
                    . "Phone: " . ($dataIFC['phone_number']) . "\n"
                    . "Status: " . $reasonIFC . "\n"
                    . "External Ref: " . ($dataIFC['payment_channel_transaction_ref_number']) . "\n"
                    . "Internal Ref: " . ($dataIFC['internal_transaction_id'] ?? '-');

                Http::post("https://api.telegram.org/bot{$telegramToken}/sendMessage", [
                    'chat_id' => $telegramChatId,
                    'text' => $message,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment Initiation Failed. Insufficient Funds.',
                ], $checkInsufficientFunds->status());

            }

            return response()->json([
                'success' => true,
                'data' => $response->json(),
            ], $response->status());

        } catch (RequestException $e) {

            $apiStatus = optional($e->response)->status() ?? 500;

            Http::post("https://api.telegram.org/bot{$telegramToken}/sendMessage", [
                'chat_id' => $telegramChatId,
                'text' => optional($e->response)->json() ?? optional($e->response)->body() ?? $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment initiation failed.',
                'error' => optional($e->response)->json() ?? optional($e->response)->body() ?? $e->getMessage(),
            ], $apiStatus);

        }
    }

    /**
     * @throws ConnectionException
     */
        public function transactionStatus(Request $request) {

        $telegramToken = env('TELEGRAM_BOT_TOKEN');
        $telegramChatId = env('TELEGRAM_CHAT_ID');

        $payloadTransStatus = [
            'merchant_code' => env('BK_MERCHANT_CODE'),
            'transaction_id' => $request->input('transaction_id'),
        ];

        $transStatusResponse = Http::withHeaders([
            'Authorization' => env('BK_AUTHORIZATION'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://urubutopay.rw/api/v2/payment/transaction/status', $payloadTransStatus);

        $body = $transStatusResponse->json();
        $data = $body['data'] ?? [];

        $checkITRN = DB::table('bk_transactions_status')
            ->where('internal_transaction_id', $data['internal_transaction_id'])
            ->exists();

        if (isset($data['reason'])) {
            $reason = $data['reason'];
        }

        if($checkITRN){

            DB::table('bk_transactions_status')
                ->where('internal_transaction_id', $data['internal_transaction_id'])
                ->update([
                'timestamp' => $body['timestamp'],
                'status' => $body['status'],
                'transaction_status' => $data['transaction_status'],
                'payer_code' => $data['payer_code'],
                'payment_channel' => $data['payment_channel'],
                'payment_channel_name' => $data['payment_channel_name'],
                'currency' => $data['currency'],
                'merchant_code' => $data['merchant_code'],
                'payer_names' => $data['payer_names'],
                'payer_email' => $data['payer_email'],
                'service_code' => $data['service_code'],
                'payment_channel_transaction_ref_number' => $data['payment_channel_transaction_ref_number'],
                'redirection_url' => $data['redirection_url'],
                'reason' => $reason ?? '',
                'transaction_id' => $data['transaction_id'],
                'payment_date_time' => $data['payment_date_time'],
                'amount' => $data['amount'],
                'phone_number' => $data['phone_number'],
                'created_at' => now(),
            ]);

        } else {

            DB::table('bk_transactions_status')->insert([
                'timestamp' => $body['timestamp'],
                'status' => $body['status'],
                'transaction_status' => $data['transaction_status'],
                'payer_code' => $data['payer_code'],
                'payment_channel' => $data['payment_channel'],
                'payment_channel_name' => $data['payment_channel_name'],
                'currency' => $data['currency'],
                'merchant_code' => $data['merchant_code'],
                'payer_names' => $data['payer_names'],
                'payer_email' => $data['payer_email'],
                'service_code' => $data['service_code'],
                'payment_channel_transaction_ref_number' => $data['payment_channel_transaction_ref_number'],
                'redirection_url' => $data['redirection_url'],
                'reason' => $reason ?? '',
                'internal_transaction_id' => $data['internal_transaction_id'],
                'transaction_id' => $data['transaction_id'],
                'payment_date_time' => $data['payment_date_time'],
                'amount' => $data['amount'],
                'phone_number' => $data['phone_number'],
                'created_at' => now(),
            ]);

        }

        DB::table('bk_transactions_init')
            ->where('internal_transaction_ref_number', $data['internal_transaction_id'])
            ->update([
                'transaction_status' => $data['transaction_status'],
                'message' => $reason ?? '',
                'updated_at' => now(),
            ]);

        if ($data['transaction_status'] == 'VALID') {

            $message = "💰 Payment Successful\n"
                . "Amount: " . $data['amount'] . " " . $data['currency'] . "\n"
                . "Payer: " . $data['payer_names'] . "\n"
                . "Phone: " . $data['phone_number'] . "\n"
                . "Status: " . $data['transaction_status'] . "\n"
                . "External Ref: " . $data['transaction_id']  . "\n"
                . "Internal Ref: " . ($data['internal_transaction_id'] ?? '-');

            Http::post("https://api.telegram.org/bot{$telegramToken}/sendMessage", [
                'chat_id' => $telegramChatId,
                'text' => $message,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transaction Successful',
                'transaction_id' => $data['internal_transaction_id'],
                'response' => $body
            ], $transStatusResponse->status());

        } else if ($data['transaction_status'] == 'PENDING' || $data['transaction_status'] == 'INITIATED') {

//            $message = "‼️ Transaction Validation Pending\n"
//                . "Amount: " . $data['paid_mount'] . " " . $data['currency'] . "\n"
//                . "Payer: " . $data['payer_names'] . "\n"
//                . "Phone: " . $data['payer_phone_number'] . "\n"
//                . "Status: " . $data['transaction_status'] . "\n"
//                . "External Ref: " . $data['external_transaction_ref_number']  . "\n"
//                . "Internal Ref: " . ($data['internal_transaction_ref_number'] ?? '-');
//
//            Http::post("https://api.telegram.org/bot{$telegramToken}/sendMessage", [
//                'chat_id' => $telegramChatId,
//                'text' => $message,
//            ]);

            return response()->json([
                'success' => false,
                'message' => 'Transaction Pending',
                'response' => $body
            ], $transStatusResponse->status());

        }

        return response()->json([
            'success' => false,
            'message' => 'Transaction Failed',
            'reason' => $reason ?? '',
            'response' => $body
        ], $transStatusResponse->status());

    }

    /**
     * @throws ConnectionException
     */
    public function testTGBot(Request $request)
    {

        $telegramToken = env('TELEGRAM_BOT_TOKEN');
        $telegramChatId = env('TELEGRAM_CHAT_ID');

        try{

            Http::post("https://api.telegram.org/bot{$telegramToken}/sendMessage", [
                'chat_id' => $telegramChatId,
                'text' => 'TEST 1234',
            ]);

            return 'success';

        }catch (\Exception $e){

            return $e->getMessage();

        }

    }
}
