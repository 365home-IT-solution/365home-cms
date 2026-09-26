<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseStockOutResource\Forms;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Support\ActiveBuildingScope;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Minihouse\App\Filament\Support\WarehouseBarcodeScan;
use Modules\Minihouse\App\Filament\Support\WarehouseCardStyle;
use Modules\Minihouse\App\Filament\Support\WarehouseItemOptions;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\WarehouseItem;
use Modules\Minihouse\App\Models\WarehouseStockOut;

class WarehouseStockOutForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin phiếu')
                ->schema([
                    self::buildingInput(),

                    // CHỈ hiện khi SỬA — lúc TẠO MỚI "Ngày xuất" chưa có giá trị thật (tự chốt SAU
                    // khi lưu, xem WarehouseStockOut::creating()) nên không có gì đáng xem trước khi
                    // lưu. 1 dòng gọn duy nhất thay vì 2 ô tách rời — cả 2 đều CHỈ ĐỂ ĐỌC, không
                    // field nào chỉnh được.
                    Placeholder::make('stockout_meta_display')
                        ->hiddenLabel()
                        ->content(fn (WarehouseStockOut $record) => new HtmlString(sprintf(
                            '<div class="flex flex-wrap items-center gap-x-8 gap-y-1 text-sm">
                                <span><span class="text-gray-500 dark:text-gray-400">Ngày xuất:</span> <span class="font-medium text-gray-950 dark:text-white">%s</span></span>
                                <span><span class="text-gray-500 dark:text-gray-400">Người xuất kho:</span> <span class="font-medium text-gray-950 dark:text-white">%s</span></span>
                            </div>',
                            e($record->issued_at?->format('d/m/Y H:i') ?? '—'),
                            e(CurrentUserDisplay::forUser($record->creator))
                        )))
                        ->visibleOn('edit'),

                    // Xem giải thích 'default' => 1 ở WarehouseItemForm — Grid::make(N)/->columns(N)/
                    // ->columnSpan(N) dạng số nguyên không tự có mobile, phải khai báo tường minh.
                    Grid::make(['default' => 1, 'lg' => 2])->schema([
                        // Phòng nhận hàng — chỉ liệt kê phòng của ĐÚNG Toà nhà đang chọn trên phiếu
                        // (bản Home dùng lưới chọn phòng theo cột chi nhánh vì 1 tài khoản Home quản
                        // nhiều chi nhánh; MiniHouse mỗi phiếu gắn 1 Toà nhà nên Select thường là đủ).
                        Select::make('room_id')
                            ->label('Chọn phòng nhận hàng')
                            ->options(fn (Get $get) => ($buildingId = $get('building_id') ?? (count(ActiveBuildingScope::permittedBuildingIds()) === 1 ? ActiveBuildingScope::permittedBuildingIds()[0] : null))
                                ? Room::withoutGlobalScope('activeBuilding')->where('building_id', $buildingId)->orderBy('name')->pluck('name', 'id')->all()
                                : [])
                            ->searchable(),

                        TextInput::make('issued_to')
                            ->label('Bộ phận / ghi chú nơi nhận (nếu không gắn 1 phòng cụ thể)')
                            ->placeholder('VD: Buồng phòng, Văn phòng, Bếp')
                            ->maxLength(255),
                    ]),
                ])
                ->compact(),

            Section::make('Chi tiết hàng xuất')
                ->compact()
                ->schema([
                    // Cùng khối <style> tô viền thẻ + tên vật tư primary như phiếu kiểm kê — xem
                    // WarehouseCardStyle (lớp riêng .fi-warehouse-stockout-repeater).
                    Placeholder::make('warehouse_stockout_repeater_style')
                        ->hiddenLabel()
                        ->content(WarehouseCardStyle::styleBlock('fi-warehouse-stockout-repeater'))
                        ->extraAttributes(['class' => 'hidden']),

                    // Chọn 1 LẦN trước khi quét hàng loạt — mọi dòng được TẠO MỚI qua quét mã vạch
                    // (bên dưới) tự nhận đúng lý do này, khỏi phải bấm chọn tay lại cho từng dòng khi
                    // quét chục món liền (yêu cầu thực tế: quét 10 món thì không thể bắt chọn tay 10
                    // lần). KHÔNG ép buộc cùng 1 lý do cho cả phiếu — mỗi dòng vẫn tự sửa riêng được
                    // (VD 9 món dùng bình thường, 1 món hư hỏng thì đổi riêng dòng đó), chỉ là điền
                    // SẴN thay vì để trống bắt chọn từ đầu.
                    Select::make('default_reason')
                        ->label('Lý do (áp dụng cho vật tư quét tiếp theo)')
                        ->options(WarehouseStockOut::REASONS)
                        // Mặc định "Hao hụt / Hư hỏng" — đúng nhu cầu thực tế đa số phiếu xuất đang
                        // lập, khỏi phải bấm chọn tay nữa. Vẫn đổi được bình thường nếu phiếu này
                        // thật ra là lý do khác (VD dùng cho khách/phòng).
                        ->default('damaged')
                        ->dehydrated(false)
                        ->live()
                        ->helperText('Chọn trước khi quét — vật tư quét mới tự điền đúng lý do này, vẫn đổi được riêng từng dòng.'),

                    // Quét/nhập mã vạch — thêm nhanh vật tư vào phiếu bằng camera điện thoại hoặc máy
                    // quét mã vạch vật lý. "reason" lấy từ ô "default_reason" ở trên (nếu đã chọn),
                    // không thì để trống như cũ — bắt chọn tay ngay lúc đó.
                    WarehouseBarcodeScan::field(fn (WarehouseItem $item, Get $get) => [
                        'warehouse_item_id'  => $item->id,
                        'quantity'           => 1,
                        'reason'             => $get('default_reason'),
                        'note'               => null,
                        '_original_quantity' => 0,
                    ]),

                    // Ô tìm nhanh theo tên — lọc bằng JS thuần, không qua Livewire (gõ tới đâu
                    // ẩn/hiện thẻ ngay tới đó). Chỉ hiện khi ĐÃ có thẻ.
                    Placeholder::make('warehouse_stockout_search')
                        ->hiddenLabel()
                        ->content(WarehouseCardStyle::searchBox('fi-warehouse-stockout-repeater'))
                        ->visible(fn (Get $get) => filled($get('items'))),

                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        // Xem giải thích đầy đủ ở WarehouseStockInForm — buộc Livewire vẽ lại TOÀN BỘ
                        // Repeater mỗi khi "items" đổi (thay vì morph từng phần, vốn bỏ sót cập nhật
                        // khi mutation đến từ ô quét mã vạch quét dồn dập).
                        ->key(fn (Get $get) => 'stockout-items-' . md5(json_encode($get('items') ?? [])))
                        ->extraAttributes(['class' => 'fi-warehouse-stockout-repeater'])
                        // Dạng LƯỚI thẻ như phiếu kiểm kê — mỗi thẻ xếp DỌC 1 cột nội bộ thay vì 1
                        // hàng ngang dài.
                        ->grid(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4])
                        ->schema([
                            Hidden::make('warehouse_item_id')
                                ->required(),

                            // Badge icon + tên nhóm vật tư — "Icon Badge Card" (mẫu đã chọn qua ảnh
                            // chụp gửi trước đó). Dùng class dark: có sẵn (không phải hex cứng) nên
                            // tự đổi đúng theo bật/tắt dark mode của panel.
                            Placeholder::make('item_meta_display')
                                ->hiddenLabel()
                                ->content(fn (Get $get) => WarehouseCardStyle::itemMetaBadge(
                                    WarehouseItem::find($get('warehouse_item_id'))
                                )),

                            // "Số lượng" trước, "Lý do" sau — cùng 1 hàng. items-center để "Lý do"
                            // canh GIỮA theo chiều dọc so với khối ô vuông "Số lượng" (ô vuông có
                            // thêm dòng "Tồn khả dụng" bên dưới nên cao hơn hẳn 1 Select bình
                            // thường — không canh giữa sẽ bị lệch lên trên như ảnh chụp thực tế).
                            // Xem giải thích 'default' => ... ở WarehouseItemForm: Grid::make(N)
                            // dạng số nguyên KHÔNG tự có cột mobile (chỉ gán "lg"), phải khai báo
                            // tường minh để 2 ô luôn nằm NGANG kể cả trên mobile.
                            Grid::make(['default' => 3, 'lg' => 3])
                                ->extraAttributes(['class' => 'items-center'])
                                ->schema([
                                    // Số lượng — Ô VUÔNG cùng phong cách "Đếm được" ở phiếu kiểm kê.
                                    TextInput::make('quantity')
                                        ->label('Số lượng')
                                        ->numeric()
                                        ->minValue(0.01)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->extraAttributes(WarehouseCardStyle::neutralBox())
                                        ->extraInputAttributes(WarehouseCardStyle::inputStyle())
                                        // "Tồn khả dụng" = tồn hiện tại + số lượng của chính dòng này
                                        // trước khi sửa (vì số đó đã bị trừ khỏi tồn kho rồi, sửa lại
                                        // phải cộng trả về mới so sánh đúng).
                                        ->helperText(function (Get $get) {
                                            $itemId = $get('warehouse_item_id');
                                            if (! $itemId) {
                                                return null;
                                            }

                                            $available = (float) (WarehouseItem::find($itemId)?->quantity ?? 0) + (float) $get('_original_quantity');

                                            return 'Tồn khả dụng: ' . Number::format($available, maxPrecision: 2);
                                        })
                                        ->rules([
                                            fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                                $itemId = $get('warehouse_item_id');
                                                if (! $itemId) {
                                                    return;
                                                }

                                                $available = (float) (WarehouseItem::find($itemId)?->quantity ?? 0) + (float) $get('_original_quantity');

                                                if ((float) $value > $available) {
                                                    $fail("Số lượng xuất vượt quá tồn khả dụng ({$available}).");
                                                }
                                            },
                                        ])
                                        ->columnSpan(1),

                                    // "Lý do xuất" thuộc về TỪNG DÒNG (không phải cả phiếu) — trong
                                    // cùng 1 lần xuất (vd 1 lượt dọn phòng), các vật tư có thể tiêu
                                    // hao vì lý do khác nhau: cái thì dùng bình thường
                                    // (housekeeping), cái thì hư hỏng/thất thoát — gộp chung 1 lý do
                                    // cho cả phiếu sẽ sai bản chất.
                                    Select::make('reason')
                                        ->label('Lý do')
                                        ->options(WarehouseStockOut::REASONS)
                                        ->required()
                                        ->columnSpan(2),
                                ]),

                            Hidden::make('_original_quantity')
                                ->default(0)
                                ->dehydrated(false)
                                ->afterStateHydrated(function (Set $set, Get $get) {
                                    $set('_original_quantity', $get('quantity') ?? 0);
                                }),

                            TextInput::make('note')
                                ->label('Ghi chú')
                                ->placeholder('Ghi chú (nếu có)')
                                ->maxLength(255),
                        ])
                        ->columns(1)
                        ->minItems(1)
                        // Không có dòng trống mồi — Filament\Repeater mặc định TỰ THÊM 1 dòng trống
                        // (Repeater::setUp() gọi defaultItems(1) sẵn), phải ép về 0.
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state) => filled($state['warehouse_item_id'] ?? null)
                            ? WarehouseItem::find($state['warehouse_item_id'])?->name
                            : 'Vật tư mới')
                        ->reorderable(false)
                        // Dùng THẲNG nút "+ Thêm vật tư" có sẵn của Repeater — xem giải thích đầy đủ
                        // ở WarehouseStockInForm (cùng lý do: bản tự vẽ dropdown/thẻ "+" riêng liên
                        // tục lệch vị trí/che khuất do tính toán neo popup không chuẩn). Modal popup
                        // giữa màn hình, chọn nhiều vật tư 1 lúc qua Select multiple có nhóm category.
                        ->addActionLabel('Thêm vật tư')
                        ->addAction(fn (Action $action) => $action
                            ->icon('heroicon-o-plus')
                            ->size('xl')
                            // Nút "to như thẻ" — xem giải thích đầy đủ ở WarehouseStockInForm.
                            ->extraAttributes([
                                'class' => 'w-full sm:w-auto !h-16 !px-10 !rounded-xl !border-2 !border-dashed',
                            ])
                            ->modalHeading('Thêm vật tư')
                            ->modalWidth('6xl')
                            ->modalSubmitActionLabel('Thêm')
                            // Menu "danh sách lớn" — mỗi nhóm (category) 1 cột CheckboxList, y hệt
                            // layout trước đây, field Filament THẬT trong modal THẬT — xem
                            // WarehouseItemOptions::pickerFormSchema().
                            ->form(fn (Repeater $component) => WarehouseItemOptions::pickerFormSchema($component))
                            ->action(function (array $data, Repeater $component): void {
                                $items = $component->getState() ?? [];

                                // KHÔNG dùng Get $get ở đây — Get/Set bên trong ->action() của
                                // Repeater::addAction() resolve theo schema RIÊNG của modal picker,
                                // KHÔNG thấy được field 'default_reason' ở form NGOÀI (đã xác nhận
                                // thực tế: bấm "Thêm" không báo lỗi gì nhưng KHÔNG thêm được dòng
                                // nào — $get() âm thầm trả về giá trị sai/rỗng). Đọc thẳng
                                // $component->getLivewire()->data, CÙNG kỹ thuật đã dùng ở
                                // WarehouseItemOptions::resolveOuterFormScope() cho đúng
                                // partner_id/branch_id của form ngoài.
                                $defaultReason = $component->getLivewire()->data['default_reason'] ?? null;

                                foreach (WarehouseItemOptions::pickerSelectedIds($data) as $warehouseItemId) {
                                    $items[(string) Str::uuid()] = [
                                        'warehouse_item_id'  => $warehouseItemId,
                                        // Cùng nguồn "default_reason" với đường quét mã vạch — chọn
                                        // nhiều vật tư 1 lúc qua modal này cũng khỏi phải chọn tay
                                        // lại lý do cho từng dòng nếu đã chọn sẵn ở trên.
                                        'reason'             => $defaultReason,
                                        'quantity'           => null,
                                        'note'               => null,
                                        '_original_quantity' => 0,
                                    ];
                                }

                                $component->state($items);
                                $component->callAfterStateUpdated();
                            })),
                ]),
        ]);
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
