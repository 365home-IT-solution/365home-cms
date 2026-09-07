<?php

namespace Modules\Minihouse\App\Filament\Resources\ResidenceDeclarationResource\Forms;

use App\Models\TbltProvince;
use App\Models\TbltWard;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Modules\Minihouse\App\Models\ResidenceDeclaration;

// Bố cục giữ NGUYÊN như bên Home (App\Filament\Resources\CccdDeclarationResource) — các Section xếp
// dọc, KHÔNG dùng Tabs, để giao diện giống hệt, không phải học lại cách dùng khi chuyển qua lại
// giữa 2 panel.
class ResidenceDeclarationForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin trích xuất từ CCCD')->schema([
                Grid::make(2)->schema([
                    TextInput::make('full_name')
                        ->label('Họ và tên')
                        ->maxLength(255),

                    Select::make('document_type')
                        ->label('Loại giấy tờ')
                        ->options(ResidenceDeclaration::DOCUMENT_TYPE_OPTIONS)
                        ->native(false)
                        ->searchable(),

                    TextInput::make('cccd_number')
                        ->label('Số giấy tờ')
                        ->maxLength(20),

                    TextInput::make('phone_number')
                        ->label('Số điện thoại')
                        ->tel()
                        ->maxLength(20),

                    TextInput::make('date_of_birth')
                        ->label('Ngày sinh')
                        ->placeholder('DD/MM/YYYY')
                        ->maxLength(20),

                    Select::make('gender')
                        ->label('Giới tính')
                        ->options(ResidenceDeclaration::GENDER_OPTIONS)
                        ->native(false),

                    Select::make('nationality')
                        ->label('Quốc tịch')
                        ->options(ResidenceDeclaration::NATIONALITY_OPTIONS)
                        ->default(ResidenceDeclaration::NATIONALITY_DEFAULT)
                        ->native(false)
                        ->searchable(),
                ]),
            ]),

            Section::make('Thông tin lưu trú')->schema([
                Grid::make(2)->schema([
                    TextInput::make('room_number')
                        ->label('Số phòng')
                        ->maxLength(255),

                    TextInput::make('stay_address')
                        ->label('Địa chỉ lưu trú (toà nhà)')
                        ->helperText('Địa chỉ nơi khách đang ở — tự lấy từ toà nhà của phòng.')
                        ->maxLength(255),

                    Select::make('reason_for_stay')
                        ->label('Lý do lưu trú')
                        ->options(ResidenceDeclaration::REASON_FOR_STAY_OPTIONS)
                        ->native(false)
                        ->searchable()
                        ->live(),

                    TextInput::make('custom_reason')
                        ->label('Nhập lý do (nếu chọn "Mục đích khác")')
                        ->maxLength(255)
                        ->visible(fn (Get $get) => $get('reason_for_stay') === '20 - Mục đích khác')
                        ->required(fn (Get $get) => $get('reason_for_stay') === '20 - Mục đích khác'),

                    DateTimePicker::make('checked_in_at')
                        ->label('Ngày đến')
                        ->native(false)
                        ->seconds(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y H:i'),

                    DateTimePicker::make('checked_out_at')
                        ->label('Ngày đi dự kiến')
                        ->native(false)
                        ->seconds(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y H:i'),
                ]),
            ]),

            Section::make('Nơi cư trú')
                ->description('"Nơi thường trú" và "Nơi ở hiện tại" là 2 khái niệm KHÁC NHAU theo mẫu khai báo cư trú của Bộ Công an. Trường dưới đây lấy tự động từ hồ sơ khách thuê, hãy sửa lại nếu khách cho biết nơi ở hiện tại khác.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('current_residence')
                            ->label('Nơi thường trú')
                            ->maxLength(255),

                        Select::make('residence_type')
                            ->label('Nơi cư trú hiện nay')
                            ->helperText('Phân loại của "Nơi thường trú" ở trên — không phải nội dung địa chỉ.')
                            ->options(ResidenceDeclaration::RESIDENCE_TYPE_OPTIONS)
                            ->native(false),

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

                        Textarea::make('address_detail')
                            ->label('Địa chỉ chi tiết')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                ]),

            Section::make('Trạng thái khai báo với công an')
                ->description('Hệ thống KHÔNG tự động gửi khai báo cho ASM/dịch vụ công — nhân viên vẫn phải tự nộp thủ công, đây chỉ là nơi tự đánh dấu đã nộp xong để tránh quên (hạn: trước 23h ngày khách đến, hoặc trước 8h sáng hôm sau nếu khách đến sau 23h).')
                ->schema([
                    Hidden::make('declared_by'),

                    Grid::make(2)->schema([
                        DateTimePicker::make('declared_at')
                            ->label('Đã khai báo lúc')
                            ->native(false)
                            ->seconds(false)
                            ->timezone('Asia/Ho_Chi_Minh')
                            ->displayFormat('d/m/Y H:i')
                            ->helperText('Để trống nếu chưa nộp khai báo cho cơ quan công an.')
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state) {
                                    $set('declared_by', auth()->id());
                                }
                            }),

                        TextInput::make('declaredBy.fullname')
                            ->label('Người khai báo')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                ]),
        ]);
    }
}
