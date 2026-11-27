<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Support\Pay2SConfig;
use Illuminate\Console\Command;
use Modules\Minihouse\App\Models\Building;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\PartnerPayOsAccount;
use Modules\Payment\Entities\PartnerSePayAccount;

// Chỉ ĐỌC, không đổi dữ liệu: báo mức sẵn sàng khi chuyển PayOS → Pay2S — tài khoản Pay2S của 365home, từng đối tác / chi nhánh / toà MiniHouse còn PayOS riêng mà
// chưa khai báo Pay2S (những nơi này GIỮ PayOS cho tới khi khai báo xong, để tiền không đi nhầm tài khoản).
class Pay2SReadiness extends Command
{
    protected $signature = 'payment:pay2s-readiness';

    protected $description = 'Kiểm tra mức sẵn sàng chuyển PayOS sang Pay2S (không đổi dữ liệu)';

    public function handle(): int
    {
        $platform = Pay2SConfig::platformAccount(false);

        $this->info('Cổng đang chọn: ' . strtoupper(Pay2SConfig::gateway()) . ' — Pay2S ' . (Pay2SConfig::enabled() ? 'BẬT' : 'TẮT'));
        $this->line('Khoản thu mới qua Pay2S: ' . (Pay2SConfig::collectsViaPay2S() && Pay2SConfig::platformAccount() ? 'CÓ' : 'KHÔNG (vẫn PayOS)'));
        $this->check((bool) $platform, 'Tài khoản ngân hàng Pay2S của 365home', 'chưa khai báo ở CMS → Pay2S — cấu hình chung: gói, ký quỹ, đối soát, thuê xe và đơn không có kênh riêng chưa thu qua Pay2S được');
        $this->check(filled($platform?->webhook_token), 'Token webhook của tài khoản 365home', 'chưa có: webhook Pay2S của tài khoản này sẽ bị từ chối (401)');
        $this->check(filled(Pay2SConfig::get('api_token')), 'API Token Pay2S (kéo giao dịch dự phòng)', 'chưa có: chỉ dựa vào webhook (Pay2S chỉ gửi lại 5 lần)');

        $withPay2S = fn (string $partnerId, ?int $categoryId = null) => PartnerSePayAccount::query()->where('partner_id', $partnerId)->where('provider', PartnerSePayAccount::PROVIDER_PAY2S)
            ->where('is_active', true)->whereNotNull('webhook_token')->when($categoryId, fn ($q) => $q->where(fn ($w) => $w->where('category_id', $categoryId)->orWhereNull('category_id')))->exists();

        $this->newLine();
        $pending = [];

        foreach (PartnerPayOsAccount::query()->where('is_active', true)->get() as $account) {
            if (! $withPay2S((string) $account->partner_id)) {
                $pending[] = ['Đối tác', Partner::withTrashed()->whereKey($account->partner_id)->value('name') ?: $account->partner_id, 'PayOS riêng của đối tác'];
            }
        }

        foreach (BranchPayOsAccount::query()->where('is_active', true)->with('category:id,name,partner_id')->get() as $account) {
            $partnerId = (string) ($account->category?->partner_id ?? '');
            if ($partnerId === '' || ! $withPay2S($partnerId, (int) $account->category_id)) {
                $pending[] = ['Chi nhánh', $account->category?->name ?: '#' . $account->category_id, 'PayOS riêng của chi nhánh'];
            }
        }

        foreach (Building::query()->with('detail')->get() as $building) {
            if ($building->payment_method === Building::PAYMENT_METHOD_PAYOS || ($building->payment_method === Building::PAYMENT_METHOD_PAY2S && ! $building->hasOwnPay2S())) {
                $pending[] = ['Toà MiniHouse', $building->name, $building->payment_method === Building::PAYMENT_METHOD_PAYOS ? 'PayOS riêng của chủ toà' : 'chọn Pay2S nhưng thiếu ngân hàng / token webhook'];
            }
        }

        if ($pending === []) {
            $this->info('Không còn đối tác / chi nhánh / toà nhà nào cần chuyển.');

            return self::SUCCESS;
        }

        $this->warn(count($pending) . ' nơi còn PayOS riêng, chưa chuyển sang Pay2S (giữ PayOS cho tới khi khai báo Pay2S):');
        $this->table(['Loại', 'Tên', 'Hiện trạng'], $pending);

        return self::SUCCESS;
    }

    private function check(bool $ok, string $label, string $problem): void
    {
        $ok ? $this->line("  <info>OK</info>     {$label}") : $this->line("  <fg=yellow>THIẾU</>  {$label} — {$problem}");
    }
}
