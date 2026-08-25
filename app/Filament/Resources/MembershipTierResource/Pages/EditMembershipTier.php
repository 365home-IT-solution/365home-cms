<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipTierResource\Pages;

use App\Filament\Resources\MembershipTierResource;
use App\Services\MembershipService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMembershipTier extends EditRecord
{
    protected static string $resource = MembershipTierResource::class;

    protected array $voucherTemplateRows = [];
    protected array $manualCouponIds     = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncAutoVouchers')
                ->label('Đồng bộ')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Đồng bộ voucher cho khách đang giữ hạng này')
                ->modalDescription('Rà lại từng khách đang giữ hạng này theo đúng cấu hình hiện tại ở "Coupon tự động cấp" (KHÔNG tính mã gắn tay ở "Mã giảm giá gắn thêm cho hạng"). Khách nào thiếu voucher nào so với cấu hình (vd dữ liệu cũ từ trước khi hạng có đủ số voucher như bây giờ) sẽ được cấp bù đúng phần còn thiếu — voucher khách đã có giữ nguyên, không cấp trùng. Đồng thời cập nhật lại GIỚI HẠN CHI NHÁNH của các voucher đã cấp trước đó theo đúng cấu hình hiện tại — chỉ áp dụng cho voucher khách CHƯA sử dụng lần nào; voucher đã dùng rồi giữ nguyên điều khoản cũ.')
                ->modalSubmitActionLabel('Đồng bộ ngay')
                ->form(fn () => [
                    Select::make('remove_coupon_keys')
                        ->label('Gỡ bớt mã khuyến mãi mồ côi khỏi khách đang giữ (tuỳ chọn)')
                        ->helperText('Chỉ liệt kê các mã KHÔNG thuộc cấu hình voucher chính thức của hạng (vd mã còn sót từ cơ chế cũ) và CHƯA được khách nào dùng. Không chọn gì thì vẫn đồng bộ bình thường như trước.')
                        ->options(fn () => app(MembershipService::class)->removableOrphanCouponOptionsForTier($this->record))
                        ->multiple()
                        ->searchable(),
                ])
                ->action(function (array $data) {
                    $service = app(MembershipService::class);

                    $removed = $service->removeOrphanCouponsFromTierMembers($this->record, $data['remove_coupon_keys'] ?? []);
                    $result  = $service->syncAutoVouchersForTierMembers($this->record);

                    $body = "Đã kiểm tra {$result['customers_checked']} khách hàng — {$result['customers_updated']} khách được cấp bù (tổng {$result['vouchers_granted']} voucher), {$result['vouchers_branch_synced']} voucher chưa dùng được cập nhật lại giới hạn chi nhánh.";
                    if ($removed > 0) {
                        $body .= " Đã gỡ {$removed} mã khuyến mãi mồ côi khỏi khách hàng.";
                    }

                    Notification::make()
                        ->title('Đồng bộ xong')
                        ->body($body)
                        ->success()
                        ->send();
                }),

            DeleteAction::make()->label('Xoá'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // Nạp lại Repeater 'voucher_templates' + Select 'manual_coupon_ids' từ các coupon đang gắn với
    // hạng này (quan hệ 'coupons', phân theo cột pivot 'source') — 2 field này không map trực tiếp
    // tới cột nào trên MembershipTier nên Filament không tự fill được.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $service = app(MembershipService::class);

        $data['voucher_templates'] = $service->voucherTemplatesToFormState($this->getRecord());
        $data['manual_coupon_ids'] = $service->manualCouponIdsToFormState($this->getRecord());

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->voucherTemplateRows = $data['voucher_templates'] ?? [];
        $this->manualCouponIds     = $data['manual_coupon_ids'] ?? [];
        unset($data['voucher_templates'], $data['manual_coupon_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        $service = app(MembershipService::class);

        $service->syncVoucherTemplates($this->record, $this->voucherTemplateRows);
        $service->syncManualCoupons($this->record, $this->manualCouponIds);
        $service->distributeTierCoupons($this->record);
    }
}
