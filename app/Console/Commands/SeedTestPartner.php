<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Models\User;
use App\Services\EscrowService;
use App\Services\PartnerContractWorkflowService;
use App\Services\PartnerLegalDocumentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Tạo 1 đối tác Homestay "bình thường" đi qua ĐÚNG luồng hợp đồng hiện tại để thử SePay: tạo hồ sơ → tạo hợp đồng (mẫu theo tỉnh/thành) →
 * xác nhận đã ký tay hai bên → hợp đồng có hiệu lực, luồng tiền mới (tiền về thẳng đối tác) bật, tài khoản chủ đối tác được tạo.
 *
 * Cơ sở NGOÀI Cần Thơ dùng mẫu VT+ĐL: không có khoản tiền đảm bảo/ký quỹ nên không bị khoá bán (mặc định tỉnh 79 = TP.HCM).
 * Cơ sở trong Cần Thơ (92) dùng mẫu CT: phải nộp tiền đảm bảo trước khi bán.
 * Bước duyệt hồ sơ pháp lý (giấy tờ) được bỏ qua vì đây là đối tác thử; không gửi email thật.
 */
class SeedTestPartner extends Command
{
    protected $signature = 'sample:partner-test
        {name=Đối tác thử SePay : Tên đối tác}
        {--email= : Email chủ đối tác (mặc định tự sinh)}
        {--province=79 : Mã tỉnh/thành của cơ sở (79 = TP.HCM, 92 = Cần Thơ có ký quỹ)}
        {--password= : Mật khẩu đăng nhập cho chủ đối tác (mặc định tự sinh, in ra)}
        {--send-mail : Gửi email thật (mặc định không gửi)}
        {--dry-run : Chạy thử rồi huỷ, không lưu}';

    protected $description = 'Tạo đối tác Homestay thử qua luồng hợp đồng (ký tay xác nhận), mẫu VT+ĐL không ký quỹ';

    public function handle(): int
    {
        $admin = User::role('super_admin')->first();
        if (! $admin) {
            $this->error('Không có tài khoản super_admin.');

            return self::FAILURE;
        }

        if (! $this->option('send-mail')) {
            Mail::fake();
        }

        // Đối tác thử không có hồ sơ pháp lý: bỏ qua riêng bước kiểm tra "hồ sơ đã được duyệt" (mọi bước khác của luồng hợp đồng giữ nguyên).
        $this->laravel->instance(PartnerLegalDocumentService::class, new class extends PartnerLegalDocumentService
        {
            public function __construct() {}

            public function assertContractEligible(Partner $partner): void {}

            public function isContractEligible(Partner $partner): bool
            {
                return true;
            }

            public function canPartnerSign(Partner $partner): bool
            {
                return true;
            }
        });

        $name = (string) $this->argument('name');
        $email = (string) ($this->option('email') ?: 'sepay-test-' . Str::lower(Str::random(6)) . '@example.test');

        if (User::query()->where('email', $email)->exists() || Partner::query()->where('email', $email)->exists()) {
            $this->error("Email {$email} đã tồn tại — dùng --email khác.");

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            $partner = Partner::create([
                'partner_type' => Partner::TYPE_HOMESTAY, 'name' => $name, 'legal_name' => $name, 'common_name' => $name,
                'representative_name' => 'Nguyễn Văn Thử', 'representative_id_number' => '079200012345', 'representative_id_issued_at' => '2020-01-01',
                'phone' => '0970' . random_int(100000, 999999), 'email' => $email, 'address' => '12 Lê Lợi, Quận 1, TP. Hồ Chí Minh',
                'province_code' => (int) $this->option('province'), 'commission_rate' => '20',
                'status' => false, 'verification_status' => 'approved', 'contract_status' => 'pending', 'created_by' => $admin->id,
            ]);

            $workflow = app(PartnerContractWorkflowService::class);
            $workflow->createVersion($partner, $admin, 'Hợp đồng — ' . now()->format('d/m/Y H:i'), 'Tạo bằng lệnh sample:partner-test');
            $result = $workflow->confirmHandSigned($partner->fresh(), $admin, null, 'Nguyễn Văn Thử', [], '127.0.0.1', 'artisan sample:partner-test');

            $partner = $partner->fresh();
            $password = (string) ($this->option('password') ?: Str::password(12, symbols: false));
            $owner = $partner->users()->first();

            if ($owner) {
                $owner->forceFill(['password' => Hash::make($password)])->save();
            }

            $escrow = app(EscrowService::class)->summary($partner);

            $this->info("Đối tác: {$partner->name} ({$partner->id})");
            $this->line('  Mẫu hợp đồng : ' . ($partner->hasNoDeposit() ? 'VT+ĐL — không có khoản tiền đảm bảo/ký quỹ' : 'CT — có khoản tiền đảm bảo'));
            $this->line('  Hợp đồng     : ' . $partner->contract_status . ($partner->contract_signed_at ? ' (ký ' . $partner->contract_signed_at->format('d/m/Y') . ')' : ''));
            $this->line('  Luồng tiền mới: ' . ($partner->usesDirectPayment() ? 'ĐÃ BẬT (tiền về thẳng đối tác)' : 'chưa bật'));
            $this->line('  Ký quỹ       : ' . $escrow['status_label'] . ' — bán online ' . ($escrow['sales_suspended'] ? 'BỊ KHOÁ' : 'được'));
            $this->line('  Tài khoản    : ' . ($owner ? "{$owner->email} / mật khẩu: {$password}" : ('KHÔNG tạo được — ' . ($result['account']['reason'] ?? ''))));

            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->warn('Chạy thử: đã huỷ, không lưu gì.');
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
