<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Services\ContractSigning\ContractSigningManager;
use App\Services\EscrowService;
use App\Services\PartnerContractWorkflowService;
use App\Services\Payment\PayOsAccountResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// KIỂM TRA SẴN SÀNG cho luồng tiền Homestay (kênh PayOS của đối tác + ký quỹ + đối soát hoa hồng): hệ thống đã cấu hình đủ để chạy đúng chưa, và từng đối tác đang
// ở bước nào. Chạy sau khi deploy và mỗi khi nghi ngờ: php artisan finance:check [--partners]. Có mục chặn (✗) thì thoát mã 1.
class CheckFinanceSetup extends Command
{
    protected $signature = 'finance:check {--partners : Liệt kê tình trạng từng đối tác Homestay}';

    protected $description = 'Kiểm tra cấu hình và lịch chạy của luồng tiền Homestay (ký quỹ, đối soát, PayOS, chữ ký số)';

    private int $blockers = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->blockers = 0;
        $this->warnings = 0;

        $this->line('<options=bold>1. Cơ sở dữ liệu</>');
        foreach (['partner_escrow_entries', 'partner_escrow_deposits', 'partner_escrow_deductions', 'partner_payos_accounts', 'partner_settlements', 'partner_settlement_disputes',
            'partner_refund_claims', 'coupon_partner_participations'] as $table) {
            $this->check(Schema::hasTable($table), "Bảng {$table}", 'chưa có — chạy php artisan migrate', true);
        }
        foreach ([['orders', 'collected_by'], ['orders', 'commission_amount'], ['coupons', 'funded_by'], ['partners', 'payment_flow_effective_at'], ['partners', 'escrow_terminated_at'],
            ['partner_settlements', 'invoice_no'], ['partner_contract_versions', 'kind']] as [$table, $column]) {
            $this->check(Schema::hasTable($table) && Schema::hasColumn($table, $column), "Cột {$table}.{$column}", 'chưa có — chạy php artisan migrate', true);
        }

        $this->line('');
        $this->line('<options=bold>2. Tài khoản PayOS của công ty (nhận tiền nạp ký quỹ và hoa hồng đối tác nộp)</>');
        $payos = filled(config('payos.client_id')) && filled(config('payos.api_key')) && filled(config('payos.checksum_key'));
        $this->check($payos, 'PAYOS_CLIENT_ID / API_KEY / CHECKSUM_KEY', 'chưa cấu hình — chưa tạo được QR nạp ký quỹ và QR nộp hoa hồng (đối tác phải chuyển khoản tay)', true);
        $this->check(filled(config('app.url')) && ! str_contains((string) config('app.url'), 'localhost'), 'APP_URL công khai (để PayOS gọi webhook)', 'đang là localhost hoặc trống — PayOS không gọi được webhook', false);

        $this->line('');
        $this->line('<options=bold>3. Thông tin pháp nhân 365home (hợp đồng, phụ lục, hoá đơn)</>');
        foreach (['name' => 'Tên công ty', 'tax_code' => 'Mã số thuế', 'address' => 'Địa chỉ', 'representative' => 'Người đại diện', 'bank_account' => 'Số tài khoản'] as $key => $label) {
            $this->check(filled(config("contract.platform.{$key}")), "config/contract.php › platform.{$key} ({$label})", 'đang trống', false);
        }

        $this->line('');
        $this->line('<options=bold>4. Gửi thông báo và email cho đối tác</>');
        $mailer = (string) config('mail.default');
        $this->check(! in_array($mailer, ['log', 'array', ''], true), "Mailer: {$mailer}", 'đang là log/array — email không tới đối tác (thông báo trong app vẫn gửi)', false);

        $this->line('');
        $this->line('<options=bold>5. Lịch chạy tự động (scheduler)</>');
        foreach ([['escrow:process', 'mỗi giờ', 2], ['settlements:process', 'hằng ngày 01:00', 30]] as [$name, $when, $maxHours]) {
            $last = Cache::get("finance:heartbeat:{$name}");
            $ok = $last && now()->diffInHours($last) <= $maxHours;
            $this->check($ok, "{$name} ({$when})", $last ? 'lần chạy gần nhất ' . $last->format('d/m/Y H:i') . ' — quá lâu, kiểm tra php artisan schedule:work / cron' : 'chưa từng chạy — bật scheduler (php artisan schedule:work hoặc cron mỗi phút)', false);
        }

        $this->line('');
        $this->line('<options=bold>6. Chữ ký số hợp đồng</>');
        $manager = app(ContractSigningManager::class);
        $homestay = $manager->providerNameForSide(ContractSigningManager::SIDE_HOMESTAY);
        $this->check($homestay !== 'local', "Homestay: nhà cung cấp {$homestay}", 'đang là ký thử nghiệm (local) — không có giá trị pháp lý, chọn nhà cung cấp thật ở Cấu hình web › Chữ ký số', false);
        $mini = $manager->providerNameForSide(ContractSigningManager::SIDE_MINIHOUSE);
        $this->line('   ' . ($manager->minihousePkiEnabled() ? "MiniHouse: bắt buộc ký số ({$mini})" : 'MiniHouse: ký tay + OTP (chưa bật ký số)'));

        $this->line('');
        $this->line('<options=bold>7. Kênh PayOS riêng theo chi nhánh</>');
        $idle = \Modules\Payment\Entities\BranchPayOsAccount::query()->where('is_active', true)->with('category:id,name,partner_id')->get()
            ->filter(fn ($branch) => ! \App\Services\Payment\PayOsAccountResolver::partnerFlowAllowsOwnChannel(\App\Services\Payment\PayOsAccountResolver::partnerIdForCategory((int) $branch->category_id)));
        $this->check($idle->isEmpty(), 'Kênh chi nhánh đang bật đều thuộc đối tác đã vào luồng tiền mới', $idle->count() . ' chi nhánh có kênh PayOS riêng nhưng đối tác chưa ký hợp đồng/phụ lục — 365home đang thu hộ cho các chi nhánh này: ' . $idle->map(fn ($b) => $b->category?->name ?? ('#' . $b->category_id))->implode(', '), false);

        $trial = app(\App\Services\PartnerTrialService::class);
        $this->line('   Miễn phí tháng đầu (đối tác mới ngoài tỉnh phải ký quỹ ngay): ' . ($trial->enabled()
            ? 'BẬT — ' . $trial->months() . ' tháng, tỉnh ký quỹ ngay (mã): ' . implode(', ', $trial->immediateProvinceCodes())
            : 'tắt (đối tác mới ký quỹ và tính hoa hồng ngay)'));

        if ($this->option('partners')) {
            $this->partners();
        }

        $this->line('');
        $this->line($this->blockers > 0
            ? "<fg=red>Có {$this->blockers} mục CHẶN và {$this->warnings} cảnh báo — xử lý các mục ✗ trước khi cho đối tác dùng.</>"
            : ($this->warnings > 0 ? "<fg=yellow>Không có mục chặn, còn {$this->warnings} cảnh báo.</>" : '<fg=green>Đã sẵn sàng.</>'));

        return $this->blockers > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function check(bool $ok, string $label, string $problem, bool $blocking): void
    {
        if ($ok) {
            $this->line("  <fg=green>✓</> {$label}");

            return;
        }

        $blocking ? $this->blockers++ : $this->warnings++;
        $this->line('  ' . ($blocking ? '<fg=red>✗</>' : '<fg=yellow>⚠</>') . " {$label} — {$problem}");
    }

    private function partners(): void
    {
        $this->line('');
        $this->line('<options=bold>7. Từng đối tác Homestay đã duyệt</>');
        $escrow = app(EscrowService::class);
        $workflow = app(PartnerContractWorkflowService::class);
        $rows = [];

        Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->where('verification_status', 'approved')
            ->where('id', '!=', \Modules\Minihouse\App\Support\HomestayBridge::PARTNER_ID)->orderBy('name')->get()->each(function (Partner $partner) use (&$rows, $escrow, $workflow) {
                $todo = [];
                if ($workflow->commissionValue($partner->commission_rate) === null) {
                    $todo[] = 'nhập tỉ lệ hoa hồng';
                }
                if (blank($partner->bank_account_holder)) {
                    $todo[] = 'nhập chủ tài khoản ngân hàng (tab Tài chính)';
                }
                if (! $partner->usesDirectPayment()) {
                    $todo[] = $partner->contract_status === 'active' ? 'tạo & ký phụ lục ký quỹ' : 'tạo & ký hợp đồng mẫu mới';
                } else {
                    if (! PayOsAccountResolver::partnerAccountFor($partner->id)) {
                        $todo[] = 'cấu hình kênh PayOS (đang 365home thu hộ)';
                    }
                    if (! $partner->escrow_min_amount) {
                        $todo[] = 'đặt mức ký quỹ';
                    }
                }

                $rows[] = [
                    $partner->legal_name ?: $partner->name,
                    $partner->usesDirectPayment() ? 'Luồng mới từ ' . $partner->payment_flow_effective_at->format('d/m/Y') . ($partner->fee_free_until ? ($partner->isInFeeFreePeriod() ? ' (miễn phí đến ' : ' (đã hết miễn phí ') . $partner->fee_free_until->format('d/m/Y') . ')' : '') : 'Đang thu hộ (cũ)',
                    EscrowService::STATES[$escrow->state($partner)] ?? '—',
                    $todo === [] ? '✓ đủ' : implode('; ', $todo),
                ];
            });

        $rows === [] ? $this->line('  (chưa có đối tác Homestay nào được duyệt)') : $this->table(['Đối tác', 'Luồng tiền', 'Ký quỹ', 'Việc cần làm'], $rows);
    }
}
