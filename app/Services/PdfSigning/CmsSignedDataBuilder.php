<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

use phpseclib3\File\ASN1;
use phpseclib3\File\ASN1\Maps\Name as NameMap;
use phpseclib3\File\X509;

// Tự dựng cấu trúc CMS SignedData (RFC 5652) — dùng cho chữ ký PAdES-BES/CAdES-BES khi chữ ký RSA
// thật được ký BÊN NGOÀI (VNPT SmartCA giữ khoá riêng, hệ thống chỉ nhận lại chữ ký thô trên 1
// hash), KHÔNG dùng openssl_pkcs7_sign() được vì hàm đó bắt buộc phải có private key ngay trên máy.
//
// Không có thư viện PHP nào dựng sẵn đúng luồng "build attrs → hash → ký ngoài → ráp lại" này, nên
// phải tự định nghĩa ASN.1 template và dùng phpseclib3\File\ASN1::encodeDER() để đảm bảo DER encode
// đúng chuẩn (an toàn hơn tự nối byte tay, vì độ dài/tag ASN.1 rất dễ sai).
class CmsSignedDataBuilder
{
    private const OID_DATA = '1.2.840.113549.1.7.1';
    private const OID_SIGNED_DATA = '1.2.840.113549.1.7.2';
    private const OID_SHA256 = '2.16.840.1.101.3.4.2.1';
    private const OID_RSA_ENCRYPTION = '1.2.840.113549.1.1.1';
    private const OID_CONTENT_TYPE = '1.2.840.113549.1.9.3';
    private const OID_MESSAGE_DIGEST = '1.2.840.113549.1.9.4';
    private const OID_SIGNING_TIME = '1.2.840.113549.1.9.5';

    private array $x509Certificate;
    private string $certificateDer;

    public function __construct(private readonly string $certificateDerBase64)
    {
        // Dùng X509 của phpseclib để đọc CHÍNH XÁC issuer Name + serialNumber từ chứng thư thật —
        // KHÔNG tự ráp lại Name từ chuỗi subjectDN (dễ sai byte so với bản gốc trong chứng thư,
        // khiến bên nhận không khớp được IssuerAndSerialNumber với đúng chứng thư).
        $this->certificateDer = base64_decode(preg_replace('/\s+/', '', $certificateDerBase64));

        $x509 = new X509();
        $cert = $x509->loadX509($this->certificateDer);

        if ($cert === false) {
            throw new \RuntimeException('Không đọc được chứng thư X.509 (cert_data không hợp lệ).');
        }

        $this->x509Certificate = $cert;
    }

    // Bước 1: dựng DER của SignedAttributes (chứa contentType/messageDigest/signingTime) — VNPT
    // phải ký lên HASH của chính DER này (không phải hash của nội dung PDF trực tiếp), vì CMS quy
    // định: khi có signedAttrs, chữ ký luôn tính trên signedAttrs, không tính trên content gốc.
    public function buildSignedAttributesDer(string $contentSha256Hex, \DateTimeImmutable $signingTime): string
    {
        $attrs = [
            [
                'attrType'   => self::OID_CONTENT_TYPE,
                'attrValues' => [self::OID_DATA],
            ],
            [
                'attrType'   => self::OID_MESSAGE_DIGEST,
                'attrValues' => [hex2bin($contentSha256Hex)],
            ],
            [
                'attrType'   => self::OID_SIGNING_TIME,
                'attrValues' => [$signingTime->format('ymdHis') . 'Z'],
            ],
        ];

        // SignedAttributes dùng để KÝ phải encode dạng SET OF (tag 0x31), khác với dạng [0] IMPLICIT
        // (tag 0xA0) dùng khi NHÚNG vào SignerInfo — 2 cách encode khác nhau ở byte tag đầu tiên dù
        // nội dung bên trong giống hệt nhau (RFC 5652 mục 5.4).
        return $this->encodeAttributesAsSet($attrs);
    }

    // Bước 2: sau khi có chữ ký RSA thật (VNPT trả về) + DER signedAttrs ở bước 1, ráp thành CMS
    // SignedData hoàn chỉnh — nhúng vào PDF làm /Contents.
    public function buildSignedData(string $signedAttributesDer, string $rawSignatureBase64): string
    {
        $rawSignature = base64_decode($rawSignatureBase64, true);
        if ($rawSignature === false) {
            throw new \RuntimeException('Chữ ký VNPT trả về không phải base64 hợp lệ.');
        }

        $issuerAndSerialDer = $this->buildIssuerAndSerialNumberDer();

        // signedAttrs nhúng vào SignerInfo phải đổi tag từ SET (0x31) sang [0] IMPLICIT (0xA0) —
        // giữ nguyên phần nội dung bên trong (đã tính hash ở dạng SET tại buildSignedAttributesDer()).
        $signedAttrsImplicit = "\xA0" . substr($signedAttributesDer, 1);

        $signerInfo = $this->sequence(
            $this->integer(1) // version 1 (issuerAndSerialNumber)
            . $issuerAndSerialDer
            . $this->algorithmIdentifier(self::OID_SHA256)
            . $signedAttrsImplicit
            . $this->algorithmIdentifier(self::OID_RSA_ENCRYPTION)
            . $this->octetString($rawSignature)
        );

        $encapContentInfo = $this->sequence($this->objectIdentifier(self::OID_DATA));

        $certificatesImplicitSet = "\xA0" . $this->lengthOf($this->certificateDer) . $this->certificateDer;

        $signedData = $this->sequence(
            $this->integer(1) // CMSVersion 1
            . $this->set($this->algorithmIdentifier(self::OID_SHA256))
            . $encapContentInfo
            . $certificatesImplicitSet
            . $this->set($signerInfo)
        );

        $contentInfo = $this->sequence(
            $this->objectIdentifier(self::OID_SIGNED_DATA)
            . "\xA0" . $this->lengthOf($signedData) . $signedData
        );

        return $contentInfo;
    }

    private function buildIssuerAndSerialNumberDer(): string
    {
        // phpseclib giữ sẵn issuer Name đã parse — dùng lại ASN1::encodeDER() với đúng map 'Name'
        // (phpseclib3\File\ASN1\Maps\Name) để có byte-for-byte khớp với chứng thư gốc, không tự
        // ráp chuỗi DN thủ công (dễ sai byte, khiến bên nhận không khớp được IssuerAndSerialNumber
        // với đúng chứng thư).
        $issuerDer = ASN1::encodeDER($this->x509Certificate['tbsCertificate']['issuer'], NameMap::MAP);

        $serialNumber = $this->x509Certificate['tbsCertificate']['serialNumber'];
        $serialDer = $this->integerFromBigInteger($serialNumber);

        return $this->sequence($issuerDer . $serialDer);
    }

    private function encodeAttributesAsSet(array $attrs): string
    {
        $body = '';
        foreach ($attrs as $attr) {
            $values = '';
            foreach ($attr['attrValues'] as $value) {
                $values .= $this->attributeValue($attr['attrType'], $value);
            }
            $body .= $this->sequence($this->objectIdentifier($attr['attrType']) . $this->set($values));
        }

        return $this->set($body);
    }

    private function attributeValue(string $oid, string $value): string
    {
        return match ($oid) {
            self::OID_CONTENT_TYPE => $this->objectIdentifier($value),
            self::OID_MESSAGE_DIGEST => $this->octetString($value),
            self::OID_SIGNING_TIME => $this->utcTime($value),
            default => $this->octetString($value),
        };
    }

    private function algorithmIdentifier(string $oid): string
    {
        // NULL parameters — quy ước phổ biến cho sha256/rsaEncryption trong CMS.
        return $this->sequence($this->objectIdentifier($oid) . "\x05\x00");
    }

    private function sequence(string $content): string
    {
        return "\x30" . $this->lengthOf($content) . $content;
    }

    private function set(string $content): string
    {
        return "\x31" . $this->lengthOf($content) . $content;
    }

    private function octetString(string $content): string
    {
        return "\x04" . $this->lengthOf($content) . $content;
    }

    private function utcTime(string $content): string
    {
        return "\x17" . $this->lengthOf($content) . $content;
    }

    private function integer(int $value): string
    {
        return "\x02\x01" . chr($value);
    }

    private function integerFromBigInteger($bigInteger): string
    {
        $bytes = ltrim($bigInteger->toBytes(), "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        // Nếu bit cao nhất = 1, phải thêm byte 0x00 phía trước để không bị hiểu nhầm là số âm.
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->lengthOf($bytes) . $bytes;
    }

    private function objectIdentifier(string $oid): string
    {
        $encoded = ASN1::encodeDER($oid, ['type' => ASN1::TYPE_OBJECT_IDENTIFIER]);

        return $encoded;
    }

    private function lengthOf(string $content): string
    {
        $len = strlen($content);

        if ($len < 128) {
            return chr($len);
        }

        $bytes = ltrim(pack('N', $len), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
