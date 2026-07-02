<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\Actions;

use Modules\TTLock\App\Services\TTLockService;
use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\HtmlString;
use Modules\AccessCode\Entities\AccessCode;
use Modules\Product\App\Models\Product;

class GenerateTTLockPasscodesAction
{
    public static function make(): Action
    {
        return Action::make('generateTTLockPasscodes')
            ->label('Tạo mã TTLock mới')
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->modalHeading('Tạo mã TTLock mới cho các phòng')
            ->modalDescription('Chọn phòng và thời gian. Hệ thống sẽ sinh mã 6 chữ số ngẫu nhiên và cấp vào TTLock cho từng phòng (cả khóa check-in và check-out).')
            ->modalWidth('lg')
            ->form(function (): array {
                // Lấy danh sách phòng có lock_id
                $products = Product::whereNotNull('lock_id')
                    ->orderBy('name')
                    ->get();

                $options = [];
                foreach ($products as $p) {
                    $checkin  = $p->lock_id;
                    $checkout = $p->lock_id_checkout ? "/{$p->lock_id_checkout}" : ' (chưa có khóa checkout)';
                    $options[$p->id] = "{$p->name}  [Lock: {$checkin}{$checkout}]";
                }

                if (empty($options)) {
                    return [
                        Placeholder::make('no_rooms')
                            ->label('')
                            ->content(new HtmlString(
                                '<div class="text-danger-600 bg-danger-50 rounded-lg p-3 text-sm">'
                                . '⚠️ Không có phòng nào được gán khóa TTLock. Vào <b>Phòng</b> → chọn Gán khóa TTLock trước.'
                                . '</div>'
                            )),
                    ];
                }

                return [
                    CheckboxList::make('product_ids')
                        ->label('Chọn phòng')
                        ->options($options)
                        ->columns(1)
                        ->bulkToggleable()
                        ->required(),

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
                ];
            })
            ->action(function (array $data): void {
                $productIds = $data['product_ids'] ?? [];
                if (empty($productIds)) {
                    return;
                }

                $startMs = Carbon::parse($data['valid_from'])->getTimestampMs();
                $endMs   = Carbon::parse($data['valid_until'])->getTimestampMs();

                $created = 0;
                $failed  = [];

                foreach ($productIds as $productId) {
                    $product = Product::find($productId);
                    if (!$product || !$product->lock_id) {
                        continue;
                    }

                    // Lấy category_id của phòng (primary category)
                    $categoryId = $product->branch_category_id
                        ?? $product->categories()->value('categories.id');

                    if (!$categoryId) {
                        $failed[] = "{$product->name}: phòng chưa được gán chi nhánh (category)";
                        continue;
                    }

                    $ttlock = TTLockService::forCategory($categoryId);

                    if (!$ttlock) {
                        $failed[] = "{$product->name}: chi nhánh chưa có tài khoản TTLock";
                        continue;
                    }

                    $name = "365Home - {$product->name}";

                    // Sinh mã 6 chữ số ngẫu nhiên
                    $generatedCode = (string) random_int(100000, 999999);
                    $checkinPwdId  = null;
                    $checkoutPwdId = null;

                    // Bước 1: Cấp mã cho khóa check-in
                    $checkinResult = $ttlock->addCustomPasscode(
                        lockId:    (int) $product->lock_id,
                        code:      $generatedCode,
                        startDate: $startMs,
                        endDate:   $endMs,
                        name:      $name,
                        pwdType:   3
                    );

                    $checkinPwdId = $checkinResult['keyboardPwdId'] ?? null;

                    if (!$checkinPwdId) {
                        $failed[] = "{$product->name}: TTLock API lỗi khi cấp mã check-in ({$generatedCode})";
                        continue;
                    }

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
                            $failed[] = "{$product->name}: cấp mã check-out thất bại (check-in OK: {$generatedCode})";
                        }
                    }

                    // Bước 3: Tạo MỚI AccessCode record
                    AccessCode::create([
                        'code'                            => $generatedCode,
                        'category_id'                     => $categoryId,
                        'status'                          => 'active',
                        'valid_from'                      => $data['valid_from'],
                        'valid_until'                     => $data['valid_until'],
                        'ttlock_keyboard_pwd_id'          => $checkinPwdId,
                        'ttlock_keyboard_pwd_id_checkout' => $checkoutPwdId,
                        'notes'                           => "Tự động cấp qua TTLock API cho phòng {$product->name}",
                    ]);

                    $created++;
                }

                if ($created > 0) {
                    Notification::make()
                        ->title("Đã tạo {$created} mã TTLock mới")
                        ->success()
                        ->send();
                }

                if (!empty($failed)) {
                    Notification::make()
                        ->title(count($failed) . ' phòng lỗi')
                        ->body(implode("\n", $failed))
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }
}
