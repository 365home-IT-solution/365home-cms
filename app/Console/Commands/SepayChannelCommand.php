<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Services\Payment\PartnerBankChannelService;
use App\Services\Payment\PayOsAccountResolver;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

// Cấu hình nhanh kênh chuyển khoản (VietQR + SePay) của 1 đối tác từ dòng lệnh — cùng quy tắc với API
// POST /api/admin/partners/{partner}/bank-channel (PartnerBankChannelService). In ra khoá webhook để dán vào SePay.
class SepayChannelCommand extends Command
{
    protected $signature = 'sepay:channel
        {partner : ID đối tác}
        {--from-financial : Dùng đúng tài khoản ở tab Tài chính của đối tác (mặc định khi tạo mới mà không truyền --bank/--account)}
        {--bank= : Mã ngân hàng trong config/banks.php (MB, VCB, ACB, TCB...)}
        {--account= : Số tài khoản nhận tiền (đúng tài khoản đã liên kết SePay)}
        {--holder= : Tên chủ tài khoản (mặc định lấy ở tab Tài chính)}
        {--disable : Tắt kênh}
        {--new-key : Cấp khoá webhook mới (khoá cũ hết hiệu lực)}
        {--skip-holder-check : Bỏ đối chiếu chủ tài khoản với tab Tài chính (chỉ dùng khi test)}
        {--enable-direct-payment : Đặt mốc hợp đồng thu trực tiếp (payment_flow_effective_at) nếu đối tác chưa có — bắt buộc để kênh được dùng}';

    protected $description = 'Tạo/sửa kênh chuyển khoản SePay của đối tác và in khoá webhook';

    public function handle(PartnerBankChannelService $channels): int
    {
        $partner = Partner::find($this->argument('partner'));

        if (! $partner) {
            $this->error('Không tìm thấy đối tác.');

            return self::FAILURE;
        }

        $data = array_filter([
            'use_financial_account' => $this->option('from-financial') ?: null,
            'bank_code'             => $this->option('bank') ? strtoupper($this->option('bank')) : null,
            'account_number'        => $this->option('account') ? trim($this->option('account')) : null,
            'account_holder'        => $this->option('holder'),
        ], fn ($value) => $value !== null) + ['is_active' => ! $this->option('disable')];

        try {
            $channel = $channels->save($partner, $data, enforceHolder: ! $this->option('skip-holder-check'));

            if ($this->option('new-key') && ! $channel->wasRecentlyCreated) {
                $channel = $channels->regenerateKey($partner);
            }
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(PHP_EOL));

            return self::FAILURE;
        }

        if ($this->option('enable-direct-payment') && $partner->payment_flow_effective_at === null) {
            $partner->forceFill(['payment_flow_effective_at' => now()])->save();
        }

        $this->table(['', ''], [
            ['Đối tác', $partner->name],
            ['Ngân hàng', ($channel->bank()['short_name'] ?? $channel->bank_code) . ' — ' . $channel->account_number . ' — ' . ($channel->account_holder ?: '(chưa có tên)')],
            ['Khớp tab Tài chính', $channels->matchesFinancial($partner, $channel) ? 'Có' : 'Không (tài khoản khác, cùng chủ tài khoản)'],
            ['Trạng thái', $channel->is_active ? 'Bật' : 'Tắt'],
            ['Đang dùng nhận tiền', PayOsAccountResolver::bankChannelFor($partner->id) ? 'Có' : 'Không (kênh tắt hoặc chưa có mốc hợp đồng thu trực tiếp — thêm --enable-direct-payment)'],
            ['Webhook URL', route('api.webhook.sepay')],
            ['API Key (dán vào SePay)', $channel->webhook_key],
        ]);

        $this->table(['Chi nhánh', 'Tiền đặt phòng về'], collect($channels->branchDestinations($partner))
            ->map(fn (array $b) => [$b['name'], $b['label'] . (isset($b['account_number']) ? " ({$b['bank_name']} {$b['account_number']})" : '')])->all());

        return self::SUCCESS;
    }
}
