<?php

namespace Modules\BladeThemeV1\Traits;

trait HandleSectionCfgTrait
{
    public array $sectionCfgs = [];

    protected function getChildSectionConfigs(string $childName): array
    {
        $child = $this->section->children->first(function($child) use ($childName) {
            return $child->name === $childName;
        });

        if (!$child) {
            return [];
        }

        return $this->processConfigs($child->sectionCfgs->toArray());
    }

    protected function processConfigs(array $data): array
    {
//        dd($data);
        $configs = [];
        foreach($data as $config) {
            $value = $config['value'];

            if (is_array($value)) {
                $configs[$config['key']] = $this->parseValue($value, $config['data_type']);
                continue;
            }

            // Xử lý các trường hợp khác
            $value = ($value !== null && $value !== '' && (string)$value !== "0")
                ? $value
                : $config['default_value'];

            $configs[$config['key']] = $this->parseValue($value, $config['data_type']);
            $configs[$config['key']] = $this->parseValue($value, $config['data_type']);
        }
        return $configs;
    }

    public function loadConfig(array $data): void
    {
        $this->sectionCfgs = $this->processConfigs($data);
    }

    public function config($key, $default = null) {
        return $this->sectionCfgs[$key] ?? $default;
    }

    protected function parseValue($value, $type) {
        if($value === null) {
            return null;
        }

        if(is_array($value)) {
            return $value;
        }

        if(is_string($value) && str_contains($value, '[{')) {
            return json_decode($value, true);
        }

        return match ($type) {
            'boolean' => (bool)$value,
            'integer' => (int)$value,
            'float' => (float)$value,
            'array' => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }

    public function toConfig(): array
    {
        return $this->sectionCfgs;
    }
}
