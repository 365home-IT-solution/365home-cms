<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Forms;

use App\Models\Partner;
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
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Warehouse\App\Filament\Support\WarehouseCardStyle;
use Modules\Warehouse\App\Filament\Support\WarehouseItemOptions;
use Modules\Warehouse\App\Filament\Support\WarehouseRoomOptions;
use Modules\Warehouse\App\Models\WarehouseItem;
use Modules\Warehouse\App\Models\WarehouseStockOut;

class WarehouseStockOutForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin phiếu')
                ->schema([
                    self::partnerInput(),

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
                        // "Menu" chọn phòng theo cột chi nhánh (yêu cầu cụ thể — KHÔNG dùng 1 Select
                        // thường) — xem HasRoomBranchPicker::selectStockOutRoom() (đăng ký ở
                        // Create/EditWarehouseStockOut) và view
                        // 'warehouse::filament.forms.room-branch-picker'. Chỉ hiện chi nhánh/phòng
                        // mà user hiện tại được phép truy cập, tái dùng
                        // User::rootProductCategoryIds() — cùng nguồn xác thực quyền chi nhánh với
                        // CouponResource/DataPermission.
                        ViewField::make('room_picker')
                            ->label('Chọn phòng nhận hàng')
                            ->dehydrated(false)
                            ->live()
                            ->view('warehouse::filament.forms.room-branch-picker')
                            ->viewData(fn (Get $get) => [
                                'label'             => 'Chọn phòng nhận hàng',
                                'branches'          => WarehouseRoomOptions::branchesWithRooms(),
                                'selectedProductId' => $get('product_id'),
                            ]),

                        TextInput::make('issued_to')
                            ->label('Bộ phận / ghi chú nơi nhận (nếu không gắn 1 phòng cụ thể)')
                            ->placeholder('VD: Buồng phòng, Văn phòng, Bếp')
                            ->maxLength(255),
                    ]),

                    Hidden::make('product_id'),
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

                    // Ô tìm nhanh theo tên — lọc bằng JS thuần, không qua Livewire (gõ tới đâu
                    // ẩn/hiện thẻ ngay tới đó). Chỉ hiện khi ĐÃ có thẻ.
                    Placeholder::make('warehouse_stockout_search')
                        ->hiddenLabel()
                        ->content(WarehouseCardStyle::searchBox('fi-warehouse-stockout-repeater'))
                        ->visible(fn (Get $get) => filled($get('items'))),

                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
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

                                foreach (WarehouseItemOptions::pickerSelectedIds($data) as $warehouseItemId) {
                                    $items[(string) Str::uuid()] = [
                                        'warehouse_item_id'  => $warehouseItemId,
                                        'reason'             => null,
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

    // Chỉ super_admin thấy field này — nhân viên đối tác tạo phiếu thì partner_id tự động lấy theo
    // tài khoản đang đăng nhập (BelongsToPartner::creating()), không cần tự chọn. Nếu super_admin
    // KHÔNG chọn, phiếu lưu với partner_id RỖNG, không đối tác nào thấy được — cùng pattern với
    // CategoryResource\Forms\CategoryForm::partnerInput() và WarehouseStockInForm::partnerInput().
    private static function partnerInput(): Select
    {
        return Select::make('partner_id')
            ->label('Đối tác sở hữu')
            ->options(fn () => Partner::withTrashed()
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Partner $partner) => [
                    $partner->id => $partner->name . ($partner->trashed() ? ' (đã xoá)' : ''),
                ])
                ->all())
            ->getOptionLabelUsing(fn ($value) => $value
                ? Partner::withTrashed()->find($value)?->name
                : null)
            ->dehydrated()
            ->searchable()
            ->preload()
            ->required()
            ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
            ->helperText('Bắt buộc chọn — nếu không, phiếu sẽ không hiện với bất kỳ tài khoản đối tác nào.');
    }
}
