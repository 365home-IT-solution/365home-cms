<?php

namespace Modules\Minihouse\App\Filament\Resources\BuildingResource\Forms;

use App\Models\TbltProvince;
use App\Models\TbltWard;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;

class BuildingForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin toà nhà')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Tên toà nhà')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('address')
                        ->label('Địa chỉ chi tiết')
                        ->helperText('Số nhà, tên đường... Dùng để tự điền "Khai báo lưu trú" khi tạo hợp đồng.')
                        ->maxLength(255),
                    // Tỉnh/Thành phố + Phường/Xã dạng "display" theo ĐÚNG mẫu chính thức Bộ Công an
                    // (App\Models\TbltProvince/TbltWard) — cùng nguồn dữ liệu ResidenceDeclarationForm
                    // đang dùng — để ResidenceDeclarationService tự điền thẳng cho "Khai báo lưu trú",
                    // không cần nhân viên chọn lại tay mỗi lần tạo hợp đồng cho phòng thuộc toà nhà này.
                    Select::make('province')
                        ->label('Tỉnh / Thành phố')
                        ->options(fn () => TbltProvince::orderBy('name')->pluck('display', 'display'))
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('ward', null)),
                    Select::make('ward')
                        ->label('Phường / Xã / Đặc khu')
                        ->options(function (Get $get) {
                            $provinceDisplay = $get('province');

                            if (blank($provinceDisplay) || ! str_contains($provinceDisplay, ' - ')) {
                                return [];
                            }

                            $provinceCode = strstr($provinceDisplay, ' - ', true);

                            return TbltWard::where('province_code', $provinceCode)
                                ->orderBy('name')
                                ->pluck('display', 'display');
                        })
                        ->searchable()
                        ->native(false)
                        ->disabled(fn (Get $get) => blank($get('province')))
                        ->helperText('Chọn Tỉnh/Thành phố trước.'),
                    // Đơn giá mặc định — InvoiceForm tự điền khi lập hoá đơn cho phòng thuộc toà
                    // nhà này (chỉ điền khi hoá đơn đang trống, không ép nếu tháng đó đổi giá).
                    TextInput::make('electric_unit_price')
                        ->label('Đơn giá điện mặc định')
                        ->numeric()
                        ->prefix('đ')
                        ->helperText('Áp dụng khi lập hoá đơn cho phòng thuộc toà này — sửa được riêng từng hoá đơn.'),
                    TextInput::make('water_unit_price')
                        ->label('Đơn giá nước mặc định')
                        ->numeric()
                        ->prefix('đ')
                        ->helperText('Áp dụng khi lập hoá đơn cho phòng thuộc toà này — sửa được riêng từng hoá đơn.'),
                    FileUpload::make('image')
                        ->label('Ảnh toà nhà')
                        ->image()
                        ->imageEditor()
                        ->imagePreviewHeight('150')
                        ->directory('minihouse/buildings')
                        ->disk('public')
                        ->columnSpanFull(),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
