<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralResource\Pages;
use App\Models\Referral;
use App\Services\ReferralService;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Giới thiệu bạn bè (chỉ Super Admin, chỉ cấu hình cho app) — cùng dữ liệu với /api/admin/games/referral. Cài đặt (bật/tắt, voucher chào mừng,
// giới hạn thưởng / tháng…) là nút "Cài đặt giới thiệu" ở đầu danh sách; danh sách dùng để soát gian lận và huỷ cặp nghi vấn.
class ReferralResource extends Resource
{
    protected static ?string $model = Referral::class;

    protected static ?string $navigationIcon   = 'heroicon-o-user-plus';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Giới thiệu bạn bè';
    protected static ?string $modelLabel       = 'Lượt giới thiệu';
    protected static ?string $pluralModelLabel = 'Giới thiệu bạn bè';
    protected static ?string $slug             = 'game-referrals';
    protected static ?int    $navigationSort   = 34;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['referrer', 'referee', 'welcomeCoupon']);
    }

    public static function table(Table $table): Table
    {
        $statusLabels = [Referral::STATUS_JOINED => 'Đã tham gia', Referral::STATUS_REWARDED => 'Đã thưởng', Referral::STATUS_INVALID => 'Đã huỷ'];

        return $table
            ->columns([
                TextColumn::make('created_at')->label('Thời gian')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('code')->label('Mã giới thiệu')->searchable(),
                TextColumn::make('referrer.fullname')->label('Người giới thiệu')->description(fn (Referral $r) => $r->referrer?->phone)
                    ->searchable(query: fn (Builder $q, string $s) => $q->whereHas('referrer', fn ($c) => $c->where('fullname', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))),
                TextColumn::make('referee.fullname')->label('Người được giới thiệu')->description(fn (Referral $r) => $r->referee?->phone)
                    ->searchable(query: fn (Builder $q, string $s) => $q->whereHas('referee', fn ($c) => $c->where('fullname', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))),
                TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => $statusLabels[$state] ?? $state)
                    ->color(fn (string $state) => [Referral::STATUS_JOINED => 'gray', Referral::STATUS_REWARDED => 'success', Referral::STATUS_INVALID => 'danger'][$state] ?? 'gray'),
                TextColumn::make('reason')->label('Lý do huỷ')->placeholder('—'),
                TextColumn::make('device_id')->label('Thiết bị')->limit(12)->tooltip(fn (Referral $r) => $r->device_id)->placeholder('—'),
                TextColumn::make('welcomeCoupon.code')->label('Voucher chào mừng')->placeholder('—'),
                TextColumn::make('rewarded_at')->label('Thưởng lúc')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options($statusLabels),
            ])
            ->actions([
                // Huỷ một chiều: thu hồi voucher / thẻ chưa dùng của cặp này (ReferralService::invalidate).
                Action::make('invalidate')
                    ->label('Huỷ (nghi gian lận)')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Referral $r) => $r->status !== Referral::STATUS_INVALID)
                    ->modalHeading('Huỷ lượt giới thiệu')
                    ->modalDescription('Thu hồi voucher chào mừng và thẻ cào thưởng nếu khách chưa dùng. Không hoàn tác được.')
                    ->form([TextInput::make('reason')->label('Lý do')->maxLength(40)->placeholder('admin')])
                    ->action(function (Referral $record, array $data): void {
                        app(ReferralService::class)->invalidate($record, $data['reason'] ?? null);
                        Notification::make()->title('Đã huỷ lượt giới thiệu')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReferrals::route('/')];
    }
}
