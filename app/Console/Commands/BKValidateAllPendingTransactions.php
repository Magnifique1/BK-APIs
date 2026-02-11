<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BKValidateAllPendingTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bk-validate-all-pending-transactions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws ConnectionException
     */
    public function handle()
    {

        $pendingValidation = DB::table('bk_transactions_init')
            ->where('transaction_status','INITIATED')
            ->orWhere('transaction_status','PENDING')
            ->get([
                'internal_transaction_ref_number'
            ]);

        if($pendingValidation->count() > 0){

            foreach ($pendingValidation as $transaction){
                $this->transactionStatusCheck($transaction->internal_transaction_ref_number);
                $this->info("\n");
                $this->info("\n");
            }

        } else {

            $this->info('No Pending Transactions Found');

        }

    }


    /**
     * @throws ConnectionException
     */
    public function transactionStatusCheck($internal_transaction_ref_number): void
    {

        $telegramToken = env('TELEGRAM_BOT_TOKEN');
        $telegramChatId = env('TELEGRAM_CHAT_ID');

        $payloadTransStatus = [
            'merchant_code' => env('BK_MERCHANT_CODE'),
            'transaction_id' => $internal_transaction_ref_number,
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

            $this->info($message);

        } else if ($data['transaction_status'] == 'FAILED') {

            $message = "❌ Payment Failed\n"
                . "Amount: " . ($data['amount']) . " " . ($data['currency'] ?? 'RWF') . "\n"
                . "Status: " . $reason ."\n"
                . "External Ref: " . ($data['transaction_id']) . "\n"
                . "Internal Ref: " . ($data['internal_transaction_id'] ?? '-');

            Http::post("https://api.telegram.org/bot{$telegramToken}/sendMessage", [
                'chat_id' => $telegramChatId,
                'text' => $message,
            ]);

            $this->info($message);

        } else {

            $this->info('Could Not Get Updated Status Transaction: ' . $internal_transaction_ref_number );

        }



    }
}
