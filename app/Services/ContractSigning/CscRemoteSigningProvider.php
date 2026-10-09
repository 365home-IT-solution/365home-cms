<?php

declare(strict_types=1);

namespace App\Services\ContractSigning;

use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Ký số từ xa theo chuẩn quốc tế CLOUD SIGNATURE CONSORTIUM (CSC API v1): oauth2/token → credentials/list → credentials/info (lấy chứng thư)
// → credentials/authorize (lấy SAD) → signatures/signHash. Dùng chung cho các nhà cung cấp CA từ xa có mở API chuẩn CSC cho đối tác
// (MISA eSign, Viettel mySign, FPT, Savis...): mỗi nhà cung cấp là 1 "hồ sơ" (name + base_url + thông tin đăng nhập) — thêm nhà cung cấp mới
// chỉ là thêm 1 hồ sơ, không viết lại luồng ký. VNPT SmartCA có biến thể riêng (VnptSmartCaProvider — tự xác nhận trên app thuê bao).
//
// LƯU Ý: các nhà cung cấp chỉ cấp tài liệu/tài khoản tích hợp cho đối tác đã ký hợp đồng — đường dẫn và kiểu xác thực (grant_type, PIN/OTP) có thể
// khác nhau đôi chút nên đều cấu hình được ở hồ sơ. Luồng đã được kiểm bằng test giả lập theo đúng đặc tả CSC, CHƯA kiểm với hệ thống thật của
// từng nhà cung cấp — dùng nút "Kiểm tra kết nối" ở trang Chữ ký số trước khi đưa vào sử dụng.
class CscRemoteSigningProvider implements DigitalSignatureProvider
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config base_url, client_id, client_secret, username, password, credential_id?, pin?, grant_type?, token_path?, hash_algo?, sign_algo? */
    public function __construct(private readonly string $providerName, array $config)
    {
        $this->config = $config + [
            'grant_type' => 'client_credentials',
            'token_path' => '/oauth2/token',
            'hash_algo'  => '2.16.840.1.101.3.4.2.1',      // SHA-256
            'sign_algo'  => '1.2.840.113549.1.1.1',        // rsaEncryption (RSSP ký PKCS#1 v1.5 trên hash đã cho)
        ];
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function certificate(array $signerContext): array
    {
        $this->ensureConfigured();

        $token = $this->accessToken();
        $credentialId = $this->credentialId($token);
        $cert = $this->fetchCertificate($token, $credentialId);

        if (blank($cert)) {
            throw new RuntimeException('Không lấy được chứng thư số của thuê bao (credentials/info).');
        }

        return ['cert_data' => $cert, 'credential_id' => $credentialId];
    }

    public function sign(string $contentHash, array $signerContext): SignatureResult
    {
        $this->ensureConfigured();

        $token = $this->accessToken();
        $credentialId = $this->credentialId($token);
        $hashBase64 = base64_encode((string) hex2bin($contentHash));

        // Lấy SAD (Signature Activation Data) — nhà cung cấp có thể gửi OTP/push xác nhận tới thuê bao ở bước này (PIN nếu có được gửi kèm).
        $authorize = Http::withToken($token)->post($this->url('/credentials/authorize'), array_filter([
            'credentialID' => $credentialId,
            'numSignatures' => 1,
            'hash' => [$hashBase64],
            'PIN' => $this->config['pin'] ?? null,
            'description' => $signerContext['transaction_desc'] ?? 'Ky hop dong',
        ], fn ($value) => $value !== null))->throw();
        $sad = $authorize->json('SAD');

        $signed = Http::withToken($token)->post($this->url('/signatures/signHash'), array_filter([
            'credentialID' => $credentialId,
            'SAD' => $sad,
            'hash' => [$hashBase64],
            'hashAlgo' => $this->config['hash_algo'],
            'signAlgo' => $this->config['sign_algo'],
        ], fn ($value) => $value !== null))->throw();

        $signature = $signed->json('signatures.0');
        if (blank($signature)) {
            throw new RuntimeException("{$this->providerName} không trả chữ ký (signatures/signHash): " . (string) ($signed->json('error_description') ?? $signed->json('error') ?? 'không rõ lỗi'));
        }

        return new SignatureResult((string) $signature, $this->certificate($signerContext));
    }

    public function verify(string $contentHash, string $signature, array $certificate): bool
    {
        $certData = $certificate['cert_data'] ?? null;
        $decoded = base64_decode($signature, true);
        if (blank($certData) || $decoded === false) {
            return false;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) preg_replace('/\s+/', '', $certData), 64, "\n") . "-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($pem);
        if (! $publicKey) {
            return false;
        }

        $decrypted = '';
        if (! openssl_public_decrypt($decoded, $decrypted, $publicKey, OPENSSL_PKCS1_PADDING)) {
            return false;
        }

        // DigestInfo chuẩn cho SHA-256 (RFC 3447, mục 9.2) + 32 byte hash.
        return hash_equals(hex2bin('3031300d060960864801650304020105000420') . hex2bin($contentHash), $decrypted);
    }

    /**
     * Kiểm tra cấu hình: đăng nhập + tra chứng thư — KHÔNG gọi authorize/signHash nên không tốn lượt ký, không gửi xác nhận về điện thoại.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $this->ensureConfigured();
            $token = $this->accessToken(fresh: true);
            $cert = $this->fetchCertificate($token, $this->credentialId($token));

            return ['ok' => true, 'message' => "Kết nối {$this->providerName} thành công" . ($cert ? ' — đã lấy được chứng thư số của thuê bao.' : ' (chưa lấy được chứng thư số).')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Kết nối thất bại: ' . $e->getMessage()];
        }
    }

    // ───────────────────────── Nội bộ ─────────────────────────

    private function url(string $path): string
    {
        return rtrim((string) $this->config['base_url'], '/') . '/' . ltrim($path, '/');
    }

    private function ensureConfigured(): void
    {
        foreach (['base_url', 'client_id', 'client_secret'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                throw new RuntimeException("Chưa cấu hình chữ ký số {$this->providerName}: thiếu {$key}.");
            }
        }
        if ($this->config['grant_type'] === 'password' && (blank($this->config['username'] ?? null) || blank($this->config['password'] ?? null))) {
            throw new RuntimeException("Chưa cấu hình chữ ký số {$this->providerName}: thiếu tài khoản thuê bao.");
        }
    }

    private function accessToken(bool $fresh = false): string
    {
        $key = 'csc_token:' . $this->providerName . ':' . md5((string) ($this->config['client_id'] ?? '') . '|' . (string) ($this->config['username'] ?? '') . '|' . (string) ($this->config['base_url'] ?? ''));

        if (! $fresh && ($cached = Cache::get($key))) {
            return (string) $cached;
        }

        $form = ['grant_type' => $this->config['grant_type']];
        if ($this->config['grant_type'] === 'password') {
            $form += ['username' => $this->config['username'], 'password' => $this->config['password']];
        }

        $response = Http::asForm()->withBasicAuth((string) $this->config['client_id'], (string) $this->config['client_secret'])
            ->post($this->url((string) $this->config['token_path']), $form)->throw();

        $token = $response->json('access_token');
        if (blank($token)) {
            throw new RuntimeException('Không lấy được access_token: ' . (string) ($response->json('error_description') ?? $response->json('error') ?? 'không rõ lỗi'));
        }

        // Trừ hao 60 giây để không dùng token sắp hết hạn giữa lúc gọi API.
        Cache::put($key, $token, max(60, (int) ($response->json('expires_in') ?? 3600) - 60));

        return (string) $token;
    }

    /** credentialID cấu hình sẵn, không có thì lấy chứng thư đầu tiên trong credentials/list của thuê bao. */
    private function credentialId(string $token): string
    {
        if (filled($this->config['credential_id'] ?? null)) {
            return (string) $this->config['credential_id'];
        }

        $list = Http::withToken($token)->post($this->url('/credentials/list'), (object) [])->throw();
        $id = $list->json('credentialIDs.0');

        if (blank($id)) {
            throw new RuntimeException('Thuê bao chưa có chứng thư số nào (credentials/list rỗng).');
        }

        return (string) $id;
    }

    private function fetchCertificate(string $token, string $credentialId): ?string
    {
        $info = Http::withToken($token)->post($this->url('/credentials/info'), [
            'credentialID' => $credentialId, 'certificates' => 'single', 'certInfo' => true,
        ])->throw();

        $cert = $info->json('cert.certificates.0');

        return filled($cert) ? preg_replace('/\s+/', '', (string) $cert) : null;
    }
}
