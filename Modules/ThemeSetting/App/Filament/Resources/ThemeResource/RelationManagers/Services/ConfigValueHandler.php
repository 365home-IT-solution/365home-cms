<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Services;

use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Enums\FieldInputType;

class ConfigValueHandler
{
    public function fillForm($configs): array
    {
        return collect($configs)->mapWithKeys(function ($config) {
            $value = $config->value === null || $config->value === ''
                ? $config->default_value
                : $config->value;

            return [$config->key => $this->formatValueForForm($value, $config)];
        })->toArray();
    }

    public function formatValueForStorage($value, $config): mixed
    {
        $fieldType = FieldInputType::fromString($config->field_type);

        return match ($fieldType) {
            FieldInputType::CONTACT_LINK,
            FieldInputType::HEADER_CONTACTS,
            FieldInputType::SOCIAL_LINKS,
            FieldInputType::FOOTER => $this->formatStorageJson($value),
            FieldInputType::TOGGLE => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            FieldInputType::NUMBER => is_numeric($value) ? (float)$value : 0,
            FieldInputType::SELECT => is_array($value) ? implode(',', $value) : $value,
            default => $value
        };
    }

    protected function formatValueForForm($value, $config): mixed
    {
        $fieldType = FieldInputType::fromString($config->field_type);

        return match ($fieldType) {
            FieldInputType::CONTACT_LINK,
            FieldInputType::HEADER_CONTACTS,
            FieldInputType::SOCIAL_LINKS,
            FieldInputType::FOOTER => $this->formatJsonValue($value),
            FieldInputType::TOGGLE => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            FieldInputType::NUMBER => $this->formatNumberValue($value, $config),
            FieldInputType::SELECT => $this->formatSelectValue($value),
            default => $value
        };
    }

    protected function formatJsonValue($value): array
    {
        if (empty($value)) {
            return [];
        }

        // Nếu đã là array thì return luôn
        if (is_array($value)) {
            return $value;
        }

        // Decode JSON string
        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];
            } catch (\Exception $e) {
                return [];
            }
        }

        return [];
    }

    protected function formatStorageJson($value): string
    {
        // Nếu là array thì encode
        if (is_array($value)) {
            return json_encode($value);
        }

        // Nếu là string JSON hợp lệ thì giữ nguyên
        if (is_string($value)) {
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }

        // Trường hợp khác encode thành JSON rỗng
        return json_encode([]);
    }

    protected function formatNumberValue($value, $config): float
    {
        if ($config->value === null || $config->value === 0.0) {
            return is_numeric($config->default_value)
                ? (float)$config->default_value
                : 0.0;
        }
        return is_numeric($value) ? (float)$value : 0.0;
    }

    protected function formatSelectValue($value): string|array
    {
        return str_contains($value, ',') ? explode(',', $value) : $value;
    }
}
