<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleBookingResource\Pages;

use DomainException;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\VehicleRental\App\Filament\Resources\VehicleBookingResource;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Models\VehicleCharge;
use Modules\VehicleRental\App\Services\VehicleBookingService;
use Modules\VehicleRental\App\Services\VehicleHandoverService;
use Modules\VehicleRental\App\Support\VehicleRentalAccess;

// Quầy chi nhánh: giao xe, nhận xe, phụ phí, tất toán, không đến nhận, huỷ, đánh dấu đã hoàn tiền — cùng service với API admin.
class ViewVehicleBooking extends ViewRecord
{
    protected static string $resource = VehicleBookingResource::class;

    /** Chạy 1 thao tác nghiệp vụ: lỗi nghiệp vụ hiện thông báo đỏ, thành công làm mới trang. */
    private function perform(\Closure $operation, string $successTitle): void
    {
        try {
            $operation(app(VehicleHandoverService::class));
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return;
        }

        $this->record->refresh();
        $this->refreshFormData([]);
        Notification::make()->success()->title($successTitle)->send();
    }

    private function status(): string
    {
        return (string) $this->record->status;
    }

    private function photoField(): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make('photos')->label('Ảnh xe (nhiều góc)')->image()->multiple()->maxFiles(12)
            ->disk('public')->directory('rental-handovers')->columnSpanFull();
    }

    protected function getHeaderActions(): array
    {
        if (! VehicleRentalAccess::canOperateBookings(auth()->user())) {
            return []; // chỉ có quyền xem đơn: không có nút giao/nhận/thu tiền/huỷ…
        }

        $money = fn (int $v) => number_format($v, 0, ',', '.') . 'đ';
        $staff = fn () => (string) auth()->id();

        return [
            Actions\Action::make('pickup')->label('Giao xe')->icon('heroicon-o-key')->color('primary')
                ->visible(fn () => $this->status() === VehicleBooking::STATUS_CONFIRMED)
                ->modalHeading('Giao xe cho khách')
                ->modalDescription(function () {
                    $base = 'Kiểm tra giấy tờ, chụp ảnh xe, ghi số km và thu cọc thế chấp trước khi giao.';
                    $warnings = $this->record->vehicle ? app(\Modules\VehicleRental\App\Services\VehicleGpsService::class)->pickupWarnings($this->record->vehicle) : [];

                    return $warnings ? $base . ' ⚠ GPS: ' . implode(' ', $warnings) : $base;
                })
                ->form(fn () => [Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('odometer_km')->label('Số km hiện tại')->numeric()->required()->minValue(0)
                        ->default($this->record->vehicle?->odometer_km)->suffix('km'),
                    Forms\Components\TextInput::make('fuel_level')->label('Mức xăng/pin')->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                    Forms\Components\TextInput::make('deposit_collected')->label('Cọc đã thu tại quầy')->numeric()->required()->minValue(0)
                        ->default($this->record->deposit_amount)->suffix('đ')
                        ->helperText('Cọc yêu cầu: ' . $money((int) $this->record->deposit_amount)),
                    $this->photoField(),
                    Forms\Components\Textarea::make('notes')->label('Tình trạng xe / ghi chú')->rows(2)->columnSpanFull(),
                ])])
                ->action(fn (array $data) => $this->perform(
                    fn (VehicleHandoverService $s) => $s->pickup($this->record, $staff(), [
                        'odometer_km' => (int) $data['odometer_km'], 'fuel_level' => $data['fuel_level'] ?? null,
                        'deposit_collected' => (int) $data['deposit_collected'], 'photos' => array_values($data['photos'] ?? []), 'notes' => $data['notes'] ?? null,
                    ]),
                    'Đã giao xe',
                )),

            Actions\Action::make('returnVehicle')->label('Nhận lại xe')->icon('heroicon-o-arrow-uturn-left')->color('primary')
                ->visible(fn () => $this->status() === VehicleBooking::STATUS_PICKED_UP)
                ->modalHeading('Nhận lại xe')
                ->modalDescription('Hệ thống tự tính trễ giờ và km vượt theo gói giá lúc đặt. Thêm phụ phí khác nếu có.')
                ->form([Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('odometer_km')->label('Số km khi trả')->numeric()->required()->minValue(0)->suffix('km'),
                    Forms\Components\TextInput::make('fuel_level')->label('Mức xăng/pin')->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                    $this->photoField(),
                    Forms\Components\Textarea::make('notes')->label('Tình trạng xe khi trả')->rows(2)->columnSpanFull(),
                    Forms\Components\Repeater::make('charges')->label('Phụ phí khác (thiếu xăng, vệ sinh, hư hỏng...)')->columnSpanFull()->defaultItems(0)->addActionLabel('Thêm phụ phí')
                        ->schema([
                            Forms\Components\Select::make('type')->label('Loại')->native(false)->required()
                                ->options(collect(VehicleCharge::TYPES)->except(['late', 'km'])->all()),
                            Forms\Components\TextInput::make('label')->label('Nội dung')->required()->maxLength(150),
                            Forms\Components\TextInput::make('amount')->label('Số tiền')->numeric()->required()->minValue(1)->suffix('đ'),
                        ])->columns(3),
                ])])
                ->action(fn (array $data) => $this->perform(
                    fn (VehicleHandoverService $s) => $s->returnVehicle($this->record, $staff(), [
                        'odometer_km' => (int) $data['odometer_km'], 'fuel_level' => $data['fuel_level'] ?? null,
                        'photos' => array_values($data['photos'] ?? []), 'notes' => $data['notes'] ?? null, 'charges' => array_values($data['charges'] ?? []),
                    ]),
                    'Đã nhận lại xe',
                )),

            Actions\Action::make('addCharge')->label('Thêm phụ phí')->icon('heroicon-o-plus-circle')->color('gray')
                ->visible(fn () => $this->status() === VehicleBooking::STATUS_RETURNED)
                ->form([
                    Forms\Components\Select::make('type')->label('Loại')->native(false)->required()
                        ->options(collect(VehicleCharge::TYPES)->except(['late', 'km'])->all()),
                    Forms\Components\TextInput::make('label')->label('Nội dung')->required()->maxLength(150),
                    Forms\Components\TextInput::make('amount')->label('Số tiền')->numeric()->required()->minValue(1)->suffix('đ'),
                    Forms\Components\Textarea::make('note')->label('Ghi chú')->rows(2),
                ])
                ->action(fn (array $data) => $this->perform(
                    fn (VehicleHandoverService $s) => $s->addCharge($this->record, $data['type'], $data['label'], (int) $data['amount'], $data['note'] ?? null, [], $staff()),
                    'Đã thêm phụ phí',
                )),

            Actions\Action::make('settle')->label('Tất toán')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => $this->status() === VehicleBooking::STATUS_RETURNED)
                ->modalHeading('Tất toán đơn thuê')
                ->modalDescription(function () use ($money) {
                    $p = app(VehicleHandoverService::class)->settlementPreview($this->record);

                    return "Phụ phí: {$money($p['extra'])} · Cọc đã thu: {$money($p['deposit'])} · Trừ vào cọc: {$money($p['deducted'])} · "
                        . "Hoàn lại cọc: {$money($p['refund'])}" . ($p['due'] > 0 ? " · Khách trả thêm tại quầy: {$money($p['due'])}" : '');
                })
                ->form(fn () => app(VehicleHandoverService::class)->settlementPreview($this->record)['due'] > 0
                    ? [Forms\Components\Toggle::make('collected_due')->label('Đã thu đủ khoản vượt cọc của khách tại quầy')->required()->accepted()]
                    : [])
                ->action(fn (array $data) => $this->perform(
                    fn (VehicleHandoverService $s) => $s->settle($this->record, $staff(), (bool) ($data['collected_due'] ?? false)),
                    'Đã tất toán',
                )),

            Actions\Action::make('noShow')->label('Không đến nhận')->icon('heroicon-o-user-minus')->color('warning')
                ->visible(fn () => $this->status() === VehicleBooking::STATUS_CONFIRMED
                    && now()->gte($this->record->start_at->copy()->addMinutes((int) config('vehiclerental.no_show_after_minutes', 120))))
                ->requiresConfirmation()
                ->modalDescription('Khách không đến nhận xe: đơn đóng lại và tiền thuê không hoàn.')
                ->action(fn () => $this->perform(fn (VehicleHandoverService $s) => $s->markNoShow($this->record), 'Đã đánh dấu không đến nhận')),

            // Khách đã chuyển khoản / trả tại quầy nhưng webhook PayOS chưa về: nhân viên xác nhận thủ công — cùng service với API admin.
            Actions\Action::make('confirmPayment')->label('Xác nhận đã thanh toán')->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn () => $this->record->paid_at === null && (
                    $this->status() === VehicleBooking::STATUS_PENDING_PAYMENT
                    || ($this->status() === VehicleBooking::STATUS_CANCELLED && $this->record->cancel_reason === VehicleBooking::CANCEL_HOLD_EXPIRED)))
                ->requiresConfirmation()
                ->modalHeading('Xác nhận đã nhận tiền')
                ->modalDescription(fn () => 'Chỉ xác nhận khi đã nhận đủ ' . number_format((int) $this->record->total_amount, 0, ',', '.') . 'đ từ khách (kiểm tra sao kê/quầy). Đơn chuyển sang "Đã xác nhận" và khách nhận thông báo.')
                ->form([Forms\Components\Textarea::make('note')->label('Ghi chú (VD: chuyển khoản VCB lúc 14:05)')->rows(2)->maxLength(255)])
                ->action(function (array $data) {
                    try {
                        app(VehicleBookingService::class)->confirmPaymentManually($this->record, (string) auth()->id(), $data['note'] ?? null);
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                        return;
                    }
                    $this->record->refresh();
                    $this->refreshFormData([]);
                    Notification::make()->success()->title('Đã xác nhận thanh toán')->send();
                }),

            // Nhân viên huỷ đơn (VD xe hỏng): hoàn 100% số đã thu — cùng service với API admin.
            Actions\Action::make('cancelBooking')->label('Huỷ đơn')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn () => app(VehicleBookingService::class)->canCancel($this->record))
                ->requiresConfirmation()
                ->modalDescription('Nhân viên huỷ đơn sẽ hoàn 100% số tiền khách đã thanh toán.')
                ->form([Forms\Components\Textarea::make('reason')->label('Lý do huỷ')->rows(2)->maxLength(255)])
                ->action(function (array $data) {
                    try {
                        app(VehicleBookingService::class)->cancel($this->record, $data['reason'] ?? null, true);
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    $this->record->refresh();
                    Notification::make()->success()->title('Đã huỷ đơn')->send();
                }),

            // Hoàn tiền là chuyển khoản thủ công: sau khi chuyển, đánh dấu để không nhắc lại.
            Actions\Action::make('markRefunded')->label('Đã hoàn tiền cho khách')->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn () => $this->record->refund_amount > 0 && $this->record->refunded_at === null)
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Xác nhận đã chuyển khoản hoàn ' . number_format($this->record->refund_amount, 0, ',', '.') . 'đ cho khách.')
                ->action(function () {
                    try {
                        app(VehicleBookingService::class)->markRefunded($this->record);
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    $this->record->refresh();
                    Notification::make()->success()->title('Đã ghi nhận hoàn tiền')->send();
                }),
        ];
    }
}
