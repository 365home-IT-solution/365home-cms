<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Enums;

enum ConfigDataType: string
{
    case STRING = 'string';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case ARRAY = 'array';
    case JSON = 'json';
    case NULL = 'null';

    public static function fromValue($value): self
    {
        if (is_array($value)) {
            return self::ARRAY;
        }

        if (is_numeric($value)) {
            return self::NUMBER;
        }

        if (is_bool($value)) {
            return self::BOOLEAN;
        }

        if (is_null($value)) {
            return self::NULL;
        }

        if (is_string($value)) {
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return self::JSON;
            }
        }

        return self::STRING;
    }

    public function formatValue($value)
    {
        return match ($this) {
            self::ARRAY, self::JSON => is_string($value) ? json_decode($value, true) : $value,
            self::NUMBER => (float) $value,
            self::BOOLEAN => (bool) $value,
            self::NULL => null,
            default => (string) $value,
        };
    }

    public function prepareForStorage($value)
    {
        return match ($this) {
            self::ARRAY, self::JSON => is_array($value) ? json_encode($value) : $value,
            self::NUMBER => (float) $value,
            self::BOOLEAN => (bool) $value,
            self::NULL => null,
            default => (string) $value,
        };
    }
}
