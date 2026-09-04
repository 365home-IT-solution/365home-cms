<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Tables\BulkActions;

use Filament\Forms\Components\Select;
use Filament\Tables;
use Modules\Category\Entities\Category;

class CouponBulkAction
{
    public static function bulkActions(): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
                Tables\Actions\BulkAction::make('activate')
                    ->label('Kích hoạt')
                    ->icon('heroicon-o-check-circle')
                    ->action(fn ($records) => $records->each->update(['is_active' => true]))
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\BulkAction::make('deactivate')
                    ->label('Tạm dừng')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->action(fn ($records) => $records->each->update(['is_active' => false]))
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\BulkAction::make('setBranches')
                    ->label('Gán chi nhánh áp dụng')
                    ->icon('heroicon-o-building-storefront')
                    ->color('info')
                    ->form([
                        Select::make('category_ids')
                            ->label('Áp dụng cho chi nhánh')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => Category::query()
                                ->whereNull('parent_id')
                                ->where('category_type', 'product')
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->helperText('Thay thế toàn bộ chi nhánh hiện tại của các mã đã chọn. Để trống = áp dụng ở TẤT CẢ chi nhánh (gồm cả khu vực con của chi nhánh đã chọn).'),
                    ])
                    ->action(function (array $data, $records) {
                        $categoryIds = $data['category_ids'] ?? [];
                        $records->each->syncCategories($categoryIds);
                    })
                    ->deselectRecordsAfterCompletion(),
            ]),
        ];
    }
}