<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource\Forms;

use App\Models\Partner;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Warehouse\App\Filament\Support\WarehouseCardStyle;
use Modules\Warehouse\App\Filament\Support\WarehouseItemOptions;
use Modules\Warehouse\App\Models\WarehouseItem;
use Modules\Warehouse\App\Models\WarehouseStockIn;

class WarehouseStockInForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            // Section này chỉ có 2 lý do để hiện: (a) đang SỬA — xem lại Ngày nhập/Người nhập/Mã
            // phiếu, hoặc (b) super_admin đang TẠO MỚI — cần chọn "Đối tác sở hữu" NGAY LÚC TẠO, vì
            // BelongsToPartner chỉ tự gán partner_id theo TÀI KHOẢN đang đăng nhập (xem
            // app/Models/Concerns/BelongsToPartner.php); super_admin không thuộc đối tác nào nên nếu
            // không tự chọn, phiếu sẽ lưu với partner_id RỖNG — nhân viên đối tác thật sẽ KHÔNG BAO
            // GIỜ thấy được phiếu này (đã xác nhận thực tế: phiếu PN000001 tạo lúc test bằng tài
            // khoản super_admin bị rỗng partner_id, tài khoản nv1@gmail.com thuộc đối tác thật không
            // xem được dù ĐÃ có đủ quyền).
            Section::make('Thông tin phiếu')
                ->schema([
                    self::partnerInput(),

                    // 1 dòng gọn duy nhất thay vì 3 ô/khung tách rời — cả 3 đều CHỈ ĐỂ ĐỌC (không
                    // field nào chỉnh được), tách thành 3 Placeholder riêng chiếm quá nhiều khoảng
                    // trắng không cần thiết.
                    Placeholder::make('stockin_meta_display')
                        ->hiddenLabel()
                        ->content(fn (WarehouseStockIn $record) => new HtmlString(sprintf(
                            '<div class="flex flex-wrap items-center gap-x-8 gap-y-1 text-sm">
                                <span><span class="text-gray-500 dark:text-gray-400">Ngày nhập:</span> <span class="font-medium text-gray-950 dark:text-white">%s</span></span>
                                <span><span class="text-gray-500 dark:text-gray-400">Người nhập kho:</span> <span class="font-medium text-gray-950 dark:text-white">%s</span></span>
                                <span><span class="text-gray-500 dark:text-gray-400">Mã phiếu:</span> <span class="font-medium text-gray-950 dark:text-white">%s</span></span>
                            </div>',
                            e($record->received_at?->format('d/m/Y H:i') ?? '—'),
                            e(CurrentUserDisplay::forUser($record->creator)),
                            e($record->code)
                        )))
                        ->visibleOn('edit'),
                ])
                ->compact()
                ->visible(fn (string $operation) => $operation === 'edit' || (auth()->user()?->isSuperAdmin() ?? false)),

            Section::make('Chi tiết hàng nhập')
                ->compact()
                ->schema([
                    // Cùng khối <style> tô viền thẻ + tên vật tư primary như phiếu kiểm kê — xem
                    // WarehouseCardStyle (lớp riêng .fi-warehouse-stockin-repeater).
                    Placeholder::make('warehouse_stockin_repeater_style')
                        ->hiddenLabel()
                        ->content(WarehouseCardStyle::styleBlock('fi-warehouse-stockin-repeater'))
                        ->extraAttributes(['class' => 'hidden']),

                    // Ô tìm nhanh theo tên — lọc bằng JS thuần, không qua Livewire (gõ tới đâu
                    // ẩn/hiện thẻ ngay tới đó). Chỉ hiện khi ĐÃ có thẻ (Repeater rỗng thì không có
                    // gì để lọc) — xem $get('items').
                    Placeholder::make('warehouse_stockin_search')
                        ->hiddenLabel()
                        ->content(WarehouseCardStyle::searchBox('fi-warehouse-stockin-repeater'))
                        ->visible(fn (Get $get) => filled($get('items'))),

                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->extraAttributes(['class' => 'fi-warehouse-stockin-repeater'])
                        // Dạng LƯỚI thẻ như phiếu kiểm kê — mỗi thẻ xếp DỌC 1 cột nội bộ (Vật tư /
                        // Số lượng + Đơn giá / Thành tiền / Ghi chú) thay vì 1 hàng ngang dài.
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

                            // Lưới 2x2: Số lượng / Đơn giá — Thành tiền / Tồn kho, y hệt bố cục
                            // "Icon Badge Card". Số lượng vẫn Ô VUÔNG nhỏ (phong cách "Đếm được" ở
                            // phiếu kiểm kê), Đơn giá vẫn input chữ nhật (số tiền có thể dài, ép
                            // vuông sẽ tràn/khó đọc). Xem giải thích 'default' => ... ở
                            // WarehouseItemForm — Grid::make(N) dạng số nguyên không tự có cột
                            // mobile, phải khai báo tường minh.
                            Grid::make(['default' => 2, 'lg' => 2])
                                ->schema([
                                    TextInput::make('quantity')
                                        ->label('Số lượng')
                                        ->numeric()
                                        ->minValue(0.01)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->extraAttributes(WarehouseCardStyle::neutralBox())
                                        ->extraInputAttributes(WarehouseCardStyle::inputStyle()),

                                    TextInput::make('unit_price')
                                        ->label('Đơn giá')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required()
                                        ->prefix('₫')
                                        ->live(onBlur: true)
                                        ->extraAttributes(WarehouseCardStyle::tallRectangleBox()),

                                    Placeholder::make('amount_display')
                                        ->label('Thành tiền')
                                        ->content(fn (Get $get) => number_format(((float) $get('quantity')) * ((float) $get('unit_price')), 0, ',', '.') . ' ₫'),

                                    // Tồn kho HIỆN TẠI của vật tư (chưa cộng phiếu này) — tô màu
                                    // theo mức tối thiểu (min_quantity), giúp nhận biết ngay vật tư
                                    // nào sắp hết mà không cần rời màn hình đi tra riêng.
                                    Placeholder::make('stock_display')
                                        ->label('Tồn kho')
                                        ->content(fn (Get $get) => WarehouseCardStyle::stockLevelHtml(
                                            WarehouseItem::find($get('warehouse_item_id'))
                                        )),
                                ]),

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
                        // Dùng THẲNG nút "+ Thêm vật tư" có sẵn của Repeater (nằm ĐÚNG trong cùng
                        // lưới thẻ — tự động nằm CẠNH thẻ cuối cùng, chỉ xuống hàng mới khi hàng đã
                        // đầy, đúng hành vi lưới thẻ bình thường) thay vì tự vẽ dropdown/thẻ "+"
                        // riêng như trước — bản tự vẽ liên tục lệch vị trí/che khuất do phải tính
                        // toán lại việc neo popup (floating-ui) theo cách không chuẩn. Bấm nút "+"
                        // giờ mở 1 MODAL popup giữa màn hình (form chuẩn của Filament, không phải
                        // dropdown), chọn được NHIỀU vật tư 1 lúc (Select multiple, có nhóm theo
                        // category qua optgroup — WarehouseItemOptions::grouped()).
                        ->addActionLabel('Thêm vật tư')
                        ->addAction(fn (Action $action) => $action
                            ->icon('heroicon-o-plus')
                            ->size('xl')
                            // Nút "to như thẻ" — Action gốc của Repeater vốn chỉ là 1 nút nhỏ dạng
                            // pill, ->extraAttributes() ép lại kích thước/kiểu viền để to hẳn lên,
                            // giống 1 tấm thẻ (khung nét đứt), không phải nút bé như trước.
                            ->extraAttributes([
                                'class' => 'w-full sm:w-auto !h-16 !px-10 !rounded-xl !border-2 !border-dashed',
                            ])
                            ->modalHeading('Thêm vật tư')
                            ->modalWidth('6xl')
                            ->modalSubmitActionLabel('Thêm')
                            // Menu "danh sách lớn" — mỗi nhóm (category) 1 cột CheckboxList, y hệt
                            // layout trước đây, nhưng giờ là field Filament THẬT bên trong modal
                            // THẬT (không phải dropdown tự vẽ) — xem
                            // WarehouseItemOptions::pickerFormSchema().
                            ->form(fn (Repeater $component) => WarehouseItemOptions::pickerFormSchema($component))
                            ->action(function (array $data, Repeater $component): void {
                                $items = $component->getState() ?? [];

                                foreach (WarehouseItemOptions::pickerSelectedIds($data) as $warehouseItemId) {
                                    $item = WarehouseItem::find($warehouseItemId);

                                    $items[(string) Str::uuid()] = [
                                        'warehouse_item_id' => $warehouseItemId,
                                        'quantity'          => null,
                                        'unit_price'        => $item?->unit_price ?? 0,
                                        'note'              => null,
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
    // KHÔNG chọn, phiếu lưu với partner_id RỖNG, không đối tác nào thấy được (xem giải thích ở khối
    // Section 'Thông tin phiếu' phía trên). Cùng pattern với
    // CategoryResource\Forms\CategoryForm::partnerInput() — dùng withTrashed() để luôn hiện đúng dù
    // đối tác đã bị xoá mềm.
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
