<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Forms;

use Dom\Text;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

class OrderForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Trước đây tách 2 tab ("Thông tin đơn đặt phòng" / "Thông tin thanh toán") — theo
                // yêu cầu gộp lại thành 1 trang duy nhất, cuộn liên tục, không còn điều hướng tab.
                // Giữ nguyên 2 Section (tiêu đề + icon) để vẫn phân biệt rõ 2 phần nội dung cũ.
                Section::make('Thông tin đơn đặt phòng')
                            ->schema([
                                Section::make('Mật khẩu phòng thủ công')
                                    ->icon('heroicon-o-key')
                                    ->iconColor('warning')
                                    ->description('Phòng này dùng khóa cơ, vui lòng cung cấp thông tin bên dưới cho khách.')
                                    ->schema([
                                        Placeholder::make('manual_lock_info')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (! $record) return '';

                                                $record->load(['items.product']);
                                                $firstItem = $record->items->sortBy('checkin_date')->first();
                                                $product   = $firstItem?->product;

                                                if (! $product) {
                                                    return new \Illuminate\Support\HtmlString('<p class="text-gray-400 text-sm italic">Không tìm thấy thông tin phòng.</p>');
                                                }

                                                $checkinDate = $firstItem?->checkin_date
                                                    ? \Carbon\Carbon::parse($firstItem->checkin_date)
                                                    : null;

                                                $entry = \Modules\Product\App\Models\ManualLockPassword::getForProductAndDate(
                                                    $product,
                                                    $checkinDate
                                                );

                                                if (! $entry) {
                                                    return new \Illuminate\Support\HtmlString(
                                                        '<div class="flex items-center gap-2 text-warning-600 font-medium">'
                                                        . '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/></svg>'
                                                        . 'Phòng được đánh dấu khóa thủ công nhưng chưa có mật khẩu. Vui lòng cấu hình trong <strong>Mật khẩu khóa thủ công</strong>.'
                                                        . '</div>'
                                                    );
                                                }

                                                $rows = '';

                                                $rows .= '
                                                    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f3f4f6;">
                                                        <div style="width:120px;font-size:13px;color:#6b7280;font-weight:500;">Pass Cổng</div>
                                                        <div style="font-size:20px;font-weight:700;letter-spacing:4px;color:#111827;font-family:monospace;">'
                                                    . e($entry->gate_password)
                                                    . '</div>
                                                    </div>';

                                                if ($entry->room_password) {
                                                    $rows .= '
                                                        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f3f4f6;">
                                                            <div style="width:120px;font-size:13px;color:#6b7280;font-weight:500;">Pass Phòng</div>
                                                            <div style="font-size:20px;font-weight:700;letter-spacing:4px;color:#111827;font-family:monospace;">'
                                                        . e($entry->room_password)
                                                        . '</div>
                                                        </div>';
                                                }

                                                if ($entry->notes) {
                                                    $rows .= '
                                                        <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;">
                                                            <div style="width:120px;font-size:13px;color:#6b7280;font-weight:500;">Ghi chú</div>
                                                            <div style="font-size:13px;color:#374151;">' . nl2br(e($entry->notes)) . '</div>
                                                        </div>';
                                                }

                                                return new \Illuminate\Support\HtmlString(
                                                    '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;">'
                                                    . $rows
                                                    . '</div>'
                                                );
                                            }),
                                    ])
                                    ->visible(function ($record) {
                                        if (! $record || ! in_array($record->status, ['paid', 'deposit'])) {
                                            return false;
                                        }
                                        $product = $record->items->sortBy('checkin_date')->first()?->product;
                                        return $product?->has_manual_lock === true;
                                    })
                                    ->collapsible(),

                                // Mã cổng + Thông tin khách hàng đặt cạnh nhau (2 cột đều nhau) — Mã
                                // cổng chỉ hiện khi đơn đã 'paid' và phòng không dùng khóa thủ công
                                // (xem ->visible() của chính Section đó), nên khi ẩn thì Thông tin
                                // khách hàng tự chiếm trọn hàng (Grid không tự thêm cột trống).
                                //
                                // Grid::make(2) mặc định của Filament quy đổi thành ->columns(['lg' =>
                                // 2]) — CHỈ chia 2 cột khi màn hình rộng >= 1024px, hẹp hơn thì luôn
                                // xếp dọc 1 cột. Khai báo tay breakpoint 'sm' (>=640px) để lên 2 cột
                                // sớm hơn, khớp yêu cầu "chia 2 thành 1 hàng có 2 cột".
                                //
                                // QUAN TRỌNG: Section::setUp() TỰ ĐỘNG gọi ->columnSpan('full') (xem
                                // vendor/filament/forms/src/Components/Section.php:61) — MỌI Section
                                // mặc định LUÔN chiếm trọn 100% Grid cha bất kể Grid mấy cột, nên PHẢI
                                // tự ->columnSpan(1) đè lại ở cả 2 Section dưới đây, nếu không Grid dù
                                // đã lên 2 cột vẫn hiện xếp dọc (mỗi Section tự chiếm cả hàng riêng).
                                Grid::make(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        Section::make('Mã cổng')
                                            ->columnSpan(1)
                                            ->schema([
                                                Placeholder::make('access_code_info')
                                                    ->label('')
                                                    ->content(function ($record) {
                                                    if (!$record) {
                                                        return 'Chưa có mã cổng';
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
                                            ])
                                            ->visible(fn($record) => self::hasAccessCodeSection($record))
                                            ->collapsible(),

                                        Section::make('Thông tin khách hàng')
                                            // Không có Mã cổng để hiển thị cạnh (đơn chưa 'paid', hoặc phòng dùng
                                            // khóa thủ công) — chiếm trọn hàng thay vì chỉ 1/2 cột như bình thường.
                                            ->columnSpan(fn($record) => self::hasAccessCodeSection($record) ? 1 : 2)
                                            ->collapsible()
                                            ->schema([
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
                                            ]),
                                    ]),
                                // Bố cục 2 cột giống trang chi tiết phòng phía client (BookingForm nằm
                                // cột phải, sticky): cột trái (span 4/7) là khu vực chọn phòng/khung
                                // giờ; cột phải (span 3/7) gộp thông tin khách hàng + CCCD + tổng thanh
                                // toán theo đúng thứ tự trên xuống của BookingForm — thông tin khách
                                // hàng/CCCD trước, tổng thanh toán nằm dưới cùng cột phải.
                                Grid::make(3)
                                    ->schema([
                                            Section::make('Chi tiết đặt phòng')
                                                ->description('Thông tin về phòng và thời gian lưu trú')
                                                ->schema([
                                                    Grid::make(2)
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
                                                                // Chung hàng với "Chi nhánh" ngay bên cạnh (Grid 2 cột) — chỉ hiện
                                                                // với platform staff nên KHÔNG cần columnSpanFull() nữa.
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
                                                                // cột nào) — chiếm trọn cả hàng thay vì chỉ nửa Grid 2 cột.
                                                                ->columnSpan(fn () => self::isPlatformStaff() ? 1 : 2)
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

                                                    Repeater::make('orderItems')
                                                        // KHÔNG dùng ->relationship('items') nữa — Filament sẽ tự tạo ĐÚNG 1
                                                        // bản ghi order_item cho mỗi 1 dòng hiển thị, nên 1 phòng chọn nhiều
                                                        // khung giờ trên CÙNG 1 bảng (không thêm dòng/thẻ phòng mới nào) sẽ
                                                        // không thể sinh ra nhiều order_item qua cơ chế đó được. Thay vào đó,
                                                        // mỗi dòng lưu 1 mảng 'selected_slots' (nhiều khung giờ cho ĐÚNG 1
                                                        // phòng), và CreateOrder/EditOrder tự tay tạo đúng số order_item cần
                                                        // thiết khi lưu (xem OrderForm::expandOrderItemsForPersistence()).
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
                                                                                    $set('price', $product->price);
                                                                                    $displayPrice = $product->discount ?: $product->price;
                                                                                    $set('discount', $displayPrice);
                                                                                    // Reset date fields khi đổi phòng
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
                                                                        ->helperText('Phụ thu theo cấu hình phòng từ khách thứ 3')
                                                                        ->numeric()
                                                                        ->minValue(1)
                                                                        ->default(1)
                                                                        ->required()
                                                                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                            self::calculateTotal($get, $set);
                                                                        }),
                                                                ]),
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
                                                        ])
                                                        ->columns(2)
                                                        ->label('Danh sách phòng đặt')
                                                        ->collapsible()
                                                        ->addActionLabel('Thêm phòng')
                                                        ->addable(true)
                                                        ->maxItems(20)
                                                        ->minItems(1)
                                                        ->itemLabel(fn($state) => !empty($state['name']) ? ' ' . $state['name'] : 'Phòng mới')
                                                        ->defaultItems(0)
                                                        ->live()
                                                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                            self::calculateTotal($get, $set);
                                                        }),
                                                ])
                                                ->columnSpan(4),

                                            Grid::make(1)
                                                ->columnSpan(3)
                                                ->schema([
                                                    Section::make('Tổng thanh toán')
                                                        ->description('Chi tiết tính toán chi phí')
                                                        ->schema([
                                                            Repeater::make('orderServices')
                                                                ->relationship('services')
                                                                ->schema([
                                                                    Grid::make(3)->schema([
                                                                        Select::make('service_id')
                                                                            ->label('Dịch vụ')
                                                                            ->options(function () {
                                                                                $query = \Modules\BladeThemeV1\App\Models\AdditionService::where('is_active', 1);

                                                                                // AdditionService đã tự lọc theo partner_id (BelongsToPartner) —
                                                                                // không cần thu hẹp gì thêm ở đây.

                                                                                return $query->pluck('name', 'id');
                                                                            })
                                                                            ->required()
                                                                            ->searchable()
                                                                            ->preload()
                                                                            ->live()
                                                                            ->columnSpan(2)
                                                                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                                if ($state) {
                                                                                    $service = \Modules\BladeThemeV1\App\Models\AdditionService::find($state);
                                                                                    if ($service) {
                                                                                        $set('service_name', $service->name);
                                                                                        $set('price', $service->price);
                                                                                        $qty = (int) ($get('quantity') ?? 1);
                                                                                        $set('subtotal', $service->price * $qty);
                                                                                        self::calculateTotal($get, $set);
                                                                                    }
                                                                                }
                                                                            }),

                                                                        TextInput::make('quantity')
                                                                            ->label('Số lượng')
                                                                            ->numeric()
                                                                            ->minValue(1)
                                                                            ->default(1)
                                                                            ->required()
                                                                            ->live(debounce: 300)
                                                                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                                $price = (float) ($get('price') ?? 0);
                                                                                $qty = max(1, (int) $state);
                                                                                $set('subtotal', $price * $qty);
                                                                                self::calculateTotal($get, $set);
                                                                            }),
                                                                    ]),

                                                                    Grid::make(2)->schema([
                                                                        TextInput::make('price')
                                                                            ->label('Đơn giá')
                                                                            ->numeric()
                                                                            ->suffix('đ')
                                                                            ->disabled()
                                                                            ->dehydrated(true),

                                                                        TextInput::make('subtotal')
                                                                            ->label('Thành tiền')
                                                                            ->numeric()
                                                                            ->suffix('đ')
                                                                            ->disabled()
                                                                            ->dehydrated(true),
                                                                    ]),

                                                                    Hidden::make('service_name'),
                                                                ])
                                                                ->columns(1)
                                                                ->label('Dịch vụ thêm')
                                                                ->collapsible()
                                                                ->addActionLabel('+ Thêm dịch vụ')
                                                                ->defaultItems(0)
                                                                ->live()
                                                                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                    self::calculateTotal($get, $set);
                                                                })
                                                                ->itemLabel(fn ($state) => !empty($state['service_name'])
                                                                    ? $state['service_name'] . (!empty($state['quantity']) ? ' × ' . $state['quantity'] : '') . (!empty($state['subtotal']) ? ' — ' . number_format((float) $state['subtotal'], 0, ',', '.') . 'đ' : '')
                                                                    : 'Dịch vụ mới'),

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

                                                    Section::make('Upload CCCD/CMND')
                                                        ->description('Tải lên ảnh CCCD/CMND rõ nét để xác minh danh tính')
                                                        ->collapsible(false)
                                                        ->collapsed()
                                                        ->schema([
                                                            Placeholder::make('cccd_download_links')
                                                                ->label('')
                                                                ->hiddenLabel()
                                                                ->content(function ($record) {
                                                                    if (! $record || (! $record->cccd_front && ! $record->cccd_back)) {
                                                                        return '';
                                                                    }

                                                                    $btnStyle = fn(string $bg, string $border, string $color) =>
                                                                        "display:inline-flex;align-items:center;gap:.375rem;padding:.4rem .875rem;" .
                                                                        "background:{$bg};border:1px solid {$border};color:{$color};" .
                                                                        "border-radius:.5rem;font-size:.8125rem;font-weight:600;text-decoration:none;" .
                                                                        "transition:background .15s;";

                                                                    $icon = '<svg xmlns="http://www.w3.org/2000/svg" style="width:.875rem;height:.875rem;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
                                                                        . '<path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>'
                                                                        . '</svg>';

                                                                    $html = '<div style="display:flex;gap:.625rem;flex-wrap:wrap;padding:.25rem 0;">';

                                                                    if ($record->cccd_front) {
                                                                        $url  = \Illuminate\Support\Facades\Storage::disk('public')->url($record->cccd_front);
                                                                        $name = 'CCCD_mat_truoc_' . ($record->order_code ?? $record->id) . '.jpg';
                                                                        $html .= '<a href="' . e($url) . '" download="' . e($name) . '" target="_blank"'
                                                                            . ' style="' . $btnStyle('#eff6ff', '#bfdbfe', '#1d4ed8') . '"'
                                                                            . ' onmouseenter="this.style.background=\'#dbeafe\'"'
                                                                            . ' onmouseleave="this.style.background=\'#eff6ff\'">'
                                                                            . $icon . ' Tải mặt trước</a>';
                                                                    }

                                                                    if ($record->cccd_back) {
                                                                        $url  = \Illuminate\Support\Facades\Storage::disk('public')->url($record->cccd_back);
                                                                        $name = 'CCCD_mat_sau_' . ($record->order_code ?? $record->id) . '.jpg';
                                                                        $html .= '<a href="' . e($url) . '" download="' . e($name) . '" target="_blank"'
                                                                            . ' style="' . $btnStyle('#f0fdf4', '#bbf7d0', '#15803d') . '"'
                                                                            . ' onmouseenter="this.style.background=\'#dcfce7\'"'
                                                                            . ' onmouseleave="this.style.background=\'#f0fdf4\'">'
                                                                            . $icon . ' Tải mặt sau</a>';
                                                                    }

                                                                    // Khách đi cùng — lưu ở bảng order_guest_cccds (guest_index=2,3,4...),
                                                                    // KHÔNG còn ở cột cccd_front_2/cccd_back_2 trên chính bảng orders nữa.
                                                                    foreach ($record->guestCccds as $guest) {
                                                                        if ($guest->cccd_front) {
                                                                            $url  = \Illuminate\Support\Facades\Storage::disk('public')->url($guest->cccd_front);
                                                                            $name = 'CCCD_khach' . $guest->guest_index . '_mat_truoc_' . ($record->order_code ?? $record->id) . '.jpg';
                                                                            $html .= '<a href="' . e($url) . '" download="' . e($name) . '" target="_blank"'
                                                                                . ' style="' . $btnStyle('#fefce8', '#fde68a', '#a16207') . '"'
                                                                                . ' onmouseenter="this.style.background=\'#fef9c3\'"'
                                                                                . ' onmouseleave="this.style.background=\'#fefce8\'">'
                                                                                . $icon . ' Tải mặt trước (khách #' . $guest->guest_index . ')</a>';
                                                                        }

                                                                        if ($guest->cccd_back) {
                                                                            $url  = \Illuminate\Support\Facades\Storage::disk('public')->url($guest->cccd_back);
                                                                            $name = 'CCCD_khach' . $guest->guest_index . '_mat_sau_' . ($record->order_code ?? $record->id) . '.jpg';
                                                                            $html .= '<a href="' . e($url) . '" download="' . e($name) . '" target="_blank"'
                                                                                . ' style="' . $btnStyle('#fefce8', '#fde68a', '#a16207') . '"'
                                                                                . ' onmouseenter="this.style.background=\'#fef9c3\'"'
                                                                                . ' onmouseleave="this.style.background=\'#fefce8\'">'
                                                                                . $icon . ' Tải mặt sau (khách #' . $guest->guest_index . ')</a>';
                                                                        }
                                                                    }

                                                                    // Tương thích ngược: đơn TẠO TRƯỚC khi chuyển sang bảng
                                                                    // order_guest_cccds vẫn còn ảnh khách thứ 2 ở cột cccd_front_2/
                                                                    // cccd_back_2 cũ (không có bản ghi guestCccds nào) — vẫn hiện link
                                                                    // tải, không để mất quyền xem lại ảnh cũ.
                                                                    if ($record->guestCccds->isEmpty()) {
                                                                        if ($record->cccd_front_2) {
                                                                            $url  = \Illuminate\Support\Facades\Storage::disk('public')->url($record->cccd_front_2);
                                                                            $name = 'CCCD_nguoidicung_mat_truoc_' . ($record->order_code ?? $record->id) . '.jpg';
                                                                            $html .= '<a href="' . e($url) . '" download="' . e($name) . '" target="_blank"'
                                                                                . ' style="' . $btnStyle('#fefce8', '#fde68a', '#a16207') . '"'
                                                                                . ' onmouseenter="this.style.background=\'#fef9c3\'"'
                                                                                . ' onmouseleave="this.style.background=\'#fefce8\'">'
                                                                                . $icon . ' Tải mặt trước (người đi cùng)</a>';
                                                                        }

                                                                        if ($record->cccd_back_2) {
                                                                            $url  = \Illuminate\Support\Facades\Storage::disk('public')->url($record->cccd_back_2);
                                                                            $name = 'CCCD_nguoidicung_mat_sau_' . ($record->order_code ?? $record->id) . '.jpg';
                                                                            $html .= '<a href="' . e($url) . '" download="' . e($name) . '" target="_blank"'
                                                                                . ' style="' . $btnStyle('#fefce8', '#fde68a', '#a16207') . '"'
                                                                                . ' onmouseenter="this.style.background=\'#fef9c3\'"'
                                                                                . ' onmouseleave="this.style.background=\'#fefce8\'">'
                                                                                . $icon . ' Tải mặt sau (người đi cùng)</a>';
                                                                        }
                                                                    }

                                                                    $html .= '</div>';
                                                                    return new \Illuminate\Support\HtmlString($html);
                                                                })
                                                                ->visible(fn ($record) => (auth()->user()?->isSuperAdmin() ?? false)
                                                                    && $record && ($record->cccd_front || $record->cccd_back || $record->guestCccds->isNotEmpty()
                                                                        || $record->cccd_front_2 || $record->cccd_back_2)),

                                                            Grid::make(2)
                                                                ->schema([
                                                                    FileUpload::make('cccd_front')
                                                                        ->label('CCCD/CMND mặt trước')
                                                                        ->helperText('Tối đa 10MB, ảnh gốc không crop/resize.')
                                                                        ->image()
                                                                        ->directory('cccd')
                                                                        ->imagePreviewHeight('100')
                                                                        ->loadingIndicatorPosition('center')
                                                                        ->panelAspectRatio('16:10')
                                                                        ->panelLayout('integrated')
                                                                        ->removeUploadedFileButtonPosition('top-right')
                                                                        ->uploadButtonPosition('center')
                                                                        ->uploadProgressIndicatorPosition('center')
                                                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                                                        ->maxSize(10240)
                                                                        ->downloadable()
                                                                        ->openable()
                                                                        ->nullable(),

                                                                    FileUpload::make('cccd_back')
                                                                        ->label('CCCD/CMND mặt sau')
                                                                        ->helperText('Tối đa 10MB, ảnh gốc không crop/resize.')
                                                                        ->image()
                                                                        ->directory('cccd')
                                                                        ->imagePreviewHeight('100')
                                                                        ->loadingIndicatorPosition('center')
                                                                        ->panelAspectRatio('16:10')
                                                                        ->panelLayout('integrated')
                                                                        ->removeUploadedFileButtonPosition('top-right')
                                                                        ->uploadButtonPosition('center')
                                                                        ->uploadProgressIndicatorPosition('center')
                                                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                                                        ->maxSize(10240)
                                                                        ->downloadable()
                                                                        ->openable()
                                                                        ->nullable(),
                                                                ]),
                                                        ]),

                                                    // Luật Cư trú (hiệu lực 01/07/2026) yêu cầu khai báo lưu trú ĐỦ
                                                    // TỪNG NGƯỜI khi ở qua đêm — chỉ hiện khi có khung giờ/lượt đặt
                                                    // QUA ĐÊM và số khách >= 2 (xem requiresSecondGuestCccd()). Số ô
                                                    // khách đi cùng SCALE theo guest_count (giống hệt cơ chế
                                                    // cccdFrontExtra/cccdBackExtra ở ProductDetail.php phía khách
                                                    // hàng — trước đây CHỈ đủ 1 khách thêm cố định (cccd_front_2/
                                                    // cccd_back_2 trên chính bảng orders), lưu vào bảng RIÊNG
                                                    // order_guest_cccds (guest_index=2,3,4...) — dùng CHUNG bảng với
                                                    // đơn khách tự đặt, xem Order::guestCccds() + CreateOrder::afterCreate()/
                                                    // EditOrder.php + CccdDeclarationService.
                                                    Section::make('CCCD/CMND khách đi cùng (bắt buộc khi ở qua đêm từ 2 khách trở lên)')
                                                        ->description('Ở qua đêm phải khai báo lưu trú đủ TỪNG người theo quy định — tải thêm CCCD/CMND của mỗi khách đi cùng (không tính khách chính đã tải ở trên).')
                                                        ->icon('heroicon-m-user-plus')
                                                        ->iconColor('warning')
                                                        ->collapsible(false)
                                                        ->visible(fn (Get $get) => self::requiresSecondGuestCccd($get))
                                                        ->schema([
                                                            // ->dehydrated() BẮT BUỘC — Section cha ->visible(requiresSecondGuestCccd())
                                                            // và Filament MẶC ĐỊNH KHÔNG dehydrate field nằm trong component
                                                            // đang ẨN. requiresSecondGuestCccd() phụ thuộc 'orderItems' (số
                                                            // khách/khung giờ qua đêm) — nếu điều kiện này bị đánh giá lại là
                                                            // false dù chỉ trong 1 khoảnh khắc lúc submit (timing/reactive
                                                            // Livewire), TOÀN BỘ dữ liệu khách đi cùng đã nhập sẽ bị lặng lẽ
                                                            // KHÔNG LƯU (đã từng xác minh thực tế với field cũ). Ép dehydrate
                                                            // luôn để không phụ thuộc thời điểm đánh giá visible().
                                                            Repeater::make('guest_cccds')
                                                                ->label('')
                                                                ->addActionLabel('Thêm khách đi cùng')
                                                                ->reorderable(false)
                                                                ->maxItems(fn (Get $get) => max(0, self::maxGuestCountAcrossItems($get) - 1))
                                                                ->dehydrated()
                                                                ->columnSpanFull()
                                                                ->schema([
                                                                    Grid::make(2)
                                                                        ->schema([
                                                                            FileUpload::make('cccd_front')
                                                                                ->label('CCCD/CMND - mặt trước')
                                                                                ->helperText('Tối đa 10MB, ảnh gốc không crop/resize.')
                                                                                ->image()
                                                                                ->directory('cccd')
                                                                                ->imagePreviewHeight('100')
                                                                                ->loadingIndicatorPosition('center')
                                                                                ->panelAspectRatio('16:10')
                                                                                ->panelLayout('integrated')
                                                                                ->removeUploadedFileButtonPosition('top-right')
                                                                                ->uploadButtonPosition('center')
                                                                                ->uploadProgressIndicatorPosition('center')
                                                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                                                                ->maxSize(10240)
                                                                                ->downloadable()
                                                                                ->openable()
                                                                                ->dehydrated()
                                                                                ->nullable(),

                                                                            FileUpload::make('cccd_back')
                                                                                ->label('CCCD/CMND - mặt sau')
                                                                                ->helperText('Tối đa 10MB, ảnh gốc không crop/resize.')
                                                                                ->image()
                                                                                ->directory('cccd')
                                                                                ->imagePreviewHeight('100')
                                                                                ->loadingIndicatorPosition('center')
                                                                                ->panelAspectRatio('16:10')
                                                                                ->panelLayout('integrated')
                                                                                ->removeUploadedFileButtonPosition('top-right')
                                                                                ->uploadButtonPosition('center')
                                                                                ->uploadProgressIndicatorPosition('center')
                                                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                                                                ->maxSize(10240)
                                                                                ->downloadable()
                                                                                ->openable()
                                                                                ->dehydrated()
                                                                                ->nullable(),
                                                                        ]),
                                                                ]),
                                                        ]),
                                                ]),

                                    ])->columns(7),
                                    ]),

                        Section::make(__('payment::order.form.fieldset.payment_info'))
                            ->schema([
                                // Lịch sử thanh toán bên trái, Phương thức thanh toán bên phải —
                                // responsive: gộp về 1 cột dọc trên màn hình < 1024px.
                                // Section/Grid::setUp() TỰ ĐỘNG ->columnSpan('full') (xem ghi chú ở
                                // Grid "Mã cổng"/"Thông tin khách hàng" phía trên) nên PHẢI tự
                                // ->columnSpan(1) đè lại ở cả 2 Grid con dưới đây.
                                Grid::make(['default' => 1, 'lg' => 2])
                                    ->schema([
                                        Grid::make(1)
                                            ->columnSpan(1)
                                            ->schema([
                                // TIMELINE THANH TOÁN
                                Section::make('Lịch sử thanh toán')
                                    ->description('Thời điểm tạo đơn và các mốc thanh toán')
                                    ->schema([
                                        Placeholder::make('payment_timeline')
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

                                                // SVG icon helper
                                                $svg = static function (string $type, string $stroke): string {
                                                    $a = "xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"{$stroke}\" stroke-width=\"2.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\"";
                                                    return match ($type) {
                                                        'banknote' => "<svg {$a}><rect x=\"2\" y=\"5\" width=\"20\" height=\"14\" rx=\"2\"/><line x1=\"2\" y1=\"10\" x2=\"22\" y2=\"10\"/></svg>",
                                                        'check'    => "<svg {$a}><path d=\"M22 11.08V12a10 10 0 1 1-5.93-9.14\"/><polyline points=\"22 4 12 14.01 9 11.01\"/></svg>",
                                                        'file'     => "<svg {$a}><path d=\"M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg>",
                                                        'qr'       => "<svg {$a}><rect x=\"3\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"14\" width=\"7\" height=\"7\"/><rect x=\"3\" y=\"14\" width=\"7\" height=\"7\"/></svg>",
                                                        default    => "<svg {$a}><circle cx=\"12\" cy=\"12\" r=\"10\"/></svg>",
                                                    };
                                                };

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

                                                $html = '<div class="flex flex-col gap-0">';
                                                foreach ($steps as $i => $step) {
                                                    $isLast   = $i === count($steps) - 1;
                                                    $dotBg    = $step['done']
                                                        ? match($step['color']) {
                                                            'blue'   => '#3b82f6',
                                                            'yellow' => '#f59e0b',
                                                            'green'  => '#22c55e',
                                                            default  => '#6b7280',
                                                        }
                                                        : '#e5e7eb';
                                                    $dotBorder  = $step['done'] ? '' : 'border:2px dashed #9ca3af;';
                                                    $dotStroke  = $step['done'] ? 'white' : '#9ca3af';
                                                    $labelColor = $step['done'] ? '#111827' : '#9ca3af';
                                                    $iconHtml   = $svg($step['icon_type'], $dotStroke);

                                                    $timeHtml = $step['time'] && $step['time'] !== '(Không rõ thời điểm)'
                                                        ? '<span style="font-size:12px;color:#6b7280;">' . $step['time'] . '</span>'
                                                        : ($step['done']
                                                            ? '<span style="font-size:12px;color:#9ca3af;font-style:italic;">Không rõ thời điểm</span>'
                                                            : '<span style="font-size:12px;color:#d1d5db;font-style:italic;">Chưa thực hiện</span>');

                                                    $subHtml = $step['sub']
                                                        ? ' <span style="font-size:12px;background:#f3f4f6;color:#374151;padding:1px 7px;border-radius:999px;font-weight:600;">' . $step['sub'] . '</span>'
                                                        : '';

                                                    // Gắn nút hành động NGAY trên dòng lịch sử phát sinh/hoàn tiền gần
                                                    // nhất còn chờ xử lý — wire:click gọi thẳng EditOrder::quick*() (xem
                                                    // EditOrder.php), không cần điều hướng xuống Section riêng bên dưới.
                                                    $actionsHtml = '';
                                                    $btnStyle2 = fn(string $border, string $bg, string $color) =>
                                                        "font-size:11px;font-weight:700;padding:4px 10px;border-radius:6px;border:1px solid {$border};background:{$bg};color:{$color};cursor:pointer;font-family:inherit;";
                                                    $recordKeyPart = $record->id ?? 'new';
                                                    if ($i === $pendingExtraChargeStepIndex) {
                                                        $actionsHtml = '
                                                            <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
                                                                <button type="button" wire:key="tl-qr-' . $recordKeyPart . '" wire:click="quickCreateExtraChargeQr" wire:loading.attr="disabled" wire:target="quickCreateExtraChargeQr" style="' . $btnStyle2('#f59e0b', '#fffbeb', '#b45309') . '">Tạo QR</button>
                                                                <button type="button" wire:key="tl-transfer-' . $recordKeyPart . '" wire:click="quickMarkExtraChargeTransfer" wire:loading.attr="disabled" wire:target="quickMarkExtraChargeTransfer" style="' . $btnStyle2('#3b82f6', '#eff6ff', '#1d4ed8') . '">Đã chuyển khoản</button>
                                                                <button type="button" wire:key="tl-cash-' . $recordKeyPart . '" wire:click="quickMarkExtraChargeCash" wire:loading.attr="disabled" wire:target="quickMarkExtraChargeCash" style="' . $btnStyle2('#22c55e', '#f0fdf4', '#15803d') . '">Đã thu tiền mặt</button>
                                                            </div>';
                                                    } elseif ($i === $pendingRefundStepIndex) {
                                                        $actionsHtml = '
                                                            <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
                                                                <button type="button" wire:key="tl-refund-transfer-' . $recordKeyPart . '" wire:click="quickMarkRefundTransfer" wire:loading.attr="disabled" wire:target="quickMarkRefundTransfer" style="' . $btnStyle2('#3b82f6', '#eff6ff', '#1d4ed8') . '">Đã chuyển khoản</button>
                                                                <button type="button" wire:key="tl-refund-cash-' . $recordKeyPart . '" wire:click="quickMarkRefundCash" wire:loading.attr="disabled" wire:target="quickMarkRefundCash" style="' . $btnStyle2('#22c55e', '#f0fdf4', '#15803d') . '">Đã thu tiền mặt</button>
                                                            </div>';
                                                    }

                                                    $html .= '
                                                        <div style="display:flex;align-items:flex-start;gap:12px;">
                                                            <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;">
                                                                <div style="width:32px;height:32px;border-radius:50%;background:' . $dotBg . ';' . $dotBorder . 'display:flex;align-items:center;justify-content:center;flex-shrink:0;">' . $iconHtml . '</div>
                                                                ' . (!$isLast ? '<div style="width:2px;background:#e5e7eb;flex:1;min-height:24px;margin:2px 0;"></div>' : '') . '
                                                            </div>
                                                            <div style="padding-bottom:' . (!$isLast ? '12px' : '0') . ';padding-top:4px;">
                                                                <div style="font-weight:600;font-size:14px;color:' . $labelColor . ';">' . $step['label'] . $subHtml . '</div>
                                                                <div style="margin-top:2px;">' . $timeHtml . '</div>
                                                                ' . $actionsHtml . '
                                                            </div>
                                                        </div>
                                                    ';
                                                }
                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            }),
                                    ])
                                    ->collapsible()
                                    ->collapsed(false),

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

                                        Grid::make(1)
                                            ->columnSpan(1)
                                            ->schema([
                                Section::make('Phương thức thanh toán')
                                    ->description('Chọn phương thức thanh toán và trạng thái đơn hàng')
                                    ->schema([
                                        Grid::make(3)->schema([
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

                                            Select::make('order_status')
                                                ->label('Trạng thái nhận/trả phòng')
                                                ->options([
                                                    'pending'     => 'Chờ nhận phòng',
                                                    'checked_in'  => 'Đã nhận phòng',
                                                    'staying'     => 'Đang ở',
                                                    'checked_out' => 'Đã trả phòng',
                                                ])
                                                ->default('pending')
                                                ->dehydrateStateUsing(fn($state) => $state)
                                                ->helperText('Tự động cập nhật khi khách mở khoá — chỉ chỉnh tay cho trường hợp đặc biệt.'),
                                        ]),

                                        TextInput::make('money_deposit')
                                            ->label('Số tiền đã đặt cọc')
                                            ->placeholder('VD: 500000')
                                            ->suffix('VNĐ')
                                            ->numeric()
                                            ->minValue(0)
                                            ->visible(fn (Get $get) => $get('status') === 'deposit')
                                            ->live(onBlur: true),

                                        Textarea::make('note_for_admin')
                                            ->label('Thông tin người dùng CCCD')
                                            ->placeholder('Thông tin người dùng CCCD')
                                            ->helperText(__('payment::order.form.helper_text.note_for_admin'))
                                            ->columnSpanFull()
                                            ->nullable()
                                            ->rows(5)
                                            ->maxLength(3000),
                                    ])
                                    ->columns(3),
                                            ]),
                                    ]),
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
    private static function hasUnpaidExtraCharge($record): bool
    {
        return $record
            && (int) ($record->extra_charge_amount ?? 0) > 0
            && is_null($record->extra_charge_paid_at);
    }

    // Khoản CẦN HOÀN cho khách (huỷ khung giờ/dịch vụ làm giá giảm trên đơn đã paid) chưa xử lý —
    // xem ExtraChargeService::recordPendingRefund()/markRefundAsDone().
    private static function hasPendingRefund($record): bool
    {
        return $record
            && (int) ($record->extra_refund_amount ?? 0) > 0
            && is_null($record->extra_refund_paid_at);
    }

    // Section "Mã cổng" chỉ hiện khi đơn đã 'paid' VÀ phòng không dùng khóa thủ công VÀ chi nhánh
    // của phòng đó CÒN tài khoản TTLock đang hoạt động (không có ttlock thì không thể cấp/hiện mã
    // cổng thật sự nào) — dùng chung điều kiện này ở cả ->visible() của chính nó lẫn ->columnSpan()
    // của "Thông tin khách hàng" cạnh nó, để khi Mã cổng ẩn thì Thông tin khách hàng tự chiếm trọn
    // hàng thay vì để trống 1 cột.
    private static function hasAccessCodeSection($record): bool
    {
        if (! $record || $record->status !== 'paid') {
            return false;
        }

        $product = $record->items->sortBy('checkin_date')->first()?->product;

        if (! $product || $product->has_manual_lock) {
            return false;
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
    private static function getRoomTimeSlots(string $productId): \Illuminate\Support\Collection
    {
        if (! $productId) {
            return collect();
        }

        return RoomTimeSlot::where('room_id', $productId)
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
                    $occupiedQuery = self::applyActiveOrderConstraint(
                        OrderItem::where('product_id', $productId)
                            ->where('checkin_date', '<', $end)
                            ->where('checkout_date', '>', $start)
                    );

                    if ($currentOrderItemId) {
                        $occupiedQuery->where('id', '!=', $currentOrderItemId);
                    }

                    // Đơn đang sửa sẽ bị XOÁ HẾT rồi tạo lại toàn bộ order_items khi lưu (xem
                    // EditOrder::handleRecordUpdate) — nên TOÀN BỘ order_item hiện có của CHÍNH đơn
                    // này phải loại trừ khỏi kiểm tra "đã đặt", không chỉ đúng 1 dòng Repeater đang
                    // thao tác ($currentOrderItemId chỉ là ID của khung giờ ĐẦU TIÊN trong dòng, 1
                    // dòng có thể có nhiều khung giờ = nhiều order_item thật). Thiếu bước này thì
                    // khung giờ thứ 2 trở đi của CHÍNH đơn đang sửa hiện nhầm "Đã đặt", và bỏ chọn 1
                    // khung giờ trên form (chưa lưu, order_item thật vẫn còn nguyên trong DB) cũng
                    // không chọn lại được luôn.
                    if ($excludeOrderId) {
                        $occupiedQuery->where('order_id', '!=', $excludeOrderId);
                    }

                    if ($occupiedQuery->exists()) {
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
    public static function buildOrderItemsFormState(Order $record): array
    {
        $items = $record->items()->orderBy('checkin_date')->get();

        $slotGroups = [];
        $result     = [];

        foreach ($items as $item) {
            $slotId = self::findMatchingSlotId($item);

            if ($slotId && $item->product_id && $item->checkin_date) {
                $slotGroups[$item->product_id]['item'] ??= $item;
                $slotGroups[$item->product_id]['slots'][] = [
                    'slot_id' => $slotId,
                    'date'    => $item->checkin_date->format('Y-m-d'),
                ];
                continue;
            }

            $result[(string) \Illuminate\Support\Str::uuid()] = self::orderItemToFormRow($item, []);
        }

        foreach ($slotGroups as $group) {
            $result[(string) \Illuminate\Support\Str::uuid()] = self::orderItemToFormRow($group['item'], $group['slots']);
        }

        return $result;
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

    // Phụ thu khách tính 1 LẦN cho mỗi LƯỢT ĐẶT PHÒNG (1 dòng Repeater = 1 phòng), KHÔNG nhân theo
    // số khung giờ đã chọn trong lượt đặt đó — nhiều khung giờ liên tiếp trong CÙNG 1 lượt đặt 1
    // phòng chỉ phụ thu 1 lần duy nhất (không phải mỗi khung giờ trừ phụ thu 1 lần). Vì vậy phải
    // tính trên $items GỐC (trước khi expandOrderItemsForPersistence() tách thành nhiều khung giờ).
    //
    // Style 2 ("đặt theo ngày") là NGOẠI LỆ — phòng ở NHIỀU ĐÊM thì phụ thu khách phải nhân theo
    // SỐ ĐÊM (khớp ExtraChargeService::calcGuestSurcharge() — nights = itemCount cho phòng không
    // phải slot vì mỗi đêm tách thành 1 order_item riêng — VÀ BookingController::buildGuestSurcharge()
    // phía client, cũng nhân theo nights cho type=daily). Trước đây tính 1 lần duy nhất bất kể số
    // đêm, khiến "Tổng thanh toán" ở OrderForm thấp hơn số client thấy khi tự đặt qua web.
    private static function calculateGuestSurcharge(array $items): float
    {
        $total = 0;

        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            if (! $productId) {
                continue;
            }

            $product = \Modules\Product\App\Models\Product::find($productId);
            $cfg     = $product ? ($product->room_config ?? []) : [];
            $maxFree = (int) ($cfg['max_free_guests'] ?? 2);
            $feeEach = (int) ($cfg['extra_guest_fee'] ?? 0);

            $guestCount = (int) ($item['guest_count'] ?? 1);

            if ($feeEach <= 0 || $guestCount <= $maxFree) {
                continue;
            }

            $nights = 1;
            $itemStyle = (int) ($item['product_style'] ?? ($product->styles ?? 1));

            if ($itemStyle === 2) {
                $checkin  = $item['checkin_day'] ?? $item['checkin_date'] ?? null;
                $checkout = $item['checkout_day'] ?? $item['checkout_date'] ?? null;

                if ($checkin && $checkout) {
                    $nights = max(1, (int) \Carbon\Carbon::parse($checkin)->startOfDay()
                        ->diffInDays(\Carbon\Carbon::parse($checkout)->startOfDay()));
                }
            }

            $total += ($guestCount - $maxFree) * $feeEach * $nights;
        }

        return $total;
    }

    // Logic tính tổng DUY NHẤT — dùng chung cho cả calculateTotal() (ghi vào field 'amount' thật
    // sự lưu vào đơn) LẪN Placeholder "Tổng thanh toán" hiển thị cho admin xem (trước đây 2 nơi
    // tính RIÊNG, "Tổng thanh toán" tự cộng price+extra_fee thô mà KHÔNG áp dụng giảm giá đặt
    // nhiều khung giờ/phụ thu khách — khiến số hiển thị sai khác với số thực sự lưu).
    public static function computeOrderTotal(array $items, array $services): float
    {
        // Loại dòng order_item "Phụ phí khách thêm" CŨ (extra_fee > 0, product_id = null — do
        // luồng đặt phòng cũ ProductDetail.php tự tạo riêng 1 dòng cho phụ thu khách). Phụ thu
        // khách giờ LUÔN tính LIVE qua calculateGuestSurcharge() dựa trên guest_count của từng
        // phòng — giữ lại dòng này sẽ cộng phụ thu 2 LẦN (1 lần từ chính dòng đó, 1 lần từ
        // calculateGuestSurcharge()). Cùng quy ước với ExtraChargeService::calculateRealTotal()
        // (->where('extra_fee', 0)) — 3 nơi tính tổng PHẢI cùng ra 1 số.
        $items = array_filter($items, fn ($item) => (float) ($item['extra_fee'] ?? 0) <= 0);

        // Phụ thu khách tính theo LƯỢT ĐẶT (dòng gốc), phải tính TRƯỚC khi expand thành nhiều
        // khung giờ — nếu không, 1 phòng chọn 3 khung giờ liên tiếp sẽ bị tính phụ thu 3 lần.
        $guestSurchargeTotal = self::calculateGuestSurcharge($items);

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

            // Mỗi khung giờ = 1 dòng order_item (giống đúng cách client/API tạo dữ liệu) — đếm
            // theo SỐ DÒNG của cùng 1 phòng để tính bậc giảm giá "đặt nhiều khung giờ".
            $slotCount = count($productItems);
            $bulkDiscountPct = 0;

            if ($product && $slotCount >= 2) {
                $rules = $product->bulk_discount_rules ?? [];
                usort($rules, fn ($a, $b) => (int) ($b['slots'] ?? 0) - (int) ($a['slots'] ?? 0));
                foreach ($rules as $rule) {
                    if ($slotCount >= (int) ($rule['slots'] ?? 0)) {
                        $bulkDiscountPct = (float) ($rule['discount'] ?? 0);
                        break;
                    }
                }
            }

            foreach ($productItems as $item) {
                $basePrice = (float) ($item['price'] ?? 0);
                if ($bulkDiscountPct > 0) {
                    $basePrice = round($basePrice * (1 - $bulkDiscountPct / 100));
                }
                $total += $basePrice + (float) ($item['extra_fee'] ?? 0);
            }
        }

        foreach ($noProductItems as $item) {
            $total += (float) ($item['price'] ?? 0) + (float) ($item['extra_fee'] ?? 0);
        }

        $total += $guestSurchargeTotal;

        $total += collect($services)->sum(fn ($s) => (float) ($s['subtotal'] ?? 0));

        return $total;
    }
}
