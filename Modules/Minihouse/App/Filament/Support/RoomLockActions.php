<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Support;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\HtmlString;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Services\ContractTtlockService;
use Modules\Minihouse\App\Support\TtlockLocks;
use App\Models\User;
use App\Services\RoomEmergencyAccessService;

// "Gán khóa TTLock" + "Mở khóa" cho từng Phòng — mirror AssignLockAction (Home). Khác Home: tài
// khoản TTLock lấy THEO TOÀ NHÀ của phòng (TtlockLocks::service($room->building_id)), không theo
// chi nhánh/đối tác.
class RoomLockActions
{
    private static function canUse(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('page_ttlock_locks') ?? false);
    }

    public static function assign(): Action
    {
        return Action::make('assignLock')
            ->label('Gán khóa')
            ->icon('heroicon-o-key')
            ->color('warning')
            ->extraAttributes(['class' => 'mh-row-action'])
            ->visible(fn (Room $record) => self::canUse() && TtlockLocks::service((int) $record->building_id) !== null)
            ->modalHeading(fn (Room $record) => 'Gán khóa TTLock → phòng ' . $record->code)
            ->modalDescription('Chọn khóa ngoài (check-in) và khóa trong (check-out) cho phòng này.')
            ->modalWidth('lg')
            ->fillForm(fn (Room $record): array => [
                'lock_id'           => $record->lock_id,
                'lock_id_checkout'  => $record->lock_id_checkout,
                'unlock_both_locks' => (bool) $record->unlock_both_locks,
            ])
            ->form(function (Room $record): array {
                $ttlock = TtlockLocks::service((int) $record->building_id);
                $options = [];

                foreach ($ttlock?->getLockList() ?? [] as $lock) {
                    $alias   = $lock['lockAlias'] ?? $lock['lockName'] ?? "Lock #{$lock['lockId']}";
                    $battery = isset($lock['electricQuantity']) ? " 🔋{$lock['electricQuantity']}%" : '';
                    $options[$lock['lockId']] = $alias . ' • ' . ($lock['lockMac'] ?? '') . $battery;
                }

                if (empty($options)) {
                    return [
                        Placeholder::make('no_locks')->label('')->content(new HtmlString(
                            '<div class="text-warning-600 bg-warning-50 rounded-lg p-3 text-sm">Không lấy được danh sách khóa từ TTLock của toà nhà này.</div>'
                        )),
                    ];
                }

                return [
                    Select::make('lock_id')->label('Khóa ngoài (Check-in)')->options($options)->searchable()->placeholder('— Chọn khóa ngoài —')->nullable(),
                    Select::make('lock_id_checkout')->label('Khóa trong (Check-out)')->options($options)->searchable()->placeholder('— Chọn khóa trong —')->nullable(),
                    Toggle::make('unlock_both_locks')
                        ->label('Cửa cần mở CẢ 2 ổ cùng lúc')
                        ->helperText('Bật nếu đây là 1 cửa vật lý gắn 2 ổ cần nhả cùng lúc; khi bật, mọi thao tác mở khóa sẽ mở cả 2 ổ.')
                        ->default(false),
                ];
            })
            ->action(function (Room $record, array $data): void {
                $record->update([
                    'lock_id'           => $data['lock_id'] ?? null,
                    'lock_id_checkout'  => $data['lock_id_checkout'] ?? null,
                    'unlock_both_locks' => $data['unlock_both_locks'] ?? false,
                ]);

                // Phòng đang có hợp đồng hiệu lực -> cấp/thu hồi mã mở ngay theo đúng khoá vừa gán.
                ContractTtlockService::syncForRoom($record->id);

                Notification::make()->title('Đã gán khóa cho phòng ' . $record->code)->success()->send();
            });
    }

    public static function unlock(): Action
    {
        return Action::make('unlockRoom')
            ->label('Mở khóa')
            ->icon('heroicon-o-lock-open')
            ->color('success')
            ->extraAttributes(['class' => 'mh-row-action'])
            ->requiresConfirmation()
            ->modalHeading(fn (Room $record) => 'Mở khóa phòng ' . $record->code . '?')
            ->visible(fn (Room $record) => self::canUse() && $record->lock_id && TtlockLocks::service((int) $record->building_id) !== null)
            ->action(function (Room $record): void {
                $ttlock = TtlockLocks::service((int) $record->building_id);

                if (! $ttlock) {
                    Notification::make()->title('Toà nhà chưa kết nối TTLock.')->danger()->send();

                    return;
                }

                $both = $record->unlock_both_locks && $record->lock_id && $record->lock_id_checkout;
                $ok = $both
                    ? $ttlock->remoteUnlockBoth((int) $record->lock_id, (int) $record->lock_id_checkout)['success']
                    : $ttlock->remoteUnlock((int) $record->lock_id);

                $ok
                    ? Notification::make()->title('Đã gửi lệnh mở khóa phòng ' . $record->code)->success()->send()
                    : Notification::make()->title('Không mở được khóa')->body('Kiểm tra khóa còn kết nối mạng và đã bật "Mở khóa từ xa" trong app Sciener chưa.')->danger()->send();
            });
    }

    public static function emergencyAccess(): Action
    {
        return Action::make('emergencyAccess')
            ->label(fn (Room $record) => $record->emergency_locked_at ? 'Gỡ khóa khẩn cấp' : 'Khóa khẩn cấp')
            ->icon(fn (Room $record) => $record->emergency_locked_at ? 'heroicon-o-lock-open' : 'heroicon-o-shield-exclamation')
            ->color(fn (Room $record) => $record->emergency_locked_at ? 'success' : 'danger')
            ->extraAttributes(['class' => 'mh-row-action'])
            ->visible(fn (Room $record) => $record->lock_id && self::canEmergencyLock())
            ->requiresConfirmation()
            ->modalHeading(fn (Room $record) => $record->emergency_locked_at
                ? 'Gỡ khóa truy cập khẩn cấp — phòng ' . $record->code . '?'
                : 'Khóa quyền mở qua ứng dụng — phòng ' . $record->code . '?')
            ->modalDescription(fn (Room $record) => $record->emergency_locked_at
                ? 'Khách có hợp đồng hiệu lực sẽ mở cửa qua ứng dụng được trở lại.'
                : 'Khách thuê sẽ không thể mở cửa qua ứng dụng. Mật mã và thẻ TTLock không bị thu hồi.')
            ->form(fn (Room $record): array => $record->emergency_locked_at ? [] : [
                Textarea::make('reason')->label('Lý do')->required()->minLength(5)->maxLength(1000),
            ])
            ->action(function (Room $record, array $data): void {
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

    private static function canEmergencyLock(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->can('page_emergency_room_lock'));
    }
}
