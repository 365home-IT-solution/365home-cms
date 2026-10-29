<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Tables;

use App\Models\Partner;
use App\Models\PartnerSubscription;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PartnerTable
{
    private const STATUS_LABELS = [
        'pending'   => 'Chờ phê duyệt',
        'approved'  => 'Đang hoạt động',
        'suspended' => 'Ngừng hoạt động',
        'rejected'  => 'Từ chối',
    ];

    private const STATUS_COLORS = [
        'pending'   => 'warning',
        'approved'  => 'success',
        'suspended' => 'gray',
        'rejected'  => 'danger',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('subscription.plan'))
            ->columns([
                TextColumn::make('name')
                    ->label('Tên đối tác')
                    ->searchable()
                    ->sortable(),

                // Hai ô nhập tay ở form đối tác (tab Doanh nghiệp), không lấy từ hợp đồng.
                TextColumn::make('common_name')
                    ->label('Tên thường gọi')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('manual_contract_no')
                    ->label('Số hợp đồng')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('id')
                    ->label('Mã đối tác')
                    ->formatStateUsing(fn (string $state): string => 'PART-' . strtoupper(substr($state, 0, 6)))
                    ->copyable(),

                TextColumn::make('representative_name')
                    ->label('Người liên hệ')
                    ->placeholder('—')
                    ->description(fn (Partner $record) => $record->email, position: 'below'),

                TextColumn::make('verification_status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray')
                    ->sortable(),

                // MiniHouse: gói đang dùng, hạn dùng và số ngày còn lại (Homestay không dùng gói).
                TextColumn::make('subscription_state')
                    ->label('Gói dịch vụ')
                    ->visible(fn () => Filament::getCurrentPanel()?->getId() === 'minihouse-admin')
                    ->badge()
                    ->placeholder('Chưa có gói')
                    ->state(fn (Partner $record) => $record->subscription ? PartnerSubscription::STATES[$record->subscription->state()] : null)
                    ->color(fn (Partner $record) => match ($record->subscription?->state()) {
                        PartnerSubscription::STATE_ACTIVE => 'success', PartnerSubscription::STATE_TRIAL => 'info', PartnerSubscription::STATE_EXPIRED => 'danger', default => 'gray',
                    })
                    ->description(fn (Partner $record) => ($sub = $record->subscription)?->expires_at
                        ? ($sub->plan?->name ? $sub->plan->name . ' · ' : '') . 'Hết hạn ' . $sub->expires_at->format('d/m/Y')
                            . ($sub->isExpired() ? ' (đã hết hạn)' : ' · còn ' . $sub->daysLeft() . ' ngày')
                        : null),

                TextColumn::make('categories_count')
                    ->label('Số cơ sở')
                    ->counts('categories')
                    ->badge()
                    ->color('gray'),

                // Tắt cho đối tác dùng thử/dữ liệu test: đơn + phòng của họ không vào số liệu tổng hợp của Super Admin
                // (dashboard, báo cáo, API doanh thu) — chính đối tác vẫn thấy đủ số liệu của mình. Xem App\Support\PlatformStats.
                ToggleColumn::make('count_in_platform_stats')
                    ->label('Tính vào thống kê')
                    ->tooltip('Tắt để doanh thu, đơn và phòng của đối tác này không cộng vào số liệu tổng hợp của Super Admin.')
                    ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),

                // Bật: khách đặt phòng của đối tác này trên web phải tải CCCD 2 mặt (mặt có mã QR + mặt còn lại), thay vì 1 mặt.
                ToggleColumn::make('cccd_two_sides')
                    ->label('CCCD 2 mặt')
                    ->tooltip('Bật để khách đặt phòng của đối tác này trên website phải tải cả 2 mặt CCCD (mặt có mã QR + mặt còn lại).')
                    ->visible(fn () => Filament::getCurrentPanel()?->getId() !== 'minihouse-admin' && (auth()->user()?->isSuperAdmin() ?? false)),

                TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('verification_status')
                    ->label('Trạng thái')
                    ->options(self::STATUS_LABELS),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                // MiniHouse: Super Admin DUYỆT / TỪ CHỐI đăng ký ngay trên danh sách (đối tác đang “Chờ phê duyệt”).
                \Filament\Tables\Actions\Action::make('approveSignup')->label('Duyệt')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (Partner $r) => Filament::getCurrentPanel()?->getId() === 'minihouse-admin' && (auth()->user()?->isSuperAdmin() ?? false) && app(\App\Services\PartnerOnboardingService::class)->awaitingSignupApproval($r))
                    ->requiresConfirmation()->modalHeading('Duyệt đăng ký MiniHouse')
                    ->modalDescription('Tặng dùng thử, kích hoạt đối tác, tạo tài khoản đăng nhập và gửi email tài khoản + mật khẩu cho đối tác.')
                    ->action(function (Partner $record): void {
                        try {
                            $result = app(\App\Services\PartnerOnboardingService::class)->approveSignup($record, auth()->user());
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            \Filament\Notifications\Notification::make()->title('Không duyệt được')->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))->danger()->persistent()->send();

                            return;
                        }
                        $result['created']
                            ? \Filament\Notifications\Notification::make()->title($result['mail_sent'] ? 'Đã duyệt và gửi tài khoản cho đối tác' : 'Đã duyệt, chưa gửi được email')->{$result['mail_sent'] ? 'success' : 'warning'}()->send()
                            : \Filament\Notifications\Notification::make()->title('Không tạo được tài khoản')->body($result['reason'])->danger()->send();
                    }),
                \Filament\Tables\Actions\Action::make('rejectSignup')->label('Từ chối')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Partner $r) => Filament::getCurrentPanel()?->getId() === 'minihouse-admin' && (auth()->user()?->isSuperAdmin() ?? false) && app(\App\Services\PartnerOnboardingService::class)->awaitingSignupApproval($r))
                    ->form([\Filament\Forms\Components\Textarea::make('reason')->label('Lý do (gửi cho đối tác)')->required()->maxLength(2000)])
                    ->action(function (Partner $record, array $data): void {
                        app(\App\Services\PartnerOnboardingService::class)->rejectSignup($record, $data['reason'], auth()->user());
                        \Filament\Notifications\Notification::make()->title('Đã từ chối đăng ký')->success()->send();
                    }),
                ViewAction::make()->label('Xem'),
                EditAction::make()->label('Sửa'),
                // Cùng quy tắc với API DELETE .../partners/{partner}: hợp đồng đang hiệu lực thì phải chấm dứt trước.
                DeleteAction::make()->label('Xóa')->before(function (DeleteAction $action, \App\Models\Partner $record) {
                    if ($reason = $record->deletionBlockedReason()) {
                        \Filament\Notifications\Notification::make()->title('Không xoá được đối tác')->body($reason)->danger()->persistent()->send();
                        $action->cancel();
                    }
                }),
            ])
            // Bật/tắt CCCD 2 mặt cho nhiều đối tác cùng lúc (tick chọn ở danh sách).
            ->bulkActions([
                self::cccdTwoSidesBulkAction(true),
                self::cccdTwoSidesBulkAction(false),
            ]);
    }

    private static function cccdTwoSidesBulkAction(bool $enabled): BulkAction
    {
        return BulkAction::make($enabled ? 'enableCccdTwoSides' : 'disableCccdTwoSides')
            ->label($enabled ? 'Bật CCCD 2 mặt' : 'Tắt CCCD 2 mặt')
            ->icon($enabled ? 'heroicon-o-identification' : 'heroicon-o-minus-circle')
            ->color($enabled ? 'primary' : 'gray')
            ->visible(fn () => Filament::getCurrentPanel()?->getId() !== 'minihouse-admin' && (auth()->user()?->isSuperAdmin() ?? false))
            ->requiresConfirmation()
            ->modalDescription($enabled
                ? 'Khách đặt phòng của các đối tác đã chọn trên website sẽ phải tải cả 2 mặt CCCD.'
                : 'Khách đặt phòng của các đối tác đã chọn trên website chỉ cần tải 1 mặt CCCD có mã QR.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($enabled): void {
                // forceFill + save từng bản ghi (không update hàng loạt) để LogsAuditTrail vẫn ghi lịch sử thay đổi.
                $records->each(fn (Partner $partner) => $partner->forceFill(['cccd_two_sides' => $enabled])->save());

                Notification::make()
                    ->title(($enabled ? 'Đã bật' : 'Đã tắt') . ' CCCD 2 mặt cho ' . $records->count() . ' đối tác')
                    ->success()
                    ->send();
            });
    }
}
