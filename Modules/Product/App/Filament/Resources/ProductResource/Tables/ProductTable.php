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
                TextColumn::make('approval_status')
                    ->label('Duyệt')
                    ->state(function (Product $record) {
                        $label = ['approved' => 'Đã duyệt', 'pending' => 'Chờ duyệt', 'rejected' => 'Bị từ chối'][$record->approval_status ?? 'approved'] ?? $record->approval_status;

                        return ($record->approval_status ?? 'approved') === 'approved' && app(\App\Services\ApprovalService::class)->hasPendingUpdate(\App\Models\ApprovalRequest::SUBJECT_PRODUCT, $record->id)
                            ? $label . ' · có bản sửa chờ duyệt'
                            : $label;
                    })
                    ->badge()
                    ->color(fn (string $state) => match (true) {
                        str_contains($state, 'Bị từ chối') => 'danger',
                        str_contains($state, 'Chờ duyệt'), str_contains($state, 'chờ duyệt') => 'warning',
                        default => 'success',
                    }),
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
                    ->toggleable()
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
                    ->hidden(fn ($livewire) => self::shouldHideTTLockColumns() || self::isFilteredToBranchWithoutTTLock($livewire))
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
                    ->hidden(fn ($livewire) => self::shouldHideTTLockColumns() || self::isFilteredToBranchWithoutTTLock($livewire))
                    ->placeholder('Chưa gán')
                    ->icon('heroicon-o-key')
                    ->badge()
                    ->formatStateUsing(fn ($state, Product $record) => TTLockService::hasAccountForCategory($record->branch_category_id) ? $state : '—')
                    ->color(fn ($state, Product $record) => TTLockService::hasAccountForCategory($record->branch_category_id)
                        ? ($state ? 'warning' : 'gray')
                        : 'gray')
                    ->sortable(),
                TextColumn::make('emergency_locked_at')
                    ->label('Truy cập app')
                    ->hidden(fn ($livewire) => self::shouldHideTTLockColumns() || self::isFilteredToBranchWithoutTTLock($livewire))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Khóa khẩn cấp' : 'Bình thường')
                    ->color(fn ($state) => $state ? 'danger' : 'success')
                    ->placeholder('Bình thường')
                    ->toggleable(),
                ToggleColumn::make('is_activated')
                    ->label(__('product::product.table.label.is_activated'))
                    ->tooltip(function ($record) {
                        return $record->is_activated
                            ? __('product::product.table.options.active')
                            : __('product::product.table.options.inactive');
                    })
                    ->onIcon(__('product::product.table.icons.active'))
                    ->offIcon(__('product::product.table.icons.inactive'))
                    // Đối tác bị tạm khoá phòng do huỷ nhiều đơn tiền mặt: không tự bật lại được (chỉ Super Admin mở khoá).
                    ->disabled(fn ($record) => ! $record->is_activated && ! (auth()->user()?->isSuperAdmin() ?? false)
                        && app(\App\Services\Payment\CancellationGuardService::class)->productLocked($record))
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
                PartnerTableHelpers::column()
                    // Mặc định ẩn ở "Thiết lập Phòng" (đỡ rối cho đối tác 1-chi-nhánh), vẫn bật
                    // lại được qua nút "Cột" khi cần (vd super_admin muốn xem nhanh theo đối tác).
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            // Thứ tự hiển thị phòng (dashboard, app admin, web khách) — kéo thả. Nên lọc theo đối tác / chi nhánh trước.
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (\Filament\Tables\Actions\Action $action, bool $isReordering) => $action
                ->label($isReordering ? 'Xong' : 'Kéo thả thứ tự phòng')
                ->tooltip($isReordering ? null : 'Thứ tự này dùng cho sơ đồ phòng ở dashboard, app admin và web. Nên lọc 1 đối tác / chi nhánh trước khi kéo.')
                ->button())
            ->headerActions([self::sortByRoomNumberAction()])
            ->filters([
                ...ProductFilter::filter(),
                PartnerTableHelpers::filter(),
            ])
            ->actions(ProductAction::action())
            ->bulkActions(ProductBulkAction::bulkActions());
    }

    /**
     * "Sắp theo số phòng": đặt sort_order 1..n cho phòng của 1 đối tác theo số trong tên (001 < 101 < 102 < … < 401).
     * Chỉ đụng các phòng người dùng được xem (ProductResource::getEloquentQuery). Cập nhật thẳng cột, không qua duyệt.
     */
    protected static function sortByRoomNumberAction(): \Filament\Tables\Actions\Action
    {
        $rooms = function (?string $partnerId) {
            if (! $partnerId) {
                return [];
            }

            return Product::sortByRoomNumber(
                \Modules\Product\App\Filament\Resources\ProductResource::getEloquentQuery()
                    ->where('partner_id', $partnerId)
                    ->get(['id', 'name', 'sort_order'])
            );
        };
        $partnerOf = fn (?string $chosen) => auth()->user()?->seesAllPartners() ? $chosen : auth()->user()?->partner_id;

        return \Filament\Tables\Actions\Action::make('sortByRoomNumber')
            ->label('Sắp theo số phòng')
            ->icon('heroicon-o-bars-arrow-down')
            ->color('gray')
            ->visible(fn () => \Modules\Product\App\Filament\Resources\ProductResource::canReorder())
            ->modalHeading('Sắp xếp phòng từ bé đến lớn theo số phòng')
            ->modalDescription('Lấy số trong tên phòng (VD 001, 101, 102 … 401) để xếp thứ tự. Dùng cho sơ đồ phòng ở dashboard, app admin và web. Muốn chỉnh tay thì dùng "Kéo thả thứ tự phòng".')
            ->modalSubmitActionLabel('Lưu thứ tự')
            ->form([
                \Filament\Forms\Components\Select::make('partner_id')
                    ->label('Đối tác')
                    ->options(fn () => \App\Models\Partner::query()
                        ->whereIn('id', \Modules\Product\App\Filament\Resources\ProductResource::getEloquentQuery()->select('partner_id'))
                        ->orderBy('name')->get(['id', 'name', 'common_name'])
                        ->mapWithKeys(fn ($p) => [$p->id => $p->common_name ?: $p->name])->all())
                    ->default(fn ($livewire) => data_get($livewire, 'tableFilters.partner_id.value') ?? data_get($livewire, 'tableFilters.partner.value'))
                    ->searchable()
                    ->required(fn () => auth()->user()?->seesAllPartners())
                    ->visible(fn () => auth()->user()?->seesAllPartners())
                    ->live(),
                \Filament\Forms\Components\Placeholder::make('preview')
                    ->label('Thứ tự sau khi lưu')
                    ->content(function (\Filament\Forms\Get $get) use ($rooms, $partnerOf) {
                        $list = $rooms($partnerOf($get('partner_id')));

                        return $list === []
                            ? 'Chọn đối tác để xem.'
                            : new \Illuminate\Support\HtmlString('<ol style="margin:0;padding-left:1.4rem;list-style:decimal">'
                                . collect($list)->map(fn (Product $r) => '<li>' . e($r->name) . '</li>')->implode('') . '</ol>');
                    }),
            ])
            ->action(function (array $data) use ($rooms, $partnerOf) {
                $list = $rooms($partnerOf($data['partner_id'] ?? null));
                foreach ($list as $i => $room) {
                    Product::withoutGlobalScopes()->whereKey($room->id)->update(['sort_order' => $i + 1]);
                }

                \Filament\Notifications\Notification::make()->success()
                    ->title('Đã sắp ' . count($list) . ' phòng theo số phòng')
                    ->send();
            });
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

    // True nếu bảng đang được lọc theo ĐÚNG 1 chi nhánh cụ thể (bộ lọc "Chi nhánh/Khu vực") và
    // chi nhánh đó không có tài khoản TTLock kích hoạt — áp dụng cho MỌI người dùng, kể cả
    // super_admin, vì khi đã lọc rõ về 1 chi nhánh không dùng khóa điện tử thì 2 cột Khóa
    // ngoài/Khóa trong chắc chắn không có ý nghĩa gì cho danh sách đang xem.
    protected static function isFilteredToBranchWithoutTTLock($livewire): bool
    {
        $branchId = data_get($livewire, 'tableFilters.categories.value');

        if (! $branchId) {
            return false;
        }

        return ! TTLockService::hasAccountForCategory((int) $branchId);
    }
}
