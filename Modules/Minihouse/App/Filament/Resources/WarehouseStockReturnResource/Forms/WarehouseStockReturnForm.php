<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseStockReturnResource\Forms;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Support\ActiveBuildingScope;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Modules\Minihouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Minihouse\App\Filament\Support\WarehouseCardStyle;
use Modules\Minihouse\App\Filament\Support\WarehouseItemOptions;
use Modules\Minihouse\App\Filament\Support\WarehouseRoomOptions;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\WarehouseItem;
use Modules\Minihouse\App\Models\WarehouseStockOutItem;
use Modules\Minihouse\App\Models\WarehouseStockReturn;
use Modules\Minihouse\App\Models\WarehouseStockReturnItem;

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
                    self::buildingInput(),

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
                        Select::make('room_id')
                            ->label('Chọn phòng hoàn trả')
                            ->options(fn (Get $get) => ($buildingId = $get('building_id') ?? (count(ActiveBuildingScope::permittedBuildingIds()) === 1 ? ActiveBuildingScope::permittedBuildingIds()[0] : null))
                                ? Room::withoutGlobalScope('activeBuilding')->where('building_id', $buildingId)->orderBy('name')->pluck('name', 'id')->all()
                                : [])
                            ->searchable(),

                        TextInput::make('returned_by')
                            ->label('Bộ phận / ghi chú người hoàn (nếu không gắn 1 phòng cụ thể)')
                            ->placeholder('VD: Buồng phòng, Lễ tân')
                            ->maxLength(255),
                    ]),
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
                                ->options(fn (Get $get) => self::returnableStockOutItemOptions($get, $get('id')))
                                // BẮT BUỘC khi dùng ->searchable() — Filament CHỈ tra nhãn hiển thị
                                // của giá trị ĐANG CHỌN SẴN (lúc mở lại 1 dòng đã lưu) trong đúng
                                // danh sách options() TRẢ VỀ TẠI THỜI ĐIỂM ĐÓ; nếu giá trị đó không
                                // có mặt trong list (dù vì lý do gì — hết hạn mức, đổi phạm vi chi
                                // nhánh, hay bất kỳ thay đổi nào sau khi phiếu đã lưu), ô hiện thẳng
                                // ID số thô thay vì tên (bug thực tế đã xác nhận qua ảnh chụp: ô
                                // hiện "18"/"19" thay vì "PX...— Tên vật tư"). getOptionLabelUsing()
                                // tra nhãn TRỰC TIẾP từ DB, không phụ thuộc options() còn chứa giá
                                // trị đó hay không — luôn đúng bất kể trường hợp nào.
                                ->getOptionLabelUsing(function ($value) {
                                    $line = WarehouseStockOutItem::with(['item:id,name', 'stockOut:id,code'])->find($value);

                                    if (! $line) {
                                        return null;
                                    }

                                    return sprintf('%s — %s', $line->stockOut?->code, $line->item?->name);
                                })
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
                                        ->options(fn (Get $get) => self::warehouseItemOptionsWithCurrent($get))
                                        // Cùng lý do ở Select 'warehouse_stock_out_item_id' bên trên
                                        // — tra nhãn trực tiếp từ DB, không phụ thuộc options().
                                        ->getOptionLabelUsing(fn ($value) => WarehouseItem::withoutGlobalScopes()->find($value)?->name)
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
    //
    // $selfReturnItemId: ID CHÍNH dòng hoàn đang sửa (Get 'id' — chỉ có giá trị khi SỬA 1 dòng đã
    // lưu, rỗng khi thêm dòng mới). BẮT BUỘC truyền vào để loại trừ CHÍNH nó khi tính "đã hoàn" —
    // thiếu bước này, số lượng CHÍNH dòng đang sửa bị tính 2 lần vào "đã hoàn" của dòng xuất gốc,
    // khiến "còn có thể hoàn" tụt về 0/âm ngay cả khi vẫn hợp lệ → dòng xuất gốc bị loại khỏi danh
    // sách lựa chọn → Select không tìm được nhãn cho giá trị đang lưu, hiện ra ID thô thay vì tên
    // (bug thực tế đã xác nhận qua ảnh chụp: ô hiện "18"/"19" thay vì "PX...— Tên vật tư").
    private static function returnableStockOutItemOptions(Get $get, int|string|null $selfReturnItemId): array
    {
        $buildingId = $get('../../building_id');
        $currentId = $get('warehouse_stock_out_item_id');

        return WarehouseStockOutItem::query()
            ->with(['item:id,name', 'stockOut:id,code,building_id'])
            ->whereHas('stockOut', function ($q) use ($buildingId) {
                if ($buildingId) {
                    $q->where('building_id', $buildingId);
                }
            })
            ->get()
            ->map(function (WarehouseStockOutItem $line) use ($selfReturnItemId, $currentId) {
                $returnable = self::returnableQuantity($line->id, $selfReturnItemId);

                // Vẫn giữ lại đúng dòng ĐANG ĐƯỢC CHỌN dù tính ra hết số dư (VD 0 vì chính nó đã
                // dùng hết) — không thì Select mất nhãn, hiện ID thô như bug đã gặp.
                if ($returnable <= 0.0001 && (string) $line->id !== (string) $currentId) {
                    return null;
                }

                $label = sprintf(
                    '%s — %s (còn %s)',
                    $line->stockOut?->code,
                    $line->item?->name,
                    Number::format(max($returnable, 0), maxPrecision: 2)
                );

                return [$line->id => $label];
            })
            ->filter()
            ->collapse()
            ->all();
    }

    // Cùng nguyên tắc trên: đảm bảo vật tư ĐANG ĐƯỢC GÁN cho dòng này luôn có mặt trong danh sách,
    // kể cả khi WarehouseItemOptions::grouped() lọc mất nó (VD vật tư đã bị tắt "Đang sử dụng", hoặc
    // đổi sang chi nhánh khác sau khi phiếu xuất gốc đã tạo) — tránh Select hiện ID thô thay vì tên.
    private static function warehouseItemOptionsWithCurrent(Get $get): array
    {
        $options = WarehouseItemOptions::grouped($get('../../building_id'));
        $currentId = $get('warehouse_item_id');

        if (blank($currentId)) {
            return $options;
        }

        foreach ($options as $group) {
            if (array_key_exists((string) $currentId, $group) || array_key_exists((int) $currentId, $group)) {
                return $options;
            }
        }

        $name = WarehouseItem::withoutGlobalScopes()->find($currentId)?->name;
        if ($name) {
            $options['Khác (ngoài phạm vi hiện tại)'][$currentId] = $name;
        }

        return $options;
    }

    private static function returnableQuantity(int|string $stockOutItemId, int|string|null $excludeReturnItemId = null): float
    {
        $issuedQuantity = (float) (WarehouseStockOutItem::whereKey($stockOutItemId)->value('quantity') ?? 0);

        $alreadyReturned = (float) WarehouseStockReturnItem::where('warehouse_stock_out_item_id', $stockOutItemId)
            ->when($excludeReturnItemId, fn ($q) => $q->whereKeyNot($excludeReturnItemId))
            ->sum('quantity');

        return $issuedQuantity - $alreadyReturned;
    }

    // Toà nhà của phiếu — mirror branchInput() của Home nhưng chỉ có 1 tầng (building_id): liệt kê
    // đúng các Toà nhà tài khoản được phép quản lý (ActiveBuildingScope::permittedBuildingIds()), tự
    // chọn sẵn nếu chỉ có đúng 1 toà và ẩn hẳn ô chọn khi đó.
    private static function buildingInput(): Select
    {
        return Select::make('building_id')
            ->label('Toà nhà')
            ->options(fn () => Building::withoutGlobalScope('activeBuilding')
                ->whereIn('id', ActiveBuildingScope::permittedBuildingIds())
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
            ->default(function () {
                $ids = ActiveBuildingScope::permittedBuildingIds();

                return count($ids) === 1 ? $ids[0] : null;
            })
            ->searchable()
            ->preload()
            ->required()
            ->live()
            ->visible(fn () => count(ActiveBuildingScope::permittedBuildingIds()) > 1)
            ->helperText('Bắt buộc chọn Toà nhà của phiếu.');
    }
}
