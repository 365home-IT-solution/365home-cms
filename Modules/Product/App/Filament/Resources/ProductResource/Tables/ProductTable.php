<?php

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions\ProductAction;
use Modules\Product\App\Filament\Resources\ProductResource\Tables\BulkActions\ProductBulkAction;
use Modules\Product\App\Filament\Resources\ProductResource\Tables\Filters\ProductFilter;
use Modules\Product\App\Models\Product;
use Modules\TTLock\App\Services\TTLockService;

class ProductTable extends Table
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('media')
                    ->label(__('product::product.table.label.product_image'))
                    ->collection('Ảnh bìa'),
                TextColumn::make('name')
                    ->label(__('product::product.table.label.name'))
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->label(__('product::product.table.label.url'))
                    ->getStateUsing(function (Product $record): string {
                        return url("/room/{$record->slug}");
                    })
                    ->copyable()
                    ->wrap()
                    ->openUrlInNewTab(),
//                SpatieTagsColumn::make('tags')
//                    ->label(__('product::product.table.label.tags'))
//                    ->type('categories')
//                    ->colors(['secondary']),
                TextColumn::make('categories.name')
                    ->label(__('product::product.table.label.categories'))
                    ->searchable(),
                // Chi nhánh chưa đăng ký tài khoản TTLock thì "Tình trạng phòng"/"Khóa ngoài"/"Khóa
                // trong" không có ý nghĩa gì (không dùng khóa điện tử, không theo dõi qua hệ thống
                // này) — Filament không cho ẩn hẳn 1 CỘT theo TỪNG DÒNG (chỉ ẩn được cả cột, coi
                // header table.blade.php: $getVisibleColumns() được gọi 1 LẦN duy nhất TRƯỚC vòng
                // lặp dòng, không phải theo từng $record), nên thay bằng cách hiện "—" ở đúng những
                // dòng thuộc chi nhánh không có TTLock thay vì hiện dữ liệu/gợi ý "Chưa gán" gây
                // hiểu lầm là còn thiếu thao tác.
                TextColumn::make('housekeeping_status')
                    ->label('Tình trạng phòng')
                    ->badge()
                    ->formatStateUsing(function (string $state, Product $record): string {
                        if (! TTLockService::hasAccountForCategory($record->branch_category_id)) {
                            return '—';
                        }
                        return match ($state) {
                            'cleaning'    => 'Đang dọn vệ sinh',
                            'maintenance' => 'Đang bảo trì',
                            default       => 'Sẵn sàng',
                        };
                    })
                    ->color(function (string $state, Product $record): string {
                        if (! TTLockService::hasAccountForCategory($record->branch_category_id)) {
                            return 'gray';
                        }
                        return match ($state) {
                            'cleaning'    => 'warning',
                            'maintenance' => 'danger',
                            default       => 'success',
                        };
                    })
                    ->sortable(),
                TextColumn::make('lock_id')
                    ->label('Khóa ngoài (check-in)')
                    ->placeholder('Chưa gán')
                    ->icon('heroicon-o-key')
                    ->badge()
                    ->formatStateUsing(fn ($state, Product $record) => TTLockService::hasAccountForCategory($record->branch_category_id) ? $state : '—')
                    ->color(fn ($state, Product $record) => TTLockService::hasAccountForCategory($record->branch_category_id)
                        ? ($state ? 'success' : 'gray')
                        : 'gray')
                    ->sortable(),
                TextColumn::make('lock_id_checkout')
                    ->label('Khóa trong (check-out)')
                    ->placeholder('Chưa gán')
                    ->icon('heroicon-o-key')
                    ->badge()
                    ->formatStateUsing(fn ($state, Product $record) => TTLockService::hasAccountForCategory($record->branch_category_id) ? $state : '—')
                    ->color(fn ($state, Product $record) => TTLockService::hasAccountForCategory($record->branch_category_id)
                        ? ($state ? 'warning' : 'gray')
                        : 'gray')
                    ->sortable(),
                ToggleColumn::make('is_activated')
                    ->label(__('product::product.table.label.is_activated'))
                    ->tooltip(function ($record) {
                        return $record->is_activated
                            ? __('product::product.table.options.active')
                            : __('product::product.table.options.inactive');
                    })
                    ->onIcon(__('product::product.table.icons.active'))
                    ->offIcon(__('product::product.table.icons.inactive'))
                    ->alignCenter()
                    ->sortable(),
//                ToggleColumn::make('is_trend')
//                    ->label(__('product::product.table.label.is_trend'))
//                    ->tooltip(function ($record) {
//                        return $record->is_trend
//                            ? __('product::product.table.options.active')
//                            : __('product::product.table.options.inactive');
//                    })
//                    ->onIcon(__('product::product.table.icons.active'))
//                    ->offIcon(__('product::product.table.icons.inactive'))
//                    ->alignCenter()
//                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('product::product.table.label.created_at'))
                    ->dateTime()
                    ->sortable(),
                PartnerTableHelpers::column(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                ...ProductFilter::filter(),
                PartnerTableHelpers::filter(),
            ])
            ->actions(ProductAction::action())
            ->bulkActions(ProductBulkAction::bulkActions());
    }
}
