<?php

namespace App\Console\Commands;

use App\Services\Payment\SepayWebhookService;
use Illuminate\Console\Command;
use Modules\Payment\Entities\BankTransaction;

// Xử lý lại giao dịch SePay chưa tự ghi nhận được (unmatched / amount_mismatch / needs_review) — xem SepayWebhookService::replay().
class SepayReplayCommand extends Command
{
    protected $signature = 'sepay:replay {ids* : ID dòng bank_transactions}';

    protected $description = 'Xử lý lại giao dịch SePay chưa khớp đơn';

    public function handle(SepayWebhookService $sepay): int
    {
        foreach ($this->argument('ids') as $id) {
            $txn = BankTransaction::find($id);

            if (! $txn) {
                $this->warn("#{$id}: không tìm thấy.");

                continue;
            }

            $result = $sepay->replay($txn);
            $this->line("#{$id} (SePay {$txn->provider_txn_id}): " . ($result?->status ?? 'bỏ qua') . ($result?->note ? " — {$result->note}" : ''));
        }

        return self::SUCCESS;
    }
}
