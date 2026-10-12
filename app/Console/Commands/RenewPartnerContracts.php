<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Partner;
use App\Models\PartnerStatusLog;
use App\Services\EscrowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

// Hợp đồng mẫu (Điều 7): khi hết thời hạn mà không bên nào thông báo chấm dứt trước ít nhất 07 ngày thì tự động gia hạn thêm 12 tháng, điều khoản không đổi.
//  - Nhắc Super Admin còn tối đa 8 ngày trước hạn (để kịp thông báo chấm dứt nếu muốn).
//  - Đến ngày hết hạn mà hợp đồng vẫn hiệu lực (chưa chấm dứt) → gia hạn thêm đúng số tháng mặc định và ghi lịch sử.
class RenewPartnerContracts extends Command
{
    protected $signature = 'partners:renew-contracts';

    protected $description = 'Tự động gia hạn hợp đồng đối tác đến hạn (Điều 7 hợp đồng mẫu) và nhắc trước 7 ngày';

    public function handle(EscrowService $escrow): int
    {
        $months = max(1, (int) config('contract.default_term_months', 12));
        $renewed = 0;
        $reminded = 0;

        Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('contract_status', 'active')->whereNotNull('contract_expires_at')->get()
            ->each(function (Partner $partner) use ($months, $escrow, &$renewed, &$reminded) {
                $expires = $partner->contract_expires_at->copy()->startOfDay();

                if ($expires->lte(now()->startOfDay())) {
                    $next = $expires->copy()->addMonths($months);
                    $partner->forceFill(['contract_expires_at' => $next])->saveQuietly();
                    PartnerStatusLog::create([
                        'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => null,
                        'note' => 'Hợp đồng đến hạn ' . $expires->format('d/m/Y') . ' — tự động gia hạn thêm ' . $months . ' tháng (đến ' . $next->format('d/m/Y') . ') theo Điều 7, vì không bên nào thông báo chấm dứt.',
                    ]);
                    $renewed++;
                } elseif ($expires->lte(now()->startOfDay()->addDays(8)) && Cache::add("contract-renew-reminder:{$partner->id}:{$expires->format('Ymd')}", 1, now()->addDays(20))) {
                    $escrow->notifySuperAdmins('contract_expiring', 'Hợp đồng đối tác sắp đến hạn', ($partner->legal_name ?: $partner->name)
                        . ': hợp đồng hết hạn ' . $expires->format('d/m/Y') . ' sẽ tự động gia hạn thêm ' . $months . ' tháng nếu không có bên nào thông báo chấm dứt trước ít nhất 07 ngày.', $partner);
                    $reminded++;
                }
            });

        $this->info("Đã gia hạn {$renewed} hợp đồng, nhắc {$reminded} hợp đồng sắp đến hạn.");

        return self::SUCCESS;
    }
}
