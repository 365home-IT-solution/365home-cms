<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Tables;

use App\Models\Partner;
use App\Models\PartnerSubscription;
use Filament\Facades\Filament;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
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
                DeleteAction::make()->label('Xóa'),
            ]);
    }
}
