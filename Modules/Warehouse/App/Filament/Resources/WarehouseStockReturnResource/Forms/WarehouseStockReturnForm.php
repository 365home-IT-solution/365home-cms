<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource\Forms;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Modules\Category\Entities\Category;
use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Warehouse\App\Filament\Support\WarehouseCardStyle;
use Modules\Warehouse\App\Filament\Support\WarehouseItemOptions;
use Modules\Warehouse\App\Filament\Support\WarehouseRoomOptions;
use Modules\Warehouse\App\Models\WarehouseItem;
use Modules\Warehouse\App\Models\WarehouseStockOutItem;
use Modules\Warehouse\App\Models\WarehouseStockReturn;
use Modules\Warehouse\App\Models\WarehouseStockReturnItem;

// Phiếu hoàn trả kho — trường hợp thực tế: xuất 2 chai nước cho phòng, khách chỉ dùng 1, còn 1
// chưa dùng thì hoàn lại kho. Mỗi dòng hoàn NÊN trỏ về đúng 1 dòng đã xuất trước đó
// (warehouse_stock_out_item_id) để hệ thống tự chặn hoàn nhiều hơn số đã thực xuất — xem
// WarehouseStockReturnItem::guardAgainstOverReturn(). Vẫn cho phép bỏ trống (hoàn "không truy vết"
// — hàng phát hiện thừa/không rõ nguồn gốc xuất) cho linh hoạt.
class WarehouseStockReturnForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin phiếu')
                ->schema([
                    self::partnerHidden(),
                    self::branchInput(),

                    Placeholder::make('stockreturn_meta_display')
                        ->hiddenLabel()
                        ->content(fn (WarehouseStockReturn $record) => new HtmlString(sprintf(
                            '<div class="flex flex-wrap items-center gap-x-8 gap-y-1 text-sm">
                                <span><span class="text-gray-500 dark:text-gray-400">Ngày hoàn:</span> <span class="font-medium text-gray-950 dark:text-white">%s</span></span>
                                <span><span class="text-gray-500 dark:text-gray-400">Người nhận hoàn:</span> <span class="font-medium text-gray-950 dark:text-white">%s</span></span>
                            </div>',
                            e($record->returned_at?->format('d/m/Y H:i') ?? '—'),
                            e(CurrentUserDisplay::forUser($record->creator))
                        )))
                        ->visibleOn('edit'),

                    Grid::make(['default' => 1, 'lg' => 2])->schema([
                        ViewField::make('room_picker')
                            ->label('Chọn phòng hoàn trả')
                            ->dehydrated(false)
                            ->live()
                            ->view('warehouse::filament.forms.room-branch-picker')
                            ->viewData(fn (Get $get) => [
                                'label'             => 'Chọn phòng hoàn trả',
                                'branches'          => \Modules\Warehouse\App\Filament\Support\WarehouseRoomOptions::branchesWithRooms(),
                                'selectedProductId' => $get('product_id'),
                            ]),

                        TextInput::make('returned_by')
                            ->label('Bộ phận / ghi chú người hoàn (nếu không gắn 1 phòng cụ thể)')
                            ->placeholder('VD: Buồng phòng, Lễ tân')
                            ->maxLength(255),
                    ]),

                    Hidden::make('product_id'),
                ])
                ->compact(),

            Section::make('Chi tiết hàng hoàn trả')
                ->compact()
                ->schema([
                    Placeholder::make('warehouse_stockreturn_repeater_style')
                        ->hiddenLabel()
                        ->content(WarehouseCardStyle::styleBlock('fi-warehouse-stockreturn-repeater'))
                        ->extraAttributes(['class' => 'hidden']),

                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->extraAttributes(['class' => 'fi-warehouse-stockreturn-repeater'])
                        ->schema([
                            Select::make('warehouse_stock_out_item_id')
                                ->label('Hoàn từ dòng đã xuất (khuyến nghị)')
                                ->helperText('Chọn đúng dòng đã xuất trước đó để hệ thống tự chặn hoàn nhiều hơn số đã xuất. Để trống nếu hoàn hàng không rõ nguồn gốc xuất.')
                                ->options(fn (Get $get) => self::returnableStockOutItemOptions($get))
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set) {
                                    if (! $state) {
                                        return;
                                    }

                                    $line = WarehouseStockOutItem::find($state);
                                    $set('warehouse_item_id', $line?->warehouse_item_id);
                                })
                                ->columnSpanFull(),

                            Grid::make(['default' => 1, 'lg' => 3])
                                ->schema([
                                    Select::make('warehouse_item_id')
                                        ->label('Vật tư')
                                        ->options(fn (Get $get) => WarehouseItemOptions::grouped($get('../../partner_id'), $get('../../branch_id')))
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->disabled(fn (Get $get) => filled($get('warehouse_stock_out_item_id')))
                                        ->dehydrated()
                                        ->columnSpan(1),

                                    TextInput::make('quantity')
                                        ->label('Số lượng hoàn')
                                        ->numeric()
                                        ->minValue(0.01)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->helperText(function (Get $get) {
                                            $stockOutItemId = $get('warehouse_stock_out_item_id');
                                            if (! $stockOutItemId) {
                                                return null;
                                            }

                                            $returnable = self::returnableQuantity($stockOutItemId, $get('id'));

                                            return 'Còn có thể hoàn: ' . Number::format($returnable, maxPrecision: 2);
                                        })
                                        ->rules([
                                            fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                                $stockOutItemId = $get('warehouse_stock_out_item_id');
                                                if (! $stockOutItemId) {
                                                    return;
                                                }

                                                $returnable = self::returnableQuantity($stockOutItemId, $get('id'));

                                                if ((float) $value > $returnable) {
                                                    $fail("Số lượng hoàn vượt quá số còn có thể hoàn ({$returnable}).");
                                                }
                                            },
                                        ])
                                        ->columnSpan(1),

                                    TextInput::make('note')
                                        ->label('Ghi chú')
                                        ->placeholder('Ghi chú (nếu có)')
                                        ->maxLength(255)
                                        ->columnSpan(1),
                                ]),
                        ])
                        ->columns(1)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->itemLabel(fn (array $state) => filled($state['warehouse_item_id'] ?? null)
                            ? WarehouseItem::find($state['warehouse_item_id'])?->name
                            : 'Dòng hoàn trả mới')
                        ->reorderable(false)
                        ->addActionLabel('Thêm dòng hoàn trả'),
                ]),
        ]);
    }

    // Danh sách dòng xuất còn số dư có thể hoàn, GIỚI HẠN đúng phạm vi đối tác/chi nhánh đang chọn
    // trên phiếu (Get đọc field NGOÀI Repeater bằng '../../' — Repeater lồng field theo mảng nên
    // '../../' mới thoát ra tới field cấp form gốc, KHÁC với addAction()->form() (modal riêng
    // không thấy field ngoài) — ở đây Get vẫn hoạt động bình thường vì Repeater::schema() render
    // trực tiếp trong cùng 1 form, không phải modal tách biệt.
    private static function returnableStockOutItemOptions(Get $get): array
    {
        $partnerId = $get('../../partner_id');
        $branchId  = $get('../../branch_id');

        return WarehouseStockOutItem::query()
            ->with(['item:id,name', 'stockOut:id,code,partner_id,branch_id'])
            ->whereHas('stockOut', function ($q) use ($partnerId, $branchId) {
                if ($partnerId) {
                    $q->where('partner_id', $partnerId);
                }
                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }
            })
            ->get()
            ->map(function (WarehouseStockOutItem $line) {
                $returnable = self::returnableQuantity($line->id);

                if ($returnable <= 0.0001) {
                    return null;
                }

                $label = sprintf(
                    '%s — %s (còn %s)',
                    $line->stockOut?->code,
                    $line->item?->name,
                    Number::format($returnable, maxPrecision: 2)
                );

                return [$line->id => $label];
            })
            ->filter()
            ->collapse()
            ->all();
    }

    private static function returnableQuantity(int|string $stockOutItemId, int|string|null $excludeReturnItemId = null): float
    {
        $issuedQuantity = (float) (WarehouseStockOutItem::whereKey($stockOutItemId)->value('quantity') ?? 0);

        $alreadyReturned = (float) WarehouseStockReturnItem::where('warehouse_stock_out_item_id', $stockOutItemId)
            ->when($excludeReturnItemId, fn ($q) => $q->whereKeyNot($excludeReturnItemId))
            ->sum('quantity');

        return $issuedQuantity - $alreadyReturned;
    }

    // Sao chép nguyên vẹn từ WarehouseStockOutForm — xem giải thích đầy đủ ở đó.
    private static function headerActiveBranchIds(): array
    {
        if (empty(session('active_branch_ids'))) {
            return [];
        }

        return auth()->user()?->effectiveBranchIds() ?? [];
    }

    private static function singleActiveBranch(): ?Category
    {
        $ids = self::headerActiveBranchIds();

        return count($ids) === 1 ? Category::find($ids[0]) : null;
    }

    private static function partnerHidden(): Hidden
    {
        return Hidden::make('partner_id')
            ->default(fn () => self::singleActiveBranch()?->partner_id)
            ->dehydrated();
    }

    private static function branchInput(): Select
    {
        return Select::make('branch_id')
            ->label('Chi nhánh')
            ->options(function () {
                $user = auth()->user();

                if ($user?->isSuperAdmin()) {
                    $narrowedIds = self::headerActiveBranchIds();

                    $query = Category::query()
                        ->where('category_type', 'product')
                        ->whereNull('parent_id');

                    if (! empty($narrowedIds)) {
                        $query->whereIn('id', $narrowedIds);
                    }

                    return $query->orderBy('name')->pluck('name', 'id')->all();
                }

                return Category::query()
                    ->whereIn('id', $user?->effectiveBranchIds() ?? [])
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all();
            })
            ->default(function () {
                $user = auth()->user();
                if ($user?->isSuperAdmin()) {
                    return self::singleActiveBranch()?->id;
                }
                $branchIds = $user?->effectiveBranchIds() ?? [];

                return count($branchIds) === 1 ? $branchIds[0] : null;
            })
            ->searchable()
            ->preload()
            ->required()
            ->live()
            ->afterStateUpdated(function ($state, Set $set) {
                if (! (auth()->user()?->isSuperAdmin() ?? false) || ! $state) {
                    return;
                }

                $set('partner_id', Category::find($state)?->partner_id);
            })
            ->visible(function () {
                $user = auth()->user();
                if ($user?->isSuperAdmin()) {
                    return ! self::singleActiveBranch();
                }

                return count($user?->effectiveBranchIds() ?? []) > 1;
            })
            ->helperText('Bắt buộc chọn — nếu không, phiếu sẽ không hiện với bất kỳ tài khoản đối tác nào.');
    }
}
