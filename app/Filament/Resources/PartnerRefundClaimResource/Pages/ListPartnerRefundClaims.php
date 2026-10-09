<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerRefundClaimResource\Pages;

use App\Filament\Resources\PartnerRefundClaimResource;
use App\Services\RefundClaimService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Modules\Payment\Entities\Order;

class ListPartnerRefundClaims extends ListRecords
{
    protected static string $resource = PartnerRefundClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 365home mở yêu cầu hoàn tiền khách cho đơn có tiền đang ở đối tác; đối tác có 24 giờ để hoàn.
            Action::make('open')
                ->label('Tạo yêu cầu hoàn tiền')->icon('heroicon-o-plus')
                ->visible(function () {
                    $user = auth()->user();

                    return (bool) ($user?->isSuperAdmin() || $user?->belongsToPlatformPartner());
                })
                ->modalDescription('Dùng khi khách cần được hoàn tiền (huỷ hợp lệ, phòng không như mô tả…) mà tiền đang ở đối tác. Đối tác có ' . config('settlement.refund_grace_hours', 24) . ' giờ để hoàn; quá hạn, Super Admin có thể hoàn thay và trừ ký quỹ của đối tác.')
                ->form([
                    Forms\Components\TextInput::make('order_code')->label('Mã đơn')->required()->maxLength(50),
                    Forms\Components\TextInput::make('amount')->label('Số tiền hoàn (đ)')->numeric()->required()->minValue(1),
                    Forms\Components\Textarea::make('reason')->label('Lý do')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    $order = Order::withoutGlobalScopes()->where('order_code', trim($data['order_code']))->first();

                    if (! $order) {
                        Notification::make()->danger()->title('Không tìm thấy đơn có mã này.')->send();

                        return;
                    }

                    try {
                        $claim = app(RefundClaimService::class)->open($order, (int) $data['amount'], $data['reason'], auth()->user());
                        Notification::make()->success()->title('Đã tạo yêu cầu hoàn tiền')->body('Đối tác được báo và có đến ' . $claim->due_at->format('d/m/Y H:i') . ' để hoàn.')->send();
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
