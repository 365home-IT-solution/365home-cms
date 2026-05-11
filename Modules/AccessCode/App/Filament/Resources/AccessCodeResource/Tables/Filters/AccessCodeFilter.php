<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\Filters;

use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

class AccessCodeFilter
{
    public static function filter(): array
    {
        return [
            SelectFilter::make('status')
                ->label('Trạng thái')
                ->options([
                    'available' => 'Khả dụng',
                    'used' => 'Đã dùng',
                    'expired' => 'Hết hạn',
                ]),
            SelectFilter::make('category.name')
                ->label('Chi nhánh')
                ->relationship('category', 'name'),

            Filter::make('valid_now')
                ->label('Đang có hiệu lực')
                ->query(
                    fn($query) => $query
                        ->where('status', 'available')
                        ->where(function ($q) {
                            $q->whereNull('valid_from')
                                ->orWhere('valid_from', '<=', now());
                        })
                        ->where(function ($q) {
                            $q->whereNull('valid_until')
                                ->orWhere('valid_until', '>=', now());
                        })
                ),
        ];
    }
}
