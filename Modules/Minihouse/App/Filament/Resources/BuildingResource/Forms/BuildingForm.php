<?php

namespace Modules\Minihouse\App\Filament\Resources\BuildingResource\Forms;

use App\Models\TbltProvince;
use App\Models\TbltWard;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Services\InvoiceMomoService;
use Modules\Minihouse\App\Support\VietnameseBanks;

class BuildingForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Building')
                ->columnSpanFull()
                ->tabs([
                    self::infoTab(),
                    self::ownerTab(),
                    self::paymentTab(),
                    self::billingCycleTab(),
                ]),
        ]);
    }

    private static function infoTab(): Tab
    {
        return Tab::make('Thông tin toà nhà')
            ->icon('heroicon-o-building-office-2')
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label('Tên toà nhà')
                    ->required()
                    ->maxLength(255),
                // Tuỳ chọn — chỉ cần khi quản lý nhiều toà nhà và muốn gộp nhóm theo vùng để gán
                // quyền/lọc theo cả cụm (xem Zone::class, User::rootBuildingIds()). Để trống nếu
                // chỉ có 1 vài toà, không bắt buộc phải tạo Khu vực.
                Select::make('zone_id')
                    ->label('Khu vực')
                    ->relationship(
                        name: 'zone',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->withoutGlobalScopes(),
                    )
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->createOptionForm([
                        TextInput::make('name')->label('Tên khu vực')->required()->maxLength(255),
                    ])
                    ->helperText('Không bắt buộc — chỉ dùng khi cần gộp nhóm nhiều toà nhà theo vùng.'),
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
            ]);
    }

    private static function ownerTab(): Tab
    {
        return Tab::make('Chủ sở hữu')
            ->icon('heroicon-o-user')
            ->schema([
                // Đủ để đưa vào mục "BÊN CHO THUÊ" của hợp đồng thuê phòng (CCCD + địa chỉ là 2
                // field pháp lý bắt buộc trên hợp đồng giấy — xem ContractContentRenderer::render(),
                // Building::hasCompleteOwnerProfile()) — tách riêng khỏi tài khoản nhận tiền (tab
                // "Tài khoản thanh toán") vì có thể khác nhau, VD tài khoản ngân hàng đứng tên người
                // khác (vợ/chồng, kế toán...).
                Section::make('Thông tin chủ sở hữu')
                    ->description('Dùng để tự điền mục "Bên cho thuê" khi in hợp đồng, và để liên hệ khi cần.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('owner_name')
                            ->label('Tên chủ sở hữu')
                            ->maxLength(255),
                        TextInput::make('owner_phone')
                            ->label('Số điện thoại')
                            ->tel()
                            ->maxLength(20),
                        TextInput::make('owner_id_card_number')
                            ->label('Số CCCD/CMND')
                            ->maxLength(20),
                        TextInput::make('owner_email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('owner_address')
                            ->label('Địa chỉ thường trú')
                            ->columnSpanFull()
                            ->maxLength(255),
                    ]),
            ]);
    }

    private static function paymentTab(): Tab
    {
        return Tab::make('Tài khoản thanh toán')
            ->icon('heroicon-o-credit-card')
            ->schema([
                // Chọn kiểu QR TỰ ĐỘNG áp dụng cho toà nhà này — dùng cho nút "Tạo mã QR thanh toán"
                // (Sửa hoá đơn) và mã QR trên "In phiếu thu" (xem Building::activePaymentMethod()).
                // KHÔNG liên quan tới việc có hiện thông tin ngân hàng hay không — mục "Tài khoản
                // ngân hàng nhận tiền" bên dưới LUÔN hiện, áp dụng cho MỌI toà nhà bất kể chọn gì ở
                // đây (VD gửi Zalo nhắc đóng tiền luôn kèm số tài khoản, dù toà đó dùng PayOS làm QR
                // chính) — chỉ khi chọn PayOS thì mới PHÁT SINH THÊM link thanh toán tự động phía dưới.
                Section::make('Kiểu QR tự động (khi tạo mã QR/in phiếu thu)')
                    ->schema([
                        Select::make('payment_method')
                            ->label('')
                            ->hiddenLabel()
                            ->options([
                                Building::PAYMENT_METHOD_VIETQR => 'QR chuyển khoản ngân hàng (VietQR) — xác nhận thủ công',
                                Building::PAYMENT_METHOD_PAYOS  => 'Tài khoản PayOS riêng — tự động xác nhận',
                                Building::PAYMENT_METHOD_MOMO   => 'Tài khoản MoMo Business riêng — tự động xác nhận',
                                Building::PAYMENT_METHOD_VNPAY  => 'Tài khoản VNPay riêng — tự động xác nhận',
                            ])
                            ->native(false)
                            ->live()
                            ->placeholder('Chưa cấu hình — không tự tạo mã QR trên hoá đơn/phiếu thu')
                            ->helperText('Chỉ ảnh hưởng tới mã QR tự tạo — số tài khoản ngân hàng ở mục dưới vẫn luôn hiển thị dù chọn gì ở đây.'),
                    ]),

                // LUÔN hiện, áp dụng cho MỌI toà nhà — không phụ thuộc "Kiểu QR tự động" ở trên.
                // Dùng để: (1) in mã QR chuyển khoản trên phiếu thu khi chọn VietQR ở trên, (2) LUÔN
                // kèm theo trong tin nhắc Zalo/mọi thông báo khác dù toà đó dùng PayOS làm QR chính
                // (khách vẫn có thể muốn chuyển khoản tay thay vì quét QR PayOS) — xem
                // MinihouseZaloService::buildInvoiceTemplateData(). Chọn ngân hàng từ danh sách cố
                // định (VietnameseBanks, lấy từ VietQR) thay vì gõ tay để tránh sai mã BIN khiến QR
                // không quét được.
                Section::make('Tài khoản ngân hàng nhận tiền')
                    ->description('Bắt buộc cho mọi toà nhà (dùng để in phiếu thu, tạo QR VietQR, và luôn kèm trong tin nhắc Zalo) — không phụ thuộc "Kiểu QR tự động" ở trên.')
                    ->columns(2)
                    ->schema([
                        Hidden::make('owner_bank_name'),
                        Select::make('owner_bank_bin')
                            ->label('Ngân hàng')
                            ->options(VietnameseBanks::options())
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->required()
                            ->afterStateUpdated(fn (Set $set, $state) => $set('owner_bank_name', VietnameseBanks::shortName($state))),
                        TextInput::make('owner_bank_account_number')
                            ->label('Số tài khoản')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('owner_bank_account_holder')
                            ->label('Tên chủ tài khoản')
                            ->helperText('Ghi đúng như trên thẻ/tài khoản ngân hàng (không dấu, in hoa) — VD: NGUYEN VAN A.')
                            ->required()
                            ->maxLength(255),
                    ]),

                // Chủ toà nhà CÓ SẴN tài khoản PayOS riêng (khác tài khoản PayOS chung của cả hệ
                // thống) — điền đủ 3 ô dưới đây thì QR thanh toán của TOÀ NÀY tự dùng tài khoản riêng
                // đó, tiền vào thẳng tài khoản của họ NGAY TỪ ĐẦU (không qua quỹ chung), PayOS vẫn tự
                // gọi webhook xác nhận thanh toán y hệt cơ chế chung, không cần thêm bước "Chi hộ"
                // chuyển tiền lại. Xem Building::hasOwnPayOs()/activePaymentMethod(), InvoicePayOsService.
                Section::make('Tài khoản PayOS riêng')
                    ->description('Lấy Client ID/API Key/Checksum Key từ dashboard.payos.vn của chủ toà nhà.')
                    ->visible(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_PAYOS)
                    ->columns(1)
                    ->schema([
                        TextInput::make('payos_client_id')
                            ->label('Client ID')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_PAYOS)
                            ->maxLength(255),
                        TextInput::make('payos_api_key')
                            ->label('API Key')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_PAYOS)
                            ->maxLength(255),
                        TextInput::make('payos_checksum_key')
                            ->label('Checksum Key')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_PAYOS)
                            ->maxLength(255),
                    ]),

                // Cùng nguyên tắc PayOS ở trên — KHÁC ở chỗ MoMo/VNPay KHÔNG có "tài khoản chung" dự
                // phòng (xem Building::hasOwnMomo()/hasOwnVnpay(), InvoiceMomoService/
                // InvoiceVnpayService) — chọn 1 trong 2 kiểu này mà chưa điền đủ thì nút "Tạo link
                // thanh toán" ở hoá đơn báo lỗi rõ ràng, không tự rơi về QR nào khác.
                //
                // "Chế độ thử nghiệm" — MoMo/VNPay dùng domain API + bộ khoá HOÀN TOÀN KHÁC nhau
                // giữa sandbox và thật (xem InvoiceMomoService/InvoiceVnpayService) — bật cờ này để
                // TEST NGAY bằng dữ liệu sandbox, TRƯỚC KHI đăng ký doanh nghiệp thật xong. Nhớ TẮT
                // lại khi đổi sang bộ khoá thật, không thì MoMo/VNPay sẽ từ chối vì gọi sai domain.
                Toggle::make('payment_sandbox')
                    ->label('Chế độ thử nghiệm (Sandbox)')
                    ->helperText('Bật để test bằng dữ liệu sandbox trước khi đăng ký doanh nghiệp thật xong — nhớ TẮT lại khi đổi sang tài khoản thật.')
                    ->visible(fn (Get $get) => in_array($get('payment_method'), [Building::PAYMENT_METHOD_MOMO, Building::PAYMENT_METHOD_VNPAY])),

                Section::make('Tài khoản MoMo Business riêng')
                    ->description('Bật "Chế độ thử nghiệm" ở trên thì điền bộ TEST công khai bên dưới để dùng ngay. Tắt đi thì lấy Partner Code/Access Key/Secret Key THẬT từ trang quản trị MoMo Business của chủ toà nhà.')
                    ->visible(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_MOMO)
                    ->columns(1)
                    ->schema([
                        Placeholder::make('momo_sandbox_hint')
                            ->label('')
                            ->visible(fn (Get $get) => (bool) $get('payment_sandbox'))
                            ->content(new HtmlString(
                                'Bộ test công khai của MoMo (copy đúng nguyên văn):<br>'
                                . '<code>Partner Code: ' . InvoiceMomoService::SANDBOX_PARTNER_CODE . '</code><br>'
                                . '<code>Access Key: ' . InvoiceMomoService::SANDBOX_ACCESS_KEY . '</code><br>'
                                . '<code>Secret Key: ' . InvoiceMomoService::SANDBOX_SECRET_KEY . '</code>'
                            )),
                        TextInput::make('momo_partner_code')
                            ->label('Partner Code')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_MOMO)
                            ->maxLength(255),
                        TextInput::make('momo_access_key')
                            ->label('Access Key')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_MOMO)
                            ->maxLength(255),
                        TextInput::make('momo_secret_key')
                            ->label('Secret Key')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_MOMO)
                            ->maxLength(255),
                    ]),

                Section::make('Tài khoản VNPay riêng')
                    ->description('Bật "Chế độ thử nghiệm" ở trên thì tự đăng ký MIỄN PHÍ 1 tài khoản sandbox (không cần giấy tờ) tại sandbox.vnpayment.vn/devreg/ để lấy TmnCode/HashSecret riêng — không có bộ test dùng chung như MoMo. Tắt đi thì lấy Mã Website/Chuỗi bí mật THẬT từ email VNPay gửi sau khi ký hợp đồng merchant.')
                    ->visible(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_VNPAY)
                    ->columns(1)
                    ->schema([
                        TextInput::make('vnpay_tmn_code')
                            ->label('Mã Website (TMN Code)')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_VNPAY)
                            ->maxLength(255),
                        TextInput::make('vnpay_hash_secret')
                            ->label('Chuỗi bí mật (Hash Secret)')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('payment_method') === Building::PAYMENT_METHOD_VNPAY)
                            ->maxLength(255),
                    ]),
            ]);
    }

    // Nhiều toà nhà có nghiệp vụ thu tiền khác nhau (theo tháng dương lịch cố định vs theo ngày dọn
    // vào riêng từng khách) — tách riêng tab này để mỗi toà tự chọn đúng cách đang vận hành thật,
    // xem InvoiceGenerationService (áp dụng lúc lập hoá đơn) và InvoiceObserver (tự tạo nhắc đóng
    // tiền theo payment_reminder_days_before).
    private static function billingCycleTab(): Tab
    {
        return Tab::make('Chu kỳ hoá đơn')
            ->icon('heroicon-o-calendar-days')
            ->columns(2)
            ->schema([
                Select::make('billing_cycle_type')
                    ->label('Kiểu tính chu kỳ hoá đơn')
                    ->options([
                        Building::BILLING_CYCLE_CALENDAR_MONTH => 'Theo tháng dương lịch (mọi hợp đồng đóng cùng đợt, mùng 1 - cuối tháng)',
                        Building::BILLING_CYCLE_ANNIVERSARY    => 'Theo ngày thuê (mỗi hợp đồng tính riêng theo ngày bắt đầu của chính nó)',
                    ])
                    ->default(Building::BILLING_CYCLE_CALENDAR_MONTH)
                    ->native(false)
                    ->live()
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Áp dụng cho MỌI hợp đồng đang có trong toà này. Đổi kiểu chỉ ảnh hưởng tới hoá đơn lập MỚI sau đó, không tính lại hoá đơn cũ.'),
                // CHỈ có ý nghĩa với "Theo tháng dương lịch" — toà "Theo ngày thuê" đã có mốc cố định
                // riêng cho từng hợp đồng là chính ngày dọn vào của khách đó rồi, không cần thêm.
                TextInput::make('fixed_due_day')
                    ->label('Ngày thu cố định trong tháng')
                    ->visible(fn (Get $get) => $get('billing_cycle_type') === Building::BILLING_CYCLE_CALENDAR_MONTH)
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(28)
                    ->placeholder('Mặc định mùng 1')
                    ->helperText('VD nhập 10 = mọi hoá đơn của toà này tính hạn đóng tiền vào mùng 10 hàng tháng, không phải mùng 1. Không đổi cách tính tiền phòng, chỉ đổi ngày dùng để tự tạo "Nhắc đóng tiền" bên dưới.'),
                TextInput::make('payment_reminder_days_before')
                    ->label('Tự nhắc đóng tiền trước hạn (số ngày)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(30)
                    ->suffix('ngày')
                    ->helperText('Để trống = không tự tạo nhắc việc, nhân viên vẫn tạo tay như cũ. Có giá trị thì mỗi hoá đơn lập ra sẽ tự sinh 1 "Nhắc đóng tiền" đúng số ngày này trước ngày đến hạn của hoá đơn đó (mùng 1, ngày thu cố định, hoặc ngày dọn vào — tuỳ cấu hình ở trên).'),
                // Chỉ áp dụng cho hoá đơn VẪN CHƯA thanh toán sau lần nhắc trước — dừng lặp ngay khi
                // hoá đơn chuyển "Đã thanh toán" (xem SendReminderNotificationsCommand,
                // InvoicePaymentObserver tự đánh dấu "Đã xử lý" cho nhắc việc khi hoá đơn được duyệt
                // thanh toán xong).
                TextInput::make('payment_reminder_repeat_days')
                    ->label('Lặp lại nhắc nếu vẫn chưa thanh toán (số ngày)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(30)
                    ->suffix('ngày')
                    ->helperText('VD nhập 3: nhắc lần đầu xong, 3 ngày sau nếu hoá đơn VẪN chưa thanh toán thì tự nhắc lại, lặp mỗi 3 ngày cho tới khi thanh toán xong. Để trống = chỉ nhắc đúng 1 lần như trước.'),
                // Khác CheckOverdueContractsCommand (chỉ nhắc SAU KHI đã quá hạn, cho NHÂN VIÊN xử
                // lý) — trường này tự gửi Zalo TRỰC TIẾP cho KHÁCH THUÊ TRƯỚC ngày hết hạn để chủ
                // động gia hạn, xem NotifyExpiringContractsCommand.
                TextInput::make('contract_expiry_reminder_days_before')
                    ->label('Tự nhắc khách gia hạn hợp đồng trước hạn (số ngày)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(90)
                    ->suffix('ngày')
                    ->columnSpanFull()
                    ->helperText('Để trống = không tự nhắc, chỉ tạo nhắc việc nội bộ SAU KHI hợp đồng đã quá hạn như hiện tại. Có giá trị thì trước ngày hết hạn đúng số ngày này, hệ thống tự gửi Zalo cho khách thuê nhắc gia hạn (cần đã cấu hình mẫu Zalo "Nhắc hết hạn hợp đồng").'),
            ]);
    }
}
