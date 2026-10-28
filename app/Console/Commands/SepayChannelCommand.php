<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Services\Payment\PayOsAccountResolver;
use App\Support\Banks;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Payment\Entities\PartnerBankChannel;

// Cấu hình nhanh kênh chuyển khoản (VietQR + SePay) của 1 đối tác từ dòng lệnh — cùng dữ liệu với API
// POST /api/admin/partners/{partner}/bank-channel (PartnerBankChannelController). In ra khoá webhook để dán vào SePay.
class SepayChannelCommand extends Command
{
    protected $signature = 'sepay:channel
        {partner : ID đối tác}
        {--bank= : Mã ngân hàng trong config/banks.php (MB, VCB, ACB, TCB...)}
        {--account= : Số tài khoản nhận tiền (đúng tài khoản đã liên kết SePay)}
        {--holder= : Tên chủ tài khoản}
        {--disable : Tắt kênh}
        {--new-key : Cấp khoá webhook mới (khoá cũ hết hiệu lực)}
        {--enable-direct-payment : Đặt mốc hợp đồng thu trực tiếp (payment_flow_effective_at) nếu đối tác chưa có — bắt buộc để kênh được dùng}';

    protected $description = 'Tạo/sửa kênh chuyển khoản SePay của đối tác và in khoá webhook';

    public function handle(): int
    {
        $partner = Partner::find($this->argument('partner'));

        if (! $partner) {
            $this->error('Không tìm thấy đối tác.');

            return self::FAILURE;
        }

        $bank = $this->option('bank') !== null ? Banks::find($this->option('bank')) : null;

        if ($this->option('bank') !== null && ! $bank) {
            $this->error('Mã ngân hàng không có trong config/banks.php: ' . implode(', ', Banks::codes()));

            return self::FAILURE;
        }

        $channel = $partner->bankChannel ?? new PartnerBankChannel(['partner_id' => $partner->id, 'is_active' => true]);

        if (! $channel->exists && (! $bank || blank($this->option('account')))) {
            $this->error('Kênh mới cần --bank và --account.');

            return self::FAILURE;
        }

        if ($bank) {
            $channel->bank_code = $bank['code'];
        }
        if (filled($this->option('account'))) {
            $channel->account_number = trim($this->option('account'));
        }
        if (filled($this->option('holder'))) {
            $channel->account_holder = Str::upper(Str::ascii(trim($this->option('holder'))));
        }
        $channel->is_active = ! $this->option('disable');

        if (! $channel->exists || $this->option('new-key')) {
            $key = Str::random(48);
            $channel->webhook_key = $key;
            $channel->webhook_key_hash = PartnerBankChannel::hashKey($key);
        }

        $channel->save();

        if ($this->option('enable-direct-payment') && $partner->payment_flow_effective_at === null) {
            $partner->forceFill(['payment_flow_effective_at' => now()])->save();
        }

        $this->table(['', ''], [
            ['Đối tác', $partner->name],
            ['Ngân hàng', ($channel->bank()['short_name'] ?? $channel->bank_code) . ' — ' . $channel->account_number . ' — ' . ($channel->account_holder ?: '(chưa có tên)')],
            ['Trạng thái', $channel->is_active ? 'Bật' : 'Tắt'],
            ['Đang dùng nhận tiền', PayOsAccountResolver::bankChannelFor($partner->id) ? 'Có' : 'Không (kênh tắt hoặc chưa có mốc hợp đồng thu trực tiếp — thêm --enable-direct-payment)'],
            ['Webhook URL', route('api.webhook.sepay')],
            ['API Key (dán vào SePay)', $channel->webhook_key],
        ]);

        return self::SUCCESS;
    }
}
