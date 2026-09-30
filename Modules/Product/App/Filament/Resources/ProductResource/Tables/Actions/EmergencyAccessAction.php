<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions;

use App\Models\User;
use App\Services\RoomEmergencyAccessService;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Modules\Product\App\Models\Product;

class EmergencyAccessAction
{
    public static function make(): Action
    {
        return Action::make('emergencyAccess')
            ->label(fn (Product $record) => $record->emergency_locked_at ? 'Gỡ khóa khẩn cấp' : 'Khóa khẩn cấp')
            ->icon(fn (Product $record) => $record->emergency_locked_at ? 'heroicon-o-lock-open' : 'heroicon-o-shield-exclamation')
            ->color(fn (Product $record) => $record->emergency_locked_at ? 'success' : 'danger')
            ->visible(fn (Product $record) => $record->lock_id && self::canUse())
            ->requiresConfirmation()
            ->modalHeading(fn (Product $record) => $record->emergency_locked_at
                ? "Gỡ khóa truy cập khẩn cấp — {$record->name}?"
                : "Khóa quyền mở qua ứng dụng — {$record->name}?")
            ->modalDescription(fn (Product $record) => $record->emergency_locked_at
                ? 'Khách có đơn hợp lệ sẽ mở cửa qua ứng dụng được trở lại.'
                : 'Khách sẽ không thể mở cửa qua ứng dụng. Mật mã và thẻ TTLock không bị thu hồi bởi thao tác này.')
            ->form(fn (Product $record): array => $record->emergency_locked_at ? [] : [
                Textarea::make('reason')->label('Lý do')->required()->minLength(5)->maxLength(1000),
            ])
            ->action(function (Product $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                $service = app(RoomEmergencyAccessService::class);

                $record->emergency_locked_at
                    ? $service->release($record, $actor)
                    : $service->lock($record, $actor, (string) $data['reason']);

                Notification::make()
                    ->title($record->emergency_locked_at ? 'Đã gỡ khóa khẩn cấp' : 'Đã khóa quyền mở qua ứng dụng')
                    ->success()->send();
            });
    }

    private static function canUse(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->isPartnerOwner());
    }
}
