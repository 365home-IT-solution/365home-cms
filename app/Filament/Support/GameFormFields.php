<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;

// Các ô cấu hình voucher thưởng dùng chung cho 3 mini game (thẻ cào, mảnh ghép, giới thiệu) — cùng luật với API admin ở App\Http\Controllers\Api\Admin\*AdminController.
class GameFormFields
{
    /** @return array<int, \Filament\Forms\Components\Component> */
    public static function coupon(bool $required = true): array
    {
        return [
            Select::make('coupon_type')
                ->label('Loại giảm')
                ->options(['fixed' => 'Giảm số tiền', 'percentage' => 'Giảm theo %'])
                ->required($required)
                ->live(),

            TextInput::make('coupon_value')
                ->label('Giá trị giảm')
                ->helperText('Số tiền (VNĐ) hoặc phần trăm tuỳ loại giảm.')
                ->numeric()
                ->minValue(1)
                ->required($required)
                ->rules([fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                    if ($get('coupon_type') === 'percentage' && (float) $value > 100) {
                        $fail('Giảm theo phần trăm không được quá 100.');
                    }
                }]),

            TextInput::make('min_order_value')
                ->label('Đơn tối thiểu (VNĐ)')
                ->numeric()
                ->minValue(0),

            TextInput::make('max_discount')
                ->label('Giảm tối đa (VNĐ)')
                ->helperText('Chỉ có ý nghĩa với giảm theo %.')
                ->numeric()
                ->minValue(0),

            TextInput::make('validity_days')
                ->label('Hạn dùng voucher (ngày)')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(365)
                ->default(30)
                ->required(),
        ];
    }
}
