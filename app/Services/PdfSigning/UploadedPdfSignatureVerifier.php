<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

// Kiểm tra file PDF do người có chữ ký số USB token ký trên máy của họ rồi tải lên (nền tảng không giữ khoá nên không tự ký được).
// Kiểm tra: (1) có chữ ký PDF và chữ ký cuối phủ TOÀN BỘ file; (2) phần đầu file đúng là bản PDF gốc đã đưa đi ký (chỉ được THÊM chữ ký,
// không đổi nội dung hợp đồng); (3) mã băm trong chữ ký khớp nội dung; (4) chữ ký đúng với chứng thư nhúng trong file; (5) chứng thư còn hạn.
// KHÔNG kiểm tra chuỗi tin cậy tới CA gốc và trạng thái thu hồi (OCSP/CRL) — việc đó dùng Adobe Acrobat / công cụ kiểm tra của NEAC.
class UploadedPdfSignatureVerifier
{
    private const DIGESTS = [
        '1.3.14.3.2.26' => 'sha1',
        '2.16.840.1.101.3.4.2.1' => 'sha256',
        '2.16.840.1.101.3.4.2.2' => 'sha384',
        '2.16.840.1.101.3.4.2.3' => 'sha512',
    ];

    private const OID_MESSAGE_DIGEST = '1.2.840.113549.1.9.4';

    private const OID_SIGNING_TIME = '1.2.840.113549.1.9.5';

    /**
     * Kiểm tra file do người ký tải lên: chữ ký MỚI NHẤT phải phủ toàn bộ file và phần đầu file đúng là bản PDF gốc đã đưa đi ký.
     *
     * @return array{ok: bool, errors: list<string>, certificate: array<string, mixed>}
     */
    public function verify(string $pdf, string $baseHash, int $baseLength, ?\DateTimeInterface $at = null): array
    {
        $fail = fn (string $msg): array => ['ok' => false, 'errors' => [$msg], 'certificate' => []];

        if (! str_starts_with($pdf, '%PDF')) {
            return $fail('File tải lên không phải PDF.');
        }

        $ranges = $this->byteRanges($pdf);
        if ($ranges === []) {
            return $fail('File PDF chưa có chữ ký số. Hãy ký file PDF đã tải về bằng chữ ký số rồi tải lên lại.');
        }

        // Chữ ký đặt sau cùng (phủ toàn file) là chữ ký của người vừa ký.
        $range = end($ranges);
        [$a, $b, $c, $d] = $range;
        $len = strlen($pdf);

        if ($a !== 0 || $c + $d !== $len || $b > $c || $c > $len) {
            return $fail('Chữ ký số cuối cùng không phủ toàn bộ file — file đã bị sửa sau khi ký, hoặc chưa ký bản mới nhất.');
        }

        if ($baseLength <= 0 || $len <= $baseLength || ! hash_equals($baseHash, hash('sha256', substr($pdf, 0, $baseLength)))) {
            return $fail('Nội dung PDF không đúng bản hợp đồng đã tải về để ký (phần nội dung gốc đã bị thay đổi). Hãy ký đúng file đã tải từ hệ thống và không chỉnh sửa.');
        }

        $one = $this->checkOne($pdf, $range, $at);

        return ['ok' => $one['ok'], 'errors' => $one['errors'], 'certificate' => $one['certificate']];
    }

    /**
     * Kiểm tra TỪNG chữ ký có trong file (dùng cho hợp đồng có 2 chữ ký: đối tác rồi nền tảng).
     *
     * @return list<array{ok: bool, errors: list<string>, certificate: array<string, mixed>, covers_whole_file: bool}>
     */
    public function verifyAll(string $pdf, ?\DateTimeInterface $at = null): array
    {
        $out = [];
        foreach ($this->byteRanges($pdf) as $range) {
            $one = $this->checkOne($pdf, $range, $at);
            $one['covers_whole_file'] = $range[0] === 0 && $range[2] + $range[3] === strlen($pdf);
            $out[] = $one;
        }

        return $out;
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: int}> */
    private function byteRanges(string $pdf): array
    {
        if (! preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $all, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(fn (array $m): array => array_map('intval', array_slice($m, 1)), $all);
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}  $range
     * @return array{ok: bool, errors: list<string>, certificate: array<string, mixed>}
     */
    private function checkOne(string $pdf, array $range, ?\DateTimeInterface $at): array
    {
        $fail = fn (string $msg): array => ['ok' => false, 'errors' => [$msg], 'certificate' => []];
        [, $b, $c] = $range;

        $contents = substr($pdf, $b, $c - $b);
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $contents);
        // Phần dự trữ cuối /Contents được đệm 0; độ dài thật lấy theo khai báo của chính cấu trúc DER nên không cần cắt.
        $cms = strlen((string) $hex) % 2 === 0 ? @hex2bin((string) $hex) : false;
        if (! $cms) {
            return $fail('Không đọc được chữ ký số trong file PDF.');
        }

        try {
            $signed = $this->parseCms($cms);
        } catch (\Throwable $e) {
            return $fail('Chữ ký số trong PDF không đúng định dạng (CMS/PKCS#7): ' . $e->getMessage());
        }

        $data = substr($pdf, $range[0], $range[1]) . substr($pdf, $range[2], $range[3]);
        $algo = $signed['digest'];
        $errors = [];

        if (! hash_equals(hash($algo, $data, true), $signed['message_digest'])) {
            return $fail('Mã băm trong chữ ký không khớp nội dung file — file đã bị sửa sau khi ký.');
        }

        // Chứng thư ký: thử từng chứng thư nhúng trong file, cái nào xác thực được chữ ký thì là chứng thư của người ký.
        $signer = null;
        foreach ($signed['certs'] as $der) {
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
            if (openssl_verify($signed['signed_attrs'], $signed['signature'], $pem, $algo) === 1) {
                $signer = $pem;
                break;
            }
        }
        if ($signer === null) {
            return $fail('Chữ ký số không xác thực được với chứng thư trong file (chữ ký sai hoặc file bị sửa).');
        }

        $parsed = openssl_x509_parse($signer) ?: [];
        $validFrom = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $when = ($at ?? new \DateTimeImmutable())->getTimestamp();
        if ($validFrom && $when < $validFrom || $validTo && $when > $validTo) {
            $errors[] = 'Chứng thư số đã hết hạn hoặc chưa có hiệu lực tại thời điểm tải lên.';
        }

        $subject = $parsed['subject'] ?? [];
        $flat = $this->flatten($subject);
        $certificate = [
            'verified' => $errors === [],
            'provider' => 'usb_token',
            'cert_subject' => $subject['CN'] ?? null,
            'cert_org' => $subject['O'] ?? null,
            'cert_issuer' => $parsed['issuer']['CN'] ?? null,
            'serial' => $parsed['serialNumberHex'] ?? null,
            'valid_from' => $validFrom ? date('c', $validFrom) : null,
            'valid_to' => $validTo ? date('c', $validTo) : null,
            'tax_code' => preg_match('/(?:MST|TAXCODE|TAX\s*CODE)\s*[:\-]?\s*([0-9]{10}(?:-[0-9]{3})?)/i', $flat, $m) ? $m[1] : null,
            'citizen_id' => preg_match('/(?:CCCD|CMND|CMT|CITIZEN\s*ID)\s*[:\-]?\s*([0-9]{9,12})/i', $flat, $m) ? $m[1] : null,
            'self_signed' => ($parsed['subject'] ?? null) === ($parsed['issuer'] ?? 1),
            'signing_time' => $signed['signing_time'],
            'digest' => $algo,
            'signature_sha256' => hash('sha256', $signed['signature']),
        ];

        return ['ok' => $errors === [], 'errors' => $errors, 'certificate' => $certificate];
    }

    /** Gộp mọi giá trị trong tên chủ thể thành 1 chuỗi để tìm MST / CCCD (mỗi CA đặt ở 1 trường khác nhau: UID, serialNumber, CN...). */
    private function flatten(array $subject): string
    {
        $parts = [];
        array_walk_recursive($subject, function ($v) use (&$parts) {
            $parts[] = (string) $v;
        });

        return implode(' | ', $parts);
    }

    /** @return array{digest: string, message_digest: string, signed_attrs: string, signature: string, certs: list<string>, signing_time: ?string} */
    private function parseCms(string $der): array
    {
        $ci = $this->read($der, 0);                     // ContentInfo
        $kids = $this->children($der, $ci);
        $explicit = $kids[1] ?? throw new \RuntimeException('thiếu SignedData');
        $sd = $this->read($der, $explicit['vs']);
        $parts = $this->children($der, $sd);

        $certs = [];
        $signerInfos = null;
        foreach ($parts as $i => $p) {
            if ($p['tag'] === 0xA0) {                   // certificates [0] IMPLICIT
                foreach ($this->children($der, $p) as $cert) {
                    $certs[] = substr($der, $cert['hs'], $cert['ve'] - $cert['hs']);
                }
            } elseif ($p['tag'] === 0x31 && $i >= 2) {   // signerInfos (SET cuối)
                $signerInfos = $p;
            }
        }
        if (! $signerInfos || ! $certs) {
            throw new \RuntimeException('thiếu chứng thư hoặc thông tin người ký');
        }

        $si = $this->children($der, $this->children($der, $signerInfos)[0] ?? throw new \RuntimeException('thiếu SignerInfo'));
        $digest = null;
        $attrs = null;
        $signature = null;
        $seenAttrs = false;
        foreach ($si as $i => $p) {
            if ($i === 2 && $p['tag'] === 0x30) {
                $digest = self::DIGESTS[$this->oid($der, $this->children($der, $p)[0])] ?? null;
            } elseif ($p['tag'] === 0xA0) {
                $attrs = $p;
                $seenAttrs = true;
            } elseif ($p['tag'] === 0x04 && $seenAttrs) {
                $signature = substr($der, $p['vs'], $p['ve'] - $p['vs']);
            }
        }
        if (! $digest || ! $attrs || $signature === null) {
            throw new \RuntimeException('thiếu thuộc tính ký (signedAttrs) hoặc thuật toán băm không hỗ trợ');
        }

        $messageDigest = null;
        $signingTime = null;
        foreach ($this->children($der, $attrs) as $attr) {
            $ak = $this->children($der, $attr);
            $oid = $this->oid($der, $ak[0]);
            $val = $this->children($der, $ak[1])[0] ?? null;
            if ($oid === self::OID_MESSAGE_DIGEST && $val) {
                $messageDigest = substr($der, $val['vs'], $val['ve'] - $val['vs']);
            } elseif ($oid === self::OID_SIGNING_TIME && $val) {
                $signingTime = $this->time(substr($der, $val['vs'], $val['ve'] - $val['vs']), $val['tag']);
            }
        }
        if ($messageDigest === null) {
            throw new \RuntimeException('thiếu messageDigest');
        }

        // signedAttrs được ký dưới dạng SET OF (tag 0x31) chứ không phải [0] IMPLICIT như khi nhúng trong SignerInfo.
        $signedAttrs = "\x31" . substr($der, $attrs['hs'] + 1, $attrs['ve'] - $attrs['hs'] - 1);

        return ['digest' => $digest, 'message_digest' => $messageDigest, 'signed_attrs' => $signedAttrs, 'signature' => $signature, 'certs' => $certs, 'signing_time' => $signingTime];
    }

    /** @return array{tag: int, hs: int, vs: int, ve: int} hs = đầu phần tử, vs/ve = đầu/cuối nội dung */
    private function read(string $s, int $pos): array
    {
        if ($pos + 2 > strlen($s)) {
            throw new \RuntimeException('DER bị cắt cụt');
        }
        $tag = ord($s[$pos]);
        $l = ord($s[$pos + 1]);
        $off = $pos + 2;
        if ($l & 0x80) {
            $n = $l & 0x7F;
            if ($n < 1 || $n > 4) {
                throw new \RuntimeException('độ dài DER không hỗ trợ');
            }
            $l = 0;
            for ($i = 0; $i < $n; $i++) {
                $l = ($l << 8) | ord($s[$off++]);
            }
        }
        if ($off + $l > strlen($s)) {
            throw new \RuntimeException('DER bị cắt cụt');
        }

        return ['tag' => $tag, 'hs' => $pos, 'vs' => $off, 've' => $off + $l];
    }

    /** @return list<array{tag: int, hs: int, vs: int, ve: int}> */
    private function children(string $s, array $node): array
    {
        $out = [];
        for ($p = $node['vs']; $p < $node['ve'];) {
            $c = $this->read($s, $p);
            $out[] = $c;
            $p = $c['ve'];
        }

        return $out;
    }

    private function oid(string $s, array $node): string
    {
        $b = substr($s, $node['vs'], $node['ve'] - $node['vs']);
        if ($b === '') {
            return '';
        }
        $first = ord($b[0]);
        $parts = [intdiv($first, 40), $first % 40];
        $v = 0;
        for ($i = 1, $n = strlen($b); $i < $n; $i++) {
            $v = ($v << 7) | (ord($b[$i]) & 0x7F);
            if (! (ord($b[$i]) & 0x80)) {
                $parts[] = $v;
                $v = 0;
            }
        }

        return implode('.', $parts);
    }

    private function time(string $raw, int $tag): ?string
    {
        $fmt = $tag === 0x18 ? 'YmdHis' : 'ymdHis';
        $dt = \DateTimeImmutable::createFromFormat($fmt, substr($raw, 0, $tag === 0x18 ? 14 : 12), new \DateTimeZone('UTC'));

        return $dt ? $dt->format('c') : null;
    }
}
