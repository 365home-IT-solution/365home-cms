<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CccdDeclarationResource\Pages;
use App\Models\CccdDeclaration;
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
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Filament\Forms\Components\DatePicker;

class CccdDeclarationResource extends Resource
{
    protected static ?string $model = CccdDeclaration::class;

    protected static ?string $navigationIcon   = 'heroicon-o-identification';
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationLabel  = 'Khai báo lưu trú';
    protected static ?string $modelLabel       = 'Khai báo lưu trú';
    protected static ?string $pluralModelLabel = 'Khai báo lưu trú';
    protected static ?int    $navigationSort   = 30;

    // Trước đây hardcode chỉ super_admin mới xem được — Filament Shield vẫn tự sinh + hiện đầy đủ
    // permission 'view_any_cccd::declaration' (và create/update/delete...) trên trang Roles &
    // Permissions, khiến admin tick "bật hết Lưu trú" cho 1 role nhưng KHÔNG có tác dụng gì, vì
    // canViewAny() ở đây chưa từng đọc permission đó. Đổi lại để check ĐÚNG permission mà Shield
    // đã sinh — hành vi mặc định của Filament khi KHÔNG override canViewAny() (nên thực chất viết
    // rõ ra ở đây chỉ để tài liệu hoá ý định, super_admin vẫn luôn bypass qua Gate::before ở
    // AuthServiceProvider như mọi permission khác).
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_cccd::declaration') ?? false;
    }

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
                        ->options(CccdDeclaration::DOCUMENT_TYPE_OPTIONS)
                        ->native(false)
                        ->searchable(),

                    TextInput::make('cccd_number')
                        ->label('Số giấy tờ')
                        ->maxLength(20),

                    TextInput::make('phone_number')
                        ->label('Số điện thoại')
                        ->tel()
                        ->maxLength(20),

                    // Luật Cư trú yêu cầu "ngày, tháng, năm sinh" là 1 mục riêng trong nội dung
                    // thông báo — trước đây chỉ gộp chung vào chuỗi 'info', không tách được để bắt
                    // buộc kiểm tra đủ/thiếu, nên tách thành field riêng.
                    TextInput::make('date_of_birth')
                        ->label('Ngày sinh')
                        ->placeholder('DD/MM/YYYY')
                        ->maxLength(20),

                    Select::make('gender')
                        ->label('Giới tính')
                        ->options(CccdDeclaration::GENDER_OPTIONS)
                        ->native(false),

                    Select::make('nationality')
                        ->label('Quốc tịch')
                        ->options(CccdDeclaration::NATIONALITY_OPTIONS)
                        ->default(CccdDeclaration::NATIONALITY_DEFAULT)
                        ->native(false)
                        ->searchable(),

                    TextInput::make('info')
                        ->label('Thông tin khác (từ OCR CCCD)')
                        ->placeholder('Nam / Cộng hòa xã hội chủ nghĩa Việt Nam')
                        ->maxLength(255),
                ]),
            ]),

            Section::make('Thông tin lưu trú')->schema([
                Grid::make(2)->schema([
                    TextInput::make('room_number')
                        ->label('Số phòng / Số căn hộ')
                        ->maxLength(255),

                    // "Địa chỉ lưu trú" theo Luật Cư trú = địa chỉ CHI NHÁNH/cơ sở khách đang ở —
                    // KHÁC với "Nơi thường trú" của khách ở section dưới. Tự lấy từ chi nhánh của
                    // đơn hàng (Category->name), vẫn cho sửa tay nếu cần.
                    TextInput::make('stay_address')
                        ->label('Địa chỉ lưu trú (chi nhánh)')
                        ->helperText('Địa chỉ nơi khách đang ở — tự lấy từ chi nhánh của đơn hàng.')
                        ->maxLength(255),

                    Select::make('reason_for_stay')
                        ->label('Lý do lưu trú')
                        ->options(CccdDeclaration::REASON_FOR_STAY_OPTIONS)
                        ->native(false)
                        ->searchable()
                        ->live(),

                    // Theo đúng mẫu: chỉ bắt buộc nhập khi lý do = "20 - Mục đích khác".
                    TextInput::make('custom_reason')
                        ->label('Nhập lý do (nếu chọn "Mục đích khác")')
                        ->maxLength(255)
                        ->visible(fn (Get $get) => $get('reason_for_stay') === '20 - Mục đích khác')
                        ->required(fn (Get $get) => $get('reason_for_stay') === '20 - Mục đích khác'),

                    DateTimePicker::make('checked_in_at')
                        ->label('Ngày đến cơ sở lưu trú')
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
                ->description('"Nơi thường trú" (theo CCCD) và "Nơi ở hiện tại" là 2 khái niệm KHÁC NHAU theo mẫu khai báo cư trú của Bộ Công an — nhiều người CCCD ghi quê nhưng thực tế đang sống/làm việc ở nơi khác. Trường dưới đây lấy TỰ ĐỘNG từ CCCD (nơi thường trú), hãy hỏi và sửa lại nếu khách cho biết nơi ở hiện tại khác.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('current_residence')
                            ->label('Nơi thường trú (theo CCCD)')
                            ->helperText('Tự lấy từ CCCD — CÓ THỂ khác nơi khách thực tế đang sinh sống. Hỏi khách và sửa lại nếu cần "nơi ở hiện tại" chính xác hơn.')
                            ->maxLength(255),

                        Select::make('residence_type')
                            ->label('Nơi cư trú hiện nay')
                            ->helperText('Phân loại của "Nơi thường trú" ở trên — không phải nội dung địa chỉ.')
                            ->options(CccdDeclaration::RESIDENCE_TYPE_OPTIONS)
                            ->native(false),

                        // Lưu thẳng chuỗi "Mã - Tên" khớp đúng dropdown TINH_THANH/PHUONG_XA của
                        // mẫu — tự gợi ý từ địa chỉ CCCD (xem TbltAddressResolver), nhân viên vẫn
                        // chọn lại tay qua đây nếu hệ thống khớp sai/không khớp được.
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
                            // Tự gán người khai báo = admin đang thao tác ngay khi họ tự điền giờ
                            // đã khai báo tay (không tự đổi nếu họ XÓA để "chưa khai báo" lại).
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

    public static function table(Table $table): Table
    {
        return $table
            // KHÔNG lọc mặc định theo "ngày đặt đơn" nữa — đơn có thể đặt trước nhiều ngày, phòng
            // để tab (xem ListCccdDeclarations::getTabs()) tự quyết định phạm vi hiển thị theo
            // đúng HẠN KHAI BÁO (tính từ checked_in_at), là mốc thời gian có ý nghĩa thật sự ở đây.
            // Chỉ hiện khách của đơn ĐÃ XÁC NHẬN (paid/deposit/shipped) — bản ghi lưu trú được tạo
            // ngay từ lúc đặt phòng (kể cả pending) để không mất dữ liệu CCCD, nhưng đơn pending có
            // thể tự huỷ sau 15 phút nếu khách không thanh toán nên KHÔNG được tính là khách thật.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('order')
                ->whereHas('order', fn (Builder $q) => $q->whereIn('status', CccdDeclaration::CONFIRMED_ORDER_STATUSES)))
            ->columns([
                TextColumn::make('order.order_code')
                    ->label('Mã đơn')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Bold),

                // Đơn qua đêm 2 khách có 2 dòng khai báo cùng chung 1 "Mã đơn" — cần cột này để
                // nhân viên phân biệt đây là khách nào, tránh tưởng nhầm là dữ liệu trùng lặp.
                TextColumn::make('guest_index')
                    ->label('Khách')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state >= 2 ? "Khách {$state}" : 'Khách chính')
                    ->color(fn (int $state): string => $state >= 2 ? 'warning' : 'gray'),

                TextColumn::make('full_name')
                    ->label('Họ và tên')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cccd_number')
                    ->label('Số CCCD')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('date_of_birth')
                    ->label('Ngày sinh')
                    ->toggleable(),

                TextColumn::make('info')
                    ->label('Giới tính / Quốc tịch')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('room_number')
                    ->label('Số phòng')
                    ->sortable(),

                TextColumn::make('stay_address')
                    ->label('Địa chỉ lưu trú')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->stay_address)
                    ->toggleable(),

                TextColumn::make('checked_in_at')
                    ->label('Ngày đến')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('checked_out_at')
                    ->label('Ngày đi dự kiến')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                // Hiển thị TRỰC TIẾP ra cột (không chỉ nằm trong tooltip) để nhân viên biết ngay
                // hạn chót là ngày/giờ nào mà không cần rê chuột — đặc biệt hữu ích ở tab "Sắp đến
                // hạn" (xem ListCccdDeclarations::getTabs()).
                TextColumn::make('declaration_deadline')
                    ->label('Hạn khai báo')
                    ->state(fn (CccdDeclaration $record) => $record->declarationDeadline()?->format('H:i d/m/Y'))
                    ->sortable(false)
                    ->color(fn (CccdDeclaration $record) => $record->isOverdue() ? 'danger' : ($record->isDueSoon() ? 'warning' : null)),

                // Trạng thái tự đánh dấu nội bộ — KHÔNG phải xác nhận từ ASM/công an, chỉ để nhân
                // viên tự theo dõi đã nộp khai báo thủ công hay chưa, tránh quên bị phạt.
                TextColumn::make('declared_at')
                    ->label('Trạng thái khai báo')
                    ->badge()
                    ->state(function (CccdDeclaration $record): string {
                        if ($record->isDeclared()) {
                            return 'Đã khai báo';
                        }

                        // Chưa đủ dữ liệu bắt buộc thì báo ngay "Thiếu thông tin" — ưu tiên hiển
                        // thị trước cả hạn/quá hạn, vì đây là lý do TẠI SAO chưa đánh dấu được.
                        if (! $record->isDataComplete()) {
                            return 'Thiếu thông tin';
                        }

                        if ($record->isOverdue()) {
                            return 'Quá hạn — CHƯA khai báo';
                        }

                        if ($record->isDueSoon()) {
                            return 'Sắp tới hạn';
                        }

                        return 'Chưa khai báo';
                    })
                    ->color(function (CccdDeclaration $record): string {
                        if ($record->isDeclared()) {
                            return 'success';
                        }

                        if (! $record->isDataComplete()) {
                            return 'danger';
                        }

                        if ($record->isOverdue()) {
                            return 'danger';
                        }

                        if ($record->isDueSoon()) {
                            return 'warning';
                        }

                        return 'gray';
                    })
                    ->tooltip(function (CccdDeclaration $record): ?string {
                        if (! $record->isDataComplete()) {
                            return 'Còn thiếu: ' . implode(', ', $record->missingRequiredFieldLabels());
                        }

                        return $record->declarationDeadline()
                            ? 'Hạn: ' . $record->declarationDeadline()->format('H:i d/m/Y')
                            : null;
                    }),

                TextColumn::make('gender')
                    ->label('Giới tính')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('nationality')
                    ->label('Quốc tịch')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reason_for_stay')
                    ->label('Lý do lưu trú')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('current_residence')
                    ->label('Nơi thường trú (CCCD)')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('province')
                    ->label('Tỉnh/Thành phố')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ward')
                    ->label('Phường/Xã')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('address_detail')
                    ->label('Địa chỉ chi tiết')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->address_detail)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Lọc theo NGÀY ĐẾN (checked_in_at) — trước đây lọc theo ngày ĐẶT ĐƠN
                // (order.created_at) và mặc định sẵn "hôm nay", nên khi qua nửa đêm, nó âm thầm
                // loại mất các khách đã đủ điều kiện hiện ở tab "Cần khai báo hôm nay"/"Sắp đến
                // hạn" (đơn đặt hôm QUA nhưng khách đến/hạn khai báo lại rơi vào hôm nay). Bỏ giá
                // trị mặc định — chỉ áp dụng khi nhân viên chủ động chọn ngày để tra cứu lịch sử,
                // không xung đột với các tab đã tự lọc đúng theo hạn khai báo.
                Filter::make('date')
                    ->form([
                        DatePicker::make('from')
                            ->label('Từ ngày (ngày đến)')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('until')
                            ->label('Đến ngày (ngày đến)')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('checked_in_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('checked_in_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'Từ ' . \Carbon\Carbon::parse($data['from'])->format('d/m/Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Đến ' . \Carbon\Carbon::parse($data['until'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),

                TernaryFilter::make('declared_at')
                    ->label('Trạng thái khai báo')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã khai báo')
                    ->falseLabel('Chưa khai báo')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('declared_at'),
                        false: fn (Builder $query) => $query->whereNull('declared_at'),
                    ),
            ])
            ->actions([
                // Đánh dấu nhanh ngay tại bảng — khỏi phải mở sửa từng dòng khi nhân viên vừa nộp
                // xong khai báo thủ công qua ASM/dịch vụ công.
                Action::make('markDeclared')
                    ->label('Đánh dấu đã khai báo')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CccdDeclaration $record) => ! $record->isDeclared())
                    // Chỉ hiện popup xác nhận khi ĐÃ đủ dữ liệu bắt buộc — thiếu thông tin thì báo
                    // lỗi ngay (xem action() bên dưới), không cho xác nhận "đã khai báo" khi chưa
                    // đủ họ tên/CCCD/nơi cư trú... để tránh nhân viên lỡ tay xác nhận nộp thiếu.
                    ->requiresConfirmation(fn (CccdDeclaration $record) => $record->isDataComplete())
                    ->modalDescription('Xác nhận bạn ĐÃ nộp khai báo lưu trú cho cơ quan công an (qua ASM/dịch vụ công) cho lượt khách này?')
                    ->action(function (CccdDeclaration $record) {
                        if (! $record->isDataComplete()) {
                            Notification::make()
                                ->title('Chưa thể đánh dấu đã khai báo')
                                ->body('Còn thiếu: ' . implode(', ', $record->missingRequiredFieldLabels()) . '. Vui lòng bổ sung đầy đủ (bấm "Sửa") trước khi đánh dấu.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update([
                            'declared_at' => now(),
                            'declared_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Đã đánh dấu khai báo thành công')
                            ->success()
                            ->send();
                    }),

                // Chiều ngược lại — lỡ tay đánh dấu nhầm, hoặc khai báo bị cơ quan công an từ chối
                // và cần nộp lại. Bỏ dấu xong, bản ghi tự quay về đúng tab "Cần khai báo hôm nay"
                // hoặc "Sắp đến hạn" tuỳ hạn khai báo hiện tại (không cần xử lý gì thêm — 2 tab đó
                // vốn đã tự tính lại từ declared_at + declarationDeadline() mỗi lần tải trang).
                Action::make('unmarkDeclared')
                    ->label('Bỏ đánh dấu đã khai báo')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (CccdDeclaration $record) => $record->isDeclared())
                    ->requiresConfirmation()
                    ->modalDescription('Xác nhận BỎ đánh dấu "đã khai báo" cho lượt khách này? Bản ghi sẽ quay lại đúng tab "Cần khai báo hôm nay"/"Sắp đến hạn" theo hạn khai báo hiện tại.')
                    ->action(function (CccdDeclaration $record) {
                        $record->update([
                            'declared_at' => null,
                            'declared_by' => null,
                        ]);

                        Notification::make()
                            ->title('Đã bỏ đánh dấu khai báo')
                            ->success()
                            ->send();
                    }),

                EditAction::make()->label('Sửa'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // Chọn nhiều dòng (tick checkbox đầu bảng) rồi đánh dấu "đã khai báo" hàng loạt
                    // — dùng khi nhân viên vừa nộp xong 1 lượt nhiều khách qua ASM/dịch vụ công,
                    // khỏi phải bấm từng dòng. Dòng nào CHƯA đủ dữ liệu bắt buộc sẽ bị BỎ QUA (giữ
                    // đúng nguyên tắc như đánh dấu đơn lẻ), không chặn cả lượt vì vài dòng lỗi.
                    BulkAction::make('markDeclaredBulk')
                        ->label('Đánh dấu đã khai báo')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Xác nhận bạn ĐÃ nộp khai báo lưu trú cho cơ quan công an (qua ASM/dịch vụ công) cho TẤT CẢ lượt khách đã chọn? Dòng nào còn thiếu dữ liệu bắt buộc sẽ tự động bị bỏ qua.')
                        ->action(function (Collection $records) {
                            $declaredCount = 0;
                            $skipped       = [];

                            foreach ($records as $record) {
                                if ($record->isDeclared()) {
                                    continue;
                                }

                                if (! $record->isDataComplete()) {
                                    $skipped[] = "{$record->full_name} (thiếu: " . implode(', ', $record->missingRequiredFieldLabels()) . ')';

                                    continue;
                                }

                                $record->update([
                                    'declared_at' => now(),
                                    'declared_by' => auth()->id(),
                                ]);
                                $declaredCount++;
                            }

                            if ($declaredCount > 0) {
                                Notification::make()
                                    ->title("Đã đánh dấu khai báo thành công {$declaredCount} lượt khách")
                                    ->success()
                                    ->send();
                            }

                            if (! empty($skipped)) {
                                Notification::make()
                                    ->title('Bỏ qua ' . count($skipped) . ' lượt khách do thiếu dữ liệu bắt buộc')
                                    ->body(implode("\n", $skipped))
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Chiều ngược lại hàng loạt — bỏ dấu "đã khai báo" cho nhiều dòng đã chọn cùng
                    // lúc. Sau khi bỏ, các dòng tự quay lại đúng tab "Cần khai báo hôm nay"/"Sắp
                    // đến hạn" theo hạn khai báo hiện tại (2 tab đó tự tính lại mỗi lần tải trang).
                    BulkAction::make('unmarkDeclaredBulk')
                        ->label('Bỏ đánh dấu đã khai báo')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('Xác nhận BỎ đánh dấu "đã khai báo" cho tất cả lượt khách đã chọn?')
                        ->action(function (Collection $records) {
                            $count = 0;

                            foreach ($records as $record) {
                                if (! $record->isDeclared()) {
                                    continue;
                                }

                                $record->update([
                                    'declared_at' => null,
                                    'declared_by' => null,
                                ]);
                                $count++;
                            }

                            Notification::make()
                                ->title("Đã bỏ đánh dấu khai báo cho {$count} lượt khách")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('checked_in_at', 'asc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCccdDeclarations::route('/'),
            'edit'  => Pages\EditCccdDeclaration::route('/{record}/edit'),
        ];
    }
}
