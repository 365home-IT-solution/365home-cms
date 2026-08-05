<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Forms;

use App\Models\Customer;
use App\Models\CustomerCompanion;
use Dom\Text;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Modules\AuditLog\Entities\AuditLog;
use Modules\AuditLog\Services\AuditLogger;
use PayOS\PayOS;
use Modules\Product\App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Support\Colors\Color;
use Modules\Category\Entities\Category;
use Modules\Category\Entities\Categorizable;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Payment\Entities\OrderGuestCccd;

class OrderForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Trước đây tách 2 tab ("Thông tin đơn đặt phòng" / "Thông tin thanh toán") — theo
                // yêu cầu gộp lại thành 1 trang duy nhất, cuộn liên tục, không còn điều hướng tab.
                // Không đặt heading nữa (Section::make() rỗng) — chỉ dùng làm khung bọc vô hình
                // (order-form-root, xem order-form.css) để gom nhóm layout, các card thật bên trong
                // đã tự đủ rõ ràng.
                Section::make()
                            ->extraAttributes(['class' => 'order-form-root'])
                            ->schema([
                                // Mã cổng + Thông tin khách hàng đặt cạnh nhau — Mã cổng chỉ hiện khi
                                // đơn đã 'paid' và phòng không dùng khóa thủ công (xem ->visible() của
                                // chính component đó). Lịch sử thanh toán LUÔN hiện (không phụ thuộc mã
                                // cổng) nên nằm CHUNG cột với mã cổng (bọc trong 1 Grid::make(1) riêng),
                                // KHÔNG còn theo kiểu "ẩn mã cổng thì nhường hết chỗ cho Thông tin khách
                                // hàng" như trước — cột này giờ LUÔN có ít nhất Lịch sử thanh toán, nên
                                // "Thông tin khách hàng" cố định 4/5, không cần columnSpan động nữa.
                                //
                                // Grid 5 cột để chia tỉ lệ LỆCH 1/5 - 4/5 (20%/80%): nội dung mã cổng +
                                // lịch sử thanh toán khá gọn, trong khi Thông tin khách hàng có nhiều
                                // field hơn nên cần rộng hơn. Khai báo tay breakpoint 'sm' (>=640px) để
                                // lên nhiều cột sớm hơn mặc định 'lg' (>=1024px) của Filament.
                                //
                                // QUAN TRỌNG: Section::setUp() TỰ ĐỘNG gọi ->columnSpan('full') (xem
                                // vendor/filament/forms/src/Components/Section.php:61) — MỌI Section
                                // mặc định LUÔN chiếm trọn 100% Grid cha bất kể Grid mấy cột, nên PHẢI
                                // tự ->columnSpan() đè lại ở Section "Thông tin khách hàng" dưới đây,
                                // nếu không Grid dù đã lên nhiều cột vẫn hiện xếp dọc (Section tự chiếm
                                // cả hàng riêng).
                                Grid::make(['default' => 1, 'sm' => 5])
                                    ->schema([
                                        Grid::make(1)
                                            ->columnSpan(1)
                                            ->schema([
                                                // Không bọc Section riêng nữa (bỏ khung/tiêu đề "Mã cổng") — nội dung
                                                // access-code-info.blade.php đã tự có khung gradient riêng, bọc thêm
                                                // Section chỉ tạo ra khung lồng khung không cần thiết.
                                                Placeholder::make('access_code_info')
                                                    ->label('')
                                                    ->visible(fn($record) => self::hasAccessCodeSection($record))
                                                    ->content(function ($record) {
                                                        if (!$record) {
                                                            return 'Chưa có mã cổng';
                                                        }

                                                        $product = $record->items->sortBy('checkin_date')->first()?->product;

                                                        // Phòng khóa THỦ CÔNG — chi nhánh không có TTLock nên KHÔNG tra
                                                        // accessCodes (bảng đó luôn rỗng cho case này), thay thế bằng
                                                        // ManualLockPassword::getForProductAndDate(). Mốc $referenceDate
                                                        // PHẢI là thời điểm đơn được CHỐT (paid_at/deposit_paid_at/
                                                        // created_at) — CÙNG mốc dùng ở assignAccessCode action
                                                        // (EditOrder.php) — KHÔNG dùng checkin_date/now(), nếu không mật
                                                        // khẩu hiển thị sẽ đổi qua ngày khác mỗi lần admin mở lại đơn
                                                        // (xem docblock ManualLockPassword::getForProductAndDate()).
                                                        if ($product && $product->has_manual_lock) {
                                                            $pwdAnchorDate = $record->paid_at ?? $record->deposit_paid_at ?? $record->created_at;
                                                            $manualPwd = \Modules\Product\App\Models\ManualLockPassword::getForProductAndDate($product, $pwdAnchorDate);

                                                            return view('payment::components.manual-lock-info', [
                                                                'manualPwd' => $manualPwd,
                                                                'order' => $record,
                                                            ]);
                                                        }

                                                        $record->load('accessCodes');

                                                        $code = $record->accessCodes->first();
                                                        if (!$code) {
                                                            return new \Illuminate\Support\HtmlString(
                                                                '<div class="flex items-center gap-2 text-warning-600 font-medium">'
                                                                . '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/></svg>'
                                                                . 'Chưa có mã cổng — Nhấn nút <strong>Gán mã cổng</strong> ở đầu trang để gán.'
                                                                . '</div>'
                                                            );
                                                        }

                                                        return view('payment::components.access-code-info', [
                                                            'code' => $code,
                                                            'order' => $record,
                                                        ]);
                                                    }),

                                                self::buildPaymentTimelineSection(),
                                            ]),

                                        // Không đặt heading nữa (Section::make() rỗng) — bỏ luôn tiêu đề
                                        // "Thông tin khách hàng" theo yêu cầu. Bỏ luôn ->collapsible() vì không
                                        // còn tiêu đề để bấm thu gọn (chỉ còn 1 chevron trơ trọi thì kỳ).
                                        Section::make()
                                            ->columnSpan(4)
                                            // Bỏ khung/nền/viền theo yêu cầu — chỉ còn nội dung, không còn là
                                            // "card" riêng nữa (xem .khach-hang-no-card trong order-form.css).
                                            ->extraAttributes(['class' => 'khach-hang-no-card'])
                                            ->columns(2)
                                            ->schema([
                                                // Cột 1: thông tin liên hệ + ghi chú.
                                                Grid::make(1)
                                                    ->columnSpan(1)
                                                    ->schema([
                                                        Grid::make(2)
                                                            ->schema([
                                                                Select::make('customer_id')
                                                                    ->label('Thành viên')
                                                                    ->placeholder('Tìm theo tên hoặc số điện thoại')
                                                                    // Chỉ super_admin thấy được — toàn bộ luồng "chọn thành viên" (CCCD
                                                                    // thành viên, người đi cùng từ customer_companions...) CHƯA hoàn
                                                                    // thiện hết, ẩn khỏi các role khác cho tới khi xong hẳn. ->dehydrated()
                                                                    // BẮT BUỘC dù đang ẩn — nếu không, admin thường (không phải
                                                                    // super_admin) mở sửa 1 đơn ĐÃ có customer_id (do super_admin gán từ
                                                                    // trước) rồi lưu lại sẽ vô tình xoá mất customer_id đó (field ẩn mặc
                                                                    // định không dehydrate, xem HasState::isDehydrated()).
                                                                    ->visible(fn () => (bool) auth()->user()?->isSuperAdmin())
                                                                    ->dehydrated()
                                                                    ->searchable()
                                                                    ->preload()
                                                                    ->options([])
                                                                    ->getSearchResultsUsing(function (string $search): array {
                                                                        $query = Customer::query()
                                                                            ->where(function ($q) use ($search) {
                                                                                $search = trim($search);
                                                                                if ($search === '') {
                                                                                    return;
                                                                                }
                                                                                $q->where('fullname', 'like', '%' . $search . '%')
                                                                                    ->orWhere('phone', 'like', '%' . $search . '%');
                                                                            })
                                                                            ->orderBy('fullname')
                                                                            ->limit(20);

                                                                        return $query->get()
                                                                            ->mapWithKeys(fn (Customer $customer) => [
                                                                                $customer->id => trim(($customer->fullname ?: '') . ($customer->phone ? ' — ' . $customer->phone : '')),
                                                                            ])
                                                                            ->toArray();
                                                                    })
                                                                    ->getOptionLabelUsing(function ($value) {
                                                                        if (! $value) {
                                                                            return null;
                                                                        }

                                                                        $customer = Customer::find($value);
                                                                        if (! $customer) {
                                                                            return null;
                                                                        }

                                                                        return trim(($customer->fullname ?: '') . ($customer->phone ? ' — ' . $customer->phone : ''));
                                                                    })
                                                                    ->live()
                                                                    ->afterStateUpdated(function ($state, Set $set) {
                                                                        if (! $state) {
                                                                            return;
                                                                        }

                                                                        $customer = Customer::find($state);
                                                                        if (! $customer) {
                                                                            return;
                                                                        }

                                                                        $set('buyer_name', $customer->fullname);
                                                                        $set('buyer_phone', $customer->phone);
                                                                    }),

                                                                TextInput::make('buyer_name')
                                                                    ->label('Tên khách hàng')
                                                                    ->placeholder('VD: Nguyễn Văn An')
                                                                    ->required()
                                                                    ->maxLength(255)
                                                                    ->live(onBlur: true)
                                                                    ->validationMessages([
                                                                        'required' => 'Vui lòng nhập họ tên khách hàng',
                                                                        'max' => 'Họ tên không được quá 255 ký tự'
                                                                    ]),
                                                            ]),

                                                        TextInput::make('buyer_phone')
                                                            ->label(__('payment::order.form.label.buyer_phone'))
                                                            ->placeholder('VD: 0912345678')
                                                            ->tel()
                                                            ->required()
                                                            ->regex('/^[0-9]{10,11}$/')
                                                            ->live(onBlur: true)
                                                            ->validationMessages([
                                                                'required' => 'Vui lòng nhập số điện thoại',
                                                                'regex' => 'Số điện thoại không đúng định dạng (10-11 số)'
                                                            ]),

                                                        Textarea::make('description')
                                                            ->label(__('payment::order.form.label.description'))
                                                            ->placeholder('VD: Yêu cầu phòng tầng cao, view đẹp...')
                                                            ->nullable()
                                                            ->default('')
                                                            ->rows(4)
                                                            ->maxLength(1000),

                                                        // Ẩn khỏi giao diện theo yêu cầu — vẫn ->dehydrated() để KHÔNG mất
                                                        // giá trị short_description đã lưu sẵn của các đơn cũ khi sửa/lưu lại
                                                        // (field bị ẩn mặc định không dehydrate, xem HasState::isDehydrated()).
                                                        TextInput::make('short_description')
                                                            ->label(__('payment::order.form.label.short_description'))
                                                            ->placeholder('VD: Yêu cầu phòng tầng cao')
                                                            ->nullable()
                                                            ->maxLength(255)
                                                            ->hidden()
                                                            ->dehydrated(),

                                                        // Không còn hiện ảnh CCCD trực tiếp dưới ghi chú nữa — bấm nút "Xem
                                                        // CCCD khách #N" bên dưới để xem (và với khách #1, upload/thay ảnh
                                                        // luôn trong popup, xem self::buildGuestOneCccdAction()). Dãy nút
                                                        // khách #1‑#8 chỉ dành cho khách VÃNG LAI (không customer_id) — đã
                                                        // chọn thành viên thì đổi hẳn sang 1 nút CCCD thành viên duy nhất
                                                        // (self::buildMemberCccdAction()) lấy CCCD/người đi cùng từ hồ sơ
                                                        // thành viên (customer_companions) thay vì nhập tay lại từ đầu.
                                                        \Filament\Forms\Components\Actions::make(array_merge(
                                                            [self::buildGuestOneCccdAction()],
                                                            array_map(
                                                                fn (int $n) => self::buildGuestViewOnlyCccdAction($n),
                                                                range(2, 8)
                                                            )
                                                        ))
                                                            ->visible(fn (Get $get) => ! $get('customer_id'))
                                                            ->columnSpanFull(),

                                                        // Chỉ super_admin thấy nút này — cùng lý do ẩn Select 'customer_id'
                                                        // ở trên (luồng CCCD thành viên chưa hoàn thiện hết). Vẫn cần check
                                                        // thêm customer_id vì field đó ->dehydrated() dù ẩn — đơn ĐÃ có
                                                        // customer_id do super_admin gán trước đó, admin thường mở lại thì
                                                        // KHÔNG được thấy nút này.
                                                        \Filament\Forms\Components\Actions::make([
                                                            self::buildMemberCccdAction(),
                                                        ])
                                                            ->visible(fn (Get $get) => (bool) auth()->user()?->isSuperAdmin() && (bool) $get('customer_id'))
                                                            ->columnSpanFull(),

                                                        // Phương thức/trạng thái thanh toán — chuyển xuống cuối cột 1
                                                        // (dưới nút "Xem CCCD"), nhường cột 2 cho Đối tác/Chi nhánh.
                                                        Grid::make(2)
                                                            ->schema([
                                                                Select::make('payment_method')
                                                                    ->label('Phương thức thanh toán')
                                                                    ->placeholder('-- Chọn phương thức --')
                                                                    ->options([
                                                                        'cod' => ('Tiền mặt'),
                                                                        'PayOS' => '' . __('payment::order.form.options.payment_method.PayOS'),
                                                                    ])
                                                                    ->required()
                                                                    ->live()
                                                                    ->dehydrateStateUsing(fn($state) => $state),

                                                                Select::make('status')
                                                                    ->label('Trạng thái thanh toán')
                                                                    ->placeholder('-- Chọn trạng thái --')
                                                                    ->options([
                                                                        'pending'           => '' . __('payment::order.form.options.status.pending'),
                                                                        'paid'              => '' . __('payment::order.form.options.status.paid'),
                                                                        'deposit'           => 'Đã đặt cọc',
                                                                        'failed'            => '' . __('payment::order.form.options.status.failed'),
                                                                        'cancelled_payment' => 'Hủy QR',
                                                                        'refunded'          => 'Hoàn tiền',
                                                                    ])
                                                                    ->default('pending')
                                                                    ->live()
                                                                    ->dehydrateStateUsing(fn($state) => $state)
                                                                    ->afterStateUpdated(function ($state, $record, $old) {
                                                                        if ($record) {
                                                                            $prevStatus = $record->status;
                                                                            $record->update(['status' => $state]);

                                                                            AuditLogger::log(
                                                                                'update', 'Order', $record,
                                                                                ['status' => $prevStatus],
                                                                                ['status' => $state],
                                                                                'Đơn #' . $record->order_code
                                                                            );

                                                                            if ($state === 'paid' && !$record->hasAccessCode()) {
                                                                                try {
                                                                                    $record->load('items');
                                                                                    $firstItem = $record->items->sortBy('checkin_date')->first();
                                                                                    $checkinDate  = $record->items->min('checkin_date');
                                                                                    $checkoutDate = $record->items->max('checkout_date');
                                                                                    $product      = $firstItem?->product;

                                                                                    /** @var \Modules\BladeThemeV1\Services\AccessCode\AccessCodeService $service */
                                                                                    $service = app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class);
                                                                                    $service->assignCodeToOrder(
                                                                                        $record->id,
                                                                                        $record->category_id,
                                                                                        $checkinDate,
                                                                                        $checkoutDate,
                                                                                        $product,
                                                                                    );

                                                                                    \Filament\Notifications\Notification::make()
                                                                                        ->title('Đã gán mã cổng thành công')
                                                                                        ->success()
                                                                                        ->send();
                                                                                } catch (\Exception $e) {
                                                                                    \Filament\Notifications\Notification::make()
                                                                                        ->title('Không thể gán mã cổng')
                                                                                        ->body($e->getMessage())
                                                                                        ->warning()
                                                                                        ->send();
                                                                                }
                                                                            }
                                                                        }
                                                                    }),

                                                            ]),

                                                        // Nằm riêng 1 hàng dưới phương thức/trạng thái thanh toán, dạng
                                                        // toggle buttons (segmented control) thay vì dropdown — chọn nhanh
                                                        // hơn vì chỉ có 4 lựa chọn cố định.
                                                        //
                                                        // BẮT BUỘC phải ->colors() tay — không khai báo thì Filament coi màu
                                                        // là null (không phải mặc định 'primary' như hay tưởng), khiến nút
                                                        // CHƯA chọn rơi vào nhánh CSS "fi-color-custom" nhưng biến CSS màu
                                                        // (--button-600...) không được set (get_color_css_variables() trả
                                                        // về null khi color=null) — chữ gần như vô hình do thiếu tương phản.
                                                        // Đã xác nhận thực tế qua ảnh chụp màn hình.
                                                        ToggleButtons::make('order_status')
                                                            ->label('Trạng thái nhận/trả phòng')
                                                            ->options([
                                                                'pending'     => 'Chờ nhận phòng',
                                                                'checked_in'  => 'Đã nhận phòng',
                                                                'staying'     => 'Đang ở',
                                                                'checked_out' => 'Đã trả phòng',
                                                            ])
                                                            ->colors([
                                                                'pending'     => 'gray',
                                                                'checked_in'  => 'info',
                                                                'staying'     => 'warning',
                                                                'checked_out' => 'success',
                                                            ])
                                                            ->default('pending')
                                                            ->grouped()
                                                            ->dehydrateStateUsing(fn($state) => $state),

                                                        TextInput::make('money_deposit')
                                                            ->label('Số tiền đã đặt cọc')
                                                            ->placeholder('VD: 500000')
                                                            ->suffix('VNĐ')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->visible(fn (Get $get) => $get('status') === 'deposit')
                                                            ->live(onBlur: true),

                                                        // Ẩn khỏi giao diện theo yêu cầu — thông tin này giờ đã hiển thị đầy
                                                        // đủ hơn trong popup "Xem CCCD khách #N" (trích xuất trực tiếp từ
                                                        // ảnh CCCD, xem self::renderExtractedCccdData()), không cần gõ tay
                                                        // trùng lặp nữa. Vẫn ->dehydrated() để KHÔNG mất giá trị đã lưu sẵn
                                                        // của các đơn cũ.
                                                        Textarea::make('note_for_admin')
                                                            ->label('Thông tin người dùng CCCD')
                                                            ->placeholder('Thông tin người dùng CCCD')
                                                            ->helperText(__('payment::order.form.helper_text.note_for_admin'))
                                                            ->nullable()
                                                            ->rows(3)
                                                            ->maxLength(3000)
                                                            ->hidden()
                                                            ->dehydrated(),
                                                    ]),

                                                // Cột 2: Đối tác + Chi nhánh (chuyển từ đầu Section "Chi tiết đặt
                                                // phòng" cũ vào đây — logic lọc/reset khi đổi đối tác-chi nhánh giữ
                                                // nguyên 100%, xem 2 field bên dưới). "Chọn phòng"/"Số lượng khách"/
                                                // lịch đặt phòng vẫn nằm trong Repeater "orderItems" ở Section "Chi
                                                // tiết đặt phòng" như cũ — đó là dữ liệu LẶP LẠI theo từng phòng, tách
                                                // khỏi Repeater sẽ phá vỡ cơ chế nhiều phòng/nhiều khung giờ.
                                                // Badge/khung riêng cho cột 2 (Đối tác/Chi nhánh + Chọn phòng/Số
                                                // lượng khách + Dịch vụ) — tái dùng đúng token màu "boulder" đã có
                                                // (xem .cot2-badge trong order-form.css), không đổi tỉ lệ cột (vẫn
                                                // ->columnSpan(1) trên chính Section thay vì Grid như trước).
                                                Section::make()
                                                    ->columnSpan(1)
                                                    ->extraAttributes(['class' => 'cot2-badge'])
                                                    ->schema([
                                                        Grid::make(3)
                                                            ->schema([
                                                                // Chỉ super_admin/nhân viên đối tác NỀN TẢNG (365home) mới thấy field
                                                                // này — họ đặt phòng hộ mọi đối tác nên phải CHỌN ĐÚNG đối tác trước,
                                                                // rồi "Chi nhánh" bên dưới mới lọc theo đúng đối tác đó (thay vì liệt kê
                                                                // lẫn lộn chi nhánh của mọi đối tác cùng lúc). Không dehydrate vào Order
                                                                // — chỉ dùng để LỌC, partner_id thật sự lấy từ category_id đã chọn
                                                                // (xem CreateOrder::mutateFormDataBeforeCreate()).
                                                                Select::make('booking_partner_id')
                                                                    ->label('Đối tác')
                                                                    ->visible(fn () => self::isPlatformStaff())
                                                                    ->required(fn () => self::isPlatformStaff())
                                                                    ->dehydrated(false)
                                                                    // Gọn hơn "Chi nhánh" bên cạnh (Grid 3 cột, chỉ chiếm 1/3) theo yêu
                                                                    // cầu — tên đối tác thường ngắn hơn tên chi nhánh nên không cần
                                                                    // chiếm nhiều chỗ bằng.
                                                                    ->columnSpan(1)
                                                                    // Chỉ liệt kê đối tác ĐÃ ĐƯỢC XÁC NHẬN (verification_status =
                                                                    // 'approved') — đối tác đang chờ duyệt/bị từ chối chưa đủ điều
                                                                    // kiện vận hành thật, không nên đặt phòng hộ cho họ.
                                                                    ->options(fn () => \App\Models\Partner::query()
                                                                        ->where('verification_status', 'approved')
                                                                        ->orderBy('name')
                                                                        ->pluck('name', 'id'))
                                                                    ->searchable()
                                                                    ->preload()
                                                                    // Mở lại 1 đơn ĐÃ ĐẶT (sửa đơn): field này dehydrated(false) nên
                                                                    // luôn rỗng lúc mới mở — nếu không tự điền lại, "Chi nhánh" bên
                                                                    // dưới không lọc được gì (options() rỗng vì thiếu booking_partner_id),
                                                                    // khiến Select không tìm được nhãn cho category_id đã lưu và hiện
                                                                    // NHẦM thành số ID thô thay vì tên chi nhánh.
                                                                    ->afterStateHydrated(function ($component, $record) {
                                                                        if ($component->getState() || ! $record) {
                                                                            return;
                                                                        }

                                                                        if ($record->partner_id) {
                                                                            $component->state($record->partner_id);
                                                                        }
                                                                    })
                                                                    ->live()
                                                                    ->afterStateUpdated(function (Set $set) {
                                                                        $set('category_id', null);
                                                                    }),

                                                                Select::make('category_id')
                                                                    ->label('Chi nhánh')
                                                                    ->options(function (Get $get) {
                                                                        $user  = auth()->user();
                                                                        // whereNull('parent_id') — chi nhánh là category CẤP GỐC; nếu không
                                                                        // lọc thêm điều kiện này, các category CON (dùng để phân loại
                                                                        // phòng bên trong 1 chi nhánh, cũng category_type='product') sẽ
                                                                        // lẫn vào danh sách, hiện nhầm tên phòng thay vì tên chi nhánh.
                                                                        $query = Category::query()
                                                                            ->where('category_type', 'product')
                                                                            ->whereNull('parent_id')
                                                                            ->orderBy('name');

                                                                        if (self::isPlatformStaff()) {
                                                                            $partnerId = $get('booking_partner_id');

                                                                            // Chưa chọn đối tác — chưa hiện chi nhánh nào, tránh liệt kê lẫn
                                                                            // lộn chi nhánh của TẤT CẢ đối tác cùng lúc.
                                                                            if (! $partnerId) {
                                                                                return [];
                                                                            }

                                                                            $query->where('partner_id', $partnerId);
                                                                        } elseif ($user) {
                                                                            $allowedIds = $user->allowedCategoryIds();
                                                                            if (! empty($allowedIds)) {
                                                                                $query->whereIn('id', $allowedIds);
                                                                            } else {
                                                                                // Mặc định thấy toàn bộ chi nhánh của đối tác mình.
                                                                                $query->where('partner_id', $user->partner_id);
                                                                            }
                                                                        }
                                                                        return $query->pluck('name', 'id');
                                                                    })
                                                                    // Đảm bảo LUÔN hiện đúng tên chi nhánh cho giá trị ĐÃ CHỌN, bất kể
                                                                    // options() ở trên có đang bị thu hẹp/rỗng do timing (vd
                                                                    // booking_partner_id chưa kịp hydrate) hay không — tránh lặp lại lỗi
                                                                    // "hiện số ID thô thay vì tên chi nhánh" khi mở sửa/xem đơn đã tạo.
                                                                    ->getOptionLabelUsing(fn ($value) => Category::find($value)?->name)
                                                                    ->searchable()
                                                                    ->required()
                                                                    ->preload()
                                                                    ->native(false)
                                                                    ->live()
                                                                    // Không phải platform staff thì "Đối tác" ở trên ẩn hẳn (không chiếm
                                                                    // cột nào) — chiếm 2/3 còn lại (rộng hơn "Đối tác" 1/3) khi cùng
                                                                    // hiện, hoặc trọn cả hàng (3/3) khi "Đối tác" bị ẩn.
                                                                    ->columnSpan(fn () => self::isPlatformStaff() ? 2 : 3)
                                                                    ->disabled(fn (Get $get) => self::isPlatformStaff() && ! $get('booking_partner_id'))
                                                                    ->afterStateUpdated(function (Set $set, Get $get) {
                                                                        // Đổi chi nhánh thì các phòng đã chọn ở Repeater bên dưới (nếu có)
                                                                        // rất có thể không còn thuộc chi nhánh mới — reset lại để tránh giữ
                                                                        // nhầm phòng của chi nhánh cũ.
                                                                        foreach (array_keys($get('orderItems') ?? []) as $key) {
                                                                            $set("orderItems.{$key}.product_id", null);
                                                                        }
                                                                    }),
                                                            ]),
                                                    Group::make()
                                                        ->statePath('orderItems.0')
                                                        ->schema([
                                                            Grid::make(2)
                                                                ->schema([
                                                                    Select::make('product_id')
                                                                        ->label('Chọn phòng')
                                                                        ->options(function (Get $get) {
                                                                            $user = auth()->user();

                                                                            // Nhân viên đối tác NỀN TẢNG (vd 365home) đặt phòng hộ mọi đối
                                                                            // tác — phải gỡ hẳn global scope 'partner' của Product mới thấy
                                                                            // được phòng của TẤT CẢ đối tác khác, không chỉ đối tác của mình.
                                                                            $query = ($user && $user->belongsToPlatformPartner())
                                                                                ? Product::withoutGlobalScope('partner')->where('is_activated', true)
                                                                                : Product::query()->where('is_activated', true);

                                                                            if ($user && ! $user->isSuperAdmin() && ! $user->belongsToPlatformPartner()) {
                                                                                $categoryIds = $user->allowedCategoryIds();
                                                                                if (! empty($categoryIds)) {
                                                                                    $allowedIds = Categorizable::where('categorizable_type', Product::class)
                                                                                        ->whereIn('category_id', $categoryIds)
                                                                                        ->distinct()
                                                                                        ->pluck('categorizable_id');
                                                                                    $query->whereIn('id', $allowedIds);
                                                                                }
                                                                                // Chưa gán quyền chi nhánh cụ thể thì không thu hẹp thêm — Product
                                                                                // đã tự lọc theo partner_id (BelongsToPartner).
                                                                            }

                                                                            // Chỉ hiện phòng THUỘC ĐÚNG chi nhánh đã chọn ở trên — trước đây
                                                                            // chọn chi nhánh không thu hẹp danh sách phòng chút nào, dễ chọn
                                                                            // nhầm phòng của chi nhánh khác cùng đối tác.
                                                                            $categoryId = $get('data.category_id', isAbsolute: true);

                                                                            if (! $categoryId) {
                                                                                return [];
                                                                            }

                                                                            // Một số đối tác (dữ liệu cũ, vd 365home) tổ chức category 2 cấp:
                                                                            // chi nhánh (parent_id NULL) → danh mục phòng con (parent_id = chi
                                                                            // nhánh) — Product được gán categorizable vào danh mục CON, không
                                                                            // phải thẳng vào chi nhánh. Phải gộp cả chi nhánh + toàn bộ danh
                                                                            // mục con của nó khi tìm phòng, nếu không chọn chi nhánh sẽ ra
                                                                            // danh sách rỗng dù chi nhánh đó có đầy đủ phòng.
                                                                            $childCategoryIds = Category::where('parent_id', $categoryId)->pluck('id');
                                                                            $searchCategoryIds = $childCategoryIds->push($categoryId);

                                                                            $roomIdsInBranch = Categorizable::where('categorizable_type', Product::class)
                                                                                ->whereIn('category_id', $searchCategoryIds)
                                                                                ->pluck('categorizable_id');
                                                                            $query->whereIn('id', $roomIdsInBranch);

                                                                            return $query->pluck('name', 'id');
                                                                        })
                                                                        // Giống category_id ở trên — options() phụ thuộc vào
                                                                        // 'data.category_id' (absolute path), riêng modal "Xem chi tiết"
                                                                        // (ViewAction) có statePath KHÁC trang Sửa nên path tuyệt đối này
                                                                        // không phải lúc nào cũng resolve đúng. getOptionLabelUsing() tra
                                                                        // thẳng theo ID nên LUÔN hiện đúng tên phòng bất kể options() ở
                                                                        // trên có rỗng hay không.
                                                                        ->getOptionLabelUsing(fn ($value) => Product::withoutGlobalScope('partner')->find($value)?->name)
                                                                        ->searchable()
                                                                        ->preload()
                                                                        ->required()
                                                                        ->reactive()
                                                                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                            if ($state) {
                                                                                $product = Product::find($state);
                                                                                if ($product) {
                                                                                    $set('name', $product->name);
                                                                                    $set('product_style', (int)($product->styles ?? 1));
                                                                                    $set('price_per_night', (float)($product->price ?? 0));
                                                                                    $displayPrice = $product->discount ?: $product->price;
                                                                                    $set('discount', $displayPrice);

                                                                                    // Đổi phòng — XOÁ SẠCH giá + khung giờ/ngày đã chọn của phòng
                                                                                    // CŨ (không còn nghĩa gì với phòng MỚI, và mỗi khung giờ có
                                                                                    // giá/khuyến mãi RIÊNG nên không thể suy ra giá đúng ngay khi
                                                                                    // vừa đổi phòng — bắt buộc chọn lại khung giờ/ngày để tính giá
                                                                                    // thật). 'price' set 0 (KHÔNG set = $product->price) để tổng
                                                                                    // tiền không hiện nhầm giá phòng gốc trong lúc chưa chọn khung
                                                                                    // giờ nào — selectTimeslot()/checkin_day-checkout_day sẽ tự
                                                                                    // ghi đè lại đúng giá thật ngay khi chọn. KHÔNG đụng
                                                                                    // 'orderServices' (dịch vụ đã chọn) — field đó tách riêng,
                                                                                    // không phụ thuộc phòng.
                                                                                    $set('price', 0);
                                                                                    $set('selected_slots', []);
                                                                                    $set('checkin_date', null);
                                                                                    $set('checkout_date', null);
                                                                                    $set('checkin_day', null);
                                                                                    $set('checkout_day', null);
                                                                                    if (!$get('quantity')) {
                                                                                        $set('quantity', 1);
                                                                                    }
                                                                                    self::calculateTotal($get, $set);
                                                                                }
                                                                            }
                                                                        }),

                                                                    TextInput::make('guest_count')
                                                                        ->label('Số lượng khách')
                                                                        ->placeholder('Số lượng khách')
                                                                        ->numeric()
                                                                        ->minValue(1)
                                                                        ->default(1)
                                                                        ->required()
                                                                        ->live()
                                                                        ->afterStateUpdated(function (Get $get, Set $set) {
                                                                            self::calculateTotal($get, $set);
                                                                        }),

                                                                    Grid::make(1)
                                                                        ->schema([
                                                                            Select::make('service_to_add')
                                                                                ->label('Thêm dịch vụ')
                                                                                ->dehydrated(false)
                                                                                ->searchable()
                                                                                ->preload()
                                                                                ->options(function (Get $get) {
                                                                                    // Ẩn dịch vụ ĐÃ chọn khỏi danh sách — chọn lại dịch vụ đã có
                                                                                    // thì dùng nút +/- ở danh sách bên dưới để tăng số lượng
                                                                                    // (xem afterStateUpdated() bên dưới đã tự cộng dồn quantity
                                                                                    // theo service_id), không tạo thêm dòng trùng tên nữa.
                                                                                    $selectedIds = collect($get('../../orderServices') ?? [])
                                                                                        ->pluck('service_id')
                                                                                        ->filter()
                                                                                        ->all();

                                                                                    return \Modules\BladeThemeV1\App\Models\AdditionService::where('is_active', 1)
                                                                                        ->whereNotIn('id', $selectedIds)
                                                                                        ->pluck('name', 'id');
                                                                                })
                                                                                ->placeholder('Chọn dịch vụ...')
                                                                                ->reactive()
                                                                                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                                    if (! $state) {
                                                                                        return;
                                                                                    }

                                                                                    $service = \Modules\BladeThemeV1\App\Models\AdditionService::find($state);

                                                                                    if (! $service) {
                                                                                        return;
                                                                                    }

                                                                                    $services = $get('../../orderServices') ?? [];
                                                                                    $services = is_array($services) ? $services : [];

                                                                                    $existingKey = null;
                                                                                    foreach ($services as $key => $item) {
                                                                                        if (($item['service_id'] ?? null) === $state) {
                                                                                            $existingKey = $key;
                                                                                            break;
                                                                                        }
                                                                                    }

                                                                                    if ($existingKey !== null) {
                                                                                        $services[$existingKey]['quantity'] = max(1, $services[$existingKey]['quantity'] + 1);
                                                                                        $services[$existingKey]['subtotal'] = $services[$existingKey]['price'] * $services[$existingKey]['quantity'];
                                                                                    } else {
                                                                                        $services[] = [
                                                                                            'service_id' => $service->id,
                                                                                            'service_name' => $service->name,
                                                                                            'price' => $service->price,
                                                                                            'quantity' => 1,
                                                                                            'subtotal' => $service->price,
                                                                                        ];
                                                                                    }

                                                                                    $set('../../orderServices', $services);
                                                                                    $set('service_to_add', null);
                                                                                    self::calculateTotal($get, $set);
                                                                                }),
                                                                        ]),
                                                                ]),
                                                        ]),

                                                    // Danh sách dịch vụ đã chọn — nằm NGAY DƯỚI "Thêm dịch vụ" (Select
                                                    // service_to_add ở trên) trong CÙNG cột 2. Giao diện TỰ CHẾ (view
                                                    // 'payment::components.order-services-list'), KHÔNG dùng Repeater
                                                    // của Filament nữa (theo yêu cầu — repeater mặc định trông cồng
                                                    // kềnh, khó custom gọn/đẹp). 'orderServices' vẫn LÀ dữ liệu thật
                                                    // (Hidden field bên dưới dehydrate mảng này khi lưu) — ViewField chỉ
                                                    // ĐỌC để hiển thị, tăng/giảm số lượng + xoá gọi thẳng qua wire:click
                                                    // vào HasOrderServicesManagement (ghi trực tiếp $this->data, cùng kỹ
                                                    // thuật đã dùng ổn định ở HasTimeslotGridSelection::selectTimeslot()
                                                    // — tránh lặp lại lỗi reactivity Alpine/$wire.entangle từng gặp).
                                                    Hidden::make('orderServices')
                                                        ->default([])
                                                        ->dehydrated(),

                                                    ViewField::make('orderServices_display')
                                                        ->label('')
                                                        ->dehydrated(false)
                                                        ->live()
                                                        ->view('payment::components.order-services-list')
                                                        ->viewData(fn (Get $get) => ['services' => $get('orderServices') ?? []]),
                                            ]),
                                    ]),
                            ]),
                                Grid::make(2)
                                    ->schema([
                                        // Chia 2 cột: cột 1 = lịch/khung giờ (bọc Group('orderItems.0')), cột
                                        // 2 = "Tổng thanh toán" — ĐẶT NGOÀI Group đó vì đây là field CẤP ĐƠN
                                        // (orders.amount/surcharge), không phải theo từng phòng. Để cả 2 cột
                                        // trong CÙNG 1 Group('orderItems.0') (như bản trước) sẽ lưu NHẦM
                                        // 'surcharge'/'amount' thành 'orderItems.0.surcharge'/'orderItems.0.amount'
                                        // thay vì cột thật trên bảng orders — CreateOrder/EditOrder đọc
                                        // $data['amount'] ở CẤP GỐC nên đọc ra null, tổng tiền KHÔNG được lưu
                                        // (lỗi đã xảy ra thực tế — Group giờ chỉ bọc cột 1, không bọc cột 2 nữa).
                                        Grid::make(1)
                                            ->columnSpan(1)
                                            ->schema([
                                                Group::make()
                                                    ->statePath('orderItems.0')
                                                    ->schema([
                                                                // Giá phòng — KHÔNG cho nhân viên gõ tay nữa (theo yêu cầu: ô này
                                                                // không thực sự có tác dụng, giá thật luôn lấy từ Product lúc chọn
                                                                // phòng ở trên/từ đơn giá×số đêm ở style 2, gõ tay vào đây không
                                                                // ảnh hưởng gì tới số tiền thật sự lưu). Chuyển thành Hidden — vẫn
                                                                // PHẢI dehydrate ở MỌI style (xem ghi chú gốc: order 2978 từng lưu
                                                                // price = NULL khi field bị ẩn không dehydrate), chỉ bỏ khỏi giao
                                                                // diện, không đổi cách tính/lưu giá trị.
                                                                Hidden::make('price')
                                                                        ->dehydrated(),

                                                                // Style 2: hiển thị tổng tính tự động
                                                                Placeholder::make('price_nights_summary')
                                                                    ->label('Tổng tiền phòng')
                                                                    ->visible(fn(Get $get) => (int)($get('product_style') ?? 1) === 2)
                                                                    ->live()
                                                                    ->content(function(Get $get) {
                                                                        $checkin  = $get('checkin_day') ?: $get('checkin_date');
                                                                        $checkout = $get('checkout_day') ?: $get('checkout_date');
                                                                        $priced   = self::calculateDailyRoomPrice($get('product_id'), $checkin, $checkout);

                                                                        if ($priced['nights'] > 0) {
                                                                            $nightsLabel = $priced['uniform_price'] !== null
                                                                                ? $priced['nights'] . ' đêm × ' . number_format($priced['uniform_price'], 0, ',', '.') . 'đ/đêm'
                                                                                : $priced['nights'] . ' đêm (giá riêng từng ngày, đã gồm khuyến mãi)';

                                                                            return new \Illuminate\Support\HtmlString(
                                                                                '<div class="text-sm" style="color:var(--color-primary)">' .
                                                                                '<span class="font-bold text-base">' . number_format($priced['total'], 0, ',', '.') . 'đ</span>' .
                                                                                ' <span class="text-gray-400 font-normal">(' . $nightsLabel . ')</span>' .
                                                                                '</div>'
                                                                            );
                                                                        }
                                                                        return new \Illuminate\Support\HtmlString('<span class="text-gray-400 italic text-xs">Chọn ngày nhận & trả phòng để tính giá</span>');
                                                                    }),

                                                                // Phụ thu — KHÔNG cho nhân viên gõ tay nữa (theo yêu cầu: ô này
                                                                // không thực sự có tác dụng, phụ thu khách thứ 3 trở lên đã tự
                                                                // động tính riêng theo guest_count — xem calculateGuestSurcharge()
                                                                // — nên gõ tay vào đây không đại diện đúng số phụ thu thật sự áp
                                                                // dụng, dễ gây hiểu nhầm). Giữ mặc định 0, không hiện trên form.
                                                                Hidden::make('extra_fee')
                                                                        ->default(0),

                                                            // Style 1 + phòng CÓ khai báo khung giờ (RoomTimeSlot) — CHỈ 1 bảng cho
                                                            // đúng 1 phòng này, chọn được NHIỀU khung giờ (nhiều ngày/nhiều khung)
                                                            // ngay trên cùng bảng đó, KHÔNG thêm dòng/thẻ phòng mới nào. Mỗi khung
                                                            // giờ đã chọn được lưu vào mảng 'selected_slots' của ĐÚNG dòng này; lúc
                                                            // lưu đơn, mỗi phần tử trong mảng đó mới được tách thành 1 order_item
                                                            // riêng (xem OrderForm::expandOrderItemsForPersistence()).
                                                            Grid::make(1)
                                                                ->visible(fn (Get $get) => (int) ($get('product_style') ?? 1) !== 2
                                                                    && self::getRoomTimeSlots((string) ($get('product_id') ?? ''))->isNotEmpty())
                                                                ->schema([
                                                                    // Lưới NGÀY × KHUNG GIỜ dạng bảng, giống trang đặt phòng phía
                                                                    // client (14 ngày tới, mỗi ô kiểm tra chồng lấn với đơn khác của
                                                                    // đúng phòng này). Bấm 1 ô = gọi thẳng method
                                                                    // selectTimeslot() trên trang Tạo/Sửa đơn (wire:click chính
                                                                    // thống của Livewire) để ghi trực tiếp checkin_date/checkout_date/
                                                                    // giá — KHÔNG dùng Alpine/$wire.entangle tự chế như 2 lần thử
                                                                    // trước (từng làm mất phản ứng tính giá), nên không rủi ro lặp lại.
                                                                    ViewField::make('timeslot_grid')
                                                                        ->label('Chọn ngày & khung giờ')
                                                                        ->dehydrated(false)
                                                                        ->live()
                                                                        ->view('payment::components.timeslot-grid-table')
                                                                        ->viewData(function (Get $get, $component, $livewire) {
                                                                            $productId = $get('product_id');

                                                                            if (! $productId) {
                                                                                return ['dates' => [], 'slots' => [], 'cells' => [], 'itemKey' => '', 'selectedSlots' => []];
                                                                            }

                                                                            // Suy ra key của dòng Repeater hiện tại từ chính statePath của
                                                                            // field này (vd "data.orderItems.abc123.timeslot_grid") — cần
                                                                            // để gọi đúng selectTimeslot(itemKey, ...) ghi vào đúng dòng.
                                                                            $segments = explode('.', $component->getStatePath());
                                                                            $itemKey  = $segments[count($segments) - 2] ?? '';

                                                                            $selectedSlots = $get('selected_slots') ?? [];
                                                                            $selectedSlots = is_array($selectedSlots) ? $selectedSlots : [];

                                                                            // Đơn đã đặt ở ngày ngoài khoảng 14 ngày tới mặc định (sửa đơn
                                                                            // cũ) — luôn chèn thêm đúng các ngày đã đặt để ô đã chọn hiện ra.
                                                                            $mustIncludeDate = collect($selectedSlots)->pluck('date')->filter()->first();
                                                                            $mustIncludeDate = $mustIncludeDate
                                                                                ? \Carbon\Carbon::parse($mustIncludeDate)->format('Y-m-d')
                                                                                : null;

                                                                            // record chỉ tồn tại ở trang SỬA đơn (EditOrder) — trang TẠO MỚI
                                                                            // (CreateOrder) chưa có đơn nào để loại trừ, giữ null là đúng.
                                                                            $currentOrderId = $livewire->getRecord()?->id ?? null;

                                                                            $gridData = self::getTimeslotGridData((string) $productId, $get('id'), 14, $mustIncludeDate, $currentOrderId);

                                                                            return [
                                                                                'dates'         => $gridData['dates'],
                                                                                'slots'         => $gridData['slots'],
                                                                                'cells'         => $gridData['cells'],
                                                                                'itemKey'       => $itemKey,
                                                                                'selectedSlots' => $selectedSlots,
                                                                            ];
                                                                        }),
                                                                ]),

                                                            // checkin_date/checkout_date THẬT SỰ — nơi DUY NHẤT lưu 2 giá trị này
                                                            // cho MỌI style (khung giờ ghi qua selectTimeslot(), style 2 ghi qua
                                                            // checkin_day/checkout_day, style 1 không khung giờ ghi qua
                                                            // manual_checkin_date/manual_checkout_date — đều $set() vào đúng đây).
                                                            //
                                                            // QUAN TRỌNG: đặt Ở NGOÀI, KHÔNG lồng trong bất kỳ Grid nào có
                                                            // ->visible() điều kiện theo style — field/Grid bị ẨN thì Filament mặc
                                                            // định KHÔNG dehydrate (tự xóa khỏi state khi lưu, xem
                                                            // HasState::isDehydrated()/dehydrateState()), và trạng thái "ẩn" của
                                                            // Grid cha còn LAN XUỐNG con (con vẫn bị loại dù tự set
                                                            // dehydratedWhenHidden() trên chính nó) — nên 2 Hidden này PHẢI nằm
                                                            // ngoài mọi Grid điều kiện style để luôn dehydrate ở MỌI style. Trước
                                                            // đây đặt trong Grid chỉ hiện với "style 1 có khung giờ" khiến
                                                            // order_item của style 2/style 1 không khung giờ lưu
                                                            // checkin_date/checkout_date = NULL (đã xác minh thực tế qua đơn 2978).
                                                            Hidden::make('checkin_date'),
                                                            Hidden::make('checkout_date'),

                                                            // Style 1 nhưng phòng CHƯA khai báo khung giờ nào — giữ nguyên cách
                                                            // nhập tự do như trước (không có khung giờ cố định để chọn). Đổi tên
                                                            // field + dehydrated(false) để KHÔNG xung đột với Hidden checkin_date/
                                                            // checkout_date thật ở Grid trên — chỉ mirror giá trị qua lại.
                                                            Grid::make(2)
                                                                ->visible(fn (Get $get) => (int) ($get('product_style') ?? 1) !== 2
                                                                    && self::getRoomTimeSlots((string) ($get('product_id') ?? ''))->isEmpty())
                                                                ->schema([
                                                                    DateTimePicker::make('manual_checkin_date')
                                                                        ->label('Ngày & Giờ nhận phòng')
                                                                        ->placeholder('Chọn ngày & giờ nhận phòng')
                                                                        ->displayFormat('d/m/Y H:i')
                                                                        ->seconds(false)
                                                                        ->native(false)
                                                                        ->dehydrated(false)
                                                                        ->afterStateHydrated(function ($component, Get $get) {
                                                                            if ($checkin = $get('checkin_date')) {
                                                                                $component->state($checkin);
                                                                            }
                                                                        })
                                                                        ->afterStateUpdated(fn ($state, Set $set) => $set('checkin_date', $state))
                                                                        ->live()
                                                                        ->required(fn(Get $get) => (int)($get('product_style') ?? 1) !== 2),

                                                                    DateTimePicker::make('manual_checkout_date')
                                                                        ->label('Ngày & Giờ trả phòng')
                                                                        ->placeholder('Chọn ngày & giờ trả phòng')
                                                                        ->displayFormat('d/m/Y H:i')
                                                                        ->seconds(false)
                                                                        ->native(false)
                                                                        ->dehydrated(false)
                                                                        ->afterStateHydrated(function ($component, Get $get) {
                                                                            if ($checkout = $get('checkout_date')) {
                                                                                $component->state($checkout);
                                                                            }
                                                                        })
                                                                        ->afterStateUpdated(fn ($state, Set $set) => $set('checkout_date', $state))
                                                                        ->live()
                                                                        ->required(fn(Get $get) => (int)($get('product_style') ?? 1) !== 2),
                                                                ]),

                                                            // Style 2: Đặt theo ngày (tính đêm)
                                                            Grid::make(3)
                                                                ->visible(fn(Get $get) => (int)($get('product_style') ?? 1) === 2)
                                                                ->schema([
                                                                    DatePicker::make('checkin_day')
                                                                        ->label('Ngày nhận phòng')
                                                                        ->placeholder('Chọn ngày nhận phòng')
                                                                        ->displayFormat('d/m/Y')
                                                                        ->native(false)
                                                                        ->icon('heroicon-o-calendar-days')
                                                                        ->weekStartsOnMonday()
                                                                        ->closeOnDateSelection()
                                                                        ->locale('vi')
                                                                        ->dehydrated(false)
                                                                        ->required(fn(Get $get) => (int)($get('product_style') ?? 1) === 2)
                                                                        // Không cho chọn ngày ĐÃ QUA — hôm nay 14 thì không thể đặt cho
                                                                        // ngày 12/13 (đã trôi qua), chỉ được chọn từ hôm nay trở đi.
                                                                        ->minDate(now()->startOfDay())
                                                                        // Chặn chọn trúng 1 đêm đã có khách khác đặt cho đúng phòng này —
                                                                        // giống cách style 1 chặn khung giờ đã đặt trên lưới.
                                                                        ->disabledDates(fn (Get $get) => self::getBookedNightsForProduct(
                                                                            $get('product_id'),
                                                                            $get('id')
                                                                        ))
                                                                        ->afterStateHydrated(function ($component, Get $get) {
                                                                            $val = $get('checkin_date');
                                                                            if ($val) {
                                                                                $component->state(\Carbon\Carbon::parse($val)->format('Y-m-d'));
                                                                            }
                                                                        })
                                                                        ->live()
                                                                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                            if (! $state) {
                                                                                return;
                                                                            }

                                                                            $checkout = $get('checkout_day');

                                                                            // Chặn "nhảy qua" 1 đêm đã đặt ở giữa khoảng (2 đầu mút tự nó
                                                                            // trống, nhưng khoảng ở giữa lại đè lên lượt đặt khác) —
                                                                            // disabledDates ở trên chỉ chặn được khi chọn TRÚNG 1 đêm kín.
                                                                            if ($checkout && self::hasOverlappingNightBooking($get('product_id'), $state, $checkout, $get('id'))) {
                                                                                $set('checkin_day', null);
                                                                                $set('checkin_date', null);
                                                                                Notification::make()
                                                                                    ->title('Khoảng ngày này đã có khách khác đặt')
                                                                                    ->body('Vui lòng chọn lại ngày nhận phòng khác.')
                                                                                    ->warning()
                                                                                    ->send();
                                                                                return;
                                                                            }

                                                                            // Ghép ngày đã chọn với giờ nhận phòng MẶC ĐỊNH đã cấu hình sẵn
                                                                            // cho phòng (Product::default_checkin) — KHÔNG tự ý dùng 00:00.
                                                                            // $state đôi khi đến kèm cả giờ (DatePicker có lúc trả về
                                                                            // "2026-07-14 00:00:00" thay vì chỉ "2026-07-14") — phải lấy
                                                                            // đúng phần NGÀY trước khi ghép giờ, nếu không bị lỗi "Double
                                                                            // time specification" khi Carbon parse lại chuỗi đã có 2 giờ.
                                                                            $product = Product::find($get('product_id'));
                                                                            $checkinTime = $product ? self::resolveCheckinCheckoutTime($product)[0] : '14:00';
                                                                            $checkinDateOnly = \Carbon\Carbon::parse($state)->format('Y-m-d');
                                                                            $set('checkin_date', $checkinDateOnly . ' ' . $checkinTime . ':00');

                                                                            if ($checkout) {
                                                                                $priced = self::calculateDailyRoomPrice($get('product_id'), $state, $checkout);
                                                                                if ($priced['nights'] > 0) {
                                                                                    $set('price', $priced['total']);
                                                                                    $set('price_per_night', $priced['uniform_price'] ?? round($priced['total'] / $priced['nights']));
                                                                                    self::calculateTotal($get, $set);
                                                                                }
                                                                            }
                                                                        }),

                                                                    DatePicker::make('checkout_day')
                                                                        ->label('Ngày trả phòng')
                                                                        ->placeholder('Chọn ngày trả phòng')
                                                                        ->displayFormat('d/m/Y')
                                                                        ->native(false)
                                                                        ->icon('heroicon-o-calendar-days')
                                                                        ->weekStartsOnMonday()
                                                                        ->closeOnDateSelection()
                                                                        ->locale('vi')
                                                                        ->dehydrated(false)
                                                                        ->required(fn(Get $get) => (int)($get('product_style') ?? 1) === 2)
                                                                        // Không cho chọn ngày ĐÃ QUA, và ngày trả phòng luôn phải SAU
                                                                        // ngày nhận phòng ít nhất 1 ngày (nếu đã chọn ngày nhận phòng).
                                                                        ->minDate(fn (Get $get) => $get('checkin_day')
                                                                            ? \Carbon\Carbon::parse($get('checkin_day'))->addDay()
                                                                            : now()->startOfDay())
                                                                        // Đêm cuối cùng của lượt đặt (checkout - 1 ngày) là đêm ĐANG NẰM
                                                                        // TRONG lượt đặt này nên KHÔNG loại nó khỏi danh sách đêm kín của
                                                                        // "lượt khác" — getBookedNightsForProduct() đã tự loại theo
                                                                        // $get('id') (đang sửa) nên vẫn đúng khi sửa lại chính lượt này.
                                                                        ->disabledDates(fn (Get $get) => self::getBookedNightsForProduct(
                                                                            $get('product_id'),
                                                                            $get('id')
                                                                        ))
                                                                        ->afterStateHydrated(function ($component, Get $get) {
                                                                            $val = $get('checkout_date');
                                                                            if ($val) {
                                                                                $component->state(\Carbon\Carbon::parse($val)->format('Y-m-d'));
                                                                            }
                                                                        })
                                                                        ->live()
                                                                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                            if (! $state) {
                                                                                return;
                                                                            }

                                                                            $checkin = $get('checkin_day');

                                                                            if ($checkin && self::hasOverlappingNightBooking($get('product_id'), $checkin, $state, $get('id'))) {
                                                                                $set('checkout_day', null);
                                                                                $set('checkout_date', null);
                                                                                Notification::make()
                                                                                    ->title('Khoảng ngày này đã có khách khác đặt')
                                                                                    ->body('Vui lòng chọn lại ngày trả phòng khác.')
                                                                                    ->warning()
                                                                                    ->send();
                                                                                return;
                                                                            }

                                                                            // Ghép ngày đã chọn với giờ trả phòng MẶC ĐỊNH đã cấu hình sẵn
                                                                            // cho phòng (Product::default_checkout) — KHÔNG tự ý dùng 00:00.
                                                                            // $state đôi khi đến kèm cả giờ (xem ghi chú tương tự ở
                                                                            // checkin_day) — phải lấy đúng phần NGÀY trước khi ghép giờ.
                                                                            $product = Product::find($get('product_id'));
                                                                            $checkoutTime = $product ? self::resolveCheckinCheckoutTime($product)[1] : '12:00';
                                                                            $checkoutDateOnly = \Carbon\Carbon::parse($state)->format('Y-m-d');
                                                                            $set('checkout_date', $checkoutDateOnly . ' ' . $checkoutTime . ':00');

                                                                            if ($checkin) {
                                                                                $priced = self::calculateDailyRoomPrice($get('product_id'), $checkin, $state);
                                                                                if ($priced['nights'] > 0) {
                                                                                    $set('price', $priced['total']);
                                                                                    $set('price_per_night', $priced['uniform_price'] ?? round($priced['total'] / $priced['nights']));
                                                                                    self::calculateTotal($get, $set);
                                                                                }
                                                                            }
                                                                        }),

                                                                    Placeholder::make('nights_display')
                                                                        ->label('Số đêm & Tổng')
                                                                        ->live()
                                                                        ->content(function (Get $get) {
                                                                            $checkin = $get('checkin_day');
                                                                            $checkout = $get('checkout_day');
                                                                            if ($checkin && $checkout) {
                                                                                $c = \Carbon\Carbon::parse($checkin);
                                                                                $o = \Carbon\Carbon::parse($checkout);
                                                                                if ($c->gte($o)) {
                                                                                    return new \Illuminate\Support\HtmlString('<span class="text-danger-600 text-xs">Ngày trả phải sau ngày nhận</span>');
                                                                                }
                                                                                $priced = self::calculateDailyRoomPrice($get('product_id'), $checkin, $checkout);
                                                                                if ($priced['nights'] <= 0) {
                                                                                    return new \Illuminate\Support\HtmlString('<span class="text-gray-400 italic text-xs">Chọn ngày để tính đêm</span>');
                                                                                }
                                                                                $rateLabel = $priced['uniform_price'] !== null
                                                                                    ? ' × ' . number_format($priced['uniform_price'], 0, ',', '.') . 'đ'
                                                                                    : ' (giá riêng từng ngày)';
                                                                                return new \Illuminate\Support\HtmlString(
                                                                                    '<div class="text-sm font-bold" style="color:var(--color-primary)">' .
                                                                                    $priced['nights'] . ' đêm' . $rateLabel .
                                                                                    ' = <span class="text-base">' . number_format($priced['total'], 0, ',', '.') . 'đ</span>' .
                                                                                    '</div>'
                                                                                );
                                                                            }
                                                                            return new \Illuminate\Support\HtmlString('<span class="text-gray-400 italic text-xs">Chọn ngày để tính đêm</span>');
                                                                        }),
                                                                ]),

                                                            Hidden::make('name'),

                                                            Hidden::make('product_style')
                                                                ->default(1),

                                                            Hidden::make('price_per_night')
                                                                ->default(0),

                                                            // Danh sách CÁC khung giờ đã chọn cho ĐÚNG 1 phòng này (nhiều khung
                                                            // giờ trên CÙNG 1 bảng, không thêm dòng/thẻ phòng mới) — mỗi phần tử
                                                            // là ['slot_id'=>.., 'date'=>..]. Không phải cột thật trên
                                                            // order_items — CreateOrder/EditOrder tự expand mảng này thành đúng
                                                            // số bản ghi order_item cần thiết lúc lưu (1 khung giờ = 1 order_item).
                                                            Hidden::make('selected_slots')
                                                                ->default([]),
                                                    ]),
                                            ]),

                                        Grid::make(1)
                                            ->columnSpan(1)
                                            ->schema([
                                                // "Tổng thanh toán" (Chi tiết tính toán chi phí) — DI CHUYỂN nguyên
                                                        // cả nhóm field vào đây theo yêu cầu, thay vì nằm rời phía dưới
                                                        // hàng lịch đặt phòng như trước. Bỏ hẳn khung Section (theo yêu
                                                        // cầu "gọn đẹp lại") — chỉ còn 1 nhãn nhỏ + nội dung trần, không
                                                        // còn là "card" riêng. Group này vẫn nằm bên trong
                                                        // Group('orderItems.0') (xem Group B ngay trên) nên MỌI $get()/
                                                        // $set() bên trong đổi sang '../../...' (2 cấp) để vẫn trỏ đúng
                                                        // 'data.orderItems'/'data.amount'/... ở gốc — giống hệt cách
                                                        // calculateTotal() đã làm (Grid không tự thêm segment path, xem
                                                        // HasState::getStatePath()).
                                                        Grid::make(1)
                                                            ->schema([
                                                                Placeholder::make('tong_thanh_toan_label')
                                                                    ->label('')
                                                                    ->content(new \Illuminate\Support\HtmlString(
                                                                        '<div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--boulder-50, #6b7280);">Tổng thanh toán</div>'
                                                                    )),

                                                                Grid::make(2)
                                                                    ->schema([
                                                                        // Phụ thu ngoài đơn giá phòng/dịch vụ (vd phí phát sinh admin thoả
                                                                        // thuận riêng với khách) — admin gõ tay, CỘNG THẲNG vào tổng ở
                                                                        // calculateTotal()/amount_preview bên dưới, giống cách 'orderServices'
                                                                        // được cộng vào tổng.
                                                                        TextInput::make('surcharge')
                                                                            ->label('Phụ thu (có thể sửa tay)')
                                                                            ->numeric()
                                                                            ->minValue(0)
                                                                            ->default(0)
                                                                            ->suffix('đ')
                                                                            ->live()
                                                                            ->dehydrated(true)
                                                                            ->afterStateUpdated(function (Get $get, Set $set) {
                                                                                self::calculateTotal($get, $set);
                                                                            }),

                                                                        // Tổng tiền THẬT SỰ sẽ lưu vào đơn — mặc định tự điền theo đúng số
                                                                        // hệ thống vừa tính ở dưới (mỗi khi sửa phòng/dịch vụ/phụ thu,
                                                                        // calculateTotal() sẽ ghi đè lại field này bằng số tính đúng). Admin
                                                                        // vẫn có thể gõ đè 1 số KHÁC ngay tại đây nếu cần điều chỉnh giá
                                                                        // thực thu.
                                                                        TextInput::make('amount')
                                                                            ->label('Tổng tiền đơn (có thể sửa tay)')
                                                                            ->numeric()
                                                                            ->minValue(0)
                                                                            ->suffix('đ')
                                                                            ->live()
                                                                            ->dehydrated(true),
                                                                    ]),

                                                                // Card breakdown (phòng/dịch vụ/giảm giá/phụ thu khách/phụ thu) TRUYỀN
                                                                // THÊM 'displayTotal' = giá trị HIỆN TẠI của field 'amount' ở trên —
                                                                // để dòng "TỔNG THANH TOÁN" trên card LUÔN khớp CHÍNH XÁC với số
                                                                // trong ô "Tổng tiền đơn" (kể cả khi admin đã gõ đè tay), thay vì
                                                                // card tự tính lại 1 số khác đứng cạnh 1 ô nhập tay có thể đang giữ
                                                                // số khác — 2 chỗ hiển thị PHẢI luôn đồng bộ.
                                                                Placeholder::make('amount_preview')
                                                                    ->label('')
                                                                    ->live()
                                                                    ->dehydrated(false)
                                                                    ->content(function (Get $get, $record) {
                                                                        // Cột 2 KHÔNG còn nằm trong Group('orderItems.0') nữa (xem ghi chú ở
                                                                        // Grid::make(2) bọc 2 cột phía trên) — $get() ở đây dùng path THƯỜNG
                                                                        // (không '../../'), khớp đúng field 'orderItems'/'amount'/... ở gốc.
                                                                        $items = $get('orderItems');
                                                                        $items = is_array($items) ? $items : [];

                                                                        $servicesFormState = $get('orderServices') ?? [];
                                                                        $servicesFormState = is_array($servicesFormState) ? $servicesFormState : [];

                                                                        $surcharge = (float) ($get('surcharge') ?? 0);

                                                                        $total = self::computeOrderTotal($items, $servicesFormState) + $surcharge;

                                                                        $expandedItems = self::expandOrderItemsForPersistence($items);

                                                                        $currentAmount = $get('amount');

                                                                        return view('payment::components.total-amount-card', [
                                                                            'totalAmount' => $total,
                                                                            'displayTotal' => ($currentAmount !== null && $currentAmount !== '') ? (float) $currentAmount : $total,
                                                                            'items' => $expandedItems,
                                                                            'originalItems' => $items,
                                                                            'record' => $record,
                                                                            'servicesFormState' => $servicesFormState,
                                                                            'surcharge' => $surcharge,
                                                                        ]);
                                                                    }),
                                                            ]),
                                                    ]),
                                            ]),
                                    ]),

                        Section::make()
                            ->extraAttributes(['class' => 'order-form-root'])
                            ->schema([
                                // "Lịch sử thanh toán" và "Phương thức thanh toán" đã chuyển vào cột 2
                                // của Section "Thông tin khách hàng" phía trên (đã thu gọn) — ở đây chỉ
                                // còn 2 Section hành động khi
                                // có phát sinh/hoàn tiền chờ xử lý.
                                // SECTION: Thanh toán còn lại — chỉ hiện với đơn cọc chưa thanh toán đủ
                                Section::make('Thanh toán còn lại')
                                    ->description('Tạo link QR hoặc xác nhận thu tiền mặt phần còn lại')
                                    ->icon('heroicon-m-qr-code')
                                    ->iconColor('warning')
                                    ->visible(fn ($record) => $record && $record->status === 'deposit' && $record->deposit_percent !== null)
                                    ->schema([
                                        TextInput::make('deposit_paid_amount')
                                            ->label('Số tiền đã cọc')
                                            ->helperText('Chỉnh sửa để điều chỉnh số tiền cọc đã thu (mặc định = tổng đơn × % cọc). Số tiền QR còn lại = tổng đơn (cố định) − giá trị này.')
                                            ->numeric()
                                            ->suffix('VNĐ')
                                            ->live()
                                            ->default(fn ($record) => $record?->depositPaidAmount())
                                            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (int) $state),

                                        Placeholder::make('remaining_qr_preview')
                                            ->label('Số tiền còn lại (dự kiến)')
                                            ->content(function ($record, $get) {
                                                if (! $record) return new \Illuminate\Support\HtmlString('');
                                                // full_amount CỐ ĐỊNH = tổng giá thật, không cần suy ngược từ % nữa.
                                                $realTotal   = (int) $record->full_amount;
                                                $depositPaid = (int) ($get('deposit_paid_amount') ?? $record->depositPaidAmount());
                                                $extraCharge = (int) ($record->extra_charge_amount ?? 0);
                                                $remaining   = max(0, $realTotal - $depositPaid) + $extraCharge;
                                                $color       = $remaining <= 0 ? '#dc2626' : '#15803d';

                                                $breakdown = '= ' . number_format($realTotal, 0, ',', '.') . ' (tổng) − ' . number_format($depositPaid, 0, ',', '.') . ' (đã cọc)';
                                                if ($extraCharge > 0) {
                                                    $breakdown .= ' + ' . number_format($extraCharge, 0, ',', '.') . ' (phát sinh)';
                                                }

                                                return new \Illuminate\Support\HtmlString(
                                                    '<span style="font-size:15px;font-weight:700;color:' . $color . ';">'
                                                    . number_format($remaining, 0, ',', '.') . 'đ</span>'
                                                    . '<span style="font-size:12px;color:#6b7280;margin-left:8px;">' . $breakdown . '</span>'
                                                );
                                            }),

                                        \Filament\Forms\Components\Actions::make([
                                            \Filament\Forms\Components\Actions\Action::make('tao_qr_con_lai_form')
                                                ->label('Tạo QR còn lại')
                                                ->color('warning')
                                                ->icon('heroicon-o-qr-code')
                                                ->requiresConfirmation()
                                                ->modalHeading('Tạo link QR thanh toán còn lại')
                                                ->modalDescription('Mỗi lần bấm sẽ tạo link PayOS mới. Link cũ (nếu có) sẽ bị thay thế.')
                                                ->modalSubmitActionLabel('Tạo và mở trang QR')
                                                ->action(function ($record) {
                                                    if (! $record) return;
                                                    try {
                                                        // full_amount CỐ ĐỊNH = tổng giá thật — trừ đi số tiền cọc THỰC TẾ đã
                                                        // thu (deposit_paid_amount nếu admin đã sửa tay, mặc định theo %).
                                                        $extraChargeR = (int) ($record->extra_charge_amount ?? 0);
                                                        $remaining    = max(0, (int) $record->full_amount - $record->depositPaidAmount()) + $extraChargeR;

                                                        if ($remaining <= 0) {
                                                            Notification::make()->warning()->title('Đơn đã được thanh toán đủ, không cần tạo QR')->send();
                                                            return;
                                                        }

                                                        $payos = new PayOS(
                                                            config('payos.client_id'),
                                                            config('payos.api_key'),
                                                            config('payos.checksum_key')
                                                        );

                                                        $orderCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
                                                        $expiredAt = now()->addMinutes(30);
                                                        $response  = $payos->createPaymentLink([
                                                            'orderCode'   => $orderCode,
                                                            'amount'      => $remaining,
                                                            'description' => 'Tt con lai - ' . $record->order_code,
                                                            'returnUrl'   => \Modules\Payment\App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $record->id]) . '?remaining_done=1',
                                                            'cancelUrl'   => \Modules\Payment\App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $record->id]) . '?remaining_cancelled=1',
                                                            'buyerName'   => $record->buyer_name ?? '',
                                                            'buyerPhone'  => $record->buyer_phone ?? '',
                                                            'expiredAt'   => $expiredAt->timestamp,
                                                            'items'       => [[
                                                                'name'     => 'Tiền còn lại - ' . ($record->items->first()?->name ?? 'Phòng'),
                                                                'quantity' => 1,
                                                                'price'    => $remaining,
                                                            ]],
                                                        ]);

                                                        $checkoutUrl = $response['checkoutUrl'] ?? null;
                                                        if (! $checkoutUrl) {
                                                            Notification::make()->danger()->title('Không thể tạo link thanh toán')->send();
                                                            return;
                                                        }

                                                        $record->update([
                                                            'remaining_payos_code'   => (string) $orderCode,
                                                            'remaining_checkout_url' => $checkoutUrl,
                                                        ]);

                                                        AuditLogger::log(
                                                            'update', 'Order', $record,
                                                            [],
                                                            ['Tạo QR' => number_format($remaining, 0, ',', '.') . 'đ', 'Mã PayOS' => (string) $orderCode],
                                                            'Đơn #' . $record->order_code
                                                        );

                                                        return redirect()->away($checkoutUrl);

                                                    } catch (\Throwable $e) {
                                                        Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                                                    }
                                                }),

                                            \Filament\Forms\Components\Actions\Action::make('xac_nhan_thu_tien_form')
                                                ->label('Đã thu tiền mặt')
                                                ->color('success')
                                                ->icon('heroicon-o-banknotes')
                                                ->requiresConfirmation()
                                                ->modalHeading('Xác nhận thu tiền mặt')
                                                ->modalDescription('Xác nhận đã thu tiền mặt phần còn lại. Đơn sẽ được cập nhật "Đã thanh toán đầy đủ" và cấp mã truy cập cho khách.')
                                                ->modalSubmitActionLabel('Xác nhận')
                                                ->action(function ($record) {
                                                    if (! $record) return;
                                                    try {
                                                        // full_amount CỐ ĐỊNH không đổi — chỉ cập nhật 'amount' = tổng thực thu
                                                        // cuối cùng (gồm phụ phí phát sinh nếu có).
                                                        $extraChargeC = (int) ($record->extra_charge_amount ?? 0);
                                                        $record->update([
                                                            'status'                   => 'paid',
                                                            'amount'                   => (int) $record->full_amount + $extraChargeC,
                                                            'remaining_paid_at'        => now(),
                                                            'remaining_payment_method' => 'cash',
                                                        ]);

                                                        $record->load('items.product');
                                                        $firstItem    = $record->items->sortBy('checkin_date')->first();
                                                        $checkinDate  = $record->items->min('checkin_date');
                                                        $checkoutDate = $record->items->max('checkout_date');
                                                        $product      = $firstItem?->product;

                                                        if (! $record->hasAccessCode()) {
                                                            app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class)
                                                                ->assignCodeToOrder($record->id, $record->category_id, $checkinDate, $checkoutDate, $product);
                                                        }

                                                        AuditLogger::log(
                                                            'update', 'Order', $record,
                                                            ['status' => 'deposit', 'remaining_payment_method' => null],
                                                            ['status' => 'paid', 'remaining_payment_method' => 'cash'],
                                                            'Đơn #' . $record->order_code
                                                        );

                                                        Notification::make()
                                                            ->success()
                                                            ->title('Đã xác nhận thanh toán và cấp mã truy cập')
                                                            ->send();

                                                    } catch (\Throwable $e) {
                                                        Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                                                    }
                                                }),

                                            \Filament\Forms\Components\Actions\Action::make('xac_nhan_chuyen_khoan_form')
                                                ->label('Đã chuyển khoản')
                                                ->color('info')
                                                ->icon('heroicon-o-credit-card')
                                                ->requiresConfirmation()
                                                ->modalHeading('Xác nhận đã chuyển khoản')
                                                ->modalDescription('Xác nhận khách đã chuyển khoản thành công phần còn lại. Đơn sẽ được cập nhật "Đã thanh toán đầy đủ" và cấp mã truy cập cho khách.')
                                                ->modalSubmitActionLabel('Xác nhận')
                                                ->action(function ($record) {
                                                    if (! $record) return;
                                                    try {
                                                        $extraChargeC = (int) ($record->extra_charge_amount ?? 0);
                                                        $record->update([
                                                            'status'                   => 'paid',
                                                            'amount'                   => (int) $record->full_amount + $extraChargeC,
                                                            'remaining_paid_at'        => now(),
                                                            'remaining_payment_method' => 'bank_transfer',
                                                        ]);

                                                        $record->load('items.product');
                                                        $firstItem    = $record->items->sortBy('checkin_date')->first();
                                                        $checkinDate  = $record->items->min('checkin_date');
                                                        $checkoutDate = $record->items->max('checkout_date');
                                                        $product      = $firstItem?->product;

                                                        if (! $record->hasAccessCode()) {
                                                            app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class)
                                                                ->assignCodeToOrder($record->id, $record->category_id, $checkinDate, $checkoutDate, $product);
                                                        }

                                                        AuditLogger::log(
                                                            'update', 'Order', $record,
                                                            ['status' => 'deposit', 'remaining_payment_method' => null],
                                                            ['status' => 'paid', 'remaining_payment_method' => 'bank_transfer'],
                                                            'Đơn #' . $record->order_code
                                                        );

                                                        Notification::make()
                                                            ->success()
                                                            ->title('Đã xác nhận chuyển khoản và cấp mã truy cập')
                                                            ->send();

                                                    } catch (\Throwable $e) {
                                                        Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                                                    }
                                                }),
                                        ]),
                                    ])
                                    ->collapsible()
                                    ->collapsed(false),

                                // Đơn ĐÃ paid nhưng admin huỷ bớt khung giờ/dịch vụ làm giá GIẢM —
                                // EditOrder::handlePriceDiff() ghi khoản cần hoàn vào extra_refund_amount
                                // (xem ExtraChargeService::recordPendingRefund()). Không thể "hoàn qua
                                // QR" như chiều thu thêm (PayOS chỉ tạo link THU tiền), nên chỉ có 1 nút
                                // admin tự xác nhận đã chuyển khoản/trả tiền mặt cho khách NGOÀI hệ thống.
                                Section::make('Hoàn tiền chưa xử lý')
                                    ->description('Đơn đã thanh toán đủ nhưng sau khi huỷ khung giờ/dịch vụ giá giảm xuống — xác nhận đã hoàn tiền cho khách')
                                    ->icon('heroicon-m-arrow-uturn-left')
                                    ->iconColor('warning')
                                    ->visible(fn ($record) => $record
                                        && $record->status === 'paid'
                                        && (int) ($record->extra_refund_amount ?? 0) > 0
                                        && is_null($record->extra_refund_paid_at))
                                    ->schema([
                                        Placeholder::make('extra_refund_amount_preview')
                                            ->label('Số tiền cần hoàn cho khách')
                                            ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                                                '<span style="font-size:18px;font-weight:700;color:#d97706;">'
                                                . number_format((int) ($record->extra_refund_amount ?? 0), 0, ',', '.') . 'đ</span>'
                                            )),

                                        \Filament\Forms\Components\Actions::make([
                                            \Filament\Forms\Components\Actions\Action::make('xac_nhan_hoan_tien_form')
                                                ->label('Đã hoàn tiền (chuyển khoản)')
                                                ->color('warning')
                                                ->icon('heroicon-o-arrow-uturn-left')
                                                ->requiresConfirmation()
                                                ->modalHeading('Xác nhận đã hoàn tiền')
                                                ->modalDescription('Xác nhận đã chuyển khoản hoàn tiền cho khách khoản chênh lệch này (xử lý ngoài hệ thống).')
                                                ->modalSubmitActionLabel('Xác nhận')
                                                ->action(function ($record) {
                                                    if (! $record) return;
                                                    try {
                                                        $amount = (int) ($record->extra_refund_amount ?? 0);
                                                        if ($amount <= 0) {
                                                            Notification::make()->warning()->title('Không có khoản hoàn tiền chờ xử lý')->send();
                                                            return;
                                                        }

                                                        app(\App\Services\ExtraChargeService::class)->markRefundAsDone($record, $amount, 'bank_transfer');

                                                        AuditLogger::log(
                                                            'update', 'Order', $record,
                                                            [],
                                                            ['Đã hoàn tiền (chuyển khoản)' => number_format($amount, 0, ',', '.') . 'đ'],
                                                            'Đơn #' . $record->order_code
                                                        );

                                                        Notification::make()
                                                            ->success()
                                                            ->title('Đã ghi nhận hoàn tiền')
                                                            ->body(number_format($amount, 0, ',', '.') . 'đ')
                                                            ->send();

                                                    } catch (\Throwable $e) {
                                                        Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                                                    }
                                                }),

                                            \Filament\Forms\Components\Actions\Action::make('xac_nhan_hoan_tien_mat_form')
                                                ->label('Đã hoàn tiền (tiền mặt)')
                                                ->color('gray')
                                                ->icon('heroicon-o-banknotes')
                                                ->requiresConfirmation()
                                                ->modalHeading('Xác nhận đã hoàn tiền mặt')
                                                ->modalDescription('Xác nhận đã trả tiền mặt hoàn lại cho khách khoản chênh lệch này.')
                                                ->modalSubmitActionLabel('Xác nhận')
                                                ->action(function ($record) {
                                                    if (! $record) return;
                                                    try {
                                                        $amount = (int) ($record->extra_refund_amount ?? 0);
                                                        if ($amount <= 0) {
                                                            Notification::make()->warning()->title('Không có khoản hoàn tiền chờ xử lý')->send();
                                                            return;
                                                        }

                                                        app(\App\Services\ExtraChargeService::class)->markRefundAsDone($record, $amount, 'cash');

                                                        AuditLogger::log(
                                                            'update', 'Order', $record,
                                                            [],
                                                            ['Đã hoàn tiền (tiền mặt)' => number_format($amount, 0, ',', '.') . 'đ'],
                                                            'Đơn #' . $record->order_code
                                                        );

                                                        Notification::make()
                                                            ->success()
                                                            ->title('Đã ghi nhận hoàn tiền')
                                                            ->body(number_format($amount, 0, ',', '.') . 'đ')
                                                            ->send();

                                                    } catch (\Throwable $e) {
                                                        Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                                                    }
                                                }),
                                        ]),
                                    ])
                                    ->collapsible()
                                    ->collapsed(false),
                            ]),
            ]);
    }

    // Helper methods for badges
    // Luật Cư trú yêu cầu khai báo lưu trú ĐỦ TỪNG NGƯỜI khi ở qua đêm — chỉ hiện section CCCD
    // khách thứ 2 khi CÓ ÍT NHẤT 1 phòng/lượt đặt: (a) số khách = 2, VÀ (b) là lượt ở QUA ĐÊM
    // (style "đặt theo ngày" luôn qua đêm; style "khung giờ" thì phải có khung giờ đã chọn đánh
    // dấu over_night=true, vd khung 22:00 - 02:00).
    private static function requiresSecondGuestCccd(Get $get): bool
    {
        $items = $get('orderItems');
        if (! is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            $guestCount = (int) ($item['guest_count'] ?? 1);
            if ($guestCount < 2) {
                continue;
            }

            $productId = $item['product_id'] ?? null;
            if (! $productId) {
                continue;
            }

            $product = Product::find($productId);
            if (! $product) {
                continue;
            }

            if ((int) $product->styles === 2) {
                return true;
            }

            $selectedSlots = $item['selected_slots'] ?? [];
            if (! is_array($selectedSlots)) {
                continue;
            }

            foreach ($selectedSlots as $selected) {
                $slotId = $selected['slot_id'] ?? null;
                if (! $slotId) {
                    continue;
                }

                $slot = RoomTimeSlot::find($slotId);
                if ($slot?->over_night) {
                    return true;
                }
            }
        }

        return false;
    }

    // Số ô CCCD "khách đi cùng" cần hiện = số khách CAO NHẤT trong các dòng đặt phòng - 1 (trừ
    // khách chính). Khai báo lưu trú tính theo CẢ ĐƠN (cccd_declarations chỉ khoá order_id +
    // guest_index, không tách theo từng phòng), nên dùng giá trị cao nhất giữa các dòng thay vì
    // cộng dồn — 1 đơn thường chỉ có 1 lượt lưu trú thực tế dù có thể tách nhiều dòng/khung giờ.
    private static function maxGuestCountAcrossItems(Get $get): int
    {
        $items = $get('orderItems');
        if (! is_array($items)) {
            return 1;
        }

        $max = 1;
        foreach ($items as $item) {
            $count = (int) ($item['guest_count'] ?? 1);
            if ($count > $max) {
                $max = $count;
            }
        }

        return $max;
    }

    // $record ở đây LUÔN là bản ghi ĐÃ LƯU trong DB (không phải state form đang sửa dở) — badge
    // phải ưu tiên cảnh báo "còn nợ phát sinh" (extra_charge_amount chưa thu, xem
    // EditOrder::handlePriceDiff()) NGAY CẢ KHI status vẫn là 'paid', vì status 'paid' chỉ phản
    // ánh đơn phòng gốc đã trả đủ — khoản phát sinh sau khi sửa đơn là 1 nghĩa vụ thanh toán
    // RIÊNG, không tự động đổi status. Trước đây badge chỉ nhìn status nên vẫn hiện "Đã thanh
    // toán" (xanh) dù đơn đang còn nợ khoản phát sinh, gây hiểu nhầm cho admin.
    public static function hasUnpaidExtraCharge($record): bool
    {
        return $record
            && (int) ($record->extra_charge_amount ?? 0) > 0
            && is_null($record->extra_charge_paid_at);
    }

    // Khoản CẦN HOÀN cho khách (huỷ khung giờ/dịch vụ làm giá giảm trên đơn đã paid) chưa xử lý —
    // xem ExtraChargeService::recordPendingRefund()/markRefundAsDone().
    public static function hasPendingRefund($record): bool
    {
        return $record
            && (int) ($record->extra_refund_amount ?? 0) > 0
            && is_null($record->extra_refund_paid_at);
    }

    // Nhãn tiếng Việt cho từng khoá trong cccd_data/OrderGuestCccd::cccd_data (JSON do
    // CccdScannerService::parseQrData()/parseOcrText() trích xuất từ ảnh CCCD) — dùng chung cho cả
    // popup "Xem CCCD khách #1" (form) lẫn "Xem CCCD khách #N" (view-only).
    private const CCCD_FIELD_LABELS = [
        'full_name'   => 'Họ và tên',
        'cccd'        => 'Số CCCD/CMND',
        'old_id'      => 'Số CMND cũ',
        'dob'         => 'Ngày sinh',
        'gender'      => 'Giới tính',
        'address'     => 'Địa chỉ',
        'issued_date' => 'Ngày cấp',
    ];

    // Tìm ảnh + dữ liệu CCCD đã trích xuất của ĐÚNG 1 khách theo guest_index — khách #1 luôn là
    // khách chính (buyer, cột thẳng trên bảng orders); khách #2 trở đi tra bảng order_guest_cccds,
    // fallback dữ liệu CŨ (đơn tạo trước khi có bảng này) ở cccd_front_2/cccd_back_2/cccd_data_2 khi
    // guestCccds rỗng. Trả về null nếu khách đó CHƯA TỒN TẠI (dùng để ẩn nút "Xem CCCD khách #N"
    // tương ứng — khác với khách #1 luôn hiện được để còn upload ảnh đầu tiên).
    private static function resolveCccdGuestSource($record, int $guestIndex): ?array
    {
        if (! $record) {
            return null;
        }

        if ($guestIndex === 1) {
            return [
                'front' => $record->cccd_front,
                'back'  => $record->cccd_back,
                'data'  => is_array($record->cccd_data) ? $record->cccd_data : null,
            ];
        }

        $guest = $record->guestCccds->firstWhere('guest_index', $guestIndex);
        if ($guest) {
            return [
                'front' => $guest->cccd_front,
                'back'  => $guest->cccd_back,
                'data'  => is_array($guest->cccd_data) ? $guest->cccd_data : null,
            ];
        }

        if ($guestIndex === 2 && $record->guestCccds->isEmpty()
            && ($record->cccd_front_2 || $record->cccd_back_2 || $record->cccd_data_2)) {
            return [
                'front' => $record->cccd_front_2,
                'back'  => $record->cccd_back_2,
                'data'  => is_array($record->cccd_data_2) ? $record->cccd_data_2 : null,
            ];
        }

        return null;
    }

    // Bảng nhãn/giá trị cho phần "Thông tin đã trích xuất" trong popup xem CCCD.
    private static function renderExtractedCccdData(?array $data): \Illuminate\Support\HtmlString
    {
        $data = $data ? array_filter($data) : [];

        if (empty($data)) {
            return new \Illuminate\Support\HtmlString(
                '<p style="font-size:.75rem;font-style:italic;color:#9ca3af;">Chưa trích xuất được thông tin từ ảnh.</p>'
            );
        }

        $rows = '';
        foreach (self::CCCD_FIELD_LABELS as $key => $label) {
            if (empty($data[$key])) {
                continue;
            }
            $rows .= '<div style="display:flex;justify-content:space-between;gap:.75rem;font-size:.8125rem;'
                . 'border-bottom:1px dashed #e5e7eb;padding-bottom:.35rem;margin-bottom:.35rem;">'
                . '<span style="color:#6b7280;">' . e($label) . '</span>'
                . '<span style="font-weight:600;color:#111827;text-align:right;">' . e($data[$key]) . '</span>'
                . '</div>';
        }

        return new \Illuminate\Support\HtmlString('<div>' . $rows . '</div>');
    }

    // Ảnh CCCD (view-only, dùng trong popup "Xem CCCD khách #N" của khách đi cùng) kèm nhãn + nút
    // tải xuống đè lên góc ảnh — không dùng kỹ thuật xếp chồng CSS Grid như cccdDownloadOverlay() vì
    // đây chỉ là HTML tĩnh trong 1 modal ->modalContent(), không có FileUpload sống nào bên dưới để
    // tranh giành z-index/overflow cả.
    private static function renderCccdImageWithDownload(?string $path, string $label, ?string $orderCode, $recordId): string
    {
        if (! $path) {
            return '';
        }

        $url      = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        $filename = 'CCCD_' . \Illuminate\Support\Str::slug($label) . '_' . ($orderCode ?? $recordId) . '.jpg';

        return '<div style="position:relative;border-radius:.5rem;overflow:hidden;border:1px solid #e5e7eb;">'
            . '<img src="' . e($url) . '" alt="' . e($label) . '" style="width:100%;aspect-ratio:4/3;object-fit:cover;display:block;" />'
            . '<span style="position:absolute;top:.375rem;left:.375rem;font-size:.625rem;font-weight:600;color:#fff;background:#111827;padding:.25rem .5rem;border-radius:9999px;">' . e($label) . '</span>'
            . '<a href="' . e($url) . '" download="' . e($filename) . '" target="_blank" title="Tải ' . e($label) . '"'
            . ' style="position:absolute;top:.375rem;right:.375rem;display:inline-flex;align-items:center;justify-content:center;width:1.75rem;height:1.75rem;border-radius:9999px;background:#111827;color:#fff;">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:.875rem;height:.875rem;">'
            . '<path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>'
            . '</svg></a>'
            . '</div>';
    }

    private static function persistCccdUploadAndScan($record, int $guestIndex, array $data): void
    {
        if (! $record) {
            return;
        }

        $front = array_key_exists('cccd_front', $data) ? $data['cccd_front'] : null;
        $back  = array_key_exists('cccd_back', $data) ? $data['cccd_back'] : null;

        if ($guestIndex === 1) {
            $record->update([
                'cccd_front' => $front,
                'cccd_back'  => $back,
            ]);

            if ($front || $back) {
                $scan = app(\Modules\Payment\App\Services\CccdScannerService::class)->scanPaths($front, $back);
                if ($scan) {
                    $updateFields = ['cccd_data' => $scan];
                    if (! empty($scan['full_name'])) {
                        $updateFields['buyer_name'] = $scan['full_name'];
                    }
                    if (! empty($scan['address'])) {
                        $updateFields['buyer_address'] = $scan['address'];
                    }
                    $record->update($updateFields);
                    app(\App\Services\CccdDeclarationService::class)->upsertFromOrder($record->fresh(['items']));
                }
            }

            return;
        }

        $guest = $record->guestCccds()->firstOrNew([
            'order_id' => $record->id,
            'guest_index' => $guestIndex,
        ]);

        $guest->fill([
            'cccd_front' => $front,
            'cccd_back'  => $back,
        ]);
        $guest->save();

        if ($front || $back) {
            $scan = app(\Modules\Payment\App\Services\CccdScannerService::class)->scanPaths($front, $back);
            if ($scan) {
                $guest->update(['cccd_data' => $scan]);
                app(\App\Services\CccdDeclarationService::class)->upsertFromOrder($record->fresh(['items']));
            }
        }
    }

    private static function shouldShowGuestCccdAction($record, int $guestIndex, ?Get $get = null): bool
    {
        if ($guestIndex === 1) {
            return true;
        }

        if (self::resolveCccdGuestSource($record, $guestIndex) !== null) {
            return true;
        }

        $guestCount = self::resolveGuestCountForCccdVisibility($get, $record);
        if ($guestCount < $guestIndex) {
            return false;
        }

        return self::requiresGuestCccdForCurrentSelection($get, $record);
    }

    // QUAN TRỌNG: $get() ở đây KHÔNG đáng tin cậy khi được gọi từ closure của 1 Action đã MOUNT
    // (vd form/action của buildMemberCccdAction()/buildGuestOneCccdAction()) — Action mounted có
    // state RIÊNG, tách biệt khỏi 'data' của form chính, nên $get('orderItems.0.guest_count') từ
    // trong đó KHÔNG thấy được field 'guest_count' của form chính (đã xác nhận qua thực tế: admin
    // đổi guest_count trong Group A nhưng popup CCCD thành viên vẫn luôn tính ra tối đa 1 người đi
    // cùng). $get() chỉ thật sự đáng tin khi gọi từ 1 field/->visible() NẰM TRỰC TIẾP trong form
    // chính (vd Actions::make(...)->visible() ở cột 1). Cách đáng tin cậy khi gọi từ BÊN TRONG 1
    // Action đã mount là đọc thẳng $livewire->data (property Livewire thật của trang, KHÔNG qua
    // $get()/$set() bị cô lập theo state riêng của Action — cùng kỹ thuật ghi trực tiếp đã dùng ổn
    // định ở HasTimeslotGridSelection::selectTimeslot()) — 'livewire' luôn được Filament tự inject
    // đúng object trang (EditOrder/CreateOrder) cho MỌI closure của Action, kể cả fillForm()/form()
    // (xem MountableAction::resolveDefaultClosureDependencyForEvaluationByName()).
    private static function resolveGuestCountForCccdVisibility(?Get $get, $record, $livewire = null): int
    {
        if ($livewire) {
            $liveValue = data_get($livewire->data ?? [], 'orderItems.0.guest_count');
            if ($liveValue !== null && $liveValue !== '') {
                return (int) $liveValue;
            }
        }

        if ($get) {
            foreach (['guest_count', 'orderItems.0.guest_count'] as $path) {
                $value = $get($path);
                if ($value !== null && $value !== '') {
                    return (int) $value;
                }
            }
        }

        $itemsMax = $record?->items?->max('guest_count');
        if ($itemsMax) {
            return (int) $itemsMax;
        }

        return (int) ($record?->guest_count ?? 1);
    }

    // Số người đi cùng CẦN CCCD tối đa cho popup "CCCD thành viên" (buildMemberCccdAction()) — ưu
    // tiên đọc số khách SỐNG từ $livewire->data (xem ghi chú ở resolveGuestCountForCccdVisibility())
    // để hoạt động NGAY cả khi đơn CHƯA lưu lần nào (tạo đơn mới, $record còn null — trước đây rơi
    // về 0 khiến cả section "Người đi cùng" luôn ẩn lúc tạo đơn, "sai luồng" đã phản ánh), fallback
    // về order_items.guest_count ĐÃ LƯU khi không có $livewire (vd gọi từ nơi khác).
    private static function resolveMaxCompanionsForMember($record, $livewire = null): int
    {
        if ($livewire) {
            $liveValue = data_get($livewire->data ?? [], 'orderItems.0.guest_count');
            if ($liveValue !== null && $liveValue !== '') {
                return max(0, (int) $liveValue - 1);
            }
        }

        if (! $record) {
            return 0;
        }

        $itemsMax   = $record->items?->max('guest_count');
        $guestCount = $itemsMax ?: (int) ($record->guest_count ?? 0);

        return max(0, (int) $guestCount - 1);
    }

    private static function requiresGuestCccdForCurrentSelection(?Get $get, $record): bool
    {
        if ($get) {
            $guestCount = self::resolveGuestCountForCccdVisibility($get, $record);
            if ($guestCount < 2) {
                return false;
            }

            return self::hasOvernightSelection($get);
        }

        if (! $record) {
            return false;
        }

        foreach ($record->items ?? [] as $item) {
            $selectedSlots = $item->selected_slots ?? [];
            if (! is_array($selectedSlots)) {
                continue;
            }

            foreach ($selectedSlots as $selected) {
                $slotId = $selected['slot_id'] ?? null;
                if (! $slotId) {
                    continue;
                }

                $slot = RoomTimeSlot::find($slotId);
                if ($slot?->over_night) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasOvernightSelection(Get $get): bool
    {
        $items = $get('orderItems');
        if (! is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            $productStyle = (int) ($item['product_style'] ?? 1);
            if ($productStyle === 2) {
                return true;
            }

            $selectedSlots = $item['selected_slots'] ?? [];
            if (! is_array($selectedSlots)) {
                continue;
            }

            foreach ($selectedSlots as $selected) {
                $slotId = $selected['slot_id'] ?? null;
                if (! $slotId) {
                    continue;
                }

                $slot = RoomTimeSlot::find($slotId);
                if ($slot?->over_night) {
                    return true;
                }
            }
        }

        return false;
    }

    // Khách #1 = khách chính (buyer) — CCCD nằm thẳng trên bảng orders (cccd_front/cccd_back/
    // cccd_data), an toàn để cho upload/thay ảnh TRỰC TIẾP trong popup (chỉ 1 $record->update(), không
    // đụng tới luồng lưu order_guest_cccds phức tạp của các khách đi cùng — xem
    // buildGuestViewOnlyCccdAction() bên dưới, khách #2 trở đi vẫn phải upload qua popup riêng).
    //
    // QUAN TRỌNG: form của Action (->form()) được Filament render ở 1 vị trí RIÊNG trong DOM (cuối
    // trang, xem vendor/filament/actions/resources/views/components/modals.blade.php), KHÔNG nằm
    // trong .order-form-root — CSS scope theo class đó (cccd-upload-compact/cccd-upload-stack, kỹ
    // thuật xếp chồng CSS Grid dùng cho FileUpload ngoài popup) sẽ KHÔNG áp dụng ở đây, khiến ảnh +
    // nút tải hiện sai kích thước/tràn giao diện (đã xác nhận thực tế). Vì vậy ở đây KHÔNG dùng lại
    // kỹ thuật đó — tách rõ 2 phần: ảnh hiện tại + nút tải (renderCccdImageWithDownload(), 100% inline
    // style, không phụ thuộc CSS ngoài nên luôn đúng kích thước dù render ở đâu) hiển thị TRƯỚC, và
    // FileUpload để THAY ảnh mới nằm RIÊNG bên dưới với ->label() bình thường của Filament.
    private static function buildGuestOneCccdAction(): \Filament\Forms\Components\Actions\Action
    {
        return \Filament\Forms\Components\Actions\Action::make('view_cccd_guest_1')
            ->label('Xem CCCD khách #1')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->size('sm')
            ->visible(fn (Get $get, $record) => self::shouldShowGuestCccdAction($record, 1, $get))
            ->modalHeading('CCCD — Khách #1 (khách chính)')
            ->modalWidth('4xl')
            ->fillForm(fn ($record) => [
                'cccd_front' => $record?->cccd_front,
                'cccd_back'  => $record?->cccd_back,
            ])
            ->form([
                // Cột 1: thông tin đã trích xuất. Cột 2: ảnh mặt trước/sau CHUNG 1 hàng, rồi khung
                // upload để thay ảnh mặt trước/sau cũng CHUNG 1 hàng riêng bên dưới — modal mở rộng
                // ('4xl') để 2 hàng đó đủ chỗ hiển thị rõ ràng, không còn xếp lộn xộn từng ảnh/khung
                // riêng lẻ theo chiều dọc như trước.
                //
                // QUAN TRỌNG: Grid::setUp() TỰ ĐỘNG gọi ->columnSpan('full') (giống hệt Section, xem
                // vendor/filament/forms/src/Components/Grid.php:39) — 2 Grid::make(1) con dưới đây
                // PHẢI tự ->columnSpan(1) đè lại, nếu không cả 2 đều chiếm trọn hàng và xếp DỌC thay
                // vì nằm cạnh nhau (đã xác nhận thực tế: popup hiện ra chỉ 1 cột).
                Grid::make(2)->schema([
                    Grid::make(1)->columnSpan(1)->schema([
                        Placeholder::make('cccd_extracted_1')
                            ->label('Thông tin đã trích xuất')
                            ->content(fn ($record) => self::renderExtractedCccdData($record?->cccd_data)),
                    ]),

                    Grid::make(1)->columnSpan(1)->schema([
                        // Hàng 1: ảnh hiện tại (mặt trước + mặt sau) CHUNG hàng.
                        Grid::make(2)->schema([
                            Placeholder::make('cccd_preview_front_1')
                                ->label('Mặt trước — ảnh hiện tại')
                                ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                                    self::renderCccdImageWithDownload($record?->cccd_front, 'Mặt trước', $record?->order_code, $record?->id)
                                ))
                                ->visible(fn ($record) => (bool) $record?->cccd_front),

                            Placeholder::make('cccd_preview_back_1')
                                ->label('Mặt sau — ảnh hiện tại')
                                ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                                    self::renderCccdImageWithDownload($record?->cccd_back, 'Mặt sau', $record?->order_code, $record?->id)
                                ))
                                ->visible(fn ($record) => (bool) $record?->cccd_back),
                        ]),

                        // Hàng 2: khung tải/thay ảnh mới (mặt trước + mặt sau) CHUNG hàng.
                        Grid::make(2)->schema([
                            FileUpload::make('cccd_front')
                                ->label(fn ($record) => $record?->cccd_front ? 'Thay ảnh mặt trước' : 'Tải ảnh mặt trước')
                                ->image()
                                ->directory('cccd')
                                ->imagePreviewHeight('80')
                                ->panelLayout('integrated')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                ->maxSize(10240)
                                ->nullable(),

                            FileUpload::make('cccd_back')
                                ->label(fn ($record) => $record?->cccd_back ? 'Thay ảnh mặt sau' : 'Tải ảnh mặt sau')
                                ->image()
                                ->directory('cccd')
                                ->imagePreviewHeight('80')
                                ->panelLayout('integrated')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                ->maxSize(10240)
                                ->nullable(),
                        ]),
                    ]),
                ]),
            ])
            ->action(function (array $data, $record) {
                if (! $record) {
                    return;
                }

                self::persistCccdUploadAndScan($record, 1, $data);
                $record->refresh();

                \Filament\Notifications\Notification::make()
                    ->title('Đã lưu CCCD khách #1')
                    ->success()
                    ->send();
            });
    }

    // Khách #2 trở đi — có thể upload ảnh CCCD và hệ thống sẽ tự quét thông tin từ mặt trước/sau
    // (đủ khả năng đọc QR ở bất kỳ mặt nào) rồi lưu vào bảng order_guest_cccds.
    private static function buildGuestViewOnlyCccdAction(int $guestIndex): \Filament\Forms\Components\Actions\Action
    {
        return \Filament\Forms\Components\Actions\Action::make('view_cccd_guest_' . $guestIndex)
            ->label('CCCD khách #' . $guestIndex)
            ->icon('heroicon-o-identification')
            ->color('gray')
            ->size('sm')
            ->visible(fn (Get $get, $record) => self::shouldShowGuestCccdAction($record, $guestIndex, $get))
            ->modalHeading('CCCD — Khách #' . $guestIndex)
            ->modalWidth('4xl')
            ->fillForm(fn ($record) => [
                'cccd_front' => self::resolveCccdGuestSource($record, $guestIndex)['front'] ?? null,
                'cccd_back'  => self::resolveCccdGuestSource($record, $guestIndex)['back'] ?? null,
            ])
            ->form([
                Grid::make(2)->schema([
                    Grid::make(1)->columnSpan(1)->schema([
                        Placeholder::make('cccd_extracted_' . $guestIndex)
                            ->label('Thông tin đã trích xuất')
                            ->content(fn ($record) => self::renderExtractedCccdData(self::resolveCccdGuestSource($record, $guestIndex)['data'] ?? null)),
                    ]),

                    Grid::make(1)->columnSpan(1)->schema([
                        Grid::make(2)->schema([
                            Placeholder::make('cccd_preview_front_' . $guestIndex)
                                ->label('Mặt trước — ảnh hiện tại')
                                ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                                    self::renderCccdImageWithDownload(self::resolveCccdGuestSource($record, $guestIndex)['front'] ?? null, 'Mặt trước', $record?->order_code, $record?->id)
                                ))
                                ->visible(fn ($record) => (bool) (self::resolveCccdGuestSource($record, $guestIndex)['front'] ?? null)),

                            Placeholder::make('cccd_preview_back_' . $guestIndex)
                                ->label('Mặt sau — ảnh hiện tại')
                                ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                                    self::renderCccdImageWithDownload(self::resolveCccdGuestSource($record, $guestIndex)['back'] ?? null, 'Mặt sau', $record?->order_code, $record?->id)
                                ))
                                ->visible(fn ($record) => (bool) (self::resolveCccdGuestSource($record, $guestIndex)['back'] ?? null)),
                        ]),

                        Grid::make(2)->schema([
                            FileUpload::make('cccd_front')
                                ->label(fn ($record) => (self::resolveCccdGuestSource($record, $guestIndex)['front'] ?? null) ? 'Thay ảnh mặt trước' : 'Tải ảnh mặt trước')
                                ->image()
                                ->directory('cccd')
                                ->imagePreviewHeight('80')
                                ->panelLayout('integrated')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                ->maxSize(10240)
                                ->nullable(),

                            FileUpload::make('cccd_back')
                                ->label(fn ($record) => (self::resolveCccdGuestSource($record, $guestIndex)['back'] ?? null) ? 'Thay ảnh mặt sau' : 'Tải ảnh mặt sau')
                                ->image()
                                ->directory('cccd')
                                ->imagePreviewHeight('80')
                                ->panelLayout('integrated')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                ->maxSize(10240)
                                ->nullable(),
                        ]),
                    ]),
                ]),
            ])
            ->action(function (array $data, $record) use ($guestIndex) {
                if (! $record) {
                    return;
                }

                self::persistCccdUploadAndScan($record, $guestIndex, $data);
                $record->refresh();

                \Filament\Notifications\Notification::make()
                    ->title('Đã lưu CCCD khách #' . $guestIndex)
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel('Lưu CCCD')
            ->modalCancelActionLabel('Đóng');
    }

    // Khi đơn gắn với 1 THÀNH VIÊN (customer_id đã chọn) — thay dãy nút "Xem CCCD khách #1..#8"
    // (dành cho khách vãng lai) bằng 1 nút DUY NHẤT quản lý CCCD của thành viên + người đi cùng LẤY
    // TỪ HỒ SƠ THÀNH VIÊN (customer_companions — đã có sẵn model/API cho luồng khách tự đặt qua app
    // di động, xem App\Http\Controllers\Api\Admin\CustomerCompanionController — logic thêm mới/kiểm
    // tra trùng ở đây mô phỏng lại chính controller đó). Khi admin CHỌN companion có sẵn để gắn vào
    // đơn, SAO CHÉP cccd_front/cccd_back/cccd_data từ customer_companions sang order_guest_cccds
    // TẠI THỜI ĐIỂM CHỌN (không tạo FK tham chiếu — đơn giữ nguyên ảnh/dữ liệu tại thời điểm đặt,
    // giống cách hệ thống đang snapshot CCCD khách vãng lai — quyết định đã chốt với người dùng).
    // $record?->customer_id là NGUỒN TIN CẬY CHÍNH (Filament luôn inject $record đúng cho các Action
    // gắn trên field, đã dùng ổn định ở buildGuestOneCccdAction()/buildGuestViewOnlyCccdAction()).
    // $get('customer_id') KHÔNG đáng tin khi gọi từ BÊN TRONG ->form()/->fillForm() của 1 Action đã
    // mount (modal 'CCCD thành viên' không có field 'customer_id' riêng, nên $get() ở đây LUÔN trả
    // null) — đơn CHƯA lưu ($record null, đang tạo đơn mới) phải đọc qua $livewire->data['customer_id']
    // (property Livewire thật của trang, KHÔNG bị cô lập theo state riêng của Action — xem ghi chú ở
    // resolveMaxCompanionsForMember()) mới thấy đúng thành viên vừa chọn ở form chính (đã xác nhận
    // qua thực tế: thiếu $livewire khiến danh sách "Người đi cùng đã lưu trong hồ sơ" luôn rỗng lúc
    // tạo đơn mới, dù thành viên đã có sẵn companion trong hồ sơ).
    private static function resolveMemberCustomerId(Get $get, $record, $livewire = null): ?string
    {
        if ($record?->customer_id) {
            return $record->customer_id;
        }

        if ($livewire) {
            $liveValue = data_get($livewire->data ?? [], 'customer_id');
            if (filled($liveValue)) {
                return $liveValue;
            }
        }

        return $get('customer_id') ?: null;
    }

    // Trích xuất CCCD LUÔN đi qua CccdScannerService — nếu model (Customer hoặc CustomerCompanion,
    // cả 2 đều có sẵn cccd_front/cccd_back/cccd_data) đã có ảnh nhưng cccd_data còn trống (thành
    // viên/người đi cùng cũ tạo trước khi có bước tự động quét, hoặc quét lúc tạo bị lỗi), quét lại
    // NGAY tại đây thay vì hiển thị trống, đồng thời LƯU LẠI kết quả để lần sau không cần quét nữa.
    private static function ensureCccdDataScanned($model): ?array
    {
        if (! $model) {
            return null;
        }

        if (! empty($model->cccd_data)) {
            return $model->cccd_data;
        }

        if (! $model->cccd_front && ! $model->cccd_back) {
            return null;
        }

        try {
            $scan = app(\Modules\Payment\App\Services\CccdScannerService::class)->scanPaths($model->cccd_front, $model->cccd_back);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Quét CCCD theo yêu cầu (khi hiển thị) thất bại', [
                'model' => get_class($model),
                'id'    => $model->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($scan) {
            $model->update(['cccd_data' => $scan]);
        }

        return $scan;
    }

    private static function buildMemberCccdAction(): \Filament\Forms\Components\Actions\Action
    {
        return \Filament\Forms\Components\Actions\Action::make('view_cccd_member')
            ->label('CCCD thành viên')
            ->icon('heroicon-o-identification')
            ->color('gray')
            ->size('sm')
            ->visible(fn (Get $get) => (bool) $get('customer_id'))
            ->modalHeading('CCCD — Thành viên')
            ->modalWidth('4xl')
            ->fillForm(function (Get $get, $record, $livewire) {
                $customerId = self::resolveMemberCustomerId($get, $record, $livewire);

                // Danh sách "người đi cùng cho đơn này" KHÔNG còn là field riêng trong ->form() của
                // popup nữa (CheckboxList cũ) — chuyển hẳn ra $livewire->data['member_companion_ids']
                // (đọc/ghi qua $livewire, đáng tin cậy xuyên suốt cả bảng ViewField LẪN các nút
                // Sửa/Xoá/Thêm gọi thẳng wire:click, xem HasMemberCompanionManagement) để bảng hiển
                // thị + Sửa/Xoá hoạt động được dù đơn CHƯA lưu lần nào. Đơn ĐÃ tồn tại thì LUÔN nạp
                // lại đúng danh sách hiện có trong DB mỗi lần mở popup (đảm bảo khớp dữ liệu thật);
                // đơn CHƯA tồn tại thì GIỮ NGUYÊN lựa chọn đã thao tác trong phiên này (nếu đã mở
                // popup trước đó), chỉ khởi tạo rỗng lần đầu.
                if ($record) {
                    $livewire->data['member_companion_ids'] = $record->guestCccds()
                        ->whereNotNull('companion_id')
                        ->pluck('companion_id')
                        ->all();
                } elseif (! array_key_exists('member_companion_ids', $livewire->data ?? [])) {
                    $livewire->data['member_companion_ids'] = [];
                }
                // Panel "Thêm/Sửa người đi cùng" luôn đóng lại mỗi lần mở popup.
                $livewire->data['member_companion_panel_id'] = null;

                return [
                    'member_cccd_front' => Customer::find($customerId)?->cccd_front,
                    'member_cccd_back'  => Customer::find($customerId)?->cccd_back,
                    // Số người đi cùng tối đa — đọc số khách SỐNG qua $livewire->data (xem ghi chú
                    // ở resolveMaxCompanionsForMember()), hoạt động NGAY CẢ KHI đơn CHƯA lưu lần
                    // nào (đang tạo đơn mới, $record còn null — trước đây rơi về 0 khiến cả section
                    // "Người đi cùng" luôn ẩn lúc tạo đơn).
                    'max_companions' => self::resolveMaxCompanionsForMember($record, $livewire),
                ];
            })
            ->form(function () {
                // QUAN TRỌNG: TRÁNH đóng gói (closure "use") các giá trị $customer/$maxCompanions/
                // $companions tính SẴN 1 LẦN ở đây rồi dùng lại trong các field bên dưới — closure
                // ngoài cùng của ->form() được Filament đánh giá ở 1 thời điểm KHÔNG đảm bảo trùng
                // với lúc admin thực sự mở popup (đã xác nhận qua thực tế: guest_count vừa gõ tay
                // trong Group A không được closure ngoài này thấy, khiến maxItems() luôn tính ra 1
                // dù đơn có 3 khách). Mỗi field/closure con dưới đây PHẢI tự nhận Get $get/$record
                // RIÊNG và tự gọi lại self::resolveMemberCustomerId()/resolveGuestCountForCccdVisibility()
                // — các closure cấp field NÀY được Filament đánh giá lại đúng lúc render/tương tác,
                // luôn thấy đúng state sống.
                return [
                    Grid::make(2)->schema([
                        Grid::make(1)->columnSpan(1)->schema([
                            Placeholder::make('member_cccd_extracted')
                                ->label('CCCD thành viên — thông tin đã trích xuất')
                                ->content(fn (Get $get, $record, $livewire) => self::renderExtractedCccdData(
                                    self::ensureCccdDataScanned(Customer::find(self::resolveMemberCustomerId($get, $record, $livewire)))
                                )),
                        ]),

                        Grid::make(1)->columnSpan(1)->schema([
                            Grid::make(2)->schema([
                                Placeholder::make('member_cccd_preview_front')
                                    ->label('Mặt trước — ảnh hiện tại')
                                    ->content(function (Get $get, $record, $livewire) {
                                        $customer = Customer::find(self::resolveMemberCustomerId($get, $record, $livewire));

                                        return new \Illuminate\Support\HtmlString(
                                            self::renderCccdImageWithDownload($customer?->cccd_front, 'Mặt trước', null, $customer?->id)
                                        );
                                    })
                                    ->visible(fn (Get $get, $record, $livewire) => (bool) Customer::find(self::resolveMemberCustomerId($get, $record, $livewire))?->cccd_front),

                                Placeholder::make('member_cccd_preview_back')
                                    ->label('Mặt sau — ảnh hiện tại')
                                    ->content(function (Get $get, $record, $livewire) {
                                        $customer = Customer::find(self::resolveMemberCustomerId($get, $record, $livewire));

                                        return new \Illuminate\Support\HtmlString(
                                            self::renderCccdImageWithDownload($customer?->cccd_back, 'Mặt sau', null, $customer?->id)
                                        );
                                    })
                                    ->visible(fn (Get $get, $record, $livewire) => (bool) Customer::find(self::resolveMemberCustomerId($get, $record, $livewire))?->cccd_back),
                            ]),

                            Grid::make(2)->schema([
                                FileUpload::make('member_cccd_front')
                                    ->label(fn (Get $get, $record, $livewire) => Customer::find(self::resolveMemberCustomerId($get, $record, $livewire))?->cccd_front ? 'Thay ảnh mặt trước' : 'Tải ảnh mặt trước')
                                    ->image()
                                    ->directory('cccd')
                                    ->imagePreviewHeight('80')
                                    ->panelLayout('integrated')
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                    ->maxSize(10240)
                                    ->nullable(),

                                FileUpload::make('member_cccd_back')
                                    ->label(fn (Get $get, $record, $livewire) => Customer::find(self::resolveMemberCustomerId($get, $record, $livewire))?->cccd_back ? 'Thay ảnh mặt sau' : 'Tải ảnh mặt sau')
                                    ->image()
                                    ->directory('cccd')
                                    ->imagePreviewHeight('80')
                                    ->panelLayout('integrated')
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                    ->maxSize(10240)
                                    ->nullable(),
                            ]),
                        ]),
                    ]),

                    Hidden::make('max_companions'),

                    Section::make('Người đi cùng')
                        ->description(function (Get $get) {
                            $max = (int) $get('max_companions');

                            return $max > 0
                                ? 'Tối đa ' . $max . ' người (dựa theo số lượng khách của đơn).'
                                : 'Số lượng khách hiện tại chỉ có 1 — không cần người đi cùng.';
                        })
                        ->visible(fn (Get $get) => (int) $get('max_companions') > 0)
                        ->schema([
                            // Bảng "người đi cùng cho đơn này" (tên + số CCCD + Sửa/Xoá) — giao diện
                            // TỰ CHẾ (view 'payment::components.member-companions-table'), thay cho
                            // CheckboxList/Repeater cũ. Sửa/Xoá/Thêm gọi thẳng wire:click vào trait
                            // HasMemberCompanionManagement (ghi trực tiếp $livewire->data), cùng kỹ
                            // thuật đã dùng ổn định ở "Dịch vụ đã chọn" (order-services-list.blade.php).
                            ViewField::make('member_companions_display')
                                ->label('')
                                ->live()
                                ->dehydrated(false)
                                ->view('payment::components.member-companions-table')
                                ->viewData(function (Get $get, $record, $livewire) {
                                    $selectedIds = data_get($livewire->data ?? [], 'member_companion_ids', []);
                                    $selectedIds = is_array($selectedIds) ? $selectedIds : [];

                                    $customer = Customer::find(self::resolveMemberCustomerId($get, $record, $livewire));

                                    // Hiện TẤT CẢ người đi cùng ĐÃ CÓ SẴN trong hồ sơ thành viên
                                    // (customer_companions) — không chỉ những người đã gắn vào đơn
                                    // này — để admin chọn nhanh lại (không phải tải ảnh quét lại từ
                                    // đầu mỗi lần đặt đơn mới cho cùng 1 thành viên). Sắp người ĐÃ
                                    // chọn cho đơn này lên đầu bảng.
                                    $allCompanions = $customer
                                        ? $customer->companions()->get()->sortByDesc(fn (CustomerCompanion $c) => in_array($c->id, $selectedIds, true))->values()
                                        : collect();

                                    return [
                                        'companions'    => $allCompanions,
                                        'selectedIds'   => $selectedIds,
                                        'maxCompanions' => (int) $get('max_companions'),
                                    ];
                                }),

                            // Panel "Thêm mới"/"Sửa ảnh" — CHỈ hiện khi bấm nút tương ứng trong bảng
                            // ở trên (member_companion_panel_id: null = ẩn, 'new' = đang thêm, hoặc
                            // = companion_id đang sửa). Dùng CHUNG 2 field upload cho cả 2 trường
                            // hợp, phân biệt bằng panel_id lúc submit (xem Action 'save_companion_panel').
                            Section::make(fn ($livewire) => data_get($livewire->data ?? [], 'member_companion_panel_id') === 'new'
                                ? 'Thêm người đi cùng mới'
                                : 'Sửa ảnh CCCD người đi cùng')
                                ->description('Tải ảnh CCCD 2 mặt — hệ thống tự động quét thông tin.')
                                ->icon('heroicon-o-user-plus')
                                ->visible(fn ($livewire) => filled(data_get($livewire->data ?? [], 'member_companion_panel_id')))
                                ->schema([
                                    Grid::make(2)->schema([
                                        FileUpload::make('new_companion_front')
                                            ->label('Mặt trước')
                                            ->image()
                                            ->directory('cccd')
                                            ->imagePreviewHeight('80')
                                            ->panelLayout('integrated')
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                            ->maxSize(10240)
                                            ->nullable(),

                                        FileUpload::make('new_companion_back')
                                            ->label('Mặt sau')
                                            ->image()
                                            ->directory('cccd')
                                            ->imagePreviewHeight('80')
                                            ->panelLayout('integrated')
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                            ->maxSize(10240)
                                            ->nullable(),
                                    ]),

                                    \Filament\Forms\Components\Actions::make([
                                        \Filament\Forms\Components\Actions\Action::make('save_companion_panel')
                                            ->label('Lưu người đi cùng này')
                                            ->color('primary')
                                            ->action(function (Get $get, Set $set, $record, $livewire) {
                                                self::saveMemberCompanionPanel($get, $set, $record, $livewire);
                                            }),
                                        \Filament\Forms\Components\Actions\Action::make('cancel_companion_panel')
                                            ->label('Huỷ')
                                            ->color('gray')
                                            ->action(function (Set $set, $livewire) {
                                                $set('new_companion_front', null);
                                                $set('new_companion_back', null);
                                                $livewire->data['member_companion_panel_id'] = null;
                                            }),
                                    ]),
                                ]),
                        ]),
                ];
            })
            ->action(function (array $data, Get $get, $record, $livewire) {
                $customer = Customer::find(self::resolveMemberCustomerId($get, $record, $livewire));
                if (! $customer) {
                    return;
                }

                // CCCD của chính thành viên — lưu thẳng vào Customer (KHÔNG phụ thuộc $record, tái
                // dùng được cho MỌI đơn sau này của thành viên đó, giống scanCustomer()).
                $memberFront = $data['member_cccd_front'] ?? null;
                $memberBack  = $data['member_cccd_back'] ?? null;

                if ($memberFront || $memberBack) {
                    $newFront = $memberFront ?: $customer->cccd_front;
                    $newBack  = $memberBack ?: $customer->cccd_back;

                    $scan = null;
                    try {
                        $scan = app(\Modules\Payment\App\Services\CccdScannerService::class)->scanPaths($newFront, $newBack);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Quét CCCD thành viên thất bại', [
                            'customer_id' => $customer->id,
                            'error'       => $e->getMessage(),
                        ]);
                    }

                    $customer->update(array_filter([
                        'cccd_front' => $newFront,
                        'cccd_back'  => $newBack,
                        'cccd_data'  => $scan,
                    ], fn ($v) => $v !== null));
                }

                // Danh sách người đi cùng đang chọn cho đơn này — quản lý qua bảng (Sửa/Xoá/Thêm ở
                // trên, xem HasMemberCompanionManagement), luôn đọc thẳng từ $livewire->data (nguồn
                // SỐNG duy nhất, không còn field 'companion_ids' riêng trong ->form() nữa).
                $companionIds = data_get($livewire->data ?? [], 'member_companion_ids', []);
                $companionIds = is_array($companionIds) ? $companionIds : [];

                if ($record) {
                    // Đơn ĐÃ tồn tại (đang sửa) — gắn NGAY vào order_guest_cccds như trước.
                    $attachedCount = self::persistMemberCompanionsToOrder($record, $companionIds);

                    $title = 'Đã lưu CCCD thành viên';
                    if ($attachedCount > 0) {
                        $title .= ' và ' . $attachedCount . ' người đi cùng';
                    }

                    \Filament\Notifications\Notification::make()->title($title)->success()->send();

                    return;
                }

                // Đơn CHƯA tồn tại (đang tạo đơn mới) — CHƯA có order_id để gắn vào
                // order_guest_cccds, nên hoãn lại: $livewire->data['member_companion_ids'] đã sẵn
                // đúng danh sách (được bảng/panel ở trên duy trì suốt phiên), xử lý thật ở
                // CreateOrder::afterCreate() ngay khi đơn vừa có ID (xem
                // self::persistMemberCompanionsToOrder() gọi từ đó).
                $title = 'Đã lưu CCCD thành viên';
                if (count($companionIds) > 0) {
                    $title .= ' — sẽ gắn ' . count($companionIds) . ' người đi cùng ngay khi tạo đơn';
                }

                \Filament\Notifications\Notification::make()->title($title)->success()->send();
            })
            ->modalSubmitActionLabel('Lưu')
            ->modalCancelActionLabel('Đóng');
    }

    // Xử lý nút "Lưu người đi cùng này" trong panel Thêm/Sửa (bên trong popup "CCCD thành viên") —
    // panel_id = 'new' thì tạo companion MỚI + thêm vào $livewire->data['member_companion_ids'];
    // panel_id = 1 companion_id có sẵn thì SỬA (thay ảnh + quét lại) companion đó, không đổi danh
    // sách. Cả 2 trường hợp đều KHÔNG đụng tới order_guest_cccds — bảng chỉ là danh sách "đang
    // chọn cho đơn này", việc gắn thật vào đơn chỉ xảy ra khi bấm "Lưu" của popup (xem
    // persistMemberCompanionsToOrder() ở ->action() bên trên).
    private static function saveMemberCompanionPanel(Get $get, Set $set, $record, $livewire): void
    {
        $panelId = data_get($livewire->data ?? [], 'member_companion_panel_id');
        if (! $panelId) {
            return;
        }

        // $get() trả TRẠNG THÁI THÔ của FileUpload — dạng mảng [uuid => path] (KHÔNG phải chuỗi
        // path đơn thuần) — chỉ được Filament tự chuyển thành string qua dehydrateStateUsing() khi
        // đi qua $data của ->action() bình thường (form submit thật sự), không áp dụng cho $get()
        // gọi tay như ở đây. Tự lấy phần tử đầu tiên để ra đúng path.
        $normalizeUpload = fn ($value) => is_array($value) ? (array_values($value)[0] ?? null) : $value;
        $front = $normalizeUpload($get('new_companion_front'));
        $back  = $normalizeUpload($get('new_companion_back'));

        if (! $front || ! $back) {
            \Filament\Notifications\Notification::make()->title('Vui lòng tải đủ ảnh 2 mặt CCCD')->warning()->send();

            return;
        }

        $customer = Customer::find(self::resolveMemberCustomerId($get, $record, $livewire));
        if (! $customer) {
            return;
        }

        if (self::cccdSidesConflict($front, $back)) {
            \Filament\Notifications\Notification::make()
                ->title('Ảnh 2 mặt không khớp')
                ->body('Có thể đã chụp nhầm CCCD của 2 người khác nhau.')
                ->danger()
                ->send();

            return;
        }

        $scan = null;
        try {
            $scan = app(\Modules\Payment\App\Services\CccdScannerService::class)->scanPaths($front, $back);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Quét CCCD người đi cùng (thành viên) thất bại', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }

        if ($panelId === 'new') {
            $maxCompanions = (int) $get('max_companions');
            $currentIds    = data_get($livewire->data ?? [], 'member_companion_ids', []);
            $currentIds    = is_array($currentIds) ? $currentIds : [];

            if ($maxCompanions > 0 && count($currentIds) >= $maxCompanions) {
                \Filament\Notifications\Notification::make()
                    ->title('Đã đạt tối đa ' . $maxCompanions . ' người đi cùng')
                    ->warning()
                    ->send();

                return;
            }

            if (self::cccdIsDuplicateForCustomer($customer, $scan)) {
                \Filament\Notifications\Notification::make()
                    ->title('Trùng CCCD')
                    ->body('Người này đã là chính thành viên hoặc đã có trong danh sách người đi cùng.')
                    ->danger()
                    ->send();

                return;
            }

            $companion = $customer->companions()->create([
                'full_name'  => $scan['full_name'] ?? null,
                'cccd_front' => $front,
                'cccd_back'  => $back,
                'cccd_data'  => $scan,
            ]);

            $currentIds[] = $companion->id;
            $livewire->data['member_companion_ids'] = $currentIds;

            \Filament\Notifications\Notification::make()->title('Đã thêm người đi cùng')->success()->send();
        } else {
            $companion = CustomerCompanion::find($panelId);
            if (! $companion || $companion->customer_id !== $customer->id) {
                return;
            }

            $companion->update([
                'full_name'  => $scan['full_name'] ?? $companion->full_name,
                'cccd_front' => $front,
                'cccd_back'  => $back,
                'cccd_data'  => $scan,
            ]);

            \Filament\Notifications\Notification::make()->title('Đã cập nhật ảnh CCCD')->success()->send();
        }

        $set('new_companion_front', null);
        $set('new_companion_back', null);
        $livewire->data['member_companion_panel_id'] = null;
    }

    // Gắn danh sách người đi cùng (companion_id) đã chọn vào order_guest_cccds cho 1 đơn CỤ THỂ —
    // SAO CHÉP cccd_front/cccd_back/cccd_data từ customer_companions TẠI THỜI ĐIỂM GẮN (snapshot,
    // không tạo FK tham chiếu — xem ghi chú ở buildMemberCccdAction()). Dùng CHUNG cho cả lúc admin
    // bấm "Lưu" ngay trong popup khi ĐANG SỬA đơn đã tồn tại, LẪN lúc CreateOrder::afterCreate() xử
    // lý danh sách đã hoãn lại ($livewire->data['member_companion_ids']) ngay khi đơn VỪA có
    // order_id thật.
    // Trả về số người đã gắn thành công.
    public static function persistMemberCompanionsToOrder($record, array $companionIds): int
    {
        if (empty($companionIds)) {
            $deletedStale = OrderGuestCccd::where('order_id', $record->id)->where('guest_index', '>=', 2)->delete();
            if ($deletedStale > 0) {
                app(\App\Services\CccdDeclarationService::class)->upsertFromOrder($record->fresh(['items']));
            }

            return 0;
        }

        $companions = CustomerCompanion::whereIn('id', $companionIds)
            ->where('customer_id', $record->customer_id)
            ->get()
            ->keyBy('id');

        $guestIndex = 2;
        $attached   = 0;

        foreach ($companionIds as $id) {
            $companion = $companions->get($id);
            if (! $companion) {
                continue;
            }

            OrderGuestCccd::updateOrCreate(
                ['order_id' => $record->id, 'guest_index' => $guestIndex],
                [
                    // Ghi lại ĐÚNG companion nguồn — thiếu cột này thì lần mở popup SAU không biết
                    // đã chọn ai trước đó, bảng "Người đi cùng" LUÔN hiện trống dù đã lưu (xem
                    // ->fillForm() ở buildMemberCccdAction()).
                    'companion_id' => $companion->id,
                    'cccd_front'   => $companion->cccd_front,
                    'cccd_back'    => $companion->cccd_back,
                    'cccd_data'    => $companion->cccd_data,
                ]
            );
            $guestIndex++;
            $attached++;
        }

        // Đồng bộ ĐẦY ĐỦ: xoá các dòng THỪA còn sót từ lần lưu TRƯỚC — vd trước đó chọn 3 người đi
        // cùng (guest_index 2,3,4), lần này bỏ bớt chỉ còn chọn 1 (guest_index 2) — nếu không xoá,
        // guest_index 3/4 vẫn giữ CCCD của người ĐÃ BỊ BỎ CHỌN, "Khai báo lưu trú" vẫn hiện thừa
        // người không có mặt thật.
        $deletedStale = OrderGuestCccd::where('order_id', $record->id)
            ->where('guest_index', '>=', $guestIndex)
            ->delete();

        if ($attached > 0 || $deletedStale > 0) {
            app(\App\Services\CccdDeclarationService::class)->upsertFromOrder($record->fresh(['items']));
        }

        return $attached;
    }

    // Ảnh mặt trước/sau lệch người (vd chụp nhầm 2 người khác nhau) — quét ĐỘC LẬP từng mặt rồi so
    // số CCCD (ưu tiên) hoặc họ tên đã chuẩn hoá nếu 1 trong 2 mặt không đọc được số. Cùng logic đã
    // dùng ở CustomerCompanionController::assertSidesMatch() (luồng admin app di động) — mô phỏng
    // lại ở đây vì Filament Action chạy ngay trong process, không cần gọi qua HTTP.
    private static function cccdSidesConflict(string $frontPath, string $backPath): bool
    {
        try {
            return app(\Modules\Payment\App\Services\CccdScannerService::class)->sidesConflict(
                \Illuminate\Support\Facades\Storage::disk('public')->path($frontPath),
                \Illuminate\Support\Facades\Storage::disk('public')->path($backPath),
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    // Chặn lưu trùng: 1 người vừa là chính thành viên vừa là "người đi cùng" của họ, hoặc trùng với
    // 1 companion đã lưu sẵn khác — cùng logic CustomerCompanionController::assertNoCccdDuplicate().
    // Chỉ so khi quét ra được số CCCD (quét lỗi thì bỏ qua check, CCCD vốn là dữ liệu tuỳ chọn).
    private static function cccdIsDuplicateForCustomer(Customer $customer, ?array $scan): bool
    {
        $cccd = trim((string) ($scan['cccd'] ?? ''));
        if ($cccd === '') {
            return false;
        }

        $customerCccd = trim((string) ($customer->cccd_data['cccd'] ?? ''));
        if ($customerCccd !== '' && $customerCccd === $cccd) {
            return true;
        }

        return $customer->companions()
            ->get()
            ->contains(fn (CustomerCompanion $c) => trim((string) ($c->cccd_data['cccd'] ?? '')) === $cccd);
    }

    // "Lịch sử thanh toán" — nằm CHUNG cột với Mã cổng (xem Grid::make(1) bọc cả 2 trong
    // OrderForm::form()), LUÔN hiện bất kể mã cổng có hay không (mã cổng chỉ hiện khi đơn 'paid',
    // còn lịch sử thanh toán cần thấy ngay cả lúc đơn mới tạo/đang chờ cọc). Không bọc Section nữa
    // (bỏ khung/tiêu đề theo yêu cầu) — trả thẳng Placeholder, giống cách "Mã cổng" cũng không còn
    // khung riêng. Tách thành method riêng vì nội dung khá dài, dùng đi dùng lại ở 1 chỗ duy nhất.
    private static function buildPaymentTimelineSection(): Placeholder
    {
        return Placeholder::make('payment_timeline')
                    ->label('')
                    ->content(function ($record) {
                        if (!$record) {
                            return new \Illuminate\Support\HtmlString('<p class="text-gray-400 text-sm italic">Chưa có dữ liệu</p>');
                        }

                        // full_amount CỐ ĐỊNH = tổng giá thật của đơn (không phải tiền cọc nữa) —
                        // tiền cọc đã thu lấy qua depositPaidAmount() (deposit_paid_amount nếu
                        // admin đã tự sửa tay, mặc định = depositDueAmount()).
                        $isDeposit   = $record->deposit_percent !== null;
                        $fullAmt     = (int) $record->full_amount;
                        $depositPctF = $isDeposit ? (int)$record->deposit_percent : 0;
                        if ($isDeposit && $depositPctF > 0) {
                            $depositAmt   = $record->depositPaidAmount();
                            $extraChargeF = (int) ($record->extra_charge_amount ?? 0);
                            $remainingAmt = max(0, $fullAmt - $depositAmt) + $extraChargeF;
                        } else {
                            $depositAmt   = null;
                            $remainingAmt = null;
                        }

                        $fmt = fn($dt) => $dt ? \Carbon\Carbon::parse($dt)->format('d/m/Y H:i') : null;

                        if ($isDeposit) {
                            $remainingDone = $record->status === 'paid' && $record->deposit_percent !== null;
                            $remainingTime = $record->remaining_paid_at ? $fmt($record->remaining_paid_at) : null;
                            $qrCreated     = ! empty($record->remaining_checkout_url);
                            $payMethod     = $record->remaining_payment_method ?? null;

                            if ($remainingDone) {
                                if ($payMethod === 'cash') {
                                    $remainingLabel = 'Admin thu tiền mặt';
                                } elseif ($payMethod === 'payos') {
                                    $remainingLabel = 'Khách thanh toán qua PayOS';
                                } elseif ($payMethod === 'bank_transfer') {
                                    $remainingLabel = 'Admin xác nhận đã chuyển khoản';
                                } else {
                                    $remainingLabel = $qrCreated ? 'Khách thanh toán qua QR/PayOS' : 'Admin xác nhận thu tiền mặt';
                                }
                            } else {
                                $remainingLabel = 'Chờ thanh toán phần còn lại';
                            }

                            $steps = [
                                [
                                    'icon_type' => 'banknote',
                                    'label'     => 'Khách đặt cọc',
                                    'time'      => $fmt($record->created_at),
                                    'done'      => true,
                                    'color'     => 'yellow',
                                    'sub'       => $depositAmt ? number_format($depositAmt, 0, ',', '.') . 'đ (' . $record->deposit_percent . '%)' : null,
                                    'sort_time' => $record->created_at,
                                ],
                                [
                                    'icon_type' => 'qr',
                                    'label'     => $qrCreated ? 'QR thanh toán đã được tạo' : 'Chưa tạo QR thanh toán',
                                    'time'      => null,
                                    'done'      => $qrCreated,
                                    'color'     => 'yellow',
                                    'sub'       => null,
                                    // Không có mốc thời gian riêng lưu lại lúc tạo QR — ghim ngay sau
                                    // bước "đặt cọc" (không ảnh hưởng thứ tự hiển thị thực tế).
                                    'sort_time' => $record->created_at,
                                ],
                                [
                                    'icon_type' => $payMethod === 'cash' ? 'banknote' : 'check',
                                    'label'     => $remainingLabel,
                                    'time'      => $remainingTime,
                                    'done'      => $remainingDone,
                                    'color'     => 'green',
                                    'sub'       => ($remainingDone && $remainingAmt) ? number_format($remainingAmt, 0, ',', '.') . 'đ' : null,
                                    'sort_time' => $record->remaining_paid_at ?? $record->updated_at,
                                ],
                            ];
                        } else {
                            // paid_at là mốc CỐ ĐỊNH, chỉ set 1 lần lúc chuyển sang 'paid' (xem
                            // OrderObserver::saving()) — KHÔNG dùng updated_at vì cột đó bị đội
                            // lên ở MỌI lần sửa đơn sau này (thêm/bớt khung giờ...), khiến mốc
                            // "Khách thanh toán đầy đủ" hiển thị sai lệch theo lần sửa gần nhất.
                            $paidAt = $record->status === 'paid' ? $fmt($record->paid_at ?? $record->updated_at) : null;
                            $steps = [
                                [
                                    'icon_type' => 'file',
                                    'label'     => 'Khách tạo đơn',
                                    'time'      => $fmt($record->created_at),
                                    'done'      => true,
                                    'color'     => 'blue',
                                    'sub'       => null,
                                    'sort_time' => $record->created_at,
                                ],
                                [
                                    'icon_type' => 'check',
                                    'label'     => 'Khách thanh toán đầy đủ',
                                    'time'      => $paidAt,
                                    'done'      => $record->status === 'paid',
                                    'color'     => 'green',
                                    'sub'       => $fullAmt ? number_format($fullAmt, 0, ',', '.') . 'đ' : null,
                                    'sort_time' => $record->status === 'paid' ? ($record->paid_at ?? $record->updated_at) : $record->created_at,
                                ],
                            ];
                        }

                        // Lịch sử phát sinh/hoàn tiền qua các lần sửa đơn (thêm/bớt khung
                        // giờ, dịch vụ, số khách) — orders.extra_charge_amount chỉ lưu ĐÚNG
                        // giá trị MỚI NHẤT (bị ghi đè mỗi lần có thay đổi), không tự giữ lại
                        // lịch sử nhiều lần phát sinh qua thời gian, nên đọc từ AuditLog (xem
                        // EditOrder::handlePriceDiff()) để người quản lý xem lại đầy đủ từng
                        // lần tăng/giảm, ai sửa, lúc nào.
                        $priceAdjustments = \Modules\AuditLog\Entities\AuditLog::where('module', 'Order')
                            ->where('target_id', (string) $record->id)
                            ->where('action', 'price_adjustment')
                            ->orderBy('created_at')
                            ->get();

                        foreach ($priceAdjustments as $adj) {
                            $adjDiff = (int) ($adj->new_values['diff'] ?? 0);
                            if ($adjDiff === 0) {
                                continue;
                            }

                            // Hiện NGUYÊN NHÂN phát sinh (thêm khung giờ/dịch vụ/người) thay vì
                            // tên admin đã sửa đơn — admin là nội bộ, người xem lịch sử chỉ cần
                            // biết ĐÃ XẢY RA CHUYỆN GÌ (xem EditOrder::buildChangeSummary()). Log
                            // cũ trước khi có field này thì fallback về nhãn chung chung.
                            $changeSummary = $adj->new_values['change_summary'] ?? [];
                            $label = (is_array($changeSummary) && ! empty($changeSummary))
                                ? implode(' · ', $changeSummary)
                                : ($adjDiff > 0 ? 'Phát sinh thêm' : 'Hoàn tiền');

                            $steps[] = [
                                'icon_type' => $adjDiff > 0 ? 'banknote' : 'check',
                                'label'     => $label,
                                'time'      => $fmt($adj->created_at),
                                'done'      => true,
                                'color'     => $adjDiff > 0 ? 'yellow' : 'green',
                                'sub'       => ($adjDiff > 0 ? '+' : '') . number_format($adjDiff, 0, ',', '.') . 'đ',
                                // Ghi tạm dấu +/- để bên dưới xác định dòng NÀO là lần phát sinh/
                                // hoàn tiền GẦN NHẤT còn cần xử lý — chỉ dòng đó mới gắn nút hành
                                // động (không lặp lại nút ở các dòng lịch sử cũ đã xử lý xong).
                                'diff'      => $adjDiff,
                                'sort_time' => $adj->created_at,
                            ];
                        }

                        // Xác nhận "Tạo QR/Đã chuyển khoản/Đã thu tiền mặt phát sinh" và "Đã
                        // hoàn tiền" (từ EditOrder::quick*()/header actions) trước đây ghi
                        // AuditLog với action='update' — nhưng query ở trên CHỈ lọc
                        // action='price_adjustment', nên các xác nhận này ÂM THẦM không hiện
                        // ra ở đây dù đã lưu đúng vào DB (đã xác nhận qua audit_logs thực tế:
                        // "Xác nhận thu tiền mặt phát sinh" vẫn được ghi, chỉ là bị query này
                        // bỏ sót). Nhận diện qua các nhãn CỐ ĐỊNH đã dùng khi ghi log đó.
                        $confirmationLabels = [
                            'Tạo QR phát sinh', 'Xác nhận chuyển khoản phát sinh',
                            'Xác nhận thu tiền mặt phát sinh',
                            'Đã hoàn tiền (chuyển khoản)', 'Đã hoàn tiền (tiền mặt)',
                        ];
                        $confirmationLogs = \Modules\AuditLog\Entities\AuditLog::where('module', 'Order')
                            ->where('target_id', (string) $record->id)
                            ->where('action', 'update')
                            ->orderBy('created_at')
                            ->get();

                        foreach ($confirmationLogs as $log) {
                            $newValues = is_array($log->new_values) ? $log->new_values : [];
                            $matchedLabel = null;
                            foreach ($confirmationLabels as $candidate) {
                                if (array_key_exists($candidate, $newValues)) {
                                    $matchedLabel = $candidate;
                                    break;
                                }
                            }

                            if ($matchedLabel === null) {
                                continue;
                            }

                            $steps[] = [
                                'icon_type' => 'check',
                                'label'     => $matchedLabel,
                                'time'      => $fmt($log->created_at),
                                'done'      => true,
                                'color'     => 'green',
                                'sub'       => (string) $newValues[$matchedLabel],
                                'sort_time' => $log->created_at,
                            ];
                        }

                        // Sắp xếp lại TOÀN BỘ dòng (mốc gốc + phát sinh/hoàn tiền) theo ĐÚNG
                        // thời gian thực (sort_time) — trước đây các mốc gốc (tạo đơn/thanh
                        // toán) luôn đứng ĐẦU mảng bất kể thời điểm thật, còn dòng phát sinh
                        // luôn nối ĐUÔI theo thứ tự thêm vào, nên nếu đơn được sửa (thêm/bớt
                        // khung giờ) SAU KHI đã thanh toán, mốc "Khách thanh toán đầy đủ" có
                        // thể hiện đứng SAI VỊ TRÍ (trước hoặc xen giữa) so với các dòng phát
                        // sinh thực sự xảy ra trước/sau nó.
                        usort($steps, fn ($a, $b) => ($a['sort_time'] ?? now()) <=> ($b['sort_time'] ?? now()));

                        // Đơn ĐANG có khoản phát sinh/hoàn tiền CHƯA xử lý (xem
                        // hasUnpaidExtraCharge()/hasPendingRefund()) — tìm dòng lịch sử GẦN
                        // NHẤT khớp chiều tăng/giảm để gắn nút "Tạo QR/Đã chuyển khoản/Đã thu
                        // tiền mặt" NGAY tại đó, thay vì bắt admin phải kéo xuống Section riêng
                        // ("Phát sinh thêm"/"Hoàn tiền chưa xử lý") mới xử lý được.
                        $pendingExtraChargeStepIndex = null;
                        $pendingRefundStepIndex     = null;
                        if (self::hasUnpaidExtraCharge($record) || self::hasPendingRefund($record)) {
                            foreach ($steps as $stepIdx => $stepRow) {
                                $stepDiff = $stepRow['diff'] ?? null;
                                if ($stepDiff === null) {
                                    continue;
                                }
                                if ($stepDiff > 0 && self::hasUnpaidExtraCharge($record)) {
                                    $pendingExtraChargeStepIndex = $stepIdx;
                                }
                                if ($stepDiff < 0 && self::hasPendingRefund($record)) {
                                    $pendingRefundStepIndex = $stepIdx;
                                }
                            }
                        }

                        // Dạng LIST gọn — không còn chấm tròn màu + icon + đường nối dọc (theo yêu
                        // cầu "bỏ icon/badge chứa icon"), tham khảo đúng phong cách hàng trong
                        // "Xem chi tiết từng khung giờ" (total-amount-card.blade.php): mỗi thao tác
                        // là 1 hàng phẳng, viền đứt phân cách, nhãn+giờ bên trái, số tiền (nếu có)
                        // bên phải — dễ nhìn lại toàn bộ lịch sử thao tác trên đơn.
                        $html = '<div>';
                        foreach ($steps as $i => $step) {
                            $isLast     = $i === count($steps) - 1;
                            $labelColor = $step['done'] ? '#111827' : '#9ca3af';

                            $timeHtml = $step['time'] && $step['time'] !== '(Không rõ thời điểm)'
                                ? '<span style="font-size:11px;color:#6b7280;">' . $step['time'] . '</span>'
                                : ($step['done']
                                    ? '<span style="font-size:11px;color:#9ca3af;font-style:italic;">Không rõ thời điểm</span>'
                                    : '<span style="font-size:11px;color:#d1d5db;font-style:italic;">Chưa thực hiện</span>');

                            $subHtml = $step['sub']
                                ? '<span style="font-size:12px;font-weight:700;color:#1e40af;white-space:nowrap;">' . $step['sub'] . '</span>'
                                : '';

                            // Gắn nút hành động NGAY trên dòng lịch sử phát sinh/hoàn tiền gần
                            // nhất còn chờ xử lý — wire:click gọi thẳng EditOrder::quick*() (xem
                            // EditOrder.php), không cần điều hướng xuống Section riêng bên dưới.
                            $actionsHtml = '';
                            $btnStyle2 = fn(string $border, string $bg, string $color) =>
                                "font-size:10px;font-weight:700;padding:3px 8px;border-radius:6px;border:1px solid {$border};background:{$bg};color:{$color};cursor:pointer;font-family:inherit;";
                            $recordKeyPart = $record->id ?? 'new';
                            if ($i === $pendingExtraChargeStepIndex) {
                                $actionsHtml = '
                                    <div style="display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;">
                                        <button type="button" wire:key="tl-qr-' . $recordKeyPart . '" wire:click="quickCreateExtraChargeQr" wire:loading.attr="disabled" wire:target="quickCreateExtraChargeQr" style="' . $btnStyle2('#f59e0b', '#fffbeb', '#b45309') . '">Tạo QR</button>
                                        <button type="button" wire:key="tl-transfer-' . $recordKeyPart . '" wire:click="quickMarkExtraChargeTransfer" wire:loading.attr="disabled" wire:target="quickMarkExtraChargeTransfer" style="' . $btnStyle2('#3b82f6', '#eff6ff', '#1d4ed8') . '">Đã chuyển khoản</button>
                                        <button type="button" wire:key="tl-cash-' . $recordKeyPart . '" wire:click="quickMarkExtraChargeCash" wire:loading.attr="disabled" wire:target="quickMarkExtraChargeCash" style="' . $btnStyle2('#22c55e', '#f0fdf4', '#15803d') . '">Đã thu tiền mặt</button>
                                    </div>';
                            } elseif ($i === $pendingRefundStepIndex) {
                                $actionsHtml = '
                                    <div style="display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;">
                                        <button type="button" wire:key="tl-refund-transfer-' . $recordKeyPart . '" wire:click="quickMarkRefundTransfer" wire:loading.attr="disabled" wire:target="quickMarkRefundTransfer" style="' . $btnStyle2('#3b82f6', '#eff6ff', '#1d4ed8') . '">Đã chuyển khoản</button>
                                        <button type="button" wire:key="tl-refund-cash-' . $recordKeyPart . '" wire:click="quickMarkRefundCash" wire:loading.attr="disabled" wire:target="quickMarkRefundCash" style="' . $btnStyle2('#22c55e', '#f0fdf4', '#15803d') . '">Đã thu tiền mặt</button>
                                    </div>';
                            }

                            $html .= '
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding:6px 0;' . (!$isLast ? 'border-bottom:1px dashed #e2e8f0;' : '') . '">
                                    <div style="min-width:0;">
                                        <div style="font-weight:600;font-size:12.5px;color:' . $labelColor . ';">' . $step['label'] . '</div>
                                        <div style="margin-top:2px;">' . $timeHtml . '</div>
                                        ' . $actionsHtml . '
                                    </div>
                                    ' . $subHtml . '
                                </div>
                            ';
                        }
                        $html .= '</div>';

                        return new \Illuminate\Support\HtmlString($html);
                    });
    }

    // "Mã cổng" chỉ hiện khi đơn đã 'paid' VÀ phòng không dùng khóa thủ công VÀ chi nhánh của phòng
    // đó CÒN tài khoản TTLock đang hoạt động (không có ttlock thì không thể cấp/hiện mã cổng thật sự
    // nào). Khi false, cột mã cổng không còn trống hẳn nữa — vẫn còn Lịch sử thanh toán ở đó (xem
    // buildPaymentTimelineSection()), nên "Thông tin khách hàng" cạnh nó giữ nguyên columnSpan(4).
    private static function hasAccessCodeSection($record): bool
    {
        if (! $record || $record->status !== 'paid') {
            return false;
        }

        $product = $record->items->sortBy('checkin_date')->first()?->product;

        if (! $product) {
            return false;
        }

        // Phòng khóa THỦ CÔNG (has_manual_lock) — chi nhánh không có tài khoản TTLock nên
        // KHÔNG có gì để tra ở bảng access_codes/TTLockService, thay vào đó luôn hiện khung này
        // để hiển thị mật khẩu thủ công (xem manual-lock-info.blade.php bên dưới) — trước đây
        // return false luôn cho case này, khiến cả khung "Mã cổng" biến mất hoàn toàn, không có
        // gì thay thế cho khách/nhân viên xem lại mật khẩu.
        if ($product->has_manual_lock) {
            return true;
        }

        return \App\Services\TTLockService::hasAccountForCategory($record->category_id);
    }

    // super_admin và nhân viên đối tác NỀN TẢNG (vd 365home) đặt phòng hộ mọi đối tác — cần chọn
    // đối tác trước để lọc đúng chi nhánh/phòng, khác nhân viên đối tác thường (chỉ thấy đối
    // tác/chi nhánh của chính mình, không cần chọn).
    private static function isPlatformStaff(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->belongsToPlatformPartner());
    }

    // Danh sách khung giờ (RoomTimeSlot) CỐ ĐỊNH khai báo sẵn cho phòng — không lọc theo ngày cụ
    // thể (đây là định nghĩa dùng lại mỗi ngày, giống cách BlockTimeslotModal đang dùng để chặn
    // lịch). Trả rỗng nếu phòng chưa khai báo khung giờ nào (dùng để fallback về DateTimePicker
    // nhập tự do như trước).
    // Nhớ tạm trong PHẠM VI 1 REQUEST (mảng static, tự reset giữa các request vì dự án không
    // dùng Octane — xem composer.json) — hàm này bị gọi LẶP LẠI với CÙNG 1 $productId nhiều lần
    // trong CÙNG 1 lượt render (2 lần ở ->visible() của 2 Grid khác nhau trong form() + 1 lần
    // trong getTimeslotGridData(), xem các nơi gọi self::getRoomTimeSlots() trong file này), mỗi
    // lần tự chạy lại ~3 query (RoomTimeSlot + eager load timeSlot + promotions) dù dữ liệu chắc
    // chắn không đổi giữa các lần gọi đó — cộng dồn vào đúng độ trễ "chọn khung giờ 1 lúc mới
    // selectable" (mỗi lần bấm là 1 lượt Livewire re-render, tức lại chạy hết các lần gọi này).
    private static array $roomTimeSlotsCache = [];

    private static function getRoomTimeSlots(string $productId): \Illuminate\Support\Collection
    {
        if (! $productId) {
            return collect();
        }

        if (isset(self::$roomTimeSlotsCache[$productId])) {
            return self::$roomTimeSlotsCache[$productId];
        }

        return self::$roomTimeSlotsCache[$productId] = RoomTimeSlot::where('room_id', $productId)
            ->whereNull('date')
            ->with(['timeSlot', 'promotions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->filter(fn (RoomTimeSlot $slot) => $slot->timeSlot !== null)
            // Sắp xếp đúng theo thứ tự thời gian trong ngày (sáng → chiều → tối), không theo id —
            // tránh hiển thị lộn xộn trên lưới chọn khung giờ.
            ->sortBy(fn (RoomTimeSlot $slot) => $slot->timeSlot->start_time)
            ->values();
    }

    // Style 2 (đặt theo ngày) — tính giá phòng GIỐNG HỆT luồng đặt phòng của khách trên web
    // (BookingController::buildDailyItems() + applyDailyPromotions()): mỗi đêm tự tra RoomTimeSlot
    // "type=date" của ĐÚNG phòng này khớp đúng ngày đó (label = "Y-m-d") để lấy giá override riêng
    // ngày (không có thì dùng giá gốc Product::price), rồi áp khuyến mãi ĐANG HOẠT ĐỘNG gắn trên
    // ĐÚNG RoomTimeSlot đó qua PromotionCalculator::calculateForDate() — TRƯỚC ĐÂY admin chỉ nhân
    // giá gốc × số đêm, bỏ qua hoàn toàn giá riêng theo ngày/khuyến mãi mà khách thấy khi tự đặt
    // qua web, khiến "Tổng thanh toán" ở đây luôn cao hơn số tiền thật khách đã trả.
    //
    // @return array{nights:int, total:float, nightly:array<string,float>, uniform_price:?float}
    public static function calculateDailyRoomPrice(?string $productId, ?string $checkin, ?string $checkout): array
    {
        $empty = ['nights' => 0, 'total' => 0.0, 'nightly' => [], 'uniform_price' => null];

        if (! $productId || ! $checkin || ! $checkout) {
            return $empty;
        }

        $product = Product::find($productId);
        if (! $product) {
            return $empty;
        }

        $checkinDate  = \Carbon\Carbon::parse($checkin)->startOfDay();
        $checkoutDate = \Carbon\Carbon::parse($checkout)->startOfDay();
        $nights       = $checkinDate->diffInDays($checkoutDate);

        if ($nights <= 0) {
            return $empty;
        }

        $slotsByDate = RoomTimeSlot::where('room_id', $productId)
            ->whereHas('timeSlot', fn ($q) => $q->where('type', 'date'))
            ->with(['timeSlot', 'promotions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->filter(fn (RoomTimeSlot $slot) => $slot->timeSlot !== null)
            ->keyBy(fn (RoomTimeSlot $slot) => $slot->timeSlot->label);

        $basePrice  = (float) $product->price;
        $calculator = app(\App\Services\PromotionCalculator::class);

        $nightly = [];
        $current = $checkinDate->copy();

        while ($current->lt($checkoutDate)) {
            $dateStr = $current->format('Y-m-d');
            $rts     = $slotsByDate->get($dateStr);

            $nightPrice = $rts?->price !== null ? (float) $rts->price : $basePrice;
            $result     = $calculator->calculateForDate($rts, $nightPrice, $dateStr);

            $nightly[$dateStr] = $result['final_price'];
            $current->addDay();
        }

        $distinctPrices = array_unique($nightly);

        return [
            'nights'        => $nights,
            'total'         => array_sum($nightly),
            'nightly'       => $nightly,
            'uniform_price' => count($distinctPrices) === 1 ? (float) reset($distinctPrices) : null,
        ];
    }

    // Style 2 — giờ nhận/trả phòng THẬT SỰ áp dụng cho phòng: luôn dùng giờ mặc định đã cài trên
    // phòng (Product::default_checkin/default_checkout), fallback '14:00'/'12:00' nếu phòng chưa
    // cấu hình — không tự bịa giờ khác (không dùng 00:00), và KHÔNG override theo từng ngày riêng
    // lẻ (giữ đơn giản, đúng yêu cầu — mọi ngày của 1 phòng đều nhận/trả phòng cùng 1 giờ cố định).
    private static function resolveCheckinCheckoutTime(Product $product): array
    {
        $checkin  = $product->default_checkin  ?? '14:00';
        $checkout = $product->default_checkout ?? '12:00';

        // Cột time trong DB có thể trả về dạng "14:00:00" — chuẩn hoá về "H:i" cho nhất quán.
        return [
            \Carbon\Carbon::parse($checkin)->format('H:i'),
            \Carbon\Carbon::parse($checkout)->format('H:i'),
        ];
    }

    // Style 2 (đặt theo ngày) — map các NGÀY đang bị CHIẾM bởi lượt đặt KHÁC của ĐÚNG phòng này,
    // dùng chung cho cả disabledDates (chặn chọn trúng ngay 1 ngày kín) LẪN kiểm tra chồng lấn
    // (chặn kiểu "nhảy qua" 1 ngày kín ở giữa khoảng). Tính TỪ checkin_date ĐẾN checkout_date, CẢ
    // HAI ĐẦU MÚT — mặc định KHÔNG cho turnover cùng ngày (ngày trả phòng của lượt đặt cũ vẫn bị
    // khoá) — TRỪ khi ngày đó là HÔM NAY và giờ hiện tại đã qua giờ trả phòng thật sự đã cấu hình
    // cho phòng ngày đó (nghĩa là khách chắc chắn đã trả phòng rồi, không còn lý do gì để khoá).
    // Order KHÔNG có FK cascade tới order_items (xem ghi chú ở Order::booted() — xoá đơn qua raw
    // SQL/bulk delete để lại order_item mồ côi, order_id trỏ tới 1 đơn không còn tồn tại). Đơn đã
    // 'failed'/'cancelled_payment'/'refunded' hoặc 'pending' đã hết hạn cũng KHÔNG còn thật sự giữ
    // chỗ. whereHas('order', ...) vừa tự loại order_item mồ côi (không JOIN được thì không tính)
    // vừa chỉ tính đúng đơn CÒN THẬT SỰ CHIẾM CHỖ — thiếu điều kiện này khiến lịch hiện "Đã đặt"
    // cho cả những ngày/khung giờ không hề có đơn nào khi tra lại (đã xác nhận thực tế với phòng
    // LUMEN: 134 order_item mồ côi trỏ tới các đơn #3227-3279 đã bị xoá từ trước).
    private static function applyActiveOrderConstraint(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('order', function ($q) {
            $q->where(function ($inner) {
                $inner->whereIn('status', ['paid', 'deposit'])
                    ->orWhere(function ($pendingQ) {
                        $pendingQ->where('status', 'pending')
                            ->where(function ($expQ) {
                                $expQ->whereNull('expired_at')
                                    ->orWhere('expired_at', '>', now());
                            });
                    });
            });
        });
    }

    private static function getOccupiedDaysForProduct(?string $productId, $currentOrderItemId = null): array
    {
        if (! $productId) {
            return [];
        }

        $product = Product::find($productId);

        $query = self::applyActiveOrderConstraint(
            OrderItem::where('product_id', $productId)
                ->whereNotNull('checkin_date')
                ->whereNotNull('checkout_date')
        );

        if ($currentOrderItemId) {
            $query->where('id', '!=', $currentOrderItemId);
        }

        $today    = now()->format('Y-m-d');
        $nowTime  = now()->format('H:i');
        $occupied = [];

        foreach ($query->get(['checkin_date', 'checkout_date']) as $item) {
            $day = \Carbon\Carbon::parse($item->checkin_date)->startOfDay();
            $end = \Carbon\Carbon::parse($item->checkout_date)->startOfDay();

            while ($day->lte($end)) {
                $dateStr = $day->format('Y-m-d');

                if ($day->equalTo($end) && $dateStr === $today && $product) {
                    [, $checkoutTime] = self::resolveCheckinCheckoutTime($product);

                    if ($nowTime >= $checkoutTime) {
                        // Giờ hiện tại đã qua giờ trả phòng cấu hình cho hôm nay — khách đã trả
                        // phòng thật sự, không khoá ngày này nữa.
                        $day->addDay();
                        continue;
                    }
                }

                $occupied[$dateStr] = true;
                $day->addDay();
            }
        }

        return $occupied;
    }

    public static function getBookedNightsForProduct(?string $productId, $currentOrderItemId = null): array
    {
        return array_keys(self::getOccupiedDaysForProduct($productId, $currentOrderItemId));
    }

    // Style 2 — kiểm tra khoảng ngày [checkin, checkout] vừa chọn có CHỒNG LẤN với lượt đặt khác
    // của ĐÚNG phòng này không (chặn cả trường hợp chọn 2 đầu mút đều là ngày trống nhưng khoảng ở
    // giữa lại đè lên 1 ngày đã đặt — disabledDates chỉ chặn được khi chọn TRÚNG 1 ngày kín, không
    // chặn được kiểu "nhảy qua" này). Dùng CHUNG map ngày bị chiếm với getBookedNightsForProduct()
    // để nhất quán luật "qua giờ trả phòng hôm nay thì được đặt lại".
    public static function hasOverlappingNightBooking(?string $productId, string $checkin, string $checkout, $currentOrderItemId = null): bool
    {
        if (! $productId) {
            return false;
        }

        $start = \Carbon\Carbon::parse($checkin)->startOfDay();
        $end   = \Carbon\Carbon::parse($checkout)->startOfDay();

        if ($end->lt($start)) {
            return false;
        }

        $occupied = self::getOccupiedDaysForProduct($productId, $currentOrderItemId);

        $day = $start->copy();
        while ($day->lte($end)) {
            if (isset($occupied[$day->format('Y-m-d')])) {
                return true;
            }
            $day->addDay();
        }

        return false;
    }

    // Ghép ngày đã chọn + giờ bắt đầu/kết thúc của khung giờ thành 2 mốc Carbon thật — khung "qua
    // đêm" (over_night) hoặc giờ kết thúc <= giờ bắt đầu thì tự cộng thêm 1 ngày cho checkout.
    public static function computeSlotDatetimes(RoomTimeSlot $slot, string $date): array
    {
        // $date đôi khi đến kèm cả giờ (vd DatePicker trả về "2026-07-14 00:00:00" thay vì chỉ
        // "2026-07-14") — phải lấy đúng phần NGÀY trước khi ghép với giờ bắt đầu/kết thúc của
        // khung giờ, nếu không Carbon::parse() sẽ lỗi "Double time specification".
        $dateOnly = \Carbon\Carbon::parse($date)->format('Y-m-d');

        $start = \Carbon\Carbon::parse($dateOnly . ' ' . $slot->timeSlot->start_time);
        $end   = \Carbon\Carbon::parse($dateOnly . ' ' . $slot->timeSlot->end_time);

        if ($slot->timeSlot->over_night || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    // Dữ liệu lưới NGÀY × KHUNG GIỜ (giống hệt logic trang đặt phòng phía client — mỗi ô kiểm tra
    // chồng lấn checkin/checkout với các đơn khác của đúng phòng này) — dùng cho lưới bấm chọn
    // dạng bảng ở admin (xem view 'payment::components.timeslot-grid-table').
    public static function getTimeslotGridData(string $productId, $currentOrderItemId = null, int $days = 14, ?string $mustIncludeDate = null, $excludeOrderId = null): array
    {
        $slots = self::getRoomTimeSlots($productId);

        if ($slots->isEmpty()) {
            return ['dates' => [], 'slots' => [], 'cells' => []];
        }

        // Hold real-time (xem TimeslotHoldService) — ô đang được admin KHÁC thao tác chọn (chưa
        // lưu đơn, hết hạn sau vài phút) cũng phải khóa lại ở đây, KHÔNG chỉ dựa vào OrderItem thật
        // đã lưu bên dưới — nếu không 2 admin vẫn chọn trùng được trong lúc cả 2 đang cùng xử lý.
        $activeHolds = auth()->check()
            ? app(\App\Services\TimeslotHoldService::class)->getActiveHoldsForProduct($productId, auth()->user())
            : collect();

        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = now()->addDays($i)->format('Y-m-d');
        }

        // Đang SỬA 1 đơn đã đặt ở ngày ngoài khoảng "14 ngày tới" mặc định (vd đặt hôm qua, hoặc
        // đặt xa hơn 14 ngày) — phải luôn chèn thêm đúng ngày đã đặt vào lưới, nếu không ô đã
        // chọn sẽ không hiện ra, admin tưởng nhầm là mất dữ liệu.
        if ($mustIncludeDate && ! in_array($mustIncludeDate, $dates, true)) {
            array_unshift($dates, $mustIncludeDate);
        }

        // 1 QUERY DUY NHẤT lấy hết OrderItem của phòng này có thể chồng lấn khoảng ngày đang hiển
        // thị, rồi so khớp CHỒNG LẤN TRONG PHP cho từng ô bên dưới — TRƯỚC ĐÂY mỗi ô (ngày × khung
        // giờ, mặc định 14 ngày × ~5 khung = ~70 ô) tự chạy 1 query exists() RIÊNG (kèm subquery
        // whereHas('order',...)), khiến MỖI lần bấm chọn 1 ô phải đợi ~70 query tuần tự chạy lại
        // (selectTimeslot() dispatch '$refresh' → viewData() của ViewField render lại từ đầu) mới
        // bấm được ô tiếp theo — đúng hiện tượng "chọn xong 1 lúc mới selectable" đã phản ánh. Cùng
        // kỹ thuật với getOccupiedDaysForProduct() ở trên (1 query rồi lọc bằng PHP), +2 ngày đệm ở
        // 2 đầu để chắc chắn phủ hết khung "qua đêm" bắt đầu từ ngày cuối/kết thúc muộn hơn 1 ngày.
        $minDateTime = \Carbon\Carbon::parse(min($dates))->startOfDay()->subDay();
        $maxDateTime = \Carbon\Carbon::parse(max($dates))->startOfDay()->addDays(2);

        $occupiedQuery = self::applyActiveOrderConstraint(
            OrderItem::where('product_id', $productId)
                ->where('checkin_date', '<', $maxDateTime)
                ->where('checkout_date', '>', $minDateTime)
        );

        if ($currentOrderItemId) {
            $occupiedQuery->where('id', '!=', $currentOrderItemId);
        }

        // Đơn đang sửa sẽ bị XOÁ HẾT rồi tạo lại toàn bộ order_items khi lưu (xem
        // EditOrder::handleRecordUpdate) — nên TOÀN BỘ order_item hiện có của CHÍNH đơn này phải
        // loại trừ khỏi kiểm tra "đã đặt", không chỉ đúng 1 dòng Repeater đang thao tác
        // ($currentOrderItemId chỉ là ID của khung giờ ĐẦU TIÊN trong dòng, 1 dòng có thể có nhiều
        // khung giờ = nhiều order_item thật). Thiếu bước này thì khung giờ thứ 2 trở đi của CHÍNH
        // đơn đang sửa hiện nhầm "Đã đặt", và bỏ chọn 1 khung giờ trên form (chưa lưu, order_item
        // thật vẫn còn nguyên trong DB) cũng không chọn lại được luôn.
        if ($excludeOrderId) {
            $occupiedQuery->where('order_id', '!=', $excludeOrderId);
        }

        $occupiedRanges = $occupiedQuery->get(['checkin_date', 'checkout_date'])
            ->map(fn (OrderItem $item) => [
                \Carbon\Carbon::parse($item->checkin_date),
                \Carbon\Carbon::parse($item->checkout_date),
            ]);

        $cells = [];
        $promotionCalculator = app(\App\Services\PromotionCalculator::class);

        foreach ($dates as $date) {
            foreach ($slots as $slot) {
                [$start, $end] = self::computeSlotDatetimes($slot, $date);

                // Chỉ đánh dấu "Qua giờ" khi khung giờ đã KẾT THÚC hẳn — khung đang diễn ra (đã
                // qua giờ bắt đầu nhưng chưa tới giờ kết thúc) vẫn phải hiện đúng trạng thái thật
                // (còn trống/đã đặt/đang giữ chỗ), không bị gắn nhầm "Qua giờ" ngay khi vừa bắt đầu.
                $isPast = $end->isPast();

                $status  = 'available';
                $heldBy  = null;

                if ($isPast) {
                    $status = 'past';
                } else {
                    $isOccupied = $occupiedRanges->contains(
                        fn (array $range) => $range[0]->lt($end) && $range[1]->gt($start)
                    );

                    if ($isOccupied) {
                        $status = 'booked';
                    } elseif ($heldBy = $activeHolds->get($slot->id . '|' . $date)) {
                        // Admin KHÁC đang giữ chỗ real-time ô này (chưa hết hạn) — khóa tạm, không
                        // phải "đã đặt" thật sự.
                        $status = 'held';
                    }
                }

                // Áp dụng khuyến mãi theo khung giờ GIỐNG HỆT client (dùng chung
                // PromotionCalculator) — trước đây admin chỉ thấy/tính giá gốc, không thấy khách
                // đang được giảm giá thật sự bao nhiêu khi đặt đúng khung giờ này.
                $promo = $promotionCalculator->calculate($slot, $date);

                $cells[$date][$slot->id] = [
                    'slot_id'        => $slot->id,
                    'start'          => $start,
                    'end'            => $end,
                    'price'          => (float) $promo['final_price'],
                    'original_price' => (float) $slot->price,
                    'has_promo'      => $promo['promo_discount'] > 0,
                    'over_night'     => (bool) $slot->timeSlot->over_night,
                    'status'         => $status,
                    'held_by'        => $heldBy ?? null,
                ];
            }
        }

        return [
            'dates' => $dates,
            'slots' => $slots->map(fn (RoomTimeSlot $slot) => [
                'id'    => $slot->id,
                'label' => $slot->timeSlot->label ?: ($slot->timeSlot->start_time . ' - ' . $slot->timeSlot->end_time),
            ])->all(),
            'cells' => $cells,
        ];
    }

    private static function calculateTotal(Get $get, Set $set): void
    {
        $items     = $get('../../orderItems') ?? $get('orderItems') ?? [];
        $services  = $get('../../orderServices') ?? $get('orderServices') ?? [];
        $surcharge = $get('../../surcharge') ?? $get('surcharge') ?? 0;

        $total = self::computeOrderTotal(
            is_array($items) ? $items : [],
            is_array($services) ? $services : []
        ) + (float) $surcharge;

        $set('../../amount', $total);
        $set('amount', $total);
    }

    // Dựng lại state ban đầu cho Repeater 'orderItems' từ các order_item THẬT đã lưu trong DB —
    // dùng chung cho MỌI nơi hiển thị/sửa đơn dùng OrderForm::form() ngoài luồng "tạo mới"
    // (EditOrder::mutateFormDataBeforeFill(), action "Xem chi tiết" ở trang danh sách...). Repeater
    // 'orderItems' KHÔNG còn dùng ->relationship('items') (xem ghi chú ở Repeater bên dưới) nên bất
    // kỳ chỗ nào render OrderForm::form() cho 1 đơn ĐÃ TỒN TẠI đều phải tự gọi hàm này để đổ dữ
    // liệu vào, nếu không Repeater sẽ trống dù order_item vẫn còn nguyên trong DB.
    //
    // Các order_item được đặt qua lưới khung giờ (khớp được đúng 1 RoomTimeSlot theo checkin_date)
    // và cùng product_id được GỘP LẠI thành 1 dòng duy nhất (mảng 'selected_slots' nhiều phần tử)
    // để hiển thị đúng như lúc đặt — 1 bảng, nhiều ô đã chọn. Các order_item KHÔNG khớp khung giờ
    // nào (style "đặt theo ngày", hoặc nhập tay) giữ nguyên mỗi item 1 dòng riêng như trước.
    // Trước đây trả về NHIỀU dòng (key UUID ngẫu nhiên) khớp với Repeater nhiều dòng cũ. Giờ
    // OrderForm.php đã đổi 'orderItems' sang 2 Group::make()->statePath('orderItems.0') CỐ ĐỊNH
    // (đơn chỉ còn đúng 1 phòng, xem form()) nên PHẢI trả về ĐÚNG 1 phần tử, key cố định '0'.
    //
    // RỦI RO ĐÃ BIẾT (chấp nhận theo yêu cầu): đơn CŨ tạo TRƯỚC khi giới hạn "1 phòng/đơn" có thể
    // đang có NHIỀU order_item khác product_id nhau — hàm này giờ CHỈ lấy phòng có checkin_date
    // SỚM NHẤT, các phòng khác sẽ không hiển thị và sẽ MẤT nếu admin mở sửa rồi lưu lại đơn đó.
    public static function buildOrderItemsFormState(Order $record): array
    {
        $items = $record->items()->orderBy('checkin_date')->get();

        if ($items->isEmpty()) {
            return [];
        }

        $firstProductId = $items->first()->product_id;
        $itemsForFirstProduct = $items->where('product_id', $firstProductId)->values();
        $representativeItem = $itemsForFirstProduct->first();

        $slots = [];
        foreach ($itemsForFirstProduct as $item) {
            $slotId = self::findMatchingSlotId($item);

            if ($slotId && $item->checkin_date) {
                $slots[] = [
                    'slot_id' => $slotId,
                    'date'    => $item->checkin_date->format('Y-m-d'),
                ];
            }
        }

        return [
            '0' => self::orderItemToFormRow($representativeItem, $slots),
        ];
    }

    private static function orderItemToFormRow(OrderItem $item, array $selectedSlots): array
    {
        return [
            'id'              => $item->id,
            'product_id'      => $item->product_id,
            'name'            => $item->name,
            'guest_count'     => $item->guest_count ?? 1,
            'product_style'   => optional($item->product)->styles ?? 1,
            'price_per_night' => $item->price,
            'extra_fee'       => $item->extra_fee ?? 0,
            'quantity'        => $item->quantity ?? 1,
            'checkin_date'    => optional($item->checkin_date)->format('Y-m-d H:i:s'),
            'checkout_date'   => optional($item->checkout_date)->format('Y-m-d H:i:s'),
            'price'           => $item->price,
            'discount'        => $item->discount,
            'selected_slots'  => $selectedSlots,
        ];
    }

    // Khớp order_item đã lưu với ĐÚNG 1 RoomTimeSlot của phòng đó (so sánh giờ bắt đầu thật sự
    // tính ra từ khung giờ với checkin_date đã lưu) — để biết đây là 1 khung giờ chọn từ lưới hay
    // 1 lượt đặt "đặt theo ngày"/nhập tay không có khung giờ cố định.
    private static function findMatchingSlotId(OrderItem $item): ?int
    {
        if (! $item->product_id || ! $item->checkin_date) {
            return null;
        }

        $slots = RoomTimeSlot::where('room_id', $item->product_id)
            ->whereNull('date')
            ->with('timeSlot')
            ->get()
            ->filter(fn (RoomTimeSlot $slot) => $slot->timeSlot !== null);

        $dateStr = $item->checkin_date->format('Y-m-d');

        foreach ($slots as $slot) {
            [$start, $end] = self::computeSlotDatetimes($slot, $dateStr);

            if ($start->equalTo($item->checkin_date)) {
                return $slot->id;
            }
        }

        return null;
    }

    // Tách 1 dòng Repeater (1 phòng) có nhiều khung giờ trong 'selected_slots' thành NHIỀU phần
    // tử phẳng — mỗi phần tử tương ứng ĐÚNG 1 order_item thật sự sẽ được lưu (1 khung giờ = 1
    // order_item, giống đúng cách client/API tạo dữ liệu). Dùng chung cho cả tính tổng tiền
    // (computeOrderTotal) LẪN lúc lưu đơn thật sự (CreateOrder/EditOrder).
    public static function expandOrderItemsForPersistence(array $items): array
    {
        $expanded = [];

        foreach ($items as $item) {
            $selectedSlots = $item['selected_slots'] ?? [];
            $selectedSlots = is_array($selectedSlots)
                ? array_values(array_filter(
                    $selectedSlots,
                    fn ($s) => is_array($s) && ! empty($s['slot_id']) && ! empty($s['date'])
                ))
                : [];

            // Không có khung giờ dạng lưới (style "đặt theo ngày", hoặc phòng chưa khai báo
            // RoomTimeSlot nên nhập tay checkin/checkout) — giữ nguyên dòng gốc, chỉ bỏ field ảo.
            if (empty($selectedSlots)) {
                unset($item['selected_slots']);
                $expanded[] = $item;
                continue;
            }

            foreach ($selectedSlots as $selected) {
                $slot = RoomTimeSlot::with(['timeSlot', 'promotions' => fn ($q) => $q->where('is_active', true)])
                    ->find($selected['slot_id']);

                if (! $slot || ! $slot->timeSlot) {
                    continue;
                }

                [$start, $end] = self::computeSlotDatetimes($slot, $selected['date']);

                // Áp dụng khuyến mãi theo khung giờ GIỐNG HỆT client (PromotionCalculator dùng
                // chung) — trước đây admin tạo/sửa đơn luôn lưu ĐÚNG giá gốc slot->price, không
                // trừ khuyến mãi, nên cùng 1 khung giờ mà admin đặt hộ thì giá khác với khách tự
                // đặt qua web/app.
                $promo = app(\App\Services\PromotionCalculator::class)->calculate($slot, $selected['date']);

                $expandedItem = $item;
                unset($expandedItem['selected_slots']);
                $expandedItem['checkin_date']  = $start->format('Y-m-d H:i:s');
                $expandedItem['checkout_date'] = $end->format('Y-m-d H:i:s');
                $expandedItem['price']         = (int) $promo['final_price'];
                $expandedItem['discount']      = (int) $slot->price;

                $expanded[] = $expandedItem;
            }
        }

        return $expanded;
    }

    // Logic tính tổng DUY NHẤT — dùng chung cho cả calculateTotal() (ghi vào field 'amount' thật
    // sự lưu vào đơn) LẪN Placeholder "Tổng thanh toán" hiển thị cho admin xem (trước đây 2 nơi
    // tính RIÊNG, "Tổng thanh toán" tự cộng price+extra_fee thô mà KHÔNG áp dụng giảm giá đặt
    // nhiều khung giờ/phụ thu khách — khiến số hiển thị sai khác với số thực sự lưu).
    // Bản linh hoạt hơn findMatchingSlotId(OrderItem $item) — dùng được cho $item ở dạng mảng
    // (form/computeOrderTotal()) LẪN OrderItem model thật (ExtraChargeService::calculateRealTotal(),
    // Eloquent hỗ trợ ArrayAccess nên $item['checkin_date'] hoạt động ở cả 2 dạng) — KHÔNG khai
    // báo type-hint `array` vì sẽ chặn OrderItem model dù nó truy cập y hệt cú pháp mảng. Cùng
    // thuật toán: so đúng thời điểm bắt đầu (computeSlotDatetimes()) với checkin_date của item.
    private static function findMatchingSlotForItem($item, string $productId): ?RoomTimeSlot
    {
        if (empty($item['checkin_date'])) {
            return null;
        }

        $checkinDate = \Carbon\Carbon::parse($item['checkin_date']);
        $dateStr = $checkinDate->format('Y-m-d');

        foreach (self::getRoomTimeSlots($productId) as $slot) {
            [$start, $end] = self::computeSlotDatetimes($slot, $dateStr);
            if ($start->equalTo($checkinDate)) {
                return $slot;
            }
        }

        return null;
    }

    // Parse chuỗi giảm giá kiểu products.full_booking_discount ("10%" hoặc số tiền cố định kiểu
    // "50.000") — Y HỆT BuildsRoomBooking::parseDiscountRule() (engine tính giá THẬT dùng chung
    // cho mọi đơn tạo qua API — /api/admin/orders/preview) — KHÔNG round() ở đây, caller tự
    // (int) cast (TRUNCATE, không làm tròn), y hệt applyFullBookingDiscount() phía backend thật.
    private static function parseDiscountValue(?string $discountStr, float $baseAmount): float
    {
        $discountStr = trim((string) $discountStr);
        if ($discountStr === '') {
            return 0.0;
        }

        if (str_contains($discountStr, '%')) {
            $percentage = (float) str_replace('%', '', $discountStr);

            return $baseAmount * $percentage / 100;
        }

        return (float) preg_replace('/[.,]/', '', $discountStr);
    }

    // Tính giá ĐẦY ĐỦ (giá phòng gốc + khuyến mãi khung giờ + giảm giá hệ thống + phụ thu khách)
    // cho 1 nhóm order_item CÙNG 1 phòng (product_id) — PORT CHÍNH XÁC thuật toán
    // App\Http\Controllers\Api\Concerns\BuildsRoomBooking::computeBookingPreview(), engine tính
    // giá DUY NHẤT dùng cho MỌI đơn tạo/sửa thật qua API (BookingController::store()/preview(),
    // OrderController::update()/preview() — xem POST /api/admin/orders/preview). Trước đây hàm
    // này TỰ SUY DIỄN công thức riêng và ra số SAI khác hẳn số khách/admin thấy ở API thật (đã
    // xác nhận qua ví dụ thực tế: cùng 1 lượt full-booking, hàm cũ tính base_price+increase_amount
    // rồi giảm giá RIÊNG cho từng ngày, trong khi engine thật luôn dùng giá GỐC (KHÔNG cộng
    // increase, KHÔNG trừ promo) và áp % giảm giá lên TOÀN BỘ tổng của CẢ NHÓM, bất kể nhóm đó
    // trải mấy ngày).
    //
    // Điểm khác biệt QUAN TRỌNG so với bản cũ:
    //   - basePrice = giá GỐC thật của khung giờ ($slot->price, KHÔNG cộng increase_amount, KHÔNG
    //     trừ promo_discount) — đọc qua item['discount'] (đã lưu sẵn = $slot->price lúc expand,
    //     xem expandOrderItemsForPersistence()), fallback item['price'] cho đơn tạo qua API thật
    //     (luồng đó không set cột 'discount', và 'price' của luồng đó CHÍNH LÀ giá gốc rồi — xem
    //     BuildsRoomBooking::buildSlotItems()) — 1 công thức chạy đúng cho CẢ 2 nguồn dữ liệu.
    //   - "Đặt full ngày" (full_booking_discount): hễ CÓ ít nhất 1 ngày trong nhóm đạt full-booking
    //     (số khung giờ chọn đúng ngày đó = tổng khung giờ đã cấu hình cho phòng) thì áp % giảm giá
    //     lên TOÀN BỘ basePrice của CẢ NHÓM (mọi ngày đã chọn trong lượt đặt/sửa này, KHÔNG CHỈ
    //     riêng ngày đủ full) — y hệt applyFullBookingDiscount($basePrice, $room) — VÀ khuyến mãi
    //     khung giờ (promo_discount) bị BỎ QUA HOÀN TOÀN, không chỉ tắt giảm giá theo số lượng.
    //   - Không còn round() khi tính % — dùng (int) cast (TRUNCATE) y hệt
    //     parseDiscountRule()/applyBulkDiscount() phía backend thật.
    //   - Phụ thu khách tính LUÔN trong hàm này (không tách riêng calculateGuestSurcharge()/
    //     calcGuestSurcharge() nữa) — nights = SỐ NGÀY DUY NHẤT trong nhóm (unique checkin dates),
    //     đúng cho CẢ 2 kiểu đặt: y hệt buildGuestSurcharge() (nights = uniqueDates(slotSummary)
    //     cho type=slot, = count(slotSummary) cho type=daily — 2 công thức đó THỰC RA quy về cùng
    //     1 số "ngày duy nhất trong nhóm", vì đặt theo ngày thì mỗi đêm vốn đã là 1 order_item = 1
    //     ngày duy nhất).
    // Hệ thống hiện giới hạn 1 phòng/đơn nên không cần xử lý nhiều phòng cùng lúc trong 1 lần gọi.
    // $groupItems: order_item ĐÃ expand (1 dòng = 1 khung giờ), CÙNG product_id — CHƯA cộng
    // extra_fee, caller tự cộng riêng (giống $noProductItems ở computeOrderTotal()).
    public static function resolveProductGroupPricing(array $groupItems, ?Product $product): array
    {
        $basePrice = collect($groupItems)->sum(fn ($item) => (float) ($item['discount'] ?? $item['price'] ?? 0));

        $result = [
            'total'                  => $basePrice,
            'base_price'             => $basePrice,
            'discount_type'          => null,
            'promotion_discount'     => 0.0,
            'system_discount'        => 0.0,
            'discount_amount'        => 0.0,
            'discount_pct'           => null,
            'full_booking_dates'     => [],
            'guest_surcharge'        => 0.0,
            'guest_surcharge_detail' => null,
        ];

        if (! $product) {
            return $result;
        }

        $itemsByDate = [];
        foreach ($groupItems as $item) {
            if (empty($item['checkin_date'])) {
                continue;
            }
            $date = \Carbon\Carbon::parse($item['checkin_date'])->format('Y-m-d');
            $itemsByDate[$date][] = $item;
        }

        // ── Phụ thu khách (guest surcharge) — y hệt BuildsRoomBooking::buildGuestSurcharge() ────
        $cfg        = $product->room_config ?? [];
        $maxFree    = (int) ($cfg['max_free_guests'] ?? 2);
        $feeEach    = (int) ($cfg['extra_guest_fee'] ?? 0);
        $guestCount = (int) ($groupItems[0]['guest_count'] ?? 1);
        $nights     = count($itemsByDate);

        if ($feeEach > 0 && $guestCount > $maxFree && $nights > 0) {
            $extraGuests = $guestCount - $maxFree;
            $surcharge   = $extraGuests * $feeEach * $nights;

            $result['guest_surcharge']        = (float) $surcharge;
            $result['guest_surcharge_detail'] = [
                'extra_guests' => $extraGuests,
                'max_free'     => $maxFree,
                'fee_each'     => $feeEach,
                'nights'       => $nights,
                'total'        => $surcharge,
            ];
        }

        // ── "Đặt full ngày" (full_booking_discount) — y hệt checkFullDayBooking() ───────────────
        $hasFullBooking = false;
        if (filled($product->full_booking_discount)) {
            $totalSlotsInRoom = self::getRoomTimeSlots($product->id)->count();

            if ($totalSlotsInRoom > 0) {
                foreach ($itemsByDate as $date => $dateItems) {
                    if (count($dateItems) === $totalSlotsInRoom) {
                        $hasFullBooking = true;
                        $result['full_booking_dates'][] = $date;
                    }
                }
            }
        }

        if ($hasFullBooking) {
            $discount = (int) self::parseDiscountValue($product->full_booking_discount, $basePrice);

            $result['total']           = max(0, $basePrice - $discount);
            $result['discount_type']   = 'full_booking';
            $result['system_discount'] = $discount;
            $result['discount_amount'] = $discount;

            return $result;
        }

        // ── Khuyến mãi theo khung giờ (promo_discount CHỈ, KHÔNG cộng increase_amount) — y hệt
        // applyPromotions() ────────────────────────────────────────────────────────────────────
        $promotionCalculator = app(\App\Services\PromotionCalculator::class);
        $promotionDiscount   = 0.0;

        foreach ($groupItems as $item) {
            if (empty($item['checkin_date'])) {
                continue;
            }
            $date = \Carbon\Carbon::parse($item['checkin_date'])->format('Y-m-d');
            $slot = self::findMatchingSlotForItem($item, $product->id);

            if ($slot) {
                $promo = $promotionCalculator->calculate($slot, $date);
                $promotionDiscount += (float) $promo['promo_discount'];
            }
        }

        // ── Giảm giá theo số lượng khung giờ (bulk_discount_rules) — y hệt applyBulkDiscount() ──
        $rules = $product->bulk_discount_rules ?? [];
        usort($rules, fn ($a, $b) => (int) ($b['slots'] ?? 0) - (int) ($a['slots'] ?? 0));

        $slotCount      = count($groupItems);
        $systemDiscount = 0.0;
        $discountPct    = null;

        foreach ($rules as $rule) {
            if ($slotCount >= (int) ($rule['slots'] ?? 0)) {
                $discountPct = (float) ($rule['discount'] ?? 0);
                break;
            }
        }

        if ($discountPct > 0) {
            $systemDiscount = (int) (($basePrice - $promotionDiscount) * ($discountPct / 100));
        }

        $discountAmount = $promotionDiscount + $systemDiscount;

        $result['total']              = max(0, $basePrice - $discountAmount);
        $result['promotion_discount'] = $promotionDiscount;
        $result['system_discount']    = $systemDiscount;
        $result['discount_amount']    = $discountAmount;

        if ($systemDiscount > 0) {
            $result['discount_type'] = 'bulk';
            $result['discount_pct']  = $discountPct;
        }

        return $result;
    }

    public static function computeOrderTotal(array $items, array $services): float
    {
        // Loại dòng order_item "Phụ phí khách thêm" CŨ (extra_fee > 0, product_id = null — do
        // luồng đặt phòng cũ ProductDetail.php tự tạo riêng 1 dòng cho phụ thu khách). Phụ thu
        // khách giờ LUÔN tính LIVE trong resolveProductGroupPricing() (theo guest_count + số ngày
        // duy nhất của từng nhóm phòng) — giữ lại dòng extra_fee cũ sẽ cộng phụ thu 2 LẦN. Cùng
        // quy ước với ExtraChargeService::calculateRealTotal() (->where('extra_fee', 0)) — 3 nơi
        // tính tổng PHẢI cùng ra 1 số.
        $items = array_filter($items, fn ($item) => (float) ($item['extra_fee'] ?? 0) <= 0);

        $expandedItems = self::expandOrderItemsForPersistence($items);

        $itemsByProduct = [];
        $noProductItems = [];
        foreach ($expandedItems as $item) {
            $productId = $item['product_id'] ?? null;
            if ($productId) {
                $itemsByProduct[$productId][] = $item;
            } else {
                $noProductItems[] = $item;
            }
        }

        $total = 0;

        foreach ($itemsByProduct as $productId => $productItems) {
            $product = \Modules\Product\App\Models\Product::find($productId);

            // Giá phòng (đã áp khuyến mãi/giảm giá hệ thống) + phụ thu khách — xem
            // resolveProductGroupPricing() (port chính xác BuildsRoomBooking::computeBookingPreview()).
            $pricing = self::resolveProductGroupPricing($productItems, $product);
            $total += $pricing['total'] + $pricing['guest_surcharge'];

            foreach ($productItems as $item) {
                $total += (float) ($item['extra_fee'] ?? 0);
            }
        }

        foreach ($noProductItems as $item) {
            $total += (float) ($item['price'] ?? 0) + (float) ($item['extra_fee'] ?? 0);
        }

        $total += collect($services)->sum(fn ($s) => (float) ($s['subtotal'] ?? 0));

        return $total;
    }
}
