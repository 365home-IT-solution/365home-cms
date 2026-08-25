<?php

declare(strict_types=1);

namespace App\Services\ContractSigning;

use App\Models\ContractSigningToken;
use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

// Theo tài liệu chính thức "DỊCH VỤ VNPT-SmartCA — Tài liệu mô tả và hướng dẫn tích hợp" v1.1
// (2024) — chuẩn CSC (Cloud Signature Consortium) quốc tế, KHÁC với API "sp769" (bản nháp trước
// đã thử, cần gửi password/OTP mỗi lần ký). API này:
//   1. OAuth2 (Resource Owner Password Credentials) — đăng nhập 1 LẦN bằng client_id/client_secret
//      + tài khoản thuê bao (uid/password) để lấy access_token + refresh_token (sống ~3 tháng).
//      Các lần ký sau chỉ cần refresh_token, KHÔNG cần gửi lại mật khẩu thuê bao.
//   2. csc/signature/signhash — gửi hash cần ký, không cần OTP/password gì thêm. VNPT sẽ gửi
//      THÔNG BÁO ĐẨY (push notification) tới app SmartCA của thuê bao — thuê bao chỉ cần MỞ ĐIỆN
//      THOẠI BẤM XÁC NHẬN (không gõ mã gì cả).
//   3. csc/credentials/gettraninfo — poll tới khi giao dịch được xác nhận xong (hoặc hết hạn/từ
//      chối), mới lấy được chữ ký thật.
class VnptSmartCaProvider implements DigitalSignatureProvider
{
    // Tổng thời gian tối đa chờ thuê bao mở điện thoại bấm xác nhận trước khi báo lỗi timeout.
    private const MAX_WAIT_SECONDS = 90;
    private const POLL_INTERVAL_SECONDS = 3;

    public function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly ?string $subscriberUserId,
        private readonly ?string $subscriberPassword,
    ) {
    }

    public function name(): string
    {
        return 'vnpt_smartca';
    }

    // Lấy chứng thư số của thuê bao — KHÔNG gọi signhash nên KHÔNG tốn lượt ký thật, chỉ gọi
    // csc/credentials/list + csc/credentials/info (API tra cứu, không tính phí theo lượt ký).
    public function certificate(array $signerContext): array
    {
        $this->ensureConfigured();

        $accessToken = $this->getValidAccessToken();
        $credentialId = $this->getCredentialId($accessToken);

        $certData = $this->fetchCertificateData($accessToken, $credentialId);

        if (blank($certData)) {
            throw new RuntimeException('Không lấy được chứng thư số của thuê bao (csc/credentials/info).');
        }

        return ['cert_data' => $certData];
    }

    public function sign(string $contentHash, array $signerContext): SignatureResult
    {
        $this->ensureConfigured();

        $accessToken = $this->getValidAccessToken();
        $credentialId = $this->getCredentialId($accessToken);

        // content_hash trong dự án là hash('sha256', $content) dạng HEX — API này yêu cầu base64
        // của đúng 32 byte digest gốc, không phải base64 hoá chuỗi hex dạng text.
        $hashBase64 = base64_encode(hex2bin($contentHash));
        $refTranId = (string) Str::uuid();

        $signResponse = Http::withToken($accessToken)
            ->post("{$this->baseUrl}/csc/signature/signhash", [
                'credentialId' => $credentialId,
                'refTranId'    => $refTranId,
                'description'  => $signerContext['transaction_desc'] ?? 'Ký hợp đồng đối tác',
                'datas'        => [
                    [
                        'name' => 'contract.pdf',
                        'hash' => $hashBase64,
                    ],
                ],
            ])->throw();

        $signData = $signResponse->json();
        $this->assertNoBusinessError($signData);

        $tranId = $signData['content']['tranId'] ?? null;
        if (blank($tranId)) {
            throw new RuntimeException('VNPT SmartCA không trả về tranId sau khi gửi yêu cầu ký.');
        }

        return $this->waitForConfirmation($accessToken, $credentialId, $tranId, $signerContext);
    }

    // Poll trạng thái giao dịch tới khi thuê bao xác nhận xong trên app (hoặc timeout/từ chối).
    private function waitForConfirmation(string $accessToken, string $credentialId, string $tranId, array $signerContext): SignatureResult
    {
        $deadline = time() + self::MAX_WAIT_SECONDS;

        while (true) {
            $statusResponse = Http::withToken($accessToken)
                ->post("{$this->baseUrl}/csc/credentials/gettraninfo", [
                    'tranId' => $tranId,
                ])->throw();

            $statusData = $statusResponse->json();
            $this->assertNoBusinessError($statusData);

            $tranStatus = $statusData['content']['tranStatus'] ?? null;

            if ($tranStatus === 1) {
                $document = $statusData['content']['documents'][0] ?? null;
                $signatureValue = $document['sig'] ?? $document['dataSigned'] ?? null;

                if (blank($signatureValue)) {
                    throw new RuntimeException('VNPT SmartCA báo ký thành công nhưng không trả về chữ ký.');
                }

                return new SignatureResult(
                    signature: (string) $signatureValue,
                    certificate: [
                        // cert_data (base64 DER) — CẦN để verify() sau này, không có thì không xác
                        // minh offline được (openssl_verify cần public key trích từ đúng chứng thư
                        // đã ký, không thể verify chỉ bằng subscriber_id).
                        'cert_data'      => $this->fetchCertificateData($accessToken, $credentialId),
                        'subscriber_id'  => $this->subscriberUserId,
                        'transaction_id' => $tranId,
                        'role'           => $signerContext['role'] ?? null,
                        'signer_name'    => $signerContext['name'] ?? null,
                        'signed_at'      => now()->toIso8601String(),
                    ],
                );
            }

            $failureMessages = [
                4001 => 'Giao dịch ký số đã hết hạn (thuê bao không xác nhận kịp thời).',
                4002 => 'Thuê bao đã TỪ CHỐI xác nhận giao dịch ký số trên điện thoại.',
                4003 => 'Xác thực khoá ký thất bại (AUTHORIZE_KEY_FAILED).',
                4004 => 'Ký số thất bại (SIGN_FAILED).',
            ];

            if (isset($failureMessages[$tranStatus])) {
                throw new RuntimeException($failureMessages[$tranStatus]);
            }

            // 4000 = WAITING_FOR_SIGNER_CONFIRM — vẫn đang chờ thuê bao mở điện thoại bấm xác nhận.
            if (time() >= $deadline) {
                throw new RuntimeException(
                    'Hết thời gian chờ (' . self::MAX_WAIT_SECONDS . 's) — thuê bao chưa xác nhận trên '
                    . 'app SmartCA. Vui lòng mở điện thoại, bấm xác nhận thông báo ký số rồi thử lại.'
                );
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    // Tài liệu CSC không có API "verify" riêng — verify OFFLINE bằng chứng thư X.509 thật (cert_data
    // lưu kèm SignatureResult lúc sign(), xem fetchCertificateData()).
    //
    // QUAN TRỌNG: signhash trả về chữ ký RSA ký TRỰC TIẾP lên digest (PKCS#1 v1.5 "sign hash", theo
    // đúng chuẩn CSC signhash) — KHÔNG dùng openssl_verify() thông thường được, vì hàm đó tự băm lại
    // dữ liệu truyền vào bằng thuật toán chỉ định rồi mới so khớp (sẽ băm 2 LẦN nếu truyền thẳng
    // hash vào, sai hoàn toàn). Cách đúng: openssl_public_decrypt() để "giải mã" (thật ra là tháo
    // đệm PKCS#1) chữ ký bằng public key, kết quả là cấu trúc DigestInfo (ASN.1) — so khớp phần hash
    // bên trong với content_hash gốc.
    public function verify(string $contentHash, string $signature, array $certificate): bool
    {
        $certData = $certificate['cert_data'] ?? null;
        if (blank($certData)) {
            return false;
        }

        $pem = $this->derBase64ToPem($certData);
        $publicKey = openssl_pkey_get_public($pem);
        if (! $publicKey) {
            return false;
        }

        $decrypted = '';
        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            return false;
        }

        if (! openssl_public_decrypt($decoded, $decrypted, $publicKey, OPENSSL_PKCS1_PADDING)) {
            return false;
        }

        // DigestInfo chuẩn cho SHA-256 (RFC 3447, mục 9.2) — prefix ASN.1 cố định + 32 byte hash.
        $sha256DigestInfoPrefix = hex2bin('3031300d060960864801650304020105000420');
        $expected = $sha256DigestInfoPrefix . hex2bin($contentHash);

        return hash_equals($expected, $decrypted);
    }

    private function derBase64ToPem(string $certDataBase64): string
    {
        // VNPT trả cert_data kèm \r\n thừa ở cuối chuỗi base64 (đã xác nhận qua debug thật) — phải
        // loại bỏ MỌI khoảng trắng trước khi tự chia dòng lại 64 ký tự/dòng, nếu không PEM sinh ra
        // bị lỗi "bad end line" (openssl không parse được, verify() luôn trả về false).
        $clean = preg_replace('/\s+/', '', $certDataBase64);
        $chunks = chunk_split($clean, 64, "\n");

        return "-----BEGIN CERTIFICATE-----\n{$chunks}-----END CERTIFICATE-----\n";
    }

    // Lấy chứng thư X.509 (base64 DER) của thuê bao — cần lưu kèm chữ ký để verify() sau này.
    private function fetchCertificateData(string $accessToken, string $credentialId): ?string
    {
        try {
            $response = Http::withToken($accessToken)
                ->post("{$this->baseUrl}/csc/credentials/info", [
                    'credentialId' => $credentialId,
                    'certificates' => 'single',
                    'certInfo'     => true,
                ])->throw();

            return $response->json('cert.certificates.0');
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    // Lấy credentialId của thuê bao — thuê bao cá nhân chỉ có đúng 1 credential nên lấy phần tử
    // đầu tiên trong danh sách, không cần cấu hình thêm.
    private function getCredentialId(string $accessToken): string
    {
        $response = Http::withToken($accessToken)
            ->post("{$this->baseUrl}/csc/credentials/list", (object) [])
            ->throw();

        $data = $response->json();
        $this->assertNoBusinessError($data);

        $credentialId = $data['content'][0] ?? null;

        if (blank($credentialId)) {
            throw new RuntimeException('Không tìm thấy credential nào cho thuê bao VNPT SmartCA này.');
        }

        return $credentialId;
    }

    // Trả về access_token còn hiệu lực — tự làm mới bằng refresh_token nếu hết hạn; nếu chưa từng
    // đăng nhập lần nào (chưa có token trong DB) hoặc refresh_token cũng hết hạn (>3 tháng không
    // dùng), đăng nhập lại bằng Resource Owner Password Credentials (cần subscriber_password).
    private function getValidAccessToken(): string
    {
        $token = ContractSigningToken::current($this->name());

        if ($token && ! $token->isExpired()) {
            return $token->access_token;
        }

        if ($token && filled($token->refresh_token)) {
            try {
                return $this->refreshToken($token);
            } catch (\Throwable $e) {
                // refresh_token cũng có thể đã hết hạn (>3 tháng) — rơi về đăng nhập lại bên dưới.
            }
        }

        return $this->loginWithPassword();
    }

    private function refreshToken(ContractSigningToken $token): string
    {
        $response = Http::asForm()->post("{$this->baseUrl}/auth/token", [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $token->refresh_token,
        ])->throw();

        $data = $response->json();

        $token->update([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
            'expires_at'    => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ]);

        return $token->access_token;
    }

    private function loginWithPassword(): string
    {
        if (blank($this->subscriberPassword)) {
            throw new RuntimeException(
                'Chưa có access_token còn hiệu lực và thiếu VNPT_SMARTCA_SUBSCRIBER_PASSWORD để đăng '
                . 'nhập lại — điền mật khẩu thuê bao vào .env (chỉ cần đúng 1 lần, refresh_token sống '
                . '~3 tháng cho các lần ký sau).'
            );
        }

        $response = Http::asForm()->post("{$this->baseUrl}/auth/token", [
            'grant_type'    => 'password',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username'      => $this->subscriberUserId,
            'password'      => $this->subscriberPassword,
        ])->throw();

        $data = $response->json();

        ContractSigningToken::updateOrCreate(
            ['provider' => $this->name()],
            [
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at'    => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            ]
        );

        return $data['access_token'];
    }

    private function assertNoBusinessError(?array $body): void
    {
        $code = $body['code'] ?? null;

        if ($code !== null && (int) $code !== 0) {
            throw new RuntimeException(
                'VNPT SmartCA trả lỗi: ' . ($body['message'] ?? $body['codeDesc'] ?? 'unknown') . " (code={$code})"
            );
        }
    }

    private function ensureConfigured(): void
    {
        if (blank($this->baseUrl) || blank($this->clientId) || blank($this->clientSecret) || blank($this->subscriberUserId)) {
            throw new RuntimeException(
                'VNPT SmartCA chưa được cấu hình đủ — cần điền VNPT_SMARTCA_BASE_URL/CLIENT_ID/'
                . 'CLIENT_SECRET và VNPT_SMARTCA_SUBSCRIBER_USER_ID trong .env.'
            );
        }
    }
}
