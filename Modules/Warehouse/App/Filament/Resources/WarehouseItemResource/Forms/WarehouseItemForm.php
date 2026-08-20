<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\Forms;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;

class WarehouseItemForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
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
                    ->helperText('Chỉ nên chỉnh trực tiếp khi khởi tạo dữ liệu ban đầu — sau đó số này sẽ được cập nhật tự động qua phiếu nhập / xuất / kiểm kê. Mọi lần sửa trực tiếp tại đây đều được ghi lại vào "Lịch sử biến động" bên dưới (ai sửa, lúc nào, từ bao nhiêu → bao nhiêu).'),

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
}
