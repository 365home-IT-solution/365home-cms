<?php

namespace Modules\BladeThemeV1\Traits;

trait HandleConfigTrait {
    public array $config = [];
    public string $uniqueId = '';

    public function getConfig(string $key, string|array|int|float|bool $defaultValue = null) {
        $value = $this->config[$key] ?? $defaultValue;

        if ($this->isJson($value)) {
            return json_decode($value, true);
        }

        return $value;
    }

    protected function isJson($value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public function setConfig(array $config): void {
        $this->config = $config['component'] ?? [];
    }

    public function generateUniqueId(string $componentName): string
    {
        return $componentName . '-' . uniqid();
    }

    public function transformAlbumsData(?array $albums): ?array
    {
        if(!$albums) {
            return null;
        }

        return array_values($albums);
    }

    public function transformBannerData(?array $banners): ?array {
        if (!$banners) {
            return null;
        }

        return array_values(array_map(function ($item) {
            $bannerData = $item['data'];

            $image = reset($bannerData['image']);

            return [
                'image' => $image,
                'title' => $bannerData['title'],
                'description' => $bannerData['description'],
                'cta_text' => $bannerData['cta_text'],
                'cta_link' => $bannerData['cta_link'],
            ];
        }, $banners));
    }

    public function transfromContactGroupData(?array $contactGroups): ?array {
        if (!$contactGroups) {
            return null;
        }

        return array_values(array_map(function ($contact) {
            return [
                'icon' => $contact['icon'],
                'name' => $contact['name'],
                'value' => $contact['value'],
            ];
        }, $contactGroups));
    }

}