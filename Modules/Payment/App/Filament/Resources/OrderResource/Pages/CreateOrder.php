<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Pages;

use App\Services\CccdDeclarationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\BladeThemeV1\Services\AccessCode\AccessCodeService;
use Modules\Payment\App\Filament\Resources\OrderResource;
use Modules\Payment\App\Filament\Resources\OrderResource\Concerns\HasMemberCompanionManagement;
use Modules\Payment\App\Filament\Resources\OrderResource\Concerns\HasOrderServicesManagement;
use Modules\Payment\App\Filament\Resources\OrderResource\Concerns\HasTimeslotGridSelection;
use Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm;
use Modules\Payment\App\Services\CccdScannerService;
use Modules\Payment\Entities\OrderGuestCccd;
use Modules\Product\App\Models\Product;
use PayOS\PayOS;

class CreateOrder extends CreateRecord
{
    use HasTimeslotGridSelection;
    use HasOrderServicesManagement;
    use HasMemberCompanionManagement;

    protected static string $resource = OrderResource::class;

    protected ?string $payosCheckoutUrl = null;

    // Mở trang tạo đơn từ menu thao tác nhanh trên thẻ phòng (Dashboard) với
    // ?product_id=... — tự chọn sẵn ĐÚNG chi nhánh + phòng đó thay vì để trống, tránh admin phải
    // tìm lại từ đầu (chi nhánh PHẢI set trước product_id vì Select 'product_id' trong Repeater
    // chỉ load được options khi đã biết category_id, xem OrderForm.php).
    public function mount(): void
    {
        parent::mount();

        $productId = request()->query('product_id');

        if (! $productId) {
            return;
        }

        $product = Product::find($productId);

        if (! $product) {
            return;
        }

        $category       = $product->categories->first();
        $branchCategory = $category?->parent_id ? $category->parent : $category;

        if (! $branchCategory) {
            return;
        }

        $user = auth()->user();

        // Nếu admin vừa "Khóa tạm thời (realtime)" phòng này ở popup lưới trên Dashboard (xem
        // App\Livewire\RoomLockGrid), các khung giờ đó đã tồn tại THẬT trong bảng timeslot_holds —
        // đọc lại ngay ở đây để điền sẵn vào dòng đầu tiên, admin không phải chọn lại từ đầu.
        $selectedSlots = [];
        if ($user) {
            $holds = \App\Models\TimeslotHold::whereHas('roomTimeSlot', fn ($q) => $q->where('room_id', $product->id))
                ->where('user_id', $user->id)
                ->where('expires_at', '>', now())
                ->get();

            foreach ($holds as $hold) {
                $selectedSlots[] = ['slot_id' => $hold->room_time_slot_id, 'date' => $hold->date->format('Y-m-d')];
            }
        }

        // Trước đây dùng Str::uuid() (khớp key ngẫu nhiên mà Repeater tự sinh) — giờ OrderForm.php
        // đã đổi 'orderItems' từ Repeater sang 2 Group::make()->statePath('orderItems.0') cố định
        // (chỉ còn đúng 1 phòng/đơn), nên PHẢI dùng cố định '0' để khớp đúng ô dữ liệu đó, không
        // còn là UUID ngẫu nhiên nữa.
        $itemKey = '0';

        // Set 'product_id' qua form->fill() KHÔNG tự kích hoạt afterStateUpdated() của Select
        // 'product_id' trong OrderForm.php (chỉ chạy khi người dùng tự tay đổi giá trị trên
        // trình duyệt) — nên phải tự set thủ công đúng NHỮNG FIELD MÀ callback đó set (name,
        // product_style, price_per_night, discount, quantity), nếu không 'name' sẽ null và lưu
        // đơn thất bại (order_items.name NOT NULL). checkin_date/checkout_date/price sẽ được
        // recalculateItemFromSelectedSlots() ở dưới ghi đè lại đúng theo khung giờ đã chọn.
        $displayPrice = $product->discount ?: $product->price;

        $fill = [
            'category_id' => $branchCategory->id,
            'orderItems'  => [
                $itemKey => [
                    'product_id'      => $product->id,
                    'name'            => $product->name,
                    'product_style'   => (int) ($product->styles ?? 1),
                    'price_per_night' => (float) ($product->price ?? 0),
                    'price'           => $product->price,
                    'discount'        => $displayPrice,
                    'quantity'        => 1,
                    // Số khách mặc định 1 (khớp ->default(1) của field guest_count trong
                    // OrderForm.php) — admin không cần nhập lại, chỉ đổi khi cần.
                    'guest_count'     => 1,
                    'selected_slots'  => $selectedSlots,
                ],
            ],
        ];

        if ($user && ($user->isSuperAdmin() || $user->belongsToPlatformPartner())) {
            $fill['booking_partner_id'] = $branchCategory->partner_id;
        }

        $this->form->fill($fill);

        // recalculateItemFromSelectedSlots() (trait HasTimeslotGridSelection) tính checkin/checkout/
        // giá từ selected_slots — thao tác trực tiếp trên $this->data (giống hệt selectTimeslot()),
        // form->fill() ở trên chỉ đổ state thô, không tự tính các trường phụ thuộc này.
        if (! empty($selectedSlots)) {
            $this->recalculateItemFromSelectedSlots($itemKey, $selectedSlots);
            $this->data['amount'] = OrderForm::computeOrderTotal(
                $this->data['orderItems'] ?? [],
                $this->data['orderServices'] ?? []
            ) + (float) ($this->data['surcharge'] ?? 0);
        }
    }

    // resources/js/echo-admin.js gắn thẳng window.Echo.private(...).listen() rồi tự gọi
    // Livewire.dispatch('timeslotHoldsChanged') — KHÔNG dùng cú pháp khai báo
    // #[On('echo-private:...')] (từng bị lỗi im lặng do thứ tự nạp script/window.Echo chưa sẵn
    // sàng lúc Livewire boot, khiến real-time không bao giờ tới nơi). Method no-op này chỉ để ép
    // Livewire render lại, lưới NGÀY × KHUNG GIỜ tự đọc lại DB mới nhất qua
    // OrderForm::getTimeslotGridData() (đã tự query TimeslotHold ở đó).
    #[\Livewire\Attributes\On('timeslotHoldsChanged')]
    public function onTimeslotHoldsChanged(): void {}

    // Nhân viên đối tác NỀN TẢNG (vd 365home) HOẶC super_admin được đặt phòng hộ cho MỌI đối
    // tác (xem OrderForm.php — category_id/product_id không còn thu hẹp theo partner_id với các
    // tài khoản này). BelongsToPartner mặc định sẽ tự gán partner_id của NGƯỜI TẠO đơn nếu để
    // trống — SAI, vì đơn phải thuộc về đúng đối tác SỞ HỮU chi nhánh/phòng được chọn để doanh
    // thu/hoa hồng tính đúng cho đối tác đó. Với super_admin, auth()->user()->partner_id vốn
    // NULL (super_admin không thuộc đối tác nào) nên trước đây (chỉ check
    // belongsToPlatformPartner(), luôn false với super_admin) đơn tạo bởi super_admin bị lưu
    // partner_id = NULL hoàn toàn — đây chính là lý do "Đối tác" hiện rỗng khi mở sửa/xem lại.
    // Category LUÔN xác định đúng đối tác sở hữu chi nhánh đó, nên chỉ cần lấy category_id đã
    // chọn là đủ, áp dụng cho MỌI loại tài khoản (kể cả đối tác thường — categories của họ vốn
    // đã chỉ thuộc đúng đối tác của họ nên không ảnh hưởng gì thêm).
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['category_id'])) {
            $category = \Modules\Category\Entities\Category::find($data['category_id']);

            if ($category) {
                $data['partner_id'] = $category->partner_id;
            }
        }

        // Ghi nhận người tạo — dùng để nhân viên đối tác nền tảng xem lại đúng đơn CHÍNH HỌ đã
        // đặt hộ cho đối tác khác (xem OrderResource::getEloquentQuery()).
        $data['created_by'] = auth()->id();

        // Đơn tạo ở admin luôn thanh toán đủ ngay (không có UI chọn cọc lúc tạo) — cả 'amount' và
        // 'full_amount' cùng lưu tổng giá, 'full_amount' cố định từ đây trở đi.
        $data['full_amount'] = (int) ($data['amount'] ?? 0);

        return $data;
    }

    // Repeater 'orderItems' KHÔNG còn dùng ->relationship('items') (xem OrderForm.php) — 1 dòng
    // Repeater (1 phòng) có thể giữ NHIỀU khung giờ đã chọn trong 'selected_slots', nên phải tự
    // tay tách thành đúng số order_item cần tạo (1 khung giờ = 1 order_item, giống đúng cách
    // client/API tạo dữ liệu) thay vì để Filament tự sync theo số dòng hiển thị.
    protected function handleRecordCreation(array $data): Model
    {
        $orderItems = $data['orderItems'] ?? [];
        unset($data['orderItems']);

        // 'orderServices' giờ là Hidden field trần (không còn ->relationship('services') của
        // Repeater cũ để Filament tự sync sau handleRecordCreation()) — phải tự tay tạo
        // order_services bên dưới, giống hệt cách EditOrder::handleRecordUpdate() đang làm.
        $orderServices = $data['orderServices'] ?? [];
        unset($data['orderServices']);

        // guest_cccds (CCCD khách đi cùng) không phải cột thật trên orders — lưu riêng vào bảng
        // order_guest_cccds ở afterCreate() (xem bên dưới), không để Order::create() tự bỏ qua
        // trong im lặng rồi tưởng nhầm là đã lưu.
        unset($data['guest_cccds']);

        $record = static::getModel()::create($data);

        $expandedItems = OrderForm::expandOrderItemsForPersistence(is_array($orderItems) ? $orderItems : []);

        foreach ($expandedItems as $item) {
            unset($item['id']);
            $record->items()->create($item);
        }

        if (is_array($orderServices)) {
            foreach ($orderServices as $service) {
                $serviceId = $service['service_id'] ?? null;
                $serviceId = filled($serviceId) ? (int) $serviceId : null;
                $price = (int) ($service['price'] ?? 0);
                $quantity = max(1, (int) ($service['quantity'] ?? 1));
                $subtotal = (int) ($service['subtotal'] ?? ($price * $quantity));
                $name = $service['service_name'] ?? null;

                if (! $serviceId && blank($name) && $subtotal <= 0) {
                    continue;
                }

                if (blank($name) && $serviceId) {
                    $name = \Modules\BladeThemeV1\App\Models\AdditionService::find($serviceId)?->name;
                }

                $record->services()->create([
                    'service_id'   => $serviceId,
                    'service_name' => $name ?: 'Dich vu',
                    'price'        => $price,
                    'quantity'     => $quantity,
                    'subtotal'     => $subtotal,
                ]);
            }
        }

        return $record;
    }

    protected function afterCreate(): void
    {
        $record = $this->record->fresh(['items.product']);

        // Đơn đã lưu thành order_item THẬT — hold real-time (xem TimeslotHoldService) không còn
        // cần giữ nữa, trả lại ngay để admin khác thấy khung giờ này mở lại (nếu còn trống) hoặc
        // "Đã đặt" (đúng trạng thái thật, không còn kẹt ở "đang xử lý").
        app(\App\Services\TimeslotHoldService::class)->releaseAllForUser(auth()->user());

        // Trước đây gọi CccdScannerService::scanOrder() đồng bộ ở đây từng gây 504 GATEWAY
        // TIMEOUT thật sự — nguyên nhân gốc là tiến trình Node.js giải mã QR (jsQR) KHÔNG có
        // giới hạn thời gian nào trên Windows (chỉ Linux/macOS mới có `timeout 12`), nên nếu
        // ảnh lớn/khó đọc, tiến trình có thể treo tới khi PHP tự ngắt ở max_execution_time (đã
        // xác nhận qua log: "Maximum execution time of 30 seconds exceeded" tại
        // CccdScannerService.php). ĐÃ SỬA tận gốc trong CccdScannerService: mọi tiến trình con
        // (Node jsQR, zbarimg) và request OCR.space giờ có timeout THẬT SỰ hoạt động trên mọi hệ
        // điều hành, tự co giãn theo ngân sách còn lại (MAX_SCAN_SECONDS=18s, chừa ~10s dư cho
        // phần còn lại của afterCreate() dưới đây — PayOS, mã cổng...). Bọc thêm try/catch để
        // dù CccdScannerService lỗi bất ngờ (ảnh hỏng, thiếu binary...) cũng KHÔNG làm hỏng việc
        // tạo đơn — chỉ báo thiếu thông tin và để admin tự quét lại thủ công ở trang Sửa.
        if (blank($record->cccd_data) && ($record->cccd_front || $record->cccd_back)) {
            try {
                $data = app(CccdScannerService::class)->scanOrder($record);
            } catch (\Throwable $e) {
                $data = null;
                Log::warning('Auto-scan CCCD khi tạo đơn thất bại', [
                    'order_id' => $record->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            if ($data) {
                $updateFields = ['cccd_data' => $data];

                if (! empty($data['full_name'])) {
                    $updateFields['buyer_name'] = $data['full_name'];
                }
                if (! empty($data['address'])) {
                    $updateFields['buyer_address'] = $data['address'];
                }

                $record->update($updateFields);
                $record->refresh();

                Notification::make()
                    ->title('Đơn đã tạo — tự động quét CCCD thành công')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Đơn đã tạo — còn thiếu thông tin CCCD')
                    ->body('Không tự động đọc được QR/OCR từ ảnh CCCD. Vào trang Sửa đơn này, bạn có thể tải lại ảnh CCCD để hệ thống quét tự động.')
                    ->warning()
                    ->send();
            }
        }

        // Khách đi cùng (ở qua đêm >= 2 người — xem OrderForm::requiresSecondGuestCccd()) — mỗi
        // phần tử Repeater 'guest_cccds' ứng với 1 khách, guest_index bắt đầu từ 2 (1 = khách
        // chính). Lưu vào bảng order_guest_cccds RIÊNG (KHÔNG còn cột cccd_front_2/cccd_back_2
        // trên chính bảng orders — đã bỏ, chỉ đủ 1 khách thêm, không scale theo guest_count), dùng
        // CHUNG bảng với đơn khách tự đặt (ProductDetail.php) để CccdDeclarationService đọc thống
        // nhất 1 nguồn cho cả 2 luồng tạo đơn.
        $guestCccds = $this->data['guest_cccds'] ?? [];
        $missingScan = false;

        foreach (array_values(is_array($guestCccds) ? $guestCccds : []) as $i => $guest) {
            $front = $guest['cccd_front'] ?? null;
            $back  = $guest['cccd_back'] ?? null;

            if (! $front && ! $back) {
                continue;
            }

            try {
                $scanned = app(CccdScannerService::class)->scanPaths($front, $back);
            } catch (\Throwable $e) {
                $scanned = null;
                Log::warning('Auto-scan CCCD khách đi cùng khi tạo đơn thất bại', [
                    'order_id'    => $record->id,
                    'guest_index' => $i + 2,
                    'error'       => $e->getMessage(),
                ]);
            }

            OrderGuestCccd::updateOrCreate(
                ['order_id' => $record->id, 'guest_index' => $i + 2],
                ['cccd_front' => $front, 'cccd_back' => $back, 'cccd_data' => $scanned]
            );

            if (! $scanned) {
                $missingScan = true;
            }
        }

        if ($missingScan) {
            Notification::make()
                ->title('Đơn đã tạo — còn thiếu thông tin CCCD khách đi cùng')
                ->body('Không tự động đọc được QR/OCR từ ảnh CCCD của (các) khách đi cùng. Vào trang Sửa đơn này để thử lại.')
                ->warning()
                ->send();
        }

        // Người đi cùng của THÀNH VIÊN (customer_companions) đã chọn qua popup "CCCD thành viên"
        // (OrderForm::buildMemberCccdAction()) LÚC ĐANG TẠO ĐƠN — khi đó CHƯA có order_id nên
        // không gắn được ngay vào order_guest_cccds, chỉ ghi tạm companion_id vào
        // $this->data['member_companion_ids'] (bảng Sửa/Xoá/Thêm trong popup duy trì mảng này suốt
        // phiên, xem HasMemberCompanionManagement). Đơn vừa có ID thật ở đây — gắn ngay.
        $memberCompanionIds = $this->data['member_companion_ids'] ?? [];
        if (is_array($memberCompanionIds) && ! empty($memberCompanionIds)) {
            OrderForm::persistMemberCompanionsToOrder($record, $memberCompanionIds);
        }

        // Đơn tạo qua CMS admin (vd nhân viên lên đơn hộ khách vãng lai) TRƯỚC ĐÂY không tự sinh
        // "Khai báo lưu trú" — chỉ đơn đặt qua app/website khách hàng (GuestBookingController) mới
        // có. Khách vãng lai vẫn có nghĩa vụ khai báo y hệt, nên phải gọi lại đúng service này ở
        // đây để không bỏ sót, tránh bị phạt theo Luật Cư trú (2-4 triệu/lần thiếu báo cáo). Cùng
        // 1 lệnh gọi này tự xử lý luôn cả khách thứ 2 nếu đã có cccd_data_2 (xem upsertFromOrder()).
        app(CccdDeclarationService::class)->upsertFromOrder($record);

        // PayOS (chuyển khoản) + số tiền cần thu ngay >= 2000 → redirect sang QR thanh toán
        if ($record->payment_method === 'PayOS' && $record->depositDueAmount() >= 2000) {
            $record->update(['status' => 'pending']);

            // Nếu đơn đã có checkout_url (được tạo trước) → dùng lại, không tạo mới
            if (! empty($record->checkout_url)) {
                $this->payosCheckoutUrl = $record->checkout_url;
                return;
            }

            $this->createAdminPayosLink($record);
            return;
        }

        // Tiền mặt hoặc amount < 2000 → luồng bình thường
        if ($record->status !== 'paid') {
            return;
        }

        if ($record->hasAccessCode()) {
            return;
        }

        try {
            $firstItem    = $record->items->sortBy('checkin_date')->first();
            $checkinDate  = $record->items->min('checkin_date');
            $checkoutDate = $record->items->max('checkout_date');
            $product      = $firstItem?->product;

            /** @var AccessCodeService $service */
            $service = app(AccessCodeService::class);
            $code = $service->assignCodeToOrder(
                $record->id,
                $record->category_id,
                $checkinDate,
                $checkoutDate,
                $product,
            );

            Notification::make()
                ->title('Đã gán mã cổng: ' . $code->code)
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Tạo đơn thành công nhưng chưa gán được mã cổng')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    private function createAdminPayosLink(\Modules\Payment\Entities\Order $record): void
    {
        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                Notification::make()
                    ->title('PayOS chưa được cấu hình')
                    ->body('Đơn đã lưu. Vui lòng liên hệ admin để thanh toán.')
                    ->warning()
                    ->send();
                return;
            }

            $payOS   = new PayOS($clientId, $apiKey, $checksumKey);
            $editUrl = static::getResource()::getUrl('edit', ['record' => $record->id]);
            $dueNow  = $record->depositDueAmount();

            $response = $payOS->createPaymentLink([
                'orderCode'   => (int) $record->order_code,
                'amount'      => $dueNow,
                'description' => 'TT don ' . $record->order_code,
                'returnUrl'   => $editUrl . '?payment_status=success',
                'cancelUrl'   => $editUrl . '?payment_status=cancelled',
                'buyerName'   => $record->buyer_name ?? '',
                'buyerPhone'  => $record->buyer_phone ?? '',
                'expiredAt'   => now()->addMinutes(15)->timestamp,
                'items'       => [[
                    'name'     => 'Dat phong - ' . ($record->items->first()?->name ?? 'Phong'),
                    'quantity' => 1,
                    'price'    => $dueNow,
                ]],
            ]);

            $checkoutUrl = $response['checkoutUrl'] ?? null;

            if (! $checkoutUrl) {
                Notification::make()
                    ->title('Không thể tạo link thanh toán PayOS')
                    ->warning()
                    ->send();
                return;
            }

            // Lưu vào DB để tránh tạo lại link khi admin reload
            $record->update(['checkout_url' => $checkoutUrl]);

            Log::info('Admin PayOS link created', [
                'order_id'   => $record->id,
                'order_code' => $record->order_code,
                'amount'     => $record->amount,
            ]);

            $this->payosCheckoutUrl = $checkoutUrl;

        } catch (\Exception $e) {
            Log::error('Admin PayOS link error', [
                'order_id' => $record->id ?? null,
                'error'    => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Lỗi tạo link thanh toán')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        if ($this->payosCheckoutUrl) {
            return $this->payosCheckoutUrl;
        }

        return static::getResource()::getUrl('index');
    }
}
