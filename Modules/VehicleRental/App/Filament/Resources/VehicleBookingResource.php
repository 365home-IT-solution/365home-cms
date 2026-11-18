<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources;

use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\VehicleRental\App\Filament\Resources\VehicleBookingResource\Pages;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Services\VehicleKycService;
use Modules\VehicleRental\App\Support\VehicleRentalAccess;

// Đơn thuê xe. Đơn do khách tạo qua API; nhân viên quầy giao/nhận/tất toán ngay trên trang chi tiết (xem ViewVehicleBooking).
// Hard-code Super Admin + vai trò "Nhân viên thuê xe" (VehicleRentalAccess), như các màn tài chính khác.
class VehicleBookingResource extends Resource
{
    protected static ?string $model = VehicleBooking::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Đơn thuê xe';

    protected static ?string $modelLabel = 'Đơn thuê xe';

    protected static ?string $pluralModelLabel = 'Đơn thuê xe';

    protected static ?string $slug = 'rental-vehicle-bookings';

    protected static ?int $navigationSort = 3;

    public const STATUSES = [
        VehicleBooking::STATUS_PENDING_PAYMENT => 'Chờ thanh toán',
        VehicleBooking::STATUS_CONFIRMED       => 'Đã xác nhận',
        VehicleBooking::STATUS_PICKED_UP       => 'Đang thuê',
        VehicleBooking::STATUS_RETURNED        => 'Đã trả xe',
        VehicleBooking::STATUS_SETTLED         => 'Đã tất toán',
        VehicleBooking::STATUS_CANCELLED       => 'Đã huỷ',
        VehicleBooking::STATUS_NO_SHOW         => 'Không đến nhận',
    ];

    public static function canViewAny(): bool
    {
        return VehicleRentalAccess::canViewBookings(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle', 'customer', 'handovers', 'charges', 'roomOrder']);
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => number_format((int) $state, 0, ',', '.') . 'đ';

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Mã đơn')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('vehicle.plate_display')->label('Xe')->searchable(['plate', 'plate_display'])
                    ->description(fn (VehicleBooking $r) => trim(($r->vehicle?->brand ?? '') . ' ' . ($r->vehicle?->model ?? ''))),
                Tables\Columns\TextColumn::make('customer.fullname')->label('Khách')->placeholder('—')
                    ->description(fn (VehicleBooking $r) => $r->customer?->phone),
                Tables\Columns\TextColumn::make('start_at')->label('Nhận')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('end_at')->label('Trả')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        VehicleBooking::STATUS_CONFIRMED, VehicleBooking::STATUS_SETTLED => 'success',
                        VehicleBooking::STATUS_PICKED_UP                                  => 'info',
                        VehicleBooking::STATUS_PENDING_PAYMENT                            => 'warning',
                        VehicleBooking::STATUS_CANCELLED, VehicleBooking::STATUS_NO_SHOW  => 'danger',
                        default                                                           => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total_amount')->label('Tổng tiền')->formatStateUsing($money),
                Tables\Columns\TextColumn::make('paid_amount')->label('Đã thu')->formatStateUsing($money),
                Tables\Columns\IconColumn::make('room_order_id')->label('Kèm phòng')->boolean()
                    ->getStateUsing(fn (VehicleBooking $r) => $r->room_order_id !== null)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(self::STATUSES),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->emptyStateHeading('Chưa có đơn thuê xe');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $money = fn ($state) => number_format((int) $state, 0, ',', '.') . 'đ';

        return $infolist->schema([
            Infolists\Components\Section::make('Đơn thuê')->columns(3)->schema([
                Infolists\Components\TextEntry::make('code')->label('Mã đơn')->weight('bold'),
                Infolists\Components\TextEntry::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state),
                Infolists\Components\TextEntry::make('vehicle.plate_display')->label('Xe'),
                Infolists\Components\TextEntry::make('vehicle.odometer_km')->label('Số km hiện tại của xe')->numeric(thousandsSeparator: '.')->suffix(' km')
                    ->helperText('Nhân viên chỉnh lại cho đúng đồng hồ khi giao xe (hoặc sửa ở Đội xe).'),
                Infolists\Components\TextEntry::make('customer.fullname')->label('Khách')->placeholder('—'),
                Infolists\Components\TextEntry::make('customer.phone')->label('Điện thoại')->placeholder('—'),
                Infolists\Components\TextEntry::make('roomOrder.order_code')->label('Đơn phòng kèm theo')->placeholder('Thuê độc lập')->prefix(fn (VehicleBooking $r) => $r->room_order_id ? '#' : null),
                Infolists\Components\TextEntry::make('start_at')->label('Nhận xe')->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('pricing_mode')->label('Cách tính tiền')
                    ->state(function (VehicleBooking $r) {
                        $q = $r->pricing_snapshot['quote'] ?? [];
                        if (($r->pricing_snapshot['mode'] ?? 'time') !== 'km') {
                            return 'Theo thời gian (giờ/ngày)';
                        }

                        return 'Theo km: ước tính ' . ($q['estimated_km'] ?? '?') . ' km × ' . number_format((int) ($q['price_per_km'] ?? 0), 0, ',', '.') . 'đ'
                            . ($r->km_driven !== null ? ' — thực tế ' . $r->km_driven . ' km' : '');
                    }),
                Infolists\Components\TextEntry::make('pickup_point')->label('Điểm giao xe')->columnSpan(2)
                    ->state(function (VehicleBooking $r) {
                        $p = \Modules\VehicleRental\App\Support\VehicleTransformer::pickupPoint($r);
                        $text = filled($p['address']) ? $p['address'] : 'Chưa rõ địa chỉ';

                        return ($p['source'] === 'vehicle' ? 'Vị trí xe (GPS): ' : 'Chi nhánh: ') . $text . ($p['live'] ? ' — cập nhật theo vị trí hiện tại của xe' : '');
                    })
                    ->url(fn (VehicleBooking $r) => \Modules\VehicleRental\App\Support\VehicleTransformer::pickupPoint($r)['maps_url'], true),
                Infolists\Components\TextEntry::make('end_at')->label('Trả xe (dự kiến)')->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('actual_end_at')->label('Trả xe (thực tế)')->dateTime('d/m/Y H:i')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Tiền')->columns(3)->schema([
                Infolists\Components\TextEntry::make('rental_amount')->label('Tiền thuê')->formatStateUsing($money),
                Infolists\Components\TextEntry::make('discount_amount')->label('Giảm giá')->formatStateUsing($money),
                Infolists\Components\TextEntry::make('extra_amount')->label('Phát sinh')->formatStateUsing($money),
                Infolists\Components\TextEntry::make('deposit_amount')->label('Cọc thế chấp (thu tại quầy)')->formatStateUsing($money),
                Infolists\Components\TextEntry::make('total_amount')->label('Tổng tiền')->formatStateUsing($money),
                Infolists\Components\TextEntry::make('paid_amount')->label('Đã thu')->formatStateUsing($money),
                Infolists\Components\TextEntry::make('paid_at')->label('Thu lúc')->dateTime('d/m/Y H:i')->placeholder('Chưa thanh toán'),
                Infolists\Components\TextEntry::make('refund_amount')->label('Cần hoàn khách')->formatStateUsing($money)
                    ->color(fn (VehicleBooking $r) => $r->refund_amount > 0 && ! $r->refunded_at ? 'danger' : null),
                Infolists\Components\TextEntry::make('refunded_at')->label('Đã hoàn lúc')->dateTime('d/m/Y H:i')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Giấy tờ khách')->schema([
                Infolists\Components\TextEntry::make('kyc')->label('Điều kiện nhận xe')->state(function (VehicleBooking $r) {
                    return $r->vehicle
                        ? (app(VehicleKycService::class)->ineligibleReason($r->customer_id, $r->vehicle, $r->end_at) ?? 'Đủ điều kiện (giấy tờ đã duyệt, GPLX hợp lệ)')
                        : '—';
                })->color(fn (VehicleBooking $r, $state) => str_starts_with((string) $state, 'Đủ') ? 'success' : 'danger'),
            ])->visible(fn (VehicleBooking $r) => in_array($r->status, [VehicleBooking::STATUS_CONFIRMED, VehicleBooking::STATUS_PENDING_PAYMENT], true)),
            Infolists\Components\Section::make('Biên bản giao / nhận xe')->visible(fn (VehicleBooking $r) => $r->handovers->isNotEmpty())->schema([
                Infolists\Components\RepeatableEntry::make('handovers')->label('')->columns(4)->schema([
                    Infolists\Components\TextEntry::make('type')->label('Biên bản')->badge()
                        ->formatStateUsing(fn ($state) => $state === 'pickup' ? 'Giao xe' : 'Nhận lại xe'),
                    Infolists\Components\TextEntry::make('odometer_km')->label('Số km')->numeric(thousandsSeparator: '.'),
                    Infolists\Components\TextEntry::make('fuel_level')->label('Xăng/pin')->suffix('%')->placeholder('—'),
                    Infolists\Components\TextEntry::make('handled_at')->label('Lúc')->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('notes')->label('Ghi chú')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\ImageEntry::make('photos')->label('Ảnh')->disk('public')->height(90)->columnSpanFull(),
                ]),
            ]),
            Infolists\Components\Section::make('Phụ phí & tất toán')->visible(fn (VehicleBooking $r) => $r->charges->isNotEmpty() || $r->settled_at !== null || $r->deposit_collected_amount > 0)->schema([
                Infolists\Components\RepeatableEntry::make('charges')->label('')->columns(3)->schema([
                    Infolists\Components\TextEntry::make('label')->label('Khoản'),
                    Infolists\Components\TextEntry::make('type')->label('Loại')->badge()
                        ->formatStateUsing(fn ($state) => \Modules\VehicleRental\App\Models\VehicleCharge::TYPES[$state] ?? $state),
                    Infolists\Components\TextEntry::make('amount')->label('Số tiền')->formatStateUsing($money),
                ])->visible(fn (VehicleBooking $r) => $r->charges->isNotEmpty()),
                Infolists\Components\Group::make([
                    Infolists\Components\TextEntry::make('deposit_collected_amount')->label('Cọc đã thu')->formatStateUsing($money),
                    Infolists\Components\TextEntry::make('deposit_deducted_amount')->label('Trừ vào cọc')->formatStateUsing($money),
                    Infolists\Components\TextEntry::make('deposit_refund_amount')->label('Đã hoàn lại cọc')->formatStateUsing($money),
                    Infolists\Components\TextEntry::make('extra_due_amount')->label('Khách trả thêm tại quầy')->formatStateUsing($money),
                    Infolists\Components\TextEntry::make('km_driven')->label('Km đã chạy')->numeric(thousandsSeparator: '.')->placeholder('—'),
                    Infolists\Components\TextEntry::make('settled_at')->label('Tất toán lúc')->dateTime('d/m/Y H:i')->placeholder('Chưa tất toán'),
                ])->columns(3),
            ]),
            Infolists\Components\Section::make('GPS & hành trình')->visible(fn (VehicleBooking $r) => class_exists(\Modules\Motrak\App\Models\MotrakVehicle::class)
                && app(\Modules\VehicleRental\App\Services\VehicleGpsService::class)->trackerFor($r->vehicle_id) !== null)->schema([
                Infolists\Components\ViewEntry::make('gps')->hiddenLabel()->columnSpanFull()->view('vehiclerental::partials.booking-gps'),
            ]),
            Infolists\Components\Section::make('Huỷ')->columns(2)->visible(fn (VehicleBooking $r) => $r->cancelled_at !== null)->schema([
                Infolists\Components\TextEntry::make('cancelled_at')->label('Huỷ lúc')->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('cancel_reason')->label('Lý do')->state(fn (VehicleBooking $r) => $r->cancelReasonLabel())->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicleBookings::route('/'),
            'view'  => Pages\ViewVehicleBooking::route('/{record}'),
        ];
    }
}
