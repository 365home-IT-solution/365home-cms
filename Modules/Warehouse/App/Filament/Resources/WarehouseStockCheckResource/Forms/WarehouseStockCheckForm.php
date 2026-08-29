<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Forms;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
use Modules\Warehouse\App\Filament\Support\WarehouseCardStyle;
use Modules\Warehouse\App\Models\WarehouseItem;

class WarehouseStockCheckForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin phiếu')
                ->schema([
                    self::partnerHidden(),
                    self::branchInput(),

                    // Xem giải thích 'default' => 1 ở WarehouseItemForm — Grid::make(N)/->columns(N)/
                    // ->columnSpan(N) dạng số nguyên không tự có mobile, phải khai báo tường minh.
                    Grid::make(['default' => 1, 'lg' => 3])->schema([
                        DateTimePicker::make('checked_at')
                            ->label('Ngày kiểm kê')
                            ->default(now())
                            ->required(),

                        // Chỉ cần biết ĐÚNG TÀI KHOẢN đang thao tác (auth()->user(), lưu qua
                        // created_by — xem WarehouseStockCheck::creating()), không cần chọn/gán gì.
                        Placeholder::make('creator_display')
                            ->label('Người kiểm kê')
                            ->content(fn () => CurrentUserDisplay::name()),

                        TextInput::make('code')
                            ->label('Mã phiếu')
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit')
                            ->placeholder('Tự động sinh khi tạo mới'),
                    ]),

                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->compact(),

            Section::make('Chi tiết kiểm kê')
                ->compact()
                ->description('Liệt kê TOÀN BỘ vật tư của đối tác để đếm 1 lượt — chỉ cần sửa "Đếm được" ở ô nào ra số khác tồn hệ thống, không cần tự tìm/chọn từng vật tư như phiếu nhập/xuất.')
                ->schema([
                    // Filament escape (không cho chèn HTML/class) chữ trả về từ ->itemLabel(), nên
                    // không thể tô màu primary cho tên vật tư + viền thẻ chỉ bằng PHP thường — phải
                    // ghi đè qua 1 khối <style> nhỏ, CHỈ áp dụng RIÊNG cho đúng repeater này (lớp
                    // .fi-warehouse-check-repeater ở dưới) — xem WarehouseCardStyle (dùng chung cho
                    // cả phiếu nhập/xuất/popup bàn giao, không đụng repeater ở form khác).
                    Placeholder::make('warehouse_check_repeater_style')
                        ->hiddenLabel()
                        ->content(WarehouseCardStyle::styleBlock('fi-warehouse-check-repeater'))
                        ->extraAttributes(['class' => 'hidden']),

                    // Ô tìm nhanh theo tên — phiếu kiểm kê liệt kê TOÀN BỘ vật tư của đối tác (có
                    // thể vài chục thẻ), khó dò tay từng thẻ. Lọc bằng JS thuần (không qua
                    // Livewire) nên gõ tới đâu ẩn/hiện thẻ ngay tới đó, không delay round-trip.
                    Placeholder::make('warehouse_check_search')
                        ->hiddenLabel()
                        ->content(WarehouseCardStyle::searchBox('fi-warehouse-check-repeater')),

                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        // KHÔNG dùng ->default() ở đây — Repeater dùng ->relationship() tự nạp lại
                        // state từ quan hệ (kể cả khi record chưa tồn tại, trả về mảng RỖNG) NGAY
                        // SAU khi default() chạy, nên default() bị ghi đè mất, hoàn toàn không hiện
                        // gì (đã kiểm chứng thực tế qua Livewire::test() — items count = 0 dù có
                        // default()). Việc liệt kê toàn bộ vật tư (cả lúc TẠO lẫn SỬA) được xử lý
                        // đáng tin cậy hơn ở CreateWarehouseStockCheck::afterFill() và
                        // EditWarehouseStockCheck::afterFill() — ghi trực tiếp vào $this->data['items']
                        // SAU KHI Filament đã fill xong, không đi qua cơ chế default()/relationship()
                        // dễ xung đột này.
                        // Dạng LƯỚI nhiều thẻ/hàng thay vì xếp dọc "1 vật tư 1 hàng dài" — thẻ đã thu
                        // gọn lại nên nhồi được tới 5 thẻ/hàng ở màn hình lớn.
                        ->extraAttributes(['class' => 'fi-warehouse-check-repeater'])
                        ->grid(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 4, 'xl' => 5])
                        ->schema([
                            // "warehouse_item_id" luôn được ghi trực tiếp sẵn (từ afterFill() ở
                            // Create/EditWarehouseStockCheck) nên KHÔNG cần Select chọn tên nữa —
                            // toàn bộ 94 thẻ đều đã có sẵn đúng vật tư. Chỉ khi bấm "+ Thêm dòng" để
                            // thêm vật tư mới phát sinh (chưa có sẵn trong danh sách) mới cần chọn —
                            // lúc đó Select xuất hiện thay cho tên, biến mất ngay khi đã chọn xong.
                            Hidden::make('warehouse_item_id')
                                ->required(),

                            Select::make('warehouse_item_id_picker')
                                ->label('Chọn vật tư')
                                ->options(function (Get $get) {
                                    // $get(..., isAbsolute: true) để lấy đúng field GỐC của form
                                    // (partner_id/branch_id), không phải field cùng dòng lặp — xem
                                    // cùng kỹ thuật ở UserForm::branchOptions().
                                    $partnerId = $get('partner_id', isAbsolute: true);
                                    $branchId  = $get('branch_id', isAbsolute: true);

                                    return WarehouseItem::query()
                                        ->where('status', true)
                                        ->when($partnerId, fn ($query, $pid) => $query->where('partner_id', $pid))
                                        ->when($branchId, fn ($query, $bid) => $query->withoutGlobalScope('branch')->where('branch_id', $bid))
                                        ->orderBy('name')
                                        ->pluck('name', 'id');
                                })
                                ->searchable()
                                ->live()
                                ->dehydrated(false)
                                ->visible(fn (Get $get) => blank($get('warehouse_item_id')))
                                ->required(fn (Get $get) => blank($get('warehouse_item_id')))
                                ->afterStateUpdated(function (Set $set, $state) {
                                    $quantity = WarehouseItem::whereKey($state)->value('quantity') ?? 0;
                                    $set('warehouse_item_id', $state);
                                    $set('system_quantity', $quantity);
                                    $set('actual_quantity', $quantity);
                                }),

                            // Tồn HT bên trái, Đếm được bên phải — Ô "Đếm được" rộng hơn ~25px cho
                            // dễ gõ. Xem giải thích 'default' => ... ở WarehouseItemForm —
                            // Grid::make(N) dạng số nguyên KHÔNG tự có cột mobile (chỉ gán "lg"),
                            // phải khai báo tường minh để 2 ô luôn nằm NGANG kể cả trên mobile.
                            Grid::make(['default' => 2, 'lg' => 2])
                                ->schema([
                                    TextInput::make('system_quantity')
                                        ->label('Tồn HT')
                                        ->disabled()
                                        ->dehydrated()
                                        ->default(0)
                                        ->formatStateUsing(fn ($state) => Number::format((float) $state, maxPrecision: 2))
                                        ->extraAttributes(WarehouseCardStyle::neutralBox())
                                        ->extraInputAttributes(WarehouseCardStyle::inputStyle()),

                                    // "Chênh lệch" hiện làm HINT — nằm ngay trên cùng dòng với label
                                    // "Đếm được" (góc phải), KHÔNG tạo thêm dòng/khối riêng bên dưới
                                    // nữa — trước đây làm khung riêng dưới "Ghi chú" khiến thẻ nào có
                                    // lệch bị cao hơn hẳn thẻ bên cạnh, xếp lưới nhìn rất xấu.
                                    TextInput::make('actual_quantity')
                                        ->label('Đếm được')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->hint(function (Get $get) {
                                            $diff = round((float) $get('actual_quantity') - (float) $get('system_quantity'), 2);

                                            return $diff !== 0.0
                                                ? '⚠ ' . ($diff > 0 ? '+' : '') . Number::format($diff, maxPrecision: 2)
                                                : null;
                                        })
                                        ->hintColor('danger')
                                        ->extraAttributes(fn (Get $get) => WarehouseCardStyle::comparedBoxWide(
                                            round((float) $get('actual_quantity') - (float) $get('system_quantity'), 2) !== 0.0
                                        ))
                                        ->extraInputAttributes(WarehouseCardStyle::inputStyle()),
                                ]),

                            // Ghi chú xuống hàng riêng, chiều ngang thu hẹp lại (2/3 thẻ) thay vì
                            // chiếm trọn hàng như trước.
                            Grid::make(['default' => 3, 'lg' => 3])
                                ->schema([
                                    TextInput::make('note')
                                        ->label('Ghi chú')
                                        ->placeholder('Ghi chú (nếu có)')
                                        ->maxLength(255)
                                        ->columnSpan(2),
                                ]),
                        ])
                        ->columns(1)
                        // Filament\Repeater mặc định TỰ THÊM 1 dòng trống (Repeater::setUp() gọi
                        // defaultItems(1) sẵn) — phải ép về 0 vì afterFill() đã đảm bảo luôn có đủ
                        // vật tư thật, không cần dòng trống mồi nào nữa (nếu không sẽ dư 1 thẻ trống
                        // vô nghĩa mỗi lần mở phiếu, đã thấy qua ảnh chụp thực tế).
                        ->defaultItems(0)
                        // Tên vật tư hiện ở HÀNG TIÊU ĐỀ mỗi thẻ (bên trái nút xoá), tô màu primary
                        // qua khối <style> ở trên — Filament tự vẽ itemLabel() ngay trong thanh
                        // header có sẵn của mỗi item.
                        ->itemLabel(fn (array $state) => filled($state['warehouse_item_id'] ?? null)
                            ? WarehouseItem::find($state['warehouse_item_id'])?->name
                            : 'Vật tư mới')
                        ->addActionLabel('+ Thêm dòng (vật tư mới phát sinh)')
                        ->reorderable(false),
                ]),
        ]);
    }

    // Dùng chung cho default() (tạo mới) và EditWarehouseStockCheck::mutateFormDataBeforeFill()
    // (sửa phiếu) — luôn lọc theo ĐÚNG đối tác đang đăng nhập nhờ global scope BelongsToPartner của
    // WarehouseItem (chỉ áp dụng trong Filament panel — đúng ngữ cảnh ở đây).
    // partner_id/branch_id: chỉ super_admin cần truyền (chọn tự do) — tài khoản thường đã tự lọc
    // đúng đối tác/chi nhánh của mình qua global scope (BelongsToPartner/BelongsToBranch), không
    // cần truyền tay. KHÔNG truyền branch_id (null) sẽ liệt kê vật tư của MỌI chi nhánh tài khoản
    // được phép xem — chỉ đúng khi tài khoản đó chỉ quản lý 1 chi nhánh duy nhất.
    public static function allActiveItemsAsRows(?string $partnerId = null, int|string|null $branchId = null): array
    {
        return WarehouseItem::query()
            ->where('status', true)
            ->when($partnerId, fn ($query, $pid) => $query->where('partner_id', $pid))
            ->when($branchId, fn ($query, $bid) => $query->withoutGlobalScope('branch')->where('branch_id', $bid))
            ->orderBy('name')
            ->get(['id', 'quantity'])
            ->map(fn (WarehouseItem $item) => [
                'warehouse_item_id' => $item->id,
                'system_quantity'   => $item->quantity,
                'actual_quantity'   => $item->quantity,
            ])
            ->toArray();
    }

    // Chi nhánh đang ACTIVE ở header "Chuyển đổi chi nhánh" — CHỈ tính khi admin đã chủ động thu
    // hẹp qua switcher (session('active_branch_ids') không rỗng); nếu switcher đang để "Chọn tất
    // cả" (session rỗng), coi như CHƯA thu hẹp gì, trả về [].
    private static function headerActiveBranchIds(): array
    {
        if (empty(session('active_branch_ids'))) {
            return [];
        }

        return auth()->user()?->effectiveBranchIds() ?? [];
    }

    // Header đã thu hẹp còn ĐÚNG 1 chi nhánh — coi như xác định xong, tự suy luôn cả Đối tác lẫn
    // Chi nhánh, ẩn field Chi nhánh.
    private static function singleActiveBranch(): ?Category
    {
        $ids = self::headerActiveBranchIds();

        return count($ids) === 1 ? Category::find($ids[0]) : null;
    }

    // Không còn Select "Đối tác" cho super_admin nữa (theo yêu cầu: chỉ cần chọn Chi nhánh, dù
    // header đang "Chọn tất cả" hay đã thu hẹp) — nhưng bảng dữ liệu vẫn cần cột partner_id để lọc
    // đúng theo BelongsToPartner, nên vẫn phải GỬI giá trị này lên, chỉ là không hỏi tay nữa: luôn
    // suy ngầm từ chi nhánh đã/đang chọn ở branchInput() (default khi có sẵn 1 chi nhánh active,
    // hoặc gán lại qua afterStateUpdated() ngay khi super_admin chọn 1 chi nhánh bất kỳ). Nhân viên
    // đối tác tạo phiếu thì partner_id tự động lấy theo tài khoản đang đăng nhập
    // (BelongsToPartner::creating()) nếu field này chưa có giá trị.
    private static function partnerHidden(): Hidden
    {
        return Hidden::make('partner_id')
            ->default(fn () => self::singleActiveBranch()?->partner_id)
            ->dehydrated();
    }

    // super_admin: liệt kê THẲNG danh sách chi nhánh để chọn — không còn hỏi Đối tác trước nữa.
    // Nếu header đã thu hẹp (1 hoặc nhiều chi nhánh cụ thể), chỉ liệt kê đúng các chi nhánh đó; nếu
    // header đang "Chọn tất cả" thì liệt kê toàn bộ chi nhánh của mọi đối tác (searchable). Chọn
    // xong thì tự gán partner_id ngầm theo chi nhánh vừa chọn VÀ nạp lại danh sách vật tư (đúng yêu
    // cầu: đổi chi nhánh PHẢI nạp lại danh sách vật tư theo chi nhánh MỚI ngay lập tức, không đợi
    // tải lại trang — trước đây chỉ đọc branch_id đúng 1 LẦN lúc mở trang (afterFill()), đổi chi
    // nhánh sau đó không cập nhật lại, dẫn tới hiện lẫn vật tư của NHIỀU chi nhánh khác nhau cùng
    // lúc, đã xác nhận qua ảnh chụp thực tế). Nhân viên đối tác tạo phiếu thì branch_id tự động lấy
    // theo tài khoản đang đăng nhập (users.branch_id, qua BelongsToBranch::creating()). Field tự
    // ẩn khi phạm vi active co về đúng 1 chi nhánh.
    private static function branchInput(): Select
    {
        return Select::make('branch_id')
            ->label('Chi nhánh')
            ->options(function () {
                $user = auth()->user();

                if ($user?->isSuperAdmin()) {
                    $narrowedIds = self::headerActiveBranchIds();

                    $query = Category::query()
                        ->where('category_type', 'product')
                        ->whereNull('parent_id');

                    if (! empty($narrowedIds)) {
                        $query->whereIn('id', $narrowedIds);
                    }

                    return $query->orderBy('name')->pluck('name', 'id')->all();
                }

                return Category::query()
                    ->whereIn('id', $user?->effectiveBranchIds() ?? [])
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all();
            })
            ->default(function () {
                $user = auth()->user();
                if ($user?->isSuperAdmin()) {
                    return self::singleActiveBranch()?->id;
                }
                $branchIds = $user?->effectiveBranchIds() ?? [];

                return count($branchIds) === 1 ? $branchIds[0] : null;
            })
            ->searchable()
            ->preload()
            ->required()
            ->live()
            ->afterStateUpdated(function (Set $set, $state) {
                $isSuperAdmin = auth()->user()?->isSuperAdmin() ?? false;
                $partnerId    = null;

                if ($isSuperAdmin && $state) {
                    $partnerId = Category::find($state)?->partner_id;
                    $set('partner_id', $partnerId);
                }

                $items = [];
                foreach (WarehouseStockCheckForm::allActiveItemsAsRows($partnerId, $state) as $row) {
                    $items[(string) Str::uuid()] = $row;
                }
                $set('items', $items);
            })
            ->visible(function () {
                $user = auth()->user();
                if ($user?->isSuperAdmin()) {
                    return ! self::singleActiveBranch();
                }

                return count($user?->effectiveBranchIds() ?? []) > 1;
            })
            ->helperText('Bắt buộc chọn — nếu không, phiếu sẽ không hiện với bất kỳ tài khoản đối tác nào.');
    }
}
