<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Tables\Filters;

use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;

class CouponFilter
{
    public static function filter(): array
    {
        return [
           SelectFilter::make('type')
                ->label('Loại')
                ->options([
                    'percentage' => 'Phần trăm',
                    'fixed' => 'Cố định',
                ]),

           SelectFilter::make('apply_type')
                ->label('Phạm vi')
                ->options([
                    'all_rooms' => 'Tất cả phòng',
                    'specific_room' => 'Phòng cụ thể',
                    'specific_slot' => 'Khung giờ cụ thể',
                ]),

           TernaryFilter::make('is_active')
                ->label('Trạng thái')
                ->placeholder('Tất cả')
                ->trueLabel('Đang hoạt động')
                ->falseLabel('Tạm dừng'),

           Filter::make('valid_now')
                ->label('Còn hiệu lực')
                ->query(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('start_at', '<=', now())
                    ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
                    ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
                ),
        ];
    }
}