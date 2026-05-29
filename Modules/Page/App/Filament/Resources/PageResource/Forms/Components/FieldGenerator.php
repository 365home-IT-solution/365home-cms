<?php

namespace Modules\Page\App\Filament\Resources\PageResource\Forms\Components;

use Filament\Forms\Components\Fieldset;
use Modules\Component\App\Enums\FieldInputType;

class FieldGenerator
{
    private BasicFieldGenerator $basicFieldGenerator;
    private SelectFieldGenerator $selectFieldGenerator;
    private MediaFieldGenerator $mediaFieldGenerator;
    private ContentFieldGenerator $contentFieldGenerator;
    private BuilderFieldGenerator $builderFieldGenerator;

    public function __construct()
    {
        $this->basicFieldGenerator = new BasicFieldGenerator();
        $this->mediaFieldGenerator = new MediaFieldGenerator();
        $this->contentFieldGenerator = new ContentFieldGenerator();
        $this->selectFieldGenerator = new SelectFieldGenerator();
        $this->builderFieldGenerator = new BuilderFieldGenerator();

    }

    public function generateFields($configs): array
    {
        $groupedFields = $configs->groupBy('field_set');
        $fieldsets = [];

        foreach ($groupedFields as $fieldsetName => $fields) {
            if ($fieldsetName) {
                $fieldsets[] = Fieldset::make(ucwords($fieldsetName))
                    ->schema($fields->map(fn($field) => $this->createField($field))->toArray())
                    ->columns(3);
            } else {
                $fieldsets = array_merge($fieldsets, $fields->map(fn($field) => $this->createField($field))->toArray());
            }
        }

        return $fieldsets;
    }

    private function createField($config)
    {
        // has_options chỉ áp dụng cho SELECT generic (tĩnh, lấy từ comp_config_options).
        // Các type động (DESTINATION, AREA, PRODUCT_SELECTION…) luôn dùng query riêng,
        // không được override bởi has_options để tránh dropdown trống.
        if ($config->has_options && $config->type_field === FieldInputType::SELECT->value) {
            return $this->selectFieldGenerator->createSelectFieldWithOptions($config);
        }

        return match ($config->type_field) {
            // Basic Fields
            FieldInputType::TEXT->value => $this->basicFieldGenerator->createTextField($config),
            FieldInputType::NUMBER->value => $this->basicFieldGenerator->createNumberField($config),
            FieldInputType::TEXTAREA->value => $this->basicFieldGenerator->createTextareaField($config),
            FieldInputType::TOGGLE->value => $this->basicFieldGenerator->createToggleField($config),
            FieldInputType::COLOR_PICKER->value => $this->basicFieldGenerator->createColorPickerField($config),
            FieldInputType::RANGE->value => $this->basicFieldGenerator->createRangeField($config),
            
            // Select Fields
            FieldInputType::FORM_SELECTION->value => $this->selectFieldGenerator->createFormSelectionField($config),
            FieldInputType::PRODUCT_SELECTION->value => $this->selectFieldGenerator->createProductSelectionField($config),
            FieldInputType::POST_SELECTION->value, 
            FieldInputType::POST_SELECTION_ONE->value => $this->selectFieldGenerator->createPostSelectionField($config),
            FieldInputType::CATEGORY_SELECTION_PRODUCT->value,
            FieldInputType::CATEGORY_SELECTION_POST->value,
            FieldInputType::DESTINATION->value,
            FieldInputType::AREA->value,
            FieldInputType::CATEGORY_SELECTION_PROCESS_DESIGN->value => $this->selectFieldGenerator->createCategorySelectionField($config),
            FieldInputType::TEMPLATE_WEBSITE_SELECTION->value => $this->selectFieldGenerator->createTemplateWebsiteSelectionField($config),
            FieldInputType::TEMPLATE_WEBSITE_CATEGORY_SELECTION->value => $this->selectFieldGenerator->createTemplateWebsiteCategorySelectionField($config),
            FieldInputType::DOMAIN_SELECTION->value => $this->selectFieldGenerator->createDomainSelectionField($config),
            FieldInputType::PRICING_GROUP_SELECTION->value,
            FieldInputType::PRICING_CATEGORY_SELECTION->value => $this->selectFieldGenerator->createPricingSelectionField($config),
            FieldInputType::FAQ->value => $this->selectFieldGenerator->createFAQField($config),
            
            // Media Fields
            FieldInputType::IMAGE->value,
            FieldInputType::IMAGES->value,
            FieldInputType::IMAGE_OR_VIDEO->value => $this->mediaFieldGenerator->createFileUploadField($config),
            FieldInputType::ICONS->value => $this->mediaFieldGenerator->createIconField($config),
            
            // Content Fields
            FieldInputType::KEY_VALUES->value => $this->contentFieldGenerator->createKeyValuesField($config),
            FieldInputType::GROUP_CONTACT->value => $this->contentFieldGenerator->createGroupContactField($config),
            FieldInputType::CONTENT_MEDIA->value => $this->contentFieldGenerator->createContentMediaField($config),
            FieldInputType::SOCIAL_MEDIA->value => $this->contentFieldGenerator->createSocialMediaField($config),
            FieldInputType::CONTENT_EDITOR->value => $this->contentFieldGenerator->createContentEditorField($config),
            FieldInputType::CONTENT_COMPONENT->value => $this->contentFieldGenerator->createContentComponent($config),
            FieldInputType::STATS->value => $this->contentFieldGenerator->createStatField($config),
            FieldInputType::BUSINESS_BRANCHES->value => $this->contentFieldGenerator->createBusinessBranchField($config),
            FieldInputType::EFFECTIVENESS_PROOF->value => $this->contentFieldGenerator->createEffectivenessProofField($config),
            FieldInputType::VIDEO_LIST->value => $this->contentFieldGenerator->createVideoListField($config),
            
            // Builder Fields
            FieldInputType::PROCESS_REPEATER->value => $this->builderFieldGenerator->createProcessRepeaterField($config),
            FieldInputType::BUILDER->value => $this->builderFieldGenerator->createBuilderField($config),
            FieldInputType::SIDEBAR->value => $this->builderFieldGenerator->createSidebarBuilder($config),
            FieldInputType::TESTIMONIAL->value => $this->builderFieldGenerator->createTestimonial($config),
            FieldInputType::CHART_COMPONENT->value => $this->builderFieldGenerator->createChartComponent($config),
            FieldInputType::ABOUT_COMPONENT->value => $this->builderFieldGenerator->createBannerComponent($config),
            FieldInputType::BUSSINESS_INTRODUCTION->value => $this->builderFieldGenerator->createBusinessIntroduction($config),
            FieldInputType::PERFORMANCE_RESULTS->value => $this->builderFieldGenerator->createPerformanceResult($config),
            FieldInputType::VISION_SECTION->value => $this->builderFieldGenerator->createVisionSection($config),
            FieldInputType::OVERVIEW->value => $this->builderFieldGenerator->createOverview($config),
            FieldInputType::BRANCH->value => $this->builderFieldGenerator->createBranch($config),
            FieldInputType::SERVICE_ICON->value => $this->builderFieldGenerator->createServiceIcon($config),
            FieldInputType::REPEATER->value => $this->builderFieldGenerator->createRepeater($config),
            FieldInputType::CHOOSE_ROOM->value => $this->builderFieldGenerator->createChooseRoom($config),
            FieldInputType::SERVICES->value => $this->builderFieldGenerator->createServiceField($config),
            
            default => $this->basicFieldGenerator->createTextField($config),
        };
    }
}