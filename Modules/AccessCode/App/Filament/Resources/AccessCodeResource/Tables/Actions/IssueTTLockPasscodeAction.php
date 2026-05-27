<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\Actions;

use Modules\TTLock\App\Services\TTLockService;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Modules\AccessCode\Entities\AccessCode;
use Modules\Product\App\Models\Product;

class IssueTTLockPasscodeAction
{
    public static function make(): Action
    {
        return Action::make('issueTTLockPasscode')
            ->label('Cấp mã TTLock')
            ->icon('heroicon-o-key')
            ->color('success')
            ->modalHeading(fn (AccessCode $record) => "Cấp mã TTLock → {$record->code}")
            ->modalDescription('Hệ thống sẽ tạo 1 mã ngẫu nhiên cho khóa check-in, rồi cấp cùng mã đó cho khóa check-out.')
            ->modalWidth('lg')
            ->fillForm(fn (AccessCode $record): array => [
                'valid_from'  => $record->valid_from  ?? now(),
                'valid_until' => $record->valid_until ?? now()->addHours(24),
            ])
            ->form(function (AccessCode $record): array {
                // Tìm phòng dựa vào category_id
                $product = Product::whereHas('categories', fn ($q) => $q->where('categories.id', $record->category_id))
                    ->whereNotNull('lock_id')
                    ->first();

                $fields = [];

                if (!$product) {
                    $fields[] = Placeholder::make('no_product')
                        ->label('')
                        ->content(new HtmlString(
                            '<div class="text-danger-600 bg-danger-50 rounded-lg p-3 text-sm">'
                            . '⚠️ Không tìm được phòng nào có lock_id thuộc chi nhánh này.'
                            . '</div>'
                        ));
                    return $fields;
                }

                $checkinLock  = $product->lock_id ?? '—';
                $checkoutLock = $product->lock_id_checkout ?? '—';
                $hasBothLocks = $product->lock_id && $product->lock_id_checkout;

                $fields[] = Placeholder::make('room_info')
                    ->label('Phòng & Khóa')
                    ->content(new HtmlString(
                        '<div class="text-sm space-y-1">'
                        . "<div>🏠 <b>{$product->name}</b></div>"
                        . "<div>🔑 Check-in lock: <code>{$checkinLock}</code></div>"
                        . "<div>🔒 Check-out lock: <code>{$checkoutLock}</code></div>"
                        . ($hasBothLocks ? '' : '<div class="text-warning-600 mt-1">⚠️ Phòng chưa có khóa check-out — mã chỉ cấp cho khóa check-in.</div>')
                        . '</div>'
                    ));

                $fields[] = DateTimePicker::make('valid_from')
                    ->label('Bắt đầu hiệu lực')
                    ->required()
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i');

                $fields[] = DateTimePicker::make('valid_until')
                    ->label('Hết hạn')
                    ->required()
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->after('valid_from');

                return $fields;
            })
            ->action(function (AccessCode $record, array $data): void {
                // Tìm phòng
                $product = Product::whereHas('categories', fn ($q) => $q->where('categories.id', $record->category_id))
                    ->whereNotNull('lock_id')
                    ->first();

                if (!$product || !$product->lock_id) {
                    Notification::make()
                        ->title('Không tìm được phòng')
                        ->body('Không có phòng nào thuộc chi nhánh này có lock_id.')
                        ->danger()->send();
                    return;
                }

                $startMs = \Carbon\Carbon::parse($data['valid_from'])->getTimestampMs();
                $endMs   = \Carbon\Carbon::parse($data['valid_until'])->getTimestampMs();
                $name    = "365Home - {$product->name}";

                $categoryId = $product->branch_category_id
                    ?? $product->categories()->value('categories.id');
                $ttlock = TTLockService::forCategory($categoryId);

                if (!$ttlock) {
                    Notification::make()
                        ->title('Chưa có tài khoản TTLock')
                        ->body('Chi nhánh này chưa được cấu hình tài khoản TTLock.')
                        ->warning()->send();
                    return;
                }

                // Bước 1: Generate mã ngẫu nhiên từ khóa check-in (lock_id)
                $checkinResult = $ttlock->generatePasscode(
                    lockId:    (int) $product->lock_id,
                    startDate: $startMs,
                    endDate:   $endMs,
                    name:      $name,
                    pwdType:   3 // Period
                );

                if (!$checkinResult) {
                    Notification::make()
                        ->title('Cấp mã thất bại')
                        ->body('Không thể tạo mã từ TTLock. Kiểm tra log để biết chi tiết.')
                        ->danger()->send();
                    return;
                }

                $generatedCode   = $checkinResult['code'];
                $checkinPwdId    = $checkinResult['keyboardPwdId'];
                $checkoutPwdId   = null;

                // Bước 2: Cấp cùng mã đó cho khóa check-out (lock_id_checkout) nếu có
                if ($product->lock_id_checkout) {
                    $checkoutResult = $ttlock->addCustomPasscode(
                        lockId:    (int) $product->lock_id_checkout,
                        code:      $generatedCode,
                        startDate: $startMs,
                        endDate:   $endMs,
                        name:      $name,
                        pwdType:   3
                    );

                    if ($checkoutResult) {
                        $checkoutPwdId = $checkoutResult['keyboardPwdId'];
                    } else {
                        // Tiếp tục lưu checkin dù checkout lỗi, nhưng cảnh báo
                        Notification::make()
                            ->title('Cảnh báo: khóa check-out thất bại')
                            ->body("Mã {$generatedCode} đã cấp cho khóa check-in nhưng không cấp được cho khóa check-out.")
                            ->warning()->send();
                    }
                }

                // Bước 3: Lưu mã + pwdId vào AccessCode
                $record->update([
                    'code'                            => $generatedCode,
                    'valid_from'                      => $data['valid_from'],
                    'valid_until'                     => $data['valid_until'],
                    'status'                          => 'active',
                    'ttlock_keyboard_pwd_id'          => $checkinPwdId,
                    'ttlock_keyboard_pwd_id_checkout' => $checkoutPwdId,
                ]);

                $checkoutLine = $checkoutPwdId
                    ? "✅ Khóa check-out: ID #{$checkoutPwdId}"
                    : "⚠️ Khóa check-out: chưa cấp";

                Notification::make()
                    ->title('Cấp mã TTLock thành công')
                    ->body(
                        "Mã: {$generatedCode}\n"
                        . "✅ Khóa check-in: ID #{$checkinPwdId}\n"
                        . $checkoutLine
                    )
                    ->success()
                    ->send();
            })
            ->visible(fn (AccessCode $record): bool =>
                // Chỉ hiện khi chưa có mã TTLock hoặc muốn cấp lại
                Product::whereHas('categories', fn ($q) => $q->where('categories.id', $record->category_id))
                    ->whereNotNull('lock_id')
                    ->exists()
            );
    }
}
