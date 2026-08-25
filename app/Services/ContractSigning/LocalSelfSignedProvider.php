<?php

declare(strict_types=1);

namespace App\Services\ContractSigning;

use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

// Provider DÙNG NGAY được lúc dev/test — tự sinh 1 cặp khoá RSA "self-signed" lưu trong
// storage/app/contract-signing/, KHÔNG cần đăng ký với bất kỳ ai (không cần MST, không cần chờ
// VNPT/MISA duyệt hồ sơ). Ký thật bằng RSA (openssl_sign/openssl_verify) nên đúng luồng kỹ thuật
// (hash → ký → verify bằng khoá công khai), nhưng KHÔNG có giá trị pháp lý như chữ ký số thật do
// CA cấp — chỉ dùng để build & test kiến trúc trước khi có tài khoản doanh nghiệp thật.
//
// Khi có MST + tài khoản VNPT SmartCA/MISA thật: chỉ cần đổi CONTRACT_SIGNING_PROVIDER trong .env
// sang 'vnpt_smartca' (xem config/contract_signing.php) — không phải sửa ContractSignController
// hay PartnerForm, vì cả 2 nơi đó chỉ gọi qua interface DigitalSignatureProvider.
class LocalSelfSignedProvider implements DigitalSignatureProvider
{
    private const KEY_DIR = 'contract-signing';

    private const PRIVATE_KEY_FILE = 'contract-signing/local_signer.key';

    private const CERTIFICATE_FILE = 'contract-signing/local_signer.crt';

    public function name(): string
    {
        return 'local';
    }

    // Trả về chứng thư X.509 TỰ KÝ (self-signed) — cần thiết để test luồng PAdES/CMS (yêu cầu 1
    // chứng thư X.509 thật để dựng IssuerAndSerialNumber), dù không có giá trị pháp lý.
    public function certificate(array $signerContext): array
    {
        return ['cert_data' => $this->getOrCreateCertificateDer()];
    }

    public function sign(string $contentHash, array $signerContext): SignatureResult
    {
        $privateKey = $this->getOrCreatePrivateKey();

        if (! openssl_sign($contentHash, $binarySignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Không thể tạo chữ ký (LocalSelfSignedProvider): ' . openssl_error_string());
        }

        return new SignatureResult(
            signature: base64_encode($binarySignature),
            certificate: [
                'subject'     => 'CN=365home Local Test Signer (KHÔNG có giá trị pháp lý)',
                'issuer'      => 'Self-signed — chỉ dùng test local, chưa đăng ký CA',
                'role'        => $signerContext['role'] ?? null,
                'signer_name' => $signerContext['name'] ?? null,
                'algorithm'   => 'RSA-SHA256',
                'signed_at'   => now()->toIso8601String(),
            ],
        );
    }

    public function verify(string $contentHash, string $signature, array $certificate): bool
    {
        $publicKey = $this->getPublicKey();

        $binarySignature = base64_decode($signature, true);

        if ($binarySignature === false) {
            return false;
        }

        return openssl_verify($contentHash, $binarySignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function getOrCreatePrivateKey(): \OpenSSLAsymmetricKey
    {
        $disk = Storage::disk('local');

        if ($disk->exists(self::PRIVATE_KEY_FILE)) {
            $resource = openssl_pkey_get_private($disk->get(self::PRIVATE_KEY_FILE));

            if ($resource !== false) {
                return $resource;
            }
        }

        // Chưa có khoá — tự sinh 1 lần duy nhất rồi lưu lại, dùng chung cho mọi lần ký sau đó
        // (để verify() sau này còn khớp được với chữ ký đã ký trước).
        //
        // Trên Windows, PHP openssl.cnf mặc định trong php.ini nhiều khi trỏ tới đường dẫn không
        // tồn tại (đã gặp thực tế: "Openssl default config => C:\Program Files\Common Files\SSL/
        // openssl.cnf" nhưng thư mục đó không có) khiến openssl_pkey_new() lỗi "No such process" —
        // phải tự trỏ lại 'config' tới file openssl.cnf THẬT sự tồn tại đi kèm bản cài PHP.
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        if ($configPath = $this->resolveOpensslConfigPath()) {
            $options['config'] = $configPath;
        }

        $keyPair = openssl_pkey_new($options);

        if ($keyPair === false) {
            throw new RuntimeException('Không thể sinh khoá RSA test (LocalSelfSignedProvider): ' . openssl_error_string());
        }

        openssl_pkey_export($keyPair, $privateKeyPem, null, $options);

        $disk->makeDirectory(self::KEY_DIR);
        $disk->put(self::PRIVATE_KEY_FILE, $privateKeyPem);

        return $keyPair;
    }

    // Chứng thư tự ký (self-signed) — sinh 1 lần, lưu lại dùng chung cho các lần ký sau (để
    // IssuerAndSerialNumber luôn khớp giữa các lần test).
    private function getOrCreateCertificateDer(): string
    {
        $disk = Storage::disk('local');

        if ($disk->exists(self::CERTIFICATE_FILE)) {
            $pem = $disk->get(self::CERTIFICATE_FILE);
            $der = $this->pemToDer($pem);
            if ($der !== null) {
                return base64_encode($der);
            }
        }

        $privateKey = $this->getOrCreatePrivateKey();

        $dn = [
            'countryName'       => 'VN',
            'organizationName'  => '365home (LOCAL TEST — KHÔNG có giá trị pháp lý)',
            'commonName'        => '365home Local Test Signer',
        ];

        $options = ['digest_alg' => 'sha256'];
        if ($configPath = $this->resolveOpensslConfigPath()) {
            $options['config'] = $configPath;
        }

        $csr = openssl_csr_new($dn, $privateKey, $options);
        if ($csr === false) {
            throw new RuntimeException('Không thể tạo CSR test (LocalSelfSignedProvider): ' . openssl_error_string());
        }

        $cert = openssl_csr_sign($csr, null, $privateKey, 730, $options);
        if ($cert === false) {
            throw new RuntimeException('Không thể tạo chứng thư tự ký test (LocalSelfSignedProvider): ' . openssl_error_string());
        }

        openssl_x509_export($cert, $pem);
        $disk->put(self::CERTIFICATE_FILE, $pem);

        return base64_encode($this->pemToDer($pem));
    }

    private function pemToDer(string $pem): ?string
    {
        if (! preg_match('/-----BEGIN CERTIFICATE-----(.*)-----END CERTIFICATE-----/s', $pem, $m)) {
            return null;
        }

        return base64_decode(preg_replace('/\s+/', '', $m[1]));
    }

    private function getPublicKey(): \OpenSSLAsymmetricKey
    {
        $privateKey = $this->getOrCreatePrivateKey();
        $details    = openssl_pkey_get_details($privateKey);

        $publicKey = openssl_pkey_get_public($details['key']);

        if ($publicKey === false) {
            throw new RuntimeException('Không thể lấy khoá công khai test (LocalSelfSignedProvider): ' . openssl_error_string());
        }

        return $publicKey;
    }

    // openssl.cnf mặc định trong php.ini (Windows/XAMPP/Ampps...) thường trỏ tới đường dẫn KHÔNG
    // tồn tại trên máy thật — tự dò vài vị trí phổ biến đi kèm bản cài PHP để openssl_pkey_new()
    // không bị lỗi "No such process" (đã xác minh thực tế trên máy dev hiện tại).
    private function resolveOpensslConfigPath(): ?string
    {
        $iniDir = dirname((string) php_ini_loaded_file());

        $candidates = [
            $iniDir . '/extras/ssl/openssl.cnf',
            $iniDir . '/openssl.cnf',
            'C:/Program Files/Common Files/SSL/openssl.cnf',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
