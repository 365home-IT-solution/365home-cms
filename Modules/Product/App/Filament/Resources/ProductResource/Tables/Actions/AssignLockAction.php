<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions;

use Modules\TTLock\App\Services\TTLockService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Placeholder;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Modules\Product\App\Models\Product;
use Illuminate\Support\HtmlString;

class AssignLockAction
{
    public static function make(): Action
    {
        return Action::make('assignLock')
            ->label('Gán khóa TTLock')
            ->icon('heroicon-o-key')
            ->color('warning')
            // Chi nhánh chưa đăng ký tài khoản TTLock thì ẩn hẳn nút này — không còn gì để gán,
            // hiện nút rồi mở modal chỉ để báo "chưa có tài khoản" gây rối, không cần thiết.
            ->visible(fn (Product $record) => TTLockService::hasAccountForCategory($record->branch_category_id))
            ->modalHeading(fn (Product $record) => "Gán khóa TTLock → {$record->name}")
            ->modalDescription('Chọn 2 khóa TTLock cho phòng này: khóa ngoài (check-in) và khóa trong (check-out).')
            ->modalWidth('lg')
            ->fillForm(fn (Product $record): array => [
                'lock_id'          => $record->lock_id,
                'lock_id_checkout' => $record->lock_id_checkout,
            ])
            ->form(function (Product $record): array {
                $categoryId = $record->branch_category_id
                    ?? $record->categories()->value('categories.id');
                $ttlock = TTLockService::forCategory($categoryId);

                $fields = [];

                if (!$ttlock) {
                    $fields[] = Placeholder::make('no_ttlock')
                        ->label('')
                        ->content(new HtmlString(
                            '<div class="text-warning-600 bg-warning-50 rounded-lg p-3 text-sm">'
                            . 'Chi nhánh này chưa có tài khoản TTLock. Vào <b>Cấu hình thông tin → Tài khoản TTLock</b> để thêm.'
                            . '</div>'
                        ));
                    return $fields;
                }

                $locks = $ttlock->getLockList();

                // Tạo options: lockId => "lockAlias (lockMac) 🔋X%"
                $options = [];
                foreach ($locks as $lock) {
                    $alias    = $lock['lockAlias'] ?? $lock['lockName'] ?? "Lock #{$lock['lockId']}";
                    $mac      = $lock['lockMac'] ?? '';
                    $battery  = isset($lock['electricQuantity']) ? " 🔋{$lock['electricQuantity']}%" : '';
                    $group    = isset($lock['groupName']) && $lock['groupName'] ? " [{$lock['groupName']}]" : '';
                    $options[$lock['lockId']] = "{$alias}{$group} • {$mac}{$battery}";
                }

                if (empty($options)) {
                    $fields[] = Placeholder::make('no_locks')
                        ->label('')
                        ->content(new HtmlString(
                            '<div class="text-warning-600 bg-warning-50 rounded-lg p-3 text-sm">'
                            . 'Không lấy được danh sách khóa từ TTLock API. Kiểm tra log để biết chi tiết.'
                            . '</div>'
                        ));
                } else {
                    $fields[] = Select::make('lock_id')
                        ->label('Khóa ngoài (Check-in)')
                        ->helperText('Khách nhập mã vào cửa ngoài để check-in')
                        ->options($options)
                        ->searchable()
                        ->placeholder('— Chọn khóa ngoài —')
                        ->nullable();

                    $fields[] = Select::make('lock_id_checkout')
                        ->label('Khóa trong (Check-out)')
                        ->helperText('Khách nhập mã ra khỏi cửa trong để check-out')
                        ->options($options)
                        ->searchable()
                        ->placeholder('— Chọn khóa trong —')
                        ->nullable();
                }

                return $fields;
            })
            ->action(function (Product $record, array $data): void {
                $newCheckin  = $data['lock_id'] ?? null;
                $newCheckout = $data['lock_id_checkout'] ?? null;

                $record->update([
                    'lock_id'          => $newCheckin,
                    'lock_id_checkout' => $newCheckout,
                ]);

                Notification::make()
                    ->title('Gán khóa thành công')
                    ->body("🚪 Ngoài (check-in): " . ($newCheckin ?? 'Chưa gán') . "\n🚪 Trong (check-out): " . ($newCheckout ?? 'Chưa gán'))
                    ->success()
                    ->send();
            });
    }
}
