<?php

declare(strict_types=1);

namespace App\Services\ContractSigning;

// Kết quả 1 lần ký — dùng chung cho mọi provider (local test lẫn VNPT SmartCA thật sau này).
final class SignatureResult
{
    public function __construct(
        public readonly string $signature,
        public readonly array $certificate,
    ) {
    }
}
