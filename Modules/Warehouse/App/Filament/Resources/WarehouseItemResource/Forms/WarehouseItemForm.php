<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\Forms;

use App\Models\Partner;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\Number;
use Modules\Category\Entities\Category;

class WarehouseItemForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            self::partnerInput(),
            self::branchInput(),

            // Ép rõ 'default' => 1 cho MỌI Grid/columnSpan trong module kho — dùng số nguyên đơn
            // (vd Grid::make(2)) chỉ gán cột cho breakpoint "lg" (Filament\Forms\Concerns\HasColumns),
            // KHÔNG hề gán gì cho mobile mặc định. Field nào có ->columnSpan(2) dạng số nguyên thì lại
            // áp dụng cho breakpoint "default" (Filament\Forms\Components\Concerns\CanSpanColumns) —
            // tức LUÔN chiếm 2 cột kể cả trên mobile dù container chưa có cột nào được định nghĩa cho
            // mobile, khiến các field 1-cột còn lại bị dồn ép chung hàng với nó. Phải khai báo mảng
            // tường minh cho cả 2 phía mới chắc chắn đúng: mobile 1 cột, desktop giữ nguyên bố cục.
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                TextInput::make('name')
                    ->label('Tên vật tư / hàng hóa')
                    ->placeholder('VD: Khăn tắm, Nước suối, Giấy vệ sinh')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('sku')
                    ->label('Mã vật tư (SKU)')
                    ->placeholder('Để trống sẽ không kiểm tra trùng')
                    ->maxLength(100),

                // Chọn từ danh mục "Nhóm vật tư" (WarehouseCategoryResource) — bấm "+" để thêm
                // nhanh nhóm mới ngay tại đây, hoặc vào menu "Quản lý kho > Nhóm vật tư" để
                // sửa/xóa toàn bộ danh sách.
                Select::make('warehouse_category_id')
                    ->label('Nhóm')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('Tên nhóm')
                            ->required()
                            ->maxLength(150),
                    ])
                    ->createOptionModalHeading('Thêm nhóm mới')
                    ->editOptionForm([
                        TextInput::make('name')
                            ->label('Tên nhóm')
                            ->required()
                            ->maxLength(150),
                    ])
                    ->editOptionModalHeading('Sửa nhóm'),

                // Chọn từ danh mục "Đơn vị tính" (WarehouseUnitResource) — cùng cơ chế thêm/sửa
                // nhanh như trên.
                Select::make('warehouse_unit_id')
                    ->label('Đơn vị tính')
                    ->relationship('unit', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('Tên đơn vị tính')
                            ->required()
                            ->maxLength(50),
                    ])
                    ->createOptionModalHeading('Thêm đơn vị tính mới')
                    ->editOptionForm([
                        TextInput::make('name')
                            ->label('Tên đơn vị tính')
                            ->required()
                            ->maxLength(50),
                    ])
                    ->editOptionModalHeading('Sửa đơn vị tính'),

                TextInput::make('unit_price')
                    ->label('Đơn giá tham chiếu')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('₫'),

                TextInput::make('quantity')
                    ->label('Tồn kho hiện tại')
                    ->numeric()
                    ->default(0)
                    ->live(onBlur: true)
                    ->helperText('Chỉ nên chỉnh trực tiếp khi khởi tạo dữ liệu ban đầu — sau đó số này sẽ được cập nhật tự động qua phiếu nhập / xuất / kiểm kê. Mọi lần sửa trực tiếp tại đây đều được ghi lại vào "Lịch sử biến động" bên dưới (ai sửa, lúc nào, từ bao nhiêu → bao nhiêu).'),

                // Tách tồn kho tổng thành "Đang dùng" (tự nhập) + "Dự phòng" (tự tính = tổng -
                // đang dùng, luôn khớp, không lưu cột riêng — xem WarehouseItem::getQuantityReserveAttribute()).
                // Số liệu NHẬP TAY để theo dõi/báo cáo — KHÔNG tự động theo phiếu nhập/xuất/kiểm kê
                // (các phiếu đó chỉ đổi tổng "Tồn kho hiện tại" ở trên).
                Grid::make(['default' => 1, 'lg' => 2])
                    ->schema([
                        TextInput::make('quantity_in_use')
                            ->label('Đang sử dụng')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->live(onBlur: true)
                            ->maxValue(fn (Get $get) => (float) $get('quantity'))
                            ->helperText('Không được lớn hơn Tồn kho hiện tại.'),

                        Placeholder::make('quantity_reserve_display')
                            ->label('Dự phòng')
                            ->content(fn (Get $get) => Number::format(
                                max(0, (float) $get('quantity') - (float) $get('quantity_in_use')),
                                maxPrecision: 2
                            )),
                    ])
                    ->columnSpanFull(),

                TextInput::make('min_quantity')
                    ->label('Ngưỡng tồn tối thiểu')
                    ->numeric()
                    ->default(0)
                    ->helperText('Cảnh báo khi tồn kho chạm ngưỡng này.'),

                Toggle::make('status')
                    ->label('Đang sử dụng')
                    ->default(true),

                Textarea::make('description')
                    ->label('Ghi chú')
                    ->rows(3)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    // Xem giải thích ở WarehouseCategoryForm::partnerInput().
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
            ->live()
            ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
            ->helperText('Bắt buộc chọn — nếu không, vật tư sẽ không hiện với bất kỳ tài khoản đối tác nào.')
            ->columnSpanFull();
    }

    // super_admin: chọn chi nhánh của đối tác đã chọn ở partnerInput(). Tài khoản khác (chủ đối
    // tác quản lý nhiều chi nhánh, hoặc nhân viên được cấp quyền nhiều chi nhánh qua
    // UserBranchPermission) dùng chung 1 nguồn xác thực duy nhất —
    // User::rootProductCategoryIds() (tái sử dụng nguyên vẹn logic phân quyền chi nhánh sẵn có
    // của hệ thống): field CHỈ hiện khi tài khoản đó quản lý NHIỀU HƠN 1 chi nhánh — nếu chỉ có
    // đúng 1, ẩn field và tự gán qua BelongsToBranch::creating() (không cần tự chọn).
    private static function branchInput(): Select
    {
        return Select::make('branch_id')
            ->label('Chi nhánh')
            ->options(function (Get $get) {
                $user = auth()->user();

                if ($user?->isSuperAdmin()) {
                    return Category::query()
                        ->where('category_type', 'product')
                        ->whereNull('parent_id')
                        ->where('partner_id', $get('partner_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();
                }

                return Category::query()
                    ->whereIn('id', $user?->rootProductCategoryIds() ?? [])
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all();
            })
            ->default(function () {
                $user = auth()->user();
                if ($user?->isSuperAdmin()) {
                    return null;
                }
                $branchIds = $user?->rootProductCategoryIds() ?? [];

                return count($branchIds) === 1 ? $branchIds[0] : null;
            })
            ->searchable()
            ->preload()
            ->required()
            ->disabled(fn (Get $get) => (auth()->user()?->isSuperAdmin() ?? false) && blank($get('partner_id')))
            ->visible(fn () => (auth()->user()?->isSuperAdmin() ?? false) || count(auth()->user()?->rootProductCategoryIds() ?? []) > 1)
            ->helperText('Bắt buộc chọn — nếu không, vật tư sẽ không hiện với bất kỳ tài khoản đối tác nào.')
            ->columnSpanFull();
    }
}
