<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\BulkActions;

use App\Services\TTLockService;
use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Modules\Product\App\Models\Product;

class IssueTTLockPasscodeBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('issueTTLockPasscodeBulk')
            ->label('Cấp mã TTLock')
            ->icon('heroicon-o-key')
            ->color('success')
            ->modalHeading('Cấp mã TTLock cho nhiều phòng')
            ->modalDescription('Hệ thống sẽ tạo mã ngẫu nhiên riêng cho từng phòng, cấp cùng mã đó cho cả khóa check-in và check-out.')
            ->modalWidth('md')
            ->form([
                DateTimePicker::make('valid_from')
                    ->label('Bắt đầu hiệu lực')
                    ->required()
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->default(now()->startOfHour()),

                DateTimePicker::make('valid_until')
                    ->label('Hết hạn')
                    ->required()
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->after('valid_from')
                    ->default(now()->addDay()->startOfHour()),
            ])
            ->action(function (Collection $records, array $data): void {
                $startMs = Carbon::parse($data['valid_from'])->getTimestampMs();
                $endMs   = Carbon::parse($data['valid_until'])->getTimestampMs();

                $success = 0;
                $failed  = [];

                foreach ($records as $accessCode) {
                    // Tìm phòng từ category_id của mã
                    $product = Product::whereHas(
                            'categories',
                            fn ($q) => $q->where('categories.id', $accessCode->category_id)
                        )
                        ->whereNotNull('lock_id')
                        ->first();

                    if (!$product) {
                        $failed[] = "Mã #{$accessCode->id}: không tìm được phòng thuộc chi nhánh này";
                        continue;
                    }

                    $ttlock = TTLockService::forCategory($accessCode->category_id);

                    if (!$ttlock) {
                        $failed[] = "{$product->name}: chi nhánh chưa có tài khoản TTLock";
                        continue;
                    }

                    $name = "365Home - {$product->name}";

                    // Bước 1: Generate mã ngẫu nhiên từ khóa check-in
                    $checkinResult = $ttlock->generatePasscode(
                        lockId:    (int) $product->lock_id,
                        startDate: $startMs,
                        endDate:   $endMs,
                        name:      $name,
                        pwdType:   3
                    );

                    if (!$checkinResult) {
                        $failed[] = "{$product->name}: TTLock API lỗi khi tạo mã check-in";
                        continue;
                    }

                    $generatedCode = $checkinResult['code'];
                    $checkinPwdId  = $checkinResult['keyboardPwdId'];
                    $checkoutPwdId = null;

                    // Bước 2: Cấp cùng mã cho khóa check-out nếu có
                    if ($product->lock_id_checkout) {
                        $checkoutResult = $ttlock->addCustomPasscode(
                            lockId:    (int) $product->lock_id_checkout,
                            code:      $generatedCode,
                            startDate: $startMs,
                            endDate:   $endMs,
                            name:      $name,
                            pwdType:   3
                        );

                        $checkoutPwdId = $checkoutResult['keyboardPwdId'] ?? null;

                        if (!$checkoutPwdId) {
                            $failed[] = "{$product->name}: cấp mã check-out thất bại (mã check-in OK: {$generatedCode})";
                        }
                    }

                    // Bước 3: Lưu vào AccessCode
                    $accessCode->update([
                        'code'                            => $generatedCode,
                        'valid_from'                      => $data['valid_from'],
                        'valid_until'                     => $data['valid_until'],
                        'status'                          => 'active',
                        'ttlock_keyboard_pwd_id'          => $checkinPwdId,
                        'ttlock_keyboard_pwd_id_checkout' => $checkoutPwdId,
                    ]);

                    $success++;
                }

                // Thông báo kết quả
                if ($success > 0) {
                    Notification::make()
                        ->title("Cấp mã thành công: {$success} phòng")
                        ->success()
                        ->send();
                }

                if (!empty($failed)) {
                    Notification::make()
                        ->title(count($failed) . ' phòng cấp mã lỗi')
                        ->body(implode("\n", $failed))
                        ->warning()
                        ->persistent()
                        ->send();
                }
            })
            ->deselectRecordsAfterCompletion();
    }
}
