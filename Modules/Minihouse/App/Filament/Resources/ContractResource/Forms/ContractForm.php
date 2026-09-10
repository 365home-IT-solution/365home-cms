<?php

namespace Modules\Minihouse\App\Filament\Resources\ContractResource\Forms;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Modules\Minihouse\App\Filament\Resources\TenantResource;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\ResidenceDeclaration;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;

class ContractForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Contract')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Thông tin hợp đồng')
                        ->columns(2)
                        ->schema([
                            Select::make('room_id')
                                ->label('Phòng')
                                ->relationship('room', 'code')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                // Bấm 1 phòng "Trống" trên sơ đồ ở Dashboard (RoomOccupancyMapWidget)
                                // sẽ mở thẳng trang này với ?room_id=... — tự chọn sẵn đúng phòng đó,
                                // không bắt nhân viên chọn lại tay.
                                ->default(fn () => request()->query('room_id'))
                                // Tự điền "Giá thuê/tháng" + đơn giá điện/nước theo đúng giá đã tạo
                                // sẵn ở Phòng/Toà nhà — chỉ điền khi field đó ĐANG TRỐNG (tạo mới hợp
                                // đồng), không ghi đè nếu đang Sửa hợp đồng đã có giá khác do thương
                                // lượng riêng với khách.
                                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                    $room = Room::find($state);

                                    if (blank($get('monthly_price'))) {
                                        $set('monthly_price', $room?->price);
                                    }

                                    if (blank($get('electric_unit_price'))) {
                                        $set('electric_unit_price', $room?->building?->electric_unit_price);
                                    }

                                    if (blank($get('water_unit_price'))) {
                                        $set('water_unit_price', $room?->building?->water_unit_price);
                                    }
                                })
                                // Chặn 1 phòng có 2 hợp đồng "Đang hiệu lực" cùng lúc — trước đây tạo
                                // hợp đồng mới cho phòng chưa trả vẫn được, dữ liệu vô lý (2 người
                                // cùng "đang thuê" 1 phòng theo 2 hợp đồng độc lập).
                                ->rules([
                                    fn (Get $get, ?Contract $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                                        if ($get('status') !== Contract::STATUS_ACTIVE || blank($value)) {
                                            return;
                                        }

                                        $conflict = Contract::query()
                                            ->where('room_id', $value)
                                            ->where('status', Contract::STATUS_ACTIVE)
                                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                            ->exists();

                                        if ($conflict) {
                                            $fail('Phòng này đang có hợp đồng khác còn hiệu lực — kết thúc/huỷ hợp đồng cũ trước khi tạo hợp đồng mới.');
                                        }
                                    },
                                ]),
                            Select::make('tenant_id')
                                ->label('Khách thuê (đứng tên)')
                                ->relationship(
                                    name: 'tenant',
                                    titleAttribute: 'fullname',
                                    modifyQueryUsing: fn (Builder $query, ?Contract $record) => self::excludeTenantsRentingElsewhere($query, $record),
                                )
                                // Kèm số CCCD vào tên hiển thị — tránh chọn nhầm khách trùng tên
                                // (VD "Nguyễn Văn A" có nhiều người) — xem tenantOptionLabel().
                                ->getOptionLabelFromRecordUsing(fn (Tenant $record) => self::tenantOptionLabel($record))
                                ->searchable(['fullname', 'id_card_number'])
                                ->preload()
                                ->required()
                                ->suffixAction(self::viewTenantAction())
                                ->createOptionForm(self::miniTenantForm())
                                ->createOptionUsing(fn (array $data) => Tenant::create($data)->getKey()),
                            DatePicker::make('start_date')
                                ->label('Ngày bắt đầu')
                                ->required(),
                            DatePicker::make('end_date')
                                ->label('Ngày kết thúc'),
                            TextInput::make('monthly_price')
                                ->label('Giá thuê / tháng')
                                ->numeric()
                                ->required()
                                ->prefix('đ')
                                ->default(fn () => Room::find(request()->query('room_id'))?->price),
                            // Cột deposit_amount ở DB là NOT NULL nhưng field này không bắt buộc
                            // nhập (cho phép thuê không cọc) — để trống mà không có default(0) +
                            // dehydrateStateUsing() ép về 0 sẽ gửi lên null, vỡ ràng buộc NOT NULL,
                            // Filament không bắt được lỗi này thành thông báo form (lỗi DB thô, ra
                            // trang lỗi 500) — xem storage/logs/laravel.log.
                            TextInput::make('deposit_amount')
                                ->label('Tiền cọc')
                                ->numeric()
                                ->prefix('đ')
                                ->default(0)
                                ->dehydrateStateUsing(fn ($state) => $state ?? 0),
                            // KHÔNG còn lựa chọn "Đã huỷ" (Contract::STATUS_CANCELLED) ở đây — trước
                            // đây trạng thái này chọn tay được mà không qua nghiệp vụ nào (không ghi
                            // lý do huỷ, không xử lý hoàn cọc nhất quán) — giờ CHỈ chuyển sang "Đã
                            // huỷ" qua nút "Huỷ hợp đồng" ở EditContract (đã có xử lý đầy đủ). Hợp
                            // đồng ĐÃ huỷ từ trước vẫn hiển thị/lọc được bình thường (badge/filter ở
                            // ContractTable vẫn còn nguyên), chỉ là không thể chọn LẠI trạng thái này
                            // bằng cách sửa tay field nữa.
                            Select::make('status')
                                ->label('Trạng thái')
                                // "Đã huỷ" chỉ hiện lại làm option khi ĐÃ là giá trị hiện có (hợp đồng
                                // huỷ qua action "Huỷ hợp đồng" trước đó) — để mở lại trang Sửa không
                                // bị hiện Select trống do giá trị không khớp option nào; KHÔNG cho
                                // chọn MỚI sang "Đã huỷ" bằng cách sửa tay field này nữa.
                                ->options(fn (Get $get) => [
                                    Contract::STATUS_ACTIVE  => 'Đang hiệu lực',
                                    Contract::STATUS_EXPIRED => 'Hết hạn',
                                    ...($get('status') === Contract::STATUS_CANCELLED ? [Contract::STATUS_CANCELLED => 'Đã huỷ'] : []),
                                ])
                                ->default(Contract::STATUS_ACTIVE)
                                ->live()
                                ->required(),
                            // Chọn sẵn ở đây để ResidenceDeclarationService tự điền vào "Khai báo lưu
                            // trú" sinh ra từ hợp đồng này (trước đây luôn để trống, bắt nhân viên
                            // phải vào từng khai báo bổ sung tay mới bấm "Đánh dấu đã khai báo" được
                            // — xem ResidenceDeclaration::REQUIRED_FIELD_LABELS). Dùng ĐÚNG danh mục
                            // chuẩn Bộ Công an, cùng nguồn với ResidenceDeclarationForm.
                            Select::make('reason_for_stay')
                                ->label('Lý do lưu trú')
                                ->options(ResidenceDeclaration::REASON_FOR_STAY_OPTIONS)
                                ->native(false)
                                ->searchable()
                                ->live()
                                ->helperText('Tự điền vào Khai báo lưu trú của khách đứng tên + người ở cùng khi tạo/sửa hợp đồng.'),
                            TextInput::make('custom_reason')
                                ->label('Nhập lý do (nếu chọn "Mục đích khác")')
                                ->maxLength(255)
                                ->visible(fn (Get $get) => $get('reason_for_stay') === '20 - Mục đích khác')
                                ->required(fn (Get $get) => $get('reason_for_stay') === '20 - Mục đích khác'),
                        ]),

                    // Ghi rõ ngay trong hợp đồng các khoản phí phát sinh hàng tháng ngoài giá phòng
                    // (trước đây chỉ thấy được khi lập hoá đơn đầu tiên) — đơn giá điện/nước tự điền
                    // theo Toà nhà lúc chọn Phòng ở tab trên, sửa được nếu thương lượng riêng. Danh
                    // sách phụ thu chọn ở đây sẽ tự điền vào MỌI hoá đơn tạo mới sau này của hợp đồng
                    // (xem InvoiceForm), không cần chọn lại mỗi tháng.
                    Tab::make('Các khoản phí hàng tháng')
                        ->columns(2)
                        ->schema([
                            TextInput::make('electric_unit_price')
                                ->label('Đơn giá điện')
                                ->numeric()
                                ->prefix('đ')
                                ->suffix('/ số')
                                ->default(fn () => Room::find(request()->query('room_id'))?->building?->electric_unit_price),
                            TextInput::make('water_unit_price')
                                ->label('Đơn giá nước')
                                ->numeric()
                                ->prefix('đ')
                                ->suffix('/ số')
                                ->default(fn () => Room::find(request()->query('room_id'))?->building?->water_unit_price),
                            CheckboxList::make('surcharges')
                                ->label('Phụ thu định kỳ áp dụng')
                                ->relationship(
                                    name: 'surcharges',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: function (Builder $query, Get $get) {
                                        $buildingId = Room::find($get('room_id'))?->building_id;

                                        return $query->where('is_active', true)
                                            ->when($buildingId, fn (Builder $query) => $query->where('building_id', $buildingId), fn (Builder $query) => $query->whereRaw('1 = 0'));
                                    },
                                )
                                ->helperText('Chỉ hiện phụ thu của đúng Toà nhà đang thuê — chọn Phòng ở tab "Thông tin hợp đồng" trước.')
                                ->columnSpanFull(),
                        ]),

                    // Người ở cùng giờ LÀ Khách thuê thật (đã gộp vào chung sổ Khách thuê — kể cả
                    // trẻ em, để tính đúng số người ở + đủ hồ sơ khai báo tạm trú) — ở đây chỉ CHỌN
                    // hoặc TẠO MỚI 1 Khách thuê (form đầy đủ, có quét CCCD) rồi gắn vào hợp đồng này
                    // với vai trò "ở cùng", không nhập tay lại hồ sơ riêng như trước.
                    Tab::make('Người ở cùng')
                        ->schema([
                            Repeater::make('occupantEntries')
                                ->relationship('occupantEntries')
                                ->label('')
                                // BẮT BUỘC set default([]) — không có dòng này, Repeater tự seed sẵn
                                // 1 dòng RỖNG ngay khi mở trang Tạo hợp đồng mới (dù không ai bấm
                                // "Thêm người ở cùng"), rồi validate 'tenant_id' ->required() của
                                // đúng dòng rỗng đó khi submit, khiến "Người ở cùng" biến thành BẮT
                                // BUỘC ngoài ý muốn cho MỌI hợp đồng — kể cả hợp đồng chỉ có 1 người ở
                                // (không có ai ở cùng).
                                ->default([])
                                ->addActionLabel('Thêm người ở cùng')
                                ->itemLabel(fn (array $state): ?string => ($tenant = Tenant::find($state['tenant_id'] ?? null)) ? self::tenantOptionLabel($tenant) : 'Người ở cùng mới')
                                ->collapsible()
                                ->columns(2)
                                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => [...$data, 'role' => ContractTenant::ROLE_OCCUPANT])
                                ->schema([
                                    Select::make('tenant_id')
                                        ->label('Khách thuê ở cùng')
                                        ->relationship(
                                            name: 'tenant',
                                            titleAttribute: 'fullname',
                                            // Loại người đứng tên chính (tenant_id ở tab "Thông tin
                                            // hợp đồng") và loại khách đã chọn ở CÁC dòng "ở cùng"
                                            // khác trong cùng hợp đồng — tránh chọn trùng 1 người 2
                                            // vai trò hoặc 2 dòng ở cùng. '../../tenant_id' = field
                                            // gốc (2 cấp lên khỏi item Repeater), '../' (không kèm
                                            // tên field) = lấy nguyên mảng occupantEntries (1 cấp).
                                            // Ngoài ra loại luôn khách đang thuê phòng khác (đứng tên
                                            // hoặc ở cùng 1 hợp đồng "Đang hiệu lực" khác) — xem
                                            // excludeTenantsRentingElsewhere(). LƯU Ý: không đặt tên
                                            // tham số là "$record" ở đây — bên trong item của Repeater
                                            // có ->relationship(), Filament tự bơm $record theo TÊN
                                            // biến trước tiên và trả về đúng bản ghi của item hiện tại
                                            // (ContractTenant), không phải Contract cha — ép kiểu
                                            // ?Contract sẽ vỡ ngay (TypeError → 500). Lấy Contract cha
                                            // qua $livewire (luôn là trang EditContract/CreateContract,
                                            // không bị ảnh hưởng bởi nesting) thay vì dựa "$record" tự
                                            // động.
                                            modifyQueryUsing: function (Builder $query, Get $get, $livewire) {
                                                // Loại giá trị của CHÍNH dòng này ra khỏi danh sách
                                                // loại trừ — nếu không, dòng đang chọn sẵn 1 khách sẽ
                                                // tự loại luôn khách đó khỏi option của chính nó, khiến
                                                // Filament không tìm được nhãn hiển thị và hiện ID thô
                                                // (VD "24") thay vì tên khách.
                                                $ownTenantId = $get('tenant_id');

                                                $excludedIds = collect($get('../') ?? [])
                                                    ->pluck('tenant_id')
                                                    ->push($get('../../tenant_id'))
                                                    ->filter()
                                                    ->unique()
                                                    ->reject(fn ($id) => $id == $ownTenantId);

                                                $contract = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

                                                return self::excludeTenantsRentingElsewhere($query, $contract instanceof Contract ? $contract : null)
                                                    ->whereNotIn('id', $excludedIds);
                                            },
                                        )
                                        ->getOptionLabelFromRecordUsing(fn (Tenant $record) => self::tenantOptionLabel($record))
                                        ->searchable(['fullname', 'id_card_number'])
                                        ->preload()
                                        ->required()
                                        ->suffixAction(self::viewTenantAction())
                                        ->createOptionForm(self::miniTenantForm())
                                        ->createOptionUsing(fn (array $data) => Tenant::create($data)->getKey()),
                                    TextInput::make('relationship_to_primary')
                                        ->label('Quan hệ với người đứng tên')
                                        ->placeholder('Vợ/chồng, con, bạn ở ghép...')
                                        ->maxLength(255),
                                ]),
                        ]),

                    Tab::make('Nội dung hợp đồng')
                        ->schema([
                            RichEditor::make('contract_content')
                                ->label('Nội dung tuỳ chỉnh')
                                ->columnSpanFull(),
                        ]),

                    Tab::make('Lưu trữ giấy tờ')
                        ->columns(3)
                        ->schema([
                            FileUpload::make('contract_file')
                                ->label('Hợp đồng (file)')
                                ->directory('minihouse/contracts')
                                ->disk('public')
                                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png']),
                            FileUpload::make('handover_file')
                                ->label('Biên bản bàn giao (lúc nhận)')
                                ->directory('minihouse/contracts')
                                ->disk('public')
                                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png']),
                            FileUpload::make('deposit_receipt_file')
                                ->label('Biên bản đặt cọc')
                                ->directory('minihouse/contracts')
                                ->disk('public')
                                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png']),
                        ]),

                    Tab::make('Thanh lý hợp đồng')
                        ->columns(2)
                        ->schema([
                            DatePicker::make('checkout_at')
                                ->label('Ngày trả phòng thực tế')
                                ->live()
                                // Điền ngày trả phòng KHÔNG tự trả phòng/đổi trạng thái hợp đồng —
                                // ContractObserver chỉ đồng bộ Room.status/Tenant.room_id khi
                                // status/room_id/tenant_id đổi, không theo dõi checkout_at. Nhân
                                // viên hay quên qua tab "Thông tin hợp đồng" đổi status tay, khiến
                                // phòng vẫn hiện "Đã thuê" dù khách đã trả — tự chuyển giúp luôn khi
                                // đang ở trạng thái "Đang hiệu lực".
                                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                    if (filled($state) && $get('status') === Contract::STATUS_ACTIVE) {
                                        $set('status', Contract::STATUS_EXPIRED);
                                    }
                                })
                                ->helperText('Điền ngày này sẽ tự chuyển trạng thái hợp đồng sang "Hết hạn" (nếu đang "Đang hiệu lực") để trả phòng lại — xem tab "Thông tin hợp đồng".'),
                            TextInput::make('deposit_refunded_amount')
                                ->label('Số tiền cọc đã hoàn')
                                ->numeric()
                                ->prefix('đ'),
                            Textarea::make('deposit_deduction_reason')
                                ->label('Lý do trừ cọc (nếu có)')
                                ->columnSpanFull(),
                            FileUpload::make('checkout_handover_file')
                                ->label('Biên bản bàn giao lúc trả phòng')
                                ->directory('minihouse/contracts')
                                ->disk('public')
                                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    // "Nguyễn Văn A - 001234567890" — kèm số CCCD để phân biệt khách trùng tên khi chọn. Khách
    // chưa có số CCCD (mới tạo, chưa quét/nhập) thì chỉ hiện tên, không để trống dấu "-".
    private static function tenantOptionLabel(Tenant $tenant): string
    {
        return $tenant->id_card_number
            ? "{$tenant->fullname} - {$tenant->id_card_number}"
            : $tenant->fullname;
    }

    // Nút "Xem khách" cạnh ô chọn — mở trang chi tiết đúng khách đang chọn ở TAB MỚI. Dùng ->url()
    // thuần (KHÔNG ->action() closure) — render thẳng ra thẻ <a href>, không gọi
    // mountFormComponentAction qua Livewire, nên không dính lỗi 419 đã gặp trước đây với Actions
    // gắn trong field.
    private static function viewTenantAction(): Action
    {
        return Action::make('viewTenant')
            ->label('Xem khách')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->url(fn (Get $get) => filled($get('tenant_id')) ? TenantResource::getUrl('edit', ['record' => $get('tenant_id')]) : null)
            ->openUrlInNewTab()
            ->visible(fn (Get $get) => filled($get('tenant_id')));
    }

    // Loại khỏi danh sách chọn mọi khách đang đứng tên HOẶC ở cùng một hợp đồng "Đang hiệu lực"
    // KHÁC — 1 người chỉ thực sự ở 1 chỗ tại 1 thời điểm. $record: hợp đồng đang sửa (null khi
    // tạo mới) — loại trừ chính hợp đồng đang sửa để không tự chặn khách/người ở cùng hiện có của
    // nó (nếu không, mở lại hợp đồng cũ để sửa sẽ mất luôn lựa chọn đang có).
    private static function excludeTenantsRentingElsewhere(Builder $query, ?Contract $record): Builder
    {
        return $query->whereDoesntHave('contracts', function (Builder $query) use ($record) {
            $query->where('minihouse_contracts.status', Contract::STATUS_ACTIVE)
                ->when($record, fn (Builder $query) => $query->where('minihouse_contracts.id', '!=', $record->getKey()));
        });
    }

    // Form rút gọn để tạo nhanh 1 Khách thuê mới ngay tại chỗ (Select::createOptionForm) — KHÔNG
    // quét live ngay khi tải ảnh (quét CCCD là thao tác nặng, gắn vào sự kiện live dễ treo request
    // Livewire → phiên hết hạn, xem giải thích ở TenantForm). Cứ tải ảnh rồi bấm "Tạo" bình thường
    // — TenantObserver sẽ tự quét NGAY SAU KHI LƯU (đây vốn là luồng tạo mới), đủ dùng cho form nhỏ
    // này; muốn quét lại/bổ sung thêm thông tin thì mở đúng khách vừa tạo trong trang Khách thuê.
    private static function miniTenantForm(): array
    {
        return [
            TextInput::make('fullname')->label('Họ tên')->required()->maxLength(255),
            TextInput::make('phone')->label('Số điện thoại')->tel()->maxLength(20),
            TextInput::make('id_card_number')->label('Số CCCD/CMND')->maxLength(20),
            FileUpload::make('id_card_front')
                ->label('CCCD mặt trước')
                ->image()
                ->directory('minihouse/tenants')
                ->disk('public'),
            FileUpload::make('id_card_back')
                ->label('CCCD mặt sau')
                ->image()
                ->directory('minihouse/tenants')
                ->disk('public'),
        ];
    }
}
