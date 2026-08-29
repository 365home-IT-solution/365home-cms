<?php

namespace Modules\Page\Traits;

use Modules\Component\App\Enums\FieldInputType;
use Modules\Page\Entities\ComponentConfiguration;
use Modules\Page\Entities\PageComponent;
use Illuminate\Support\Str;

trait HandlePage
{
    private const ORDER = 'order';
    private const CONFIG_VALUES = 'config_values';

    public function fill($values): array
    {
        if (!is_array($values)) {
            return parent::fill($values);
        }

        $data = $values;
        $data['components'] = PageComponent::with(['pageComponentConfigurationValues', 'component'])
            ->where('page_id', $this->record->id)
            ->orderBy(self::ORDER)
            ->get()
            ->map(function ($pageComponent) {
                return [
                    'component_id' => $pageComponent->component_id,
                    'instance_id' => $pageComponent->instance_id,
                    self::ORDER => $pageComponent->order,
                    self::CONFIG_VALUES => $pageComponent->pageComponentConfigurationValues
                        ->mapWithKeys(function ($configValue) {
                            $value = $configValue->pivot->value;
                            $fieldType = FieldInputType::tryFrom($configValue->type_field);

                            $processedValue = $this->processValueForFill($fieldType, $value);

                            if ($fieldType === FieldInputType::BUILDER && !is_array($processedValue)) {
                                $processedValue = [];
                            }

                            if ($fieldType === FieldInputType::SIDEBAR && !is_array($processedValue)) {
                                $processedValue = [];
                            }

                            return [$configValue->id => $processedValue];
                        })->toArray(),
                ];
            })
            ->toArray();

        return $data;
    }

    public function isJson($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    public function createPage(): void
    {
        $order = 0;
        $componentsData = collect($this->data['components'])->map(function ($componentData) use (&$order) {
            $order++;
            return [
                'component_id' => $componentData['component_id'],
                self::ORDER => $order,
                'instance_id' => $componentData['instance_id'] ?? (string) Str::uuid(),
                self::CONFIG_VALUES => $componentData[self::CONFIG_VALUES] ?? [],
            ];
        })->values()->all();

        // Mỗi lần Lưu trang, TOÀN BỘ component bị xoá-tạo lại (kể cả khi nội dung không đổi gì) —
        // nếu để PageComponent::LogsAuditTrail tự log từng create()/delete() sẽ ra rất nhiều dòng
        // vụn (1 dòng/section) mỗi lần Lưu, kể cả khi chỉ sửa 1 chữ. Tạm tắt log tự động, tự ghi 1
        // dòng TÓM TẮT duy nhất bên dưới — trước đây hoàn toàn KHÔNG có log nào cho việc này.
        $oldLabels = $this->record->pageComponents()->with('component')
            ->get()
            ->map(fn ($pc) => $pc->component?->label ?? ('#' . $pc->component_id))
            ->all();

        \Modules\Page\Entities\PageComponent::withoutAuditLog(function () use ($componentsData) {
            $this->record->pageComponents()->get()->each->delete();

            foreach ($componentsData as $componentData) {
                $pageComponent = $this->record->pageComponents()->create([
                    'component_id' => $componentData['component_id'],
                    self::ORDER => $componentData[self::ORDER],
                    'instance_id' => $componentData['instance_id'],
                ]);

                if (!empty($componentData[self::CONFIG_VALUES])) {
                    $syncData = $this->prepareSyncData($componentData[self::CONFIG_VALUES]);
                    $pageComponent->pageComponentConfigurationValues()->sync($syncData);
                }
            }
        });

        $newLabels = collect($componentsData)
            ->map(fn ($c) => \Modules\Page\Entities\Component::find($c['component_id'])?->label ?? ('#' . $c['component_id']))
            ->all();

        \Modules\AuditLog\Services\AuditLogger::log(
            action: 'update',
            module: 'Page',
            record: $this->record,
            old: ['danh_sach_section' => implode(', ', $oldLabels)],
            new: ['danh_sach_section' => implode(', ', $newLabels)],
            label: 'Trang ' . ($this->record->title ?? '#' . $this->record->id) . ' — Cập nhật nội dung',
        );
    }

    public function prepareSyncData(array $data): array
    {
        $syncData = [];
        $configurations = ComponentConfiguration::whereIn('id', array_keys($data))->get();

        foreach ($data as $key => $value) {
            $configuration = $configurations->firstWhere('id', $key);
            if (!$configuration) {
                continue;
            }

            $type = $configuration->type ?? 'default';
            $fieldType = FieldInputType::tryFrom($configuration->type_field);

            $processedValue = $this->processValueByFieldType($fieldType, $value);

            if ($processedValue !== null) {
                $syncData[$key] = [
                    'value' => $processedValue,
                    'type' => $type,
                ];
            }
        }

        return $syncData;
    }

    private function processValueByFieldType(?FieldInputType $fieldType, $value)
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($fieldType) {
            FieldInputType::RANGE,
            FieldInputType::ICONS,
            FieldInputType::CONTENT_EDITOR,
            FieldInputType::CONTENT_COMPONENT,
            FieldInputType::TEXTAREA,
            FieldInputType::TEXT,
            FieldInputType::NUMBER,
            FieldInputType::TOGGLE,
            FieldInputType::RADIO,
            FieldInputType::COLOR_PICKER => $value,

            FieldInputType::TEMPLATE_WEBSITE_CATEGORY_SELECTION,
            FieldInputType::TEMPLATE_WEBSITE_SELECTION,
            FieldInputType::DOMAIN_SELECTION,
            FieldInputType::PRICING_GROUP_SELECTION,
            FieldInputType::PRICING_CATEGORY_SELECTION,
            FieldInputType::FORM_SELECTION,
            FieldInputType::PRODUCT_SELECTION,
            FieldInputType::POST_SELECTION,
            FieldInputType::POST_SELECTION_ONE,
            FieldInputType::CATEGORY_SELECTION_PRODUCT,
            FieldInputType::CATEGORY_SELECTION_POST,
            FieldInputType::DESTINATION,
            FieldInputType::AREA,
            FieldInputType::FAQ,
            FieldInputType::CATEGORY_SELECTION_PROCESS_DESIGN,
            FieldInputType::SELECT => $this->processSelectValue($value),


            FieldInputType::IMAGE_OR_VIDEO,
            FieldInputType::IMAGE => $this->processSingleImageValue($value),
            FieldInputType::IMAGES => $this->processMultipleImagesValue($value),

            FieldInputType::TESTIMONIAL,
            FieldInputType::STATS,
            FieldInputType::BUSINESS_BRANCHES,
            FieldInputType::SOCIAL_MEDIA,
            FieldInputType::SERVICES,
            FieldInputType::GROUP_CONTACT,
            FieldInputType::KEY_VALUES,
            FieldInputType::EFFECTIVENESS_PROOF,
            FieldInputType::KEY_VALUES,
            FieldInputType::VIDEO_LIST,

            FieldInputType::PROCESS_REPEATER => $this->processJsonEncodedValue($value),

            FieldInputType::SIDEBAR,
            // FieldInputType::INFO_CARD,
            // FieldInputType::ITEM_SWITCHER,
            FieldInputType::CHART_COMPONENT,
            FieldInputType::ABOUT_COMPONENT,
            FieldInputType::BUSSINESS_INTRODUCTION,
            FieldInputType::PERFORMANCE_RESULTS,
            FieldInputType::VISION_SECTION,
            FieldInputType::OVERVIEW,
            FieldInputType::BRANCH,
            FieldInputType::SERVICE_ICON,
            FieldInputType::REPEATER,
            FieldInputType::CHOOSE_ROOM,
            FieldInputType::BUILDER => $this->processBuilderValue($value),

            default => $this->processGenericValue($value),
        };
    }

    private function decodeJsonIfValid($value)
    {
        if (!is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
    }

    private function processSelectValue($value): ?string
    {
        if (is_array($value)) {
            $filteredValue = array_filter($value, fn($v) => $v !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);
            return !empty($filteredValue) ? implode(',', $filteredValue) : null;
        }
        return $value;
    }

    private function processSingleImageValue($value)
    {
        if (is_array($value) && !empty($value)) {
            return current(array_filter($value));
        }
        return is_string($value) && !empty($value) ? $value : null;
    }

    private function processMultipleImagesValue($value)
    {
        if (is_array($value)) {
            $filteredValue = array_filter($value);
            return !empty($filteredValue) ? json_encode($filteredValue, JSON_UNESCAPED_UNICODE) : null;
        }
        return null;
    }

    private function processJsonEncodedValue($value)
    {
        return is_array($value) && !empty($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
    }

    private function processGenericValue($value)
    {
        if (is_array($value)) {
            $filteredValue = $this->filterArrayRecursively($value);
            if (empty($filteredValue)) {
                return null;
            }
            if ($this->isAssociativeArray($filteredValue)) {
                return json_encode($filteredValue, JSON_UNESCAPED_UNICODE);
            }
            return implode(',', array_map(function ($item) {
                return is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : $item;
            }, $filteredValue));
        }
        return $value;
    }

    private function filterArrayRecursively($array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->filterArrayRecursively($value);
            }
        }
        return array_filter($array, function ($value) {
            return $value !== '' && $value !== null && $value !== [];
        });
    }

    private function isAssociativeArray($array)
    {
        if (!is_array($array)) return false;
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function processValueForFill(?FieldInputType $fieldType, $value)
    {
        if ($value === null) {
            return null;
        }

        return match ($fieldType) {
            FieldInputType::RANGE,
            FieldInputType::ICONS,
            FieldInputType::CONTENT_EDITOR,
            FieldInputType::CONTENT_COMPONENT,
            FieldInputType::TEXTAREA,
            FieldInputType::TEXT,
            FieldInputType::NUMBER,
            FieldInputType::TOGGLE,
            FieldInputType::RADIO,
            FieldInputType::COLOR_PICKER => $value,

            FieldInputType::TEMPLATE_WEBSITE_CATEGORY_SELECTION,
            FieldInputType::TEMPLATE_WEBSITE_SELECTION,
            FieldInputType::DOMAIN_SELECTION,
            FieldInputType::PRICING_GROUP_SELECTION,
            FieldInputType::PRICING_CATEGORY_SELECTION,
            FieldInputType::FORM_SELECTION,
            FieldInputType::PRODUCT_SELECTION,
            FieldInputType::POST_SELECTION,
            FieldInputType::POST_SELECTION_ONE,
            FieldInputType::CATEGORY_SELECTION_PRODUCT,
            FieldInputType::CATEGORY_SELECTION_POST,
            FieldInputType::DESTINATION,
            FieldInputType::AREA,
            FieldInputType::FAQ,
            FieldInputType::CATEGORY_SELECTION_PROCESS_DESIGN,
            FieldInputType::SELECT => $this->processSelectValueForFill($value),

            FieldInputType::IMAGE_OR_VIDEO,
            FieldInputType::IMAGE => $this->processSingleImageValueForFill($value),
            FieldInputType::IMAGES => $this->processMultipleImagesValueForFill($value),

            FieldInputType::TESTIMONIAL,
            FieldInputType::STATS,
            FieldInputType::BUSINESS_BRANCHES,
            FieldInputType::SOCIAL_MEDIA,
            FieldInputType::SERVICES,
            FieldInputType::GROUP_CONTACT,
//            FieldInputType::KEY_VALUES,
            FieldInputType::EFFECTIVENESS_PROOF,
            FieldInputType::KEY_VALUES,
            FieldInputType::VIDEO_LIST,
            FieldInputType::PROCESS_REPEATER => $this->processJsonEncodedValueForFill($value),

            FieldInputType::SIDEBAR,
//            FieldInputType::INFO_CARD,
//            FieldInputType::ITEM_SWITCHER,
            FieldInputType::CHART_COMPONENT => $this->decodeJsonIfValid($value),
            FieldInputType::ABOUT_COMPONENT => $this->decodeJsonIfValid($value),
            FieldInputType::BUSSINESS_INTRODUCTION => $this->decodeJsonIfValid($value),
            FieldInputType::PERFORMANCE_RESULTS => $this->decodeJsonIfValid($value),
            FieldInputType::VISION_SECTION => $this->decodeJsonIfValid($value),
            FieldInputType::OVERVIEW => $this->decodeJsonIfValid($value),
            FieldInputType::BRANCH,
            FieldInputType::SERVICE_ICON => $this->decodeJsonIfValid($value),
            FieldInputType::REPEATER => $this->decodeJsonIfValid($value),
            FieldInputType::CHOOSE_ROOM => $this->decodeJsonIfValid($value),
            FieldInputType::BUILDER => $this->processBuilderValueForFill($value),

            default => $this->processGenericValueForFill($value),
        };
    }

    private function processSelectValueForFill($value)
    {
        return is_string($value) ? explode(',', $value) : $value;
    }

    private function processSingleImageValueForFill($value)
    {
        return $value;
    }

    private function processMultipleImagesValueForFill($value)
    {
        return $this->isJson($value) ? json_decode($value, true) : $value;
    }

    private function processJsonEncodedValueForFill($value)
    {
        return $this->isJson($value) ? json_decode($value, true) : $value;
    }

    private function processGenericValueForFill($value)
    {
        return is_string($value) && str_contains($value, ',') ? explode(',', $value) : $value;
    }

    private function processBuilderValue($value)
    {
        if (is_array($value) && !empty($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return null;
    }

    private function processBuilderValueForFill($value)
    {
        if ($this->isJson($value)) {
            $decodedValue = json_decode($value, true);
            return array_map(function ($item) {
                return [
                    'type' => $item['type'] ?? '',
                    'data' => $item['data'] ?? [],
                ];
            }, $decodedValue);
        }
        return $value;
    }
}
