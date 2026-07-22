<?php

declare(strict_types=1);

namespace App\Services\ContractSigning\Contracts;

use App\Services\ContractSigning\SignatureResult;

// Trừu tượng hoá "ký số 1 hash nội dung hợp đồng" — cho phép đổi nhà cung cấp (local test,
// VNPT SmartCA, MISA...) chỉ bằng cách đổi config/contract_signing.php + biến môi trường, KHÔNG
// phải sửa lại ContractSignController/PartnerForm. Xem LocalSelfSignedProvider (dùng ngay lúc dev,
// không cần đăng ký gì) và VnptSmartCaProvider (khung sẵn, điền client_id/secret thật khi có MST).
interface DigitalSignatureProvider
{
    // Tên định danh provider — lưu vào cột 'signing_provider' để biết bản ghi cũ ký bằng gì.
    public function name(): string;

    // Ký lên $contentHash (sha256 của nội dung hợp đồng, xem PartnerContractVersion::content_hash)
    // — $signerContext chứa thông tin người ký (vd ['role' => 'partner'|'platform', 'name' => ...,
    // 'partner_id' => ..., 'user_id' => ...]) để provider thật (VNPT SmartCA) biết ký bằng chứng
    // thư của ai. Trả về chữ ký (base64) + thông tin chứng thư đã dùng.
    public function sign(string $contentHash, array $signerContext): SignatureResult;

    // Xác minh 1 chữ ký đã ký trước đó còn khớp với $contentHash hay không (dùng lại thông tin
    // certificate đã lưu lúc ký, KHÔNG tự ý tin theo giá trị 'valid' cũ trong DB).
    public function verify(string $contentHash, string $signature, array $certificate): bool;

    // Lấy thông tin chứng thư số (bắt buộc có 'cert_data' — base64 DER) của người/tổ chức SẼ ký,
    // KHÔNG thực hiện hành động ký nào (không tốn lượt ký thật) — cần biết TRƯỚC nội dung chứng thư
    // để dựng đúng cấu trúc CMS/PAdES (IssuerAndSerialNumber) trước khi gửi hash đi ký thật (xem
    // ContractPdfSigningService — phải biết chứng thư trước khi build signedAttrs cần ký).
    public function certificate(array $signerContext): array;
}
