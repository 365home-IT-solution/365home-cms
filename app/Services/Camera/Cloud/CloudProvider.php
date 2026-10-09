<?php

declare(strict_types=1);

namespace App\Services\Camera\Cloud;

interface CloudProvider
{
    /** Khoá trùng với cameras.provider (VD "imou", "ezviz"). */
    public function key(): string;

    public function label(): string;

    /**
     * Các ô tài khoản developer đối tác phải nhập. "secret" = không bao giờ trả về qua API.
     *
     * @return list<array{key: string, label: string, secret: bool, required: bool, default: ?string, options: ?list<string>}>
     */
    public function credentialFields(): array;

    /** Kênh mặc định khi camera không khai báo external_channel. */
    public function defaultChannel(): string;

    public function supportsSnapshot(): bool;

    /**
     * Đăng nhập thử bằng tài khoản developer (bỏ qua cache) để kiểm tra App ID/Secret đúng chưa.
     *
     * @param  array<string, mixed>  $credentials
     *
     * @throws CloudProviderException
     */
    public function verifyCredentials(array $credentials): void;

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{hls_url: string, expires_in: ?int, cover_url: ?string}
     *
     * @throws CloudProviderException
     */
    public function liveStream(array $credentials, string $deviceId, string $channel): array;

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{url: string, expires_in: int}
     *
     * @throws CloudProviderException
     */
    public function snapshot(array $credentials, string $deviceId, string $channel): array;
}
