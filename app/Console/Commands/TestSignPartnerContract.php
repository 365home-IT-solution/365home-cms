<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PartnerContractVersion;
use App\Services\ContractSigning\ContractSigningManager;
use App\Services\PartnerContractCksService;
use App\Services\PdfSigning\PdfPkiSigner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

// CHỈ ĐỂ TEST luồng "đối tác ký bằng chữ ký số (CKS)" khi chỉ có MỘT tài khoản chữ ký số: dùng chính nhà cung cấp đã cấu hình cho Homestay
// (Cấu hình web > Chữ ký số) ký PDF hợp đồng thay cho đối tác rồi đưa qua ĐÚNG bước kiểm tra như khi đối tác tải file lên (chữ ký, nội dung nguyên vẹn,
// chứng thư, MST/CCCD khớp hồ sơ). Không dùng cho đối tác thật — đối tác thật phải ký bằng chứng thư của chính họ.
class TestSignPartnerContract extends Command
{
    protected $signature = 'contract:test-sign-partner {token : Mã trong link ký (phần cuối của /hop-dong/ky/{token})} {--force : Cho phép chạy ở môi trường production}';

    protected $description = 'TEST: ký PDF hợp đồng thay đối tác bằng nhà cung cấp chữ ký số đã cấu hình (chỉ để thử luồng CKS của đối tác)';

    public function handle(PartnerContractCksService $cks, PdfPkiSigner $signer, ContractSigningManager $manager): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Lệnh này chỉ để test. Môi trường đang là production — thêm --force nếu bạn chắc chắn (đang test trên server).');

            return self::FAILURE;
        }

        $version = PartnerContractVersion::query()->where('signing_token', $this->argument('token'))->with('partner')->first();
        if (! $version) {
            $this->error('Không tìm thấy hợp đồng theo mã này (link đã hết hiệu lực hoặc nhập sai).');

            return self::FAILURE;
        }

        try {
            $this->line('Đối tác: ' . ($version->partner->legal_name ?: $version->partner->name));
            $pdf = $cks->preparePdf($version)['pdf'];

            $provider = $manager->forSide(ContractSigningManager::SIDE_HOMESTAY);
            $this->info('Đang ký bằng nhà cung cấp: ' . $provider->name() . ' — nếu là VNPT SmartCA, hãy mở app và bấm xác nhận.');
            if ($provider->name() === 'local') {
                $this->warn('CẢNH BÁO: nhà cung cấp đang là "local" (khoá tự sinh, không gửi xác nhận về app, không có giá trị pháp lý). Chọn VNPT SmartCA ở Cấu hình web › Chữ ký số để ký thật.');
            }
            $signed = $signer->sign($pdf, $provider, ['role' => 'partner', 'name' => $version->partner->legal_name ?: $version->partner->name]);

            $result = $cks->submit($version, $signed['pdf'], null, '127.0.0.1', 'artisan contract:test-sign-partner');
        } catch (ValidationException $e) {
            $this->error('Bị từ chối: ' . collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Không ký được: ' . $e->getMessage());

            return self::FAILURE;
        }

        $cert = $result['certificate'];
        $this->info('Đã ghi nhận đối tác ký bằng CKS.');
        $this->table(['Chủ thể', 'Tổ chức', 'CA', 'MST', 'CCCD', 'Hết hạn'], [[
            $cert['cert_subject'] ?? '—', $cert['cert_org'] ?? '—', $cert['cert_issuer'] ?? '—', $cert['tax_code'] ?? '—', $cert['citizen_id'] ?? '—', $cert['valid_to'] ?? '—',
        ]]);

        return self::SUCCESS;
    }
}
