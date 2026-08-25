<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipTierResource\Pages;

use App\Filament\Resources\MembershipTierResource;
use App\Services\MembershipService;
use Filament\Resources\Pages\CreateRecord;

class CreateMembershipTier extends CreateRecord
{
    protected static string $resource = MembershipTierResource::class;

    protected array $voucherTemplateRows = [];
    protected array $manualCouponIds     = [];

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // 'voucher_templates' và 'manual_coupon_ids' không phải cột trên MembershipTier — tách ra
    // trước khi tạo record, xử lý riêng ở afterCreate() (xem MembershipService::syncVoucherTemplates()
    // / syncManualCoupons() — dùng chung với REST API Api\Admin\MembershipTierController).
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->voucherTemplateRows = $data['voucher_templates'] ?? [];
        $this->manualCouponIds     = $data['manual_coupon_ids'] ?? [];
        unset($data['voucher_templates'], $data['manual_coupon_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $service = app(MembershipService::class);

        $service->syncVoucherTemplates($this->record, $this->voucherTemplateRows);
        $service->syncManualCoupons($this->record, $this->manualCouponIds);
        $service->distributeTierCoupons($this->record);
    }
}
