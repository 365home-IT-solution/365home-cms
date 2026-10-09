<?php

declare(strict_types=1);

namespace App\Services\Camera\Cloud;

// Danh sách hãng camera cloud đã có connector. Thêm hãng mới = viết 1 lớp CloudProvider rồi khai báo
// vào PROVIDERS — API, form cấu hình và tài liệu tự nhận hãng mới qua camera-options.
class CloudProviderRegistry
{
    private const PROVIDERS = [ImouProvider::class, EzvizProvider::class];

    /** @var array<string, CloudProvider>|null */
    private ?array $instances = null;

    /** @return array<string, CloudProvider> */
    public function all(): array
    {
        if ($this->instances === null) {
            $this->instances = [];

            foreach (self::PROVIDERS as $class) {
                $provider = app($class);
                $this->instances[$provider->key()] = $provider;
            }
        }

        return $this->instances;
    }

    public function get(?string $key): ?CloudProvider
    {
        return $key !== null ? ($this->all()[$key] ?? null) : null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return list<string> */
    public function secretKeys(CloudProvider $provider): array
    {
        return array_values(array_map(
            fn (array $field): string => $field['key'],
            array_filter($provider->credentialFields(), fn (array $field): bool => $field['secret']),
        ));
    }

    /** @param array<string, mixed> $credentials */
    public function isComplete(CloudProvider $provider, array $credentials): bool
    {
        foreach ($provider->credentialFields() as $field) {
            if ($field['required'] && blank($credentials[$field['key']] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
