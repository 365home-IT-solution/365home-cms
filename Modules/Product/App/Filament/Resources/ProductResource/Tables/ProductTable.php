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
use Modules\Category\Entities\Category;
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
                // lặp dòng, không phải theo từng $record).
                // Với tài khoản chi nhánh (chỉ thấy phòng của (các) chi nhánh mình qua
                // allowedBranchIds()), nếu KHÔNG chi nhánh nào của họ có TTLock kích hoạt thì ẩn
                // hẳn 3 cột này (->hidden(), tính 1 lần cho cả cột). Super admin / chủ đối tác xem
                // nhiều chi nhánh trộn lẫn thì vẫn giữ cột, hiện "—" cho từng dòng không có TTLock.
                TextColumn::make('housekeeping_status')
                    ->label('Tình trạng phòng')
                    ->hidden(fn () => self::shouldHideTTLockColumns())
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
                    ->hidden(fn () => self::shouldHideTTLockColumns())
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
                    ->hidden(fn () => self::shouldHideTTLockColumns())
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

    // True nếu người dùng hiện tại chỉ quản lý (các) chi nhánh mà KHÔNG chi nhánh nào có
    // tài khoản TTLock kích hoạt — khi đó 3 cột TTLock vô nghĩa với họ, ẩn hẳn cột.
    // Chỉ super_admin (thấy TẤT CẢ đối tác, chi nhánh trộn lẫn) mới luôn giữ cột và để "—" tự
    // xử lý theo từng dòng (xem formatStateUsing ở trên) — mọi tài khoản khác đều được quy về
    // đúng (các) chi nhánh của riêng đối tác mình.
    protected static function shouldHideTTLockColumns(): bool
    {
        $user = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return false;
        }

        $branchIds = $user->allowedBranchIds();

        // Tài khoản không có dòng phân quyền chi nhánh cụ thể nào (vd chủ đối tác, hoặc nhân
        // viên "works_all_branches") — ProductResource::getEloquentQuery() coi như họ thấy TẤT
        // CẢ chi nhánh của chính đối tác mình (lọc sẵn qua BelongsToPartner), nên ở đây cũng phải
        // suy ra "chi nhánh đang xem" là toàn bộ chi nhánh của đối tác đó, thay vì bỏ qua hẳn việc
        // ẩn cột — nếu không thì tài khoản 1-chi-nhánh-duy-nhất chưa có TTLock (như chủ đối tác
        // MONACO chỉ có 1 chi nhánh) sẽ luôn thấy cột dù chi nhánh của họ chưa hề có TTLock.
        if (empty($branchIds)) {
            if (! $user->partner_id) {
                return false;
            }

            $branchIds = Category::where('category_type', 'product')
                ->whereNull('parent_id')
                ->where('partner_id', $user->partner_id)
                ->pluck('id')
                ->toArray();
        }

        if (empty($branchIds)) {
            return false;
        }

        foreach ($branchIds as $branchId) {
            if (TTLockService::hasAccountForCategory($branchId)) {
                return false;
            }
        }

        return true;
    }
}
