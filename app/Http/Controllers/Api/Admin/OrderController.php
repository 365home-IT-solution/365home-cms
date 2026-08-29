<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\BuildsRoomBooking;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SlotRealtimeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Payment\App\Services\CccdScannerService;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\ManualLockPassword;
use Modules\Product\App\Models\Product;

class OrderController extends Controller
{
    use BuildsRoomBooking;

    /**
     * GET /api/admin/orders
     * Danh sách đơn đặt phòng lọc theo đối tác của tài khoản đang đăng nhập — user thuộc đối
     * tác nào (users.partner_id) chỉ thấy đơn của các chi nhánh thuộc đối tác đó
     * (orders.partner_id, gán theo chi nhánh lúc đặt phòng — xem BelongsToPartner/Order::creating).
     * super_admin xem toàn bộ, không lọc.
     *
     * Mọi filter đọc được qua CẢ 2 cách tương đương — query param phẳng (?branch_id=...) HOẶC gộp
     * trong ?filter[...] (?filter[branch_id]=...), dùng dot-notation của Request::input() nên
     * không cần parse tay; filter[...] THẮNG nếu gửi trùng cả 2. Giữ đường cũ để không phá FE đang
     * dùng param phẳng có sẵn (branch_id, status, payment_method, search, from, to).
     *
     * Danh sách filter:
     *  - filter[branch_id]      : lọc theo 1 chi nhánh cụ thể (category_id) trong đối tác — khớp
     *                             CHÍNH XÁC category_id, không tự gồm khu vực con.
     *  - filter[categories]     : danh sách slug chi nhánh GỐC cách nhau bởi dấu phẩy (cùng field
     *                             'categories' trả về ở POST /api/admin/login, giống ?categories=
     *                             của các API admin khác — dashboard/kpi-stats, rankings, rooms) —
     *                             lọc đơn thuộc (những) chi nhánh đó KÈM toàn bộ khu vực con của
     *                             nó. Có cả 2 (branch_id lẫn categories) thì categories thắng. Slug
     *                             không khớp chi nhánh nào → trả rỗng thay vì bỏ qua filter.
     *  - filter[room_id]        : lọc đơn có ít nhất 1 item thuộc phòng này (order_items.product_id)
     *  - filter[status]         : pending|paid|deposit|cancelled|failed|refunded...
     *  - filter[payment_method] : PayOS|cod
     *  - filter[search]         : order_code / buyer_name / buyer_phone / tên phòng trong đơn (items.product.name)
     *  - filter[from], filter[to]       : lọc theo ngày TẠO đơn — created_at (yyyy-mm-dd)
     *  - filter[checkin_date]   : lọc theo ngày NHẬN phòng (order_items.checkin_date, yyyy-mm-dd)
     *  - filter[checkout_date]  : lọc theo ngày TRẢ phòng (order_items.checkout_date, yyyy-mm-dd)
     *    Gửi CẢ HAI cùng lúc = lọc đơn có 1 item khớp ĐÚNG cặp nhận-trả này (không phải 2 item
     *    khác nhau) — vd filter[checkin_date]=2026-08-01&filter[checkout_date]=2026-08-03.
     *  - per_page (không nằm trong filter) : mặc định 10
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Order::query()->with([
            'items.product:id,name,styles',
            'items.product.media',
            'category:id,name',
            'customer:id,fullname,phone',
        ]);

        if (! $user->isSuperAdmin()) {
            // Tài khoản không thuộc đối tác nào (partner_id null, không phải super_admin) không có
            // dữ liệu hợp lệ để xem — trả rỗng thay vì lộ toàn bộ đơn (WHERE partner_id = NULL sẽ
            // không khớp dòng nào nên về mặt kết quả tương đương, nhưng viết tường minh cho rõ ý).
            if (empty($user->partner_id)) {
                return response()->json($query->whereRaw('1 = 0')->paginate($request->integer('per_page', 10)));
            }

            $query->where('partner_id', $user->partner_id);

            // Thu hẹp thêm về đúng chi nhánh admin được gán (rỗng = không bị giới hạn chi nhánh cụ
            // thể trong đối tác, vd chủ đối tác — thấy hết đối tác mình) — trước đây chỉ lọc chi
            // nhánh khi FE tự truyền filter[branch_id]/filter[categories], không thì admin bị giới
            // hạn chi nhánh vẫn thấy đơn của MỌI chi nhánh khác trong cùng đối tác.
            $allowedCategoryIds = $user->allowedCategoryIds();
            if (! empty($allowedCategoryIds)) {
                $query->whereIn('category_id', $allowedCategoryIds);
            }
        }

        $f = fn (string $key) => $request->input("filter.{$key}", $request->input($key));

        $query
            // 'categories' (slug) thắng 'branch_id' (id số) nếu gửi cả 2 — giống resolveBranchCategoryIds
            // ở DashboardController, tránh 2 filter cùng ý nghĩa đá nhau khi FE gửi thừa cả hai.
            ->when(filled($f('categories')), function ($q) use ($f) {
                $categoryIds = $this->resolveCategoryIdsFromSlugs((string) $f('categories'));
                $q->whereIn('category_id', $categoryIds ?: [-1]);
            }, fn ($q) => $q->when(filled($f('branch_id')), fn ($q2) => $q2->where('category_id', (int) $f('branch_id'))))
            ->when(filled($f('room_id')), fn ($q) => $q->whereHas(
                'items', fn ($q2) => $q2->where('product_id', (int) $f('room_id'))
            ))
            ->when(filled($f('status')), fn ($q) => $q->where('status', $f('status')))
            ->when(filled($f('payment_method')), fn ($q) => $q->where('payment_method', $f('payment_method')))
            ->when(filled($f('search')), function ($q) use ($f) {
                $search = $f('search');
                $q->where(function ($q2) use ($search) {
                    $q2->where('order_code', 'like', "%{$search}%")
                        ->orWhere('buyer_name', 'like', "%{$search}%")
                        ->orWhere('buyer_phone', 'like', "%{$search}%")
                        ->orWhereHas('items.product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(filled($f('from')), fn ($q) => $q->whereDate('created_at', '>=', $f('from')))
            ->when(filled($f('to')), fn ($q) => $q->whereDate('created_at', '<=', $f('to')))
            // Ngày nhận/trả phòng nằm ở order_items, không phải Order — gộp chung 1 whereHas khi
            // gửi cả 2 để đảm bảo khớp ĐÚNG 1 item (nhận đúng ngày NÀY và trả đúng ngày ĐÓ), thay
            // vì 2 whereHas riêng (có thể khớp 2 item khác nhau trong cùng đơn multi-room/multi-slot).
            ->when(filled($f('checkin_date')) || filled($f('checkout_date')), function ($q) use ($f) {
                $q->whereHas('items', function ($q2) use ($f) {
                    $q2->when(filled($f('checkin_date')), fn ($q3) => $q3->whereDate('checkin_date', $f('checkin_date')))
                        ->when(filled($f('checkout_date')), fn ($q3) => $q3->whereDate('checkout_date', $f('checkout_date')));
                });
            })
            ->orderByDesc('created_at');

        $orders = $query->paginate($request->integer('per_page', 10));

        $orders->getCollection()->transform(fn (Order $order) => $this->toListItem($order));

        return response()->json($orders);
    }

    // Slug chi nhánh GỐC (cách nhau dấu phẩy) -> id chi nhánh đó + TOÀN BỘ khu vực con (đệ quy
    // theo parent_id) — cùng logic với DashboardController::resolveBranchCategoryIds(). Slug rỗng
    // hoặc không khớp chi nhánh nào -> mảng rỗng (caller tự quyết định lọc rỗng thay vì bỏ qua filter).
    private function resolveCategoryIdsFromSlugs(string $slugsParam): array
    {
        $slugs = collect(explode(',', $slugsParam))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values();

        if ($slugs->isEmpty()) {
            return [];
        }

        $ids          = Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->toArray();
        $currentLevel = $ids;

        while (! empty($currentLevel)) {
            $children = Category::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            if (empty($children)) {
                break;
            }
            $ids          = array_merge($ids, $children);
            $currentLevel = $children;
        }

        return array_unique($ids);
    }

    /**
     * GET /api/admin/orders/{order_code}
     * Chi tiết đầy đủ 1 đơn — items (phòng/khung giờ/giá), dịch vụ đã đặt, CCCD khách chính + khách
     * đi cùng (ảnh ĐÃ LƯU, xem trực tiếp không cần gửi kèm request cập nhật như trước), thông tin
     * cọc, mốc thời gian thanh toán/nhận-trả phòng, người tạo đơn. Cùng phạm vi truy cập với
     * index()/update() — 404 nếu đơn không tồn tại hoặc không thuộc đối tác của tài khoản (không
     * trả 403, tránh lộ sự tồn tại của đơn ngoài phạm vi).
     *
     * 'guest_surcharge': {guest_count, threshold, extra_guests, fee_per_person, nights, total, label}
     * — phụ thu do khách vượt ngưỡng miễn phí của phòng (products.room_config), TÍNH LẠI mỗi lần gọi
     * từ order.guest_count + order_items hiện có (không đọc số đã cộng sẵn vào 'amount' lúc tạo/sửa
     * đơn — số đó không persist riêng), cùng công thức với client (xem OrderController::buildDetail()
     * phía app khách) để 2 nơi luôn khớp số. null nếu phòng không cấu hình phụ thu hoặc không vượt
     * ngưỡng — xem buildGuestSurchargeInfo().
     */
    public function show(Request $request, string $orderCode): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = Order::query()
            ->with([
                // room_config: nguồn tính guest_surcharge (extra_guest_fee/max_free_guests) — xem
                // toDetailItem()/buildGuestSurchargeInfo(). lock_id: cần cho buildLockInfo() (case TTLock).
                'items.product:id,name,styles,room_config,lock_id',
                'items.product.media',
                // Suy timeslot_id cho từng item (đơn theo giờ) — order_items không lưu timeslot_id
                // trực tiếp, phải khớp ngược qua giờ bắt đầu (checkin_date) với RoomTimeSlot lặp
                // lại (date IS NULL) của phòng, cùng cách làm với update() ở trên.
                'items.product.roomTimeSlots' => fn ($q) => $q->whereNull('date'),
                'items.product.roomTimeSlots.timeSlot',
                'services',
                'category:id,name',
                'customer:id,fullname,phone',
                'guestCccds',
                'creator:id,fullname',
            ])
            ->where('order_code', $orderCode)
            ->first();

        if (! $order || (! $user->isSuperAdmin() && $order->partner_id !== $user->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        return response()->json(['order' => $this->toDetailItem($order)]);
    }

    /**
     * GET /api/admin/orders/{order_code}/cccds
     * Toàn bộ CCCD đã lưu của 1 đơn, GỘP CHUNG khách chính (guest_index=1 — lưu trực tiếp trên
     * Order.cccd_front/back/data) và khách đi cùng (guest_index từ 2 — bảng order_guest_cccds)
     * thành 1 mảng phẳng duy nhất, sắp theo guest_index — dùng khi FE chỉ cần danh sách CCCD để
     * hiển thị/duyệt qua, không cần cả object đơn như GET /api/admin/orders/{order_code}.
     * Guest nào chưa có ảnh nào được lưu (chưa từng upload) thì không xuất hiện trong mảng.
     */
    public function cccds(Request $request, string $orderCode): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = Order::query()
            ->with('guestCccds')
            ->where('order_code', $orderCode)
            ->first();

        if (! $order || (! $user->isSuperAdmin() && $order->partner_id !== $user->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        $cccds = collect();

        if ($order->cccd_front || $order->cccd_back || $order->cccd_data) {
            $cccds->push([
                'guest_index' => 1,
                'is_primary'  => true,
                'cccd_front'  => $order->cccd_front ? Storage::disk('public')->url($order->cccd_front) : null,
                'cccd_back'   => $order->cccd_back  ? Storage::disk('public')->url($order->cccd_back)  : null,
                'cccd_data'   => $order->cccd_data,
            ]);
        }

        foreach ($order->guestCccds as $g) {
            $cccds->push([
                'guest_index' => $g->guest_index,
                'is_primary'  => false,
                'cccd_front'  => $g->cccd_front ? Storage::disk('public')->url($g->cccd_front) : null,
                'cccd_back'   => $g->cccd_back  ? Storage::disk('public')->url($g->cccd_back)  : null,
                'cccd_data'   => $g->cccd_data,
            ]);
        }

        return response()->json(['data' => $cccds->values()]);
    }

    /**
     * PUT /api/admin/orders/{order_code}
     * Cập nhật đơn (đơn admin/lễ tân tạo qua BookingController hoặc đơn bất kỳ trong phạm vi
     * đối tác của tài khoản đang đăng nhập). Mọi field đều tuỳ chọn — chỉ gửi field nào cần sửa.
     *
     * Field đơn giản:
     *   - note_for_admin, description, short_description : text
     *   - buyer_name, buyer_phone                         : thông tin người đặt
     *   - status     : sửa được bất kể payment_method (kể cả đơn PayOS — trạng thái bình thường do
     *                  webhook thanh toán tự cập nhật, nhưng admin vẫn có thể gõ tay đè nếu cần)
     *   - surcharge  : phụ thu sửa tay (Order.surcharge — cộng thêm ngoài giá phòng/dịch vụ)
     *   - amount     : tổng tiền đơn sửa tay — ghi đè trực tiếp số cuối cùng, ÁP DỤNG SAU CÙNG nên
     *                  luôn thắng số tự tính lại ở phần "đổi khung giờ" bên dưới nếu gửi chung.
     *   'status'/'surcharge'/'amount' sửa được BẤT KỂ trạng thái/payment_method đơn (kể cả đã
     *   paid/deposit, kể cả PayOS) — admin toàn quyền điều chỉnh tại quầy, khác CMS. Sửa 'amount'
     *   luôn đồng bộ thẳng 'full_amount', phá vỡ bất biến "full_amount cố định sau khi thanh toán"
     *   — đánh đổi có chủ đích theo yêu cầu nghiệp vụ.
     *
     * CCCD (tùy chọn, không bắt buộc — giống lúc tạo đơn):
     *   - cccd_front, cccd_back       : ảnh khách chính, gửi thì quét lại QR, xoá ảnh cũ
     *   - guests[{guest_index}][front|back] : ảnh khách đi cùng, guest_index từ 2 — chỉ ghi đè
     *     đúng khách nào có gửi ảnh mới (updateOrCreate theo guest_index), không đụng khách khác.
     *
     * Đổi khung giờ/ngày (tùy chọn — gửi 'type' để kích hoạt, bỏ qua nếu không đổi lịch):
     *   - type: slot|daily|monthly + slots[]/checkin_date+checkout_date như lúc tạo đơn.
     *   - Xoá TOÀN BỘ order_items cũ, dựng lại theo dữ liệu mới (dùng lại đúng engine tính giá ở
     *     BuildsRoomBooking — promotions, bulk/full-booking discount), tính lại 'amount'/'full_amount'
     *     = giá phòng mới + phụ thu khách + tổng dịch vụ + surcharge hiện có (hoặc vừa sửa ở field
     *     'surcharge' bên trên) — TRỪ KHI có gửi kèm 'amount' thủ công (Phase cuối, luôn thắng).
     *   - room_id (tùy chọn, CHỈ có tác dụng khi gửi kèm 'type'): ĐỔI SANG PHÒNG KHÁC — id phòng đích
     *     (products.id), phải is_activated=true. Không gửi -> giữ nguyên phòng hiện có của đơn (hành
     *     vi cũ, chỉ đổi khung giờ/ngày). Cùng quyền hạn với BookingController::store() — nhân viên
     *     đối tác thường CHỈ đổi sang phòng cùng đối tác (room.partner_id === admin.partner_id), nhân
     *     viên đối tác nền tảng (User::belongsToPlatformPartner()) đổi được sang phòng của đối tác bất
     *     kỳ, super_admin không giới hạn. slots[]/checkin_date+checkout_date phải khớp cấu hình khung
     *     giờ/giá của PHÒNG MỚI (không phải phòng cũ) — dùng GET /api/admin/rooms/{room_id}/time-slots
     *     hoặc /dates trước để lấy đúng dữ liệu khả dụng của phòng đích. Đổi phòng khác chi nhánh/đối
     *     tác sẽ tự cập nhật lại category_id/partner_id của đơn theo phòng mới.
     *   - guest_count gửi kèm 'type' sẽ áp cho cả items mới lẫn Order; gửi RIÊNG guest_count (không
     *     kèm 'type') chỉ cập nhật số khách trên items hiện có, KHÔNG tính lại giá.
     *   - services gửi kèm sẽ THAY THẾ toàn bộ dịch vụ cũ (xoá hết, tạo lại theo danh sách mới).
     *   - Vẫn kiểm tra trùng khung giờ với đơn KHÁC (không tự đụng chính đơn này vì items cũ đã xoá
     *     trước khi dựng lại, trong cùng 1 transaction — lỗi trùng sẽ rollback về y hệt trước đó).
     *   - payment_type: CHỈ có tác dụng khi type=daily. Không gửi -> giữ nguyên deposit_percent hiện
     *     có (không đụng gì, tránh vô tình "mất cọc" khi admin chỉ định sửa khung giờ khác). Gửi
     *     payment_type=deposit -> tính lại % cọc theo cấu hình phòng (room.deposit_min_nights/
     *     deposit_multi_night), lỗi 422 nếu không đủ điều kiện (số đêm chưa tới mức tối thiểu) hoặc
     *     payment_method hiện tại của đơn là cod (đặt cọc bắt buộc qua PayOS). Gửi payment_type=full
     *     -> xoá cọc, chuyển hẳn về thanh toán đủ (deposit_percent = null). LƯU Ý: 'amount'/
     *     'full_amount' LUÔN là TỔNG GIÁ ĐẦY ĐỦ bất kể có cọc hay không — số tiền cọc thực tế xem
     *     qua Order::depositDueAmount() (tính động = full_amount * deposit_percent / 100), không
     *     phải chính 'amount' trả về.
     */
    public function update(Request $request, string $orderCode): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::query()
            ->with(['items.product:id,name', 'category:id,name', 'customer:id,fullname,phone', 'guestCccds'])
            ->where('order_code', $orderCode)
            ->first();

        // Không thuộc phạm vi đối tác của admin -> trả 404 (không phải 403) để không lộ sự tồn
        // tại của đơn đó cho admin không liên quan, cùng quy ước với EmployeeController.
        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        $rules = [
            'note_for_admin'     => 'sometimes|nullable|string|max:500',
            'description'        => 'sometimes|nullable|string|max:500',
            'short_description'  => 'sometimes|nullable|string|max:255',
            'status'             => 'sometimes|in:pending,paid,deposit,failed,cancelled_payment,refunded',
            'surcharge'          => 'sometimes|nullable|numeric|min:0',
            'amount'             => 'sometimes|nullable|numeric|min:0',
            'buyer_name'         => 'sometimes|nullable|string|max:100',
            'buyer_phone'        => 'sometimes|nullable|string|max:20',
            'cccd_front'         => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'cccd_back'          => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'guests'                 => 'sometimes|array',
            'guests.*.front'         => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'guests.*.back'          => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'guest_count'            => 'sometimes|integer|min:1',
            'type'                   => 'sometimes|in:slot,daily,monthly',
            'room_id'                => 'sometimes|string',
            'payment_type'           => 'sometimes|in:full,deposit',
            'services'               => 'sometimes|nullable|array',
            'services.*.service_id'  => 'required_with:services|integer',
            'services.*.quantity'    => 'required_with:services|integer|min:1',
        ];

        if ($request->input('type') === 'slot') {
            $rules['date']                = 'sometimes|date_format:Y-m-d|after_or_equal:today';
            $rules['slots']               = 'required|array|min:1';
            $rules['slots.*.timeslot_id'] = 'required|integer';
            $rules['slots.*.date']        = 'sometimes|date_format:Y-m-d|after_or_equal:today';
        } elseif ($request->filled('type')) {
            $rules['checkin_date']  = 'required|date|after_or_equal:today';
            $rules['checkout_date'] = 'required|date|after:checkin_date';
        }

        if ($request->input('type') === 'daily') {
            $rules['checkin_date']  = 'required|date_format:Y-m-d|after_or_equal:today';
            $rules['checkout_date'] = 'required|date_format:Y-m-d|after:checkin_date';
        }

        $data = $request->validate($rules);

        $oldAmount = (int) $order->amount;
        $updates   = [];

        // ── Field đơn giản ───────────────────────────────────────────────────
        foreach (['note_for_admin', 'description', 'short_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        foreach (['buyer_name', 'buyer_phone'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field] !== null ? trim((string) $data[$field]) : null;
            }
        }

        // Sửa được bất kể payment_method — kể cả đơn PayOS (trước đây chỉ cho phép với cod, vì
        // trạng thái đơn PayOS thường được webhook thanh toán cập nhật tự động; giờ admin cũng có
        // thể gõ tay đè theo yêu cầu nghiệp vụ, đồng nhất với 'amount'/'surcharge').
        if (array_key_exists('status', $data)) {
            $updates['status'] = $data['status'];
        }

        if (array_key_exists('surcharge', $data)) {
            $updates['surcharge'] = (int) ($data['surcharge'] ?? 0);
        }

        // ── CCCD khách chính (tùy chọn) ────────────────────────────────────────
        if ($request->hasFile('cccd_front') && $request->hasFile('cccd_back')) {
            $newFront = $request->file('cccd_front')->store('cccd', 'public');
            $newBack  = $request->file('cccd_back')->store('cccd', 'public');

            $cccdData = null;
            try {
                $tempOrder = new Order(['cccd_front' => $newFront, 'cccd_back' => $newBack]);
                $cccdData  = app(CccdScannerService::class)->scanOrder($tempOrder);
            } catch (\Throwable $e) {
                Log::warning('Admin API: quét lại CCCD khách chính thất bại', ['order_code' => $order->order_code, 'error' => $e->getMessage()]);
            }
            // Không bắt buộc đọc được QR (giống lúc tạo đơn) — vẫn lưu ảnh, cccd_data để trống nếu quét lỗi.

            if ($order->cccd_front) Storage::disk('public')->delete($order->cccd_front);
            if ($order->cccd_back)  Storage::disk('public')->delete($order->cccd_back);

            $updates['cccd_front'] = $newFront;
            $updates['cccd_back']  = $newBack;
            $updates['cccd_data']  = $cccdData;
        }

        // ── CCCD khách đi cùng (tùy chọn) — chỉ ghi đè guest_index nào có gửi ảnh mới ──────────
        foreach ((array) $request->file('guests', []) as $guestIndex => $files) {
            $front = $files['front'] ?? null;
            $back  = $files['back']  ?? null;
            if (! $front || ! $back) {
                continue;
            }

            $frontPath = $front->store('cccd', 'public');
            $backPath  = $back->store('cccd', 'public');

            $guestData = null;
            try {
                $guestData = app(CccdScannerService::class)->scanPaths($frontPath, $backPath);
            } catch (\Throwable $e) {
                Log::warning('Admin API: quét lại CCCD khách đi cùng thất bại', ['guest_index' => $guestIndex, 'error' => $e->getMessage()]);
            }

            $existing = $order->guestCccds->firstWhere('guest_index', (int) $guestIndex);
            if ($existing) {
                if ($existing->cccd_front) Storage::disk('public')->delete($existing->cccd_front);
                if ($existing->cccd_back)  Storage::disk('public')->delete($existing->cccd_back);
            }

            $order->guestCccds()->updateOrCreate(
                ['guest_index' => (int) $guestIndex],
                ['cccd_front' => $frontPath, 'cccd_back' => $backPath, 'cccd_data' => $guestData]
            );
        }

        // ── Đổi khung giờ/ngày (chỉ khi có gửi 'type') ─────────────────────────
        if ($request->filled('type')) {
            // room_id gửi kèm 'type' -> ĐỔI PHÒNG (dùng phòng đích thay vì phòng hiện có của đơn).
            // Không gửi -> hành vi cũ, chỉ đổi khung giờ/ngày trong cùng phòng.
            $isRoomChange = $request->filled('room_id');
            $productId    = $isRoomChange ? (string) $request->input('room_id') : $order->items->first()?->product_id;

            $room = Product::where('id', $productId)
                ->where('is_activated', true)
                ->with([
                    'roomType',
                    'additionalServices',
                    'roomTimeSlots.timeSlot',
                    'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
                ])
                ->first();

            if (! $room) {
                return response()->json([
                    'message' => $isRoomChange
                        ? 'Phòng muốn chuyển đến không tồn tại hoặc đã ngừng hoạt động.'
                        : 'Phòng của đơn này không còn tồn tại hoặc đã ngừng hoạt động.',
                ], 422);
            }

            // Cùng quyền hạn với BookingController::store() bước 3 — nhân viên đối tác thường chỉ
            // đổi được sang phòng cùng đối tác mình, nhân viên đối tác nền tảng (365home) đổi được
            // sang phòng của bất kỳ đối tác nào, super_admin không giới hạn.
            if ($isRoomChange && ! $admin->isSuperAdmin() && ! $admin->belongsToPlatformPartner() && $room->partner_id !== $admin->partner_id) {
                return response()->json(['message' => 'Bạn không có quyền chuyển sang phòng của đối tác khác.'], 403);
            }

            // Đổi phòng khác chi nhánh/đối tác -> đơn phải theo đúng category_id/partner_id của
            // phòng MỚI (cùng cách gán lúc tạo đơn ở BookingController::store()), không giữ theo
            // phòng cũ nữa.
            if ($isRoomChange) {
                $newCategory = $room->categories()->first();
                for ($i = 0; $i < 5 && $newCategory && $newCategory->parent_id; $i++) {
                    $parent = Category::find($newCategory->parent_id);
                    if (! $parent) {
                        break;
                    }
                    $newCategory = $parent;
                }

                $updates['category_id'] = $newCategory?->id;
                $updates['partner_id']  = $room->partner_id;
            }

            // buildSlotItems/buildDailyItems/buildMonthlyItem đọc guest_count qua $request->guest_count
            // — nếu admin không gửi kèm, giữ nguyên số khách hiện có của đơn.
            if (! $request->filled('guest_count')) {
                $request->merge(['guest_count' => $order->guest_count]);
            }

            // Chụp lại (ngày, timeslot_id) của items CŨ trước khi transaction xoá — dùng để bắn
            // realtime "giải phóng" sau khi commit thành công. order_items không lưu timeslot_id
            // trực tiếp nên suy ngược qua giờ bắt đầu (checkin_date) khớp với RoomTimeSlot lặp lại
            // (date IS NULL) của phòng — best-effort cho mục đích hiển thị realtime, không ảnh
            // hưởng tính đúng đắn dữ liệu nếu suy sai (khoá DB lúc submit vẫn là chốt chặn chính).
            $recurringSlotsByStartTime = $room->roomTimeSlots
                ->filter(fn ($rts) => $rts->timeSlot && is_null($rts->date))
                ->keyBy(fn ($rts) => $rts->timeSlot->start_time);

            $oldSlotSummary = $order->items
                ->filter(fn ($item) => $item->checkin_date)
                ->map(function ($item) use ($recurringSlotsByStartTime) {
                    $checkin = Carbon::parse($item->checkin_date);
                    $rts     = $recurringSlotsByStartTime->get($checkin->format('H:i:s'));

                    return $rts ? ['date' => $checkin->toDateString(), 'timeslot_id' => $rts->timeslot_id] : null;
                })
                ->filter()
                ->values();

            // Khoảng ngày CŨ (min checkin - max checkout của items hiện có) — dùng để giải phóng
            // đúng khoảng cũ khi rebuild sang type=daily. Không phân biệt items cũ vốn là slot hay
            // daily (chỉ cần có checkin/checkout) — trường hợp đổi hẳn loại đặt (slot ↔ daily) rất
            // hiếm, nếu có thì bắn thêm 1 broadcast daily-released dư cũng vô hại.
            $oldDailyRange = null;
            $oldCheckinRaw  = $order->items->pluck('checkin_date')->filter()->min();
            $oldCheckoutRaw = $order->items->pluck('checkout_date')->filter()->max();
            if ($oldCheckinRaw && $oldCheckoutRaw) {
                $oldDailyRange = [
                    'checkin'  => Carbon::parse($oldCheckinRaw)->toDateString(),
                    'checkout' => Carbon::parse($oldCheckoutRaw)->toDateString(),
                ];
            }

            $newSlotSummary = [];

            DB::transaction(function () use ($request, $room, $order, $data, &$updates, &$newSlotSummary) {
                Product::where('id', $room->id)->lockForUpdate()->first();

                // Xoá items cũ TRƯỚC khi dựng lại — để không tự báo trùng với chính đơn này. Nằm
                // trong transaction nên nếu build bên dưới ném lỗi (trùng đơn KHÁC thật sự), toàn
                // bộ rollback, items cũ khôi phục nguyên vẹn.
                $order->items()->delete();

                $rtsCollection = collect();
                $slotSummary   = [];

                if ($request->input('type') === 'slot') {
                    [$basePrice, , $itemsData, $rtsCollection, $slotSummary] = $this->buildSlotItems($request, $room);
                } elseif ($request->input('type') === 'daily') {
                    [$basePrice, , $itemsData, $rtsCollection, $slotSummary] = $this->buildDailyItems($request, $room);
                } else {
                    [$basePrice, , $itemsData] = $this->buildMonthlyItem($request, $room);
                }

                $newSlotSummary = $slotSummary;

                foreach ($itemsData as $itemData) {
                    $order->items()->create($itemData);
                }

                [$guestSurcharge] = $this->buildGuestSurcharge($request, $room, $slotSummary);

                $promotionDiscount = 0;
                $systemDiscount    = 0;
                $hasFullBooking    = ! empty($slotSummary) && $this->checkFullDayBooking($slotSummary, $room);

                if ($hasFullBooking) {
                    [$systemDiscount] = $this->applyFullBookingDiscount($basePrice, $room);
                } else {
                    if ($rtsCollection->isNotEmpty()) {
                        [$promotionDiscount] = $request->input('type') === 'daily'
                            ? $this->applyDailyPromotions($rtsCollection, $slotSummary)
                            : $this->applyPromotions($rtsCollection, $slotSummary);
                    }
                    if (! empty($slotSummary)) {
                        [$systemDiscount] = $this->applyBulkDiscount(count($slotSummary), $room, $basePrice - $promotionDiscount);
                    }
                }

                $slotFinalPrice = max(0, $basePrice - $promotionDiscount - $systemDiscount);

                // Dịch vụ: THAY THẾ toàn bộ nếu có gửi 'services', không thì giữ nguyên (cộng theo DB).
                if (array_key_exists('services', $data)) {
                    [$servicesTotal, $servicesData] = $this->buildServices($request, $room);
                    $order->services()->delete();
                    foreach ($servicesData as $svc) {
                        $order->services()->create($svc);
                    }
                } else {
                    $servicesTotal = (int) $order->services()->sum('subtotal');
                }

                $effectiveSurcharge = array_key_exists('surcharge', $updates) ? (int) $updates['surcharge'] : (int) $order->surcharge;
                $newTotal = (int) $slotFinalPrice + (int) $guestSurcharge + $servicesTotal + $effectiveSurcharge;

                $updates['amount']      = $newTotal;
                $updates['full_amount'] = $newTotal;
                $updates['guest_count'] = (int) $request->guest_count;

                // Đặt cọc: CHỈ áp dụng cho type=daily, và CHỈ đụng tới deposit_percent khi admin có
                // gửi 'payment_type' trong request này — không gửi thì giữ nguyên % cọc hiện có.
                if ($request->input('type') === 'daily' && $request->filled('payment_type')) {
                    if ($request->input('payment_type') === 'deposit') {
                        if ($order->payment_method === 'cod') {
                            throw ValidationException::withMessages([
                                'payment_type' => ['Đặt cọc không áp dụng cho phương thức thanh toán tiền mặt.'],
                            ]);
                        }

                        $depositMin = (int) ($room->deposit_min_nights  ?? 0);
                        $depositPct = (int) ($room->deposit_multi_night ?? 50);
                        $nights     = count($slotSummary);

                        if ($depositMin > 0 && $nights >= $depositMin && $depositPct < 100) {
                            $updates['deposit_percent'] = $depositPct;
                        } else {
                            throw ValidationException::withMessages([
                                'payment_type' => [
                                    'Đặt cọc không áp dụng' . ($depositMin > 0 ? " (cần tối thiểu {$depositMin} đêm)" : '') . '.',
                                ],
                            ]);
                        }
                    } else {
                        // payment_type=full -> huỷ cọc, chuyển hẳn về thanh toán đủ.
                        $updates['deposit_percent'] = null;
                    }
                }
            });

            // Bắn realtime SAU KHI transaction đã commit thành công — khung giờ mới bị chiếm phải
            // báo ngay để ai khác đang xem lịch phòng này không cố chọn trùng (dù khoá DB lúc submit
            // vẫn chặn đúng, đây chỉ để UI cập nhật sớm, giống hệt BookingController::store()).
            $realtimeService = app(SlotRealtimeService::class);

            if ($request->input('type') === 'daily') {
                if (! empty($newSlotSummary)) {
                    $realtimeService->broadcastDailyBooked($room->id, $request->input('checkin_date'), $request->input('checkout_date'));
                }

                // Giải phóng khoảng ngày CŨ — xem broadcastDailyReleased()/pushDaily() trong
                // SlotRealtimeService: cần server WebSocket nội bộ đọc field 'status' để xử lý
                // đúng, nếu chưa cập nhật phía đó thì lệnh gọi này tạm thời chưa có tác dụng.
                if ($oldDailyRange !== null) {
                    $realtimeService->broadcastDailyReleased($room->id, $oldDailyRange['checkin'], $oldDailyRange['checkout']);
                }
            } elseif (! empty($newSlotSummary)) {
                collect($newSlotSummary)->groupBy('date')->each(
                    fn ($slots, $date) => $realtimeService->broadcastBooked($room->id, $date, $slots->pluck('timeslot_id')->values()->toArray())
                );
            }

            // Giải phóng khung giờ CŨ (chỉ có ý nghĩa với type=slot — daily không có API "giải
            // phóng theo khoảng ngày" riêng nên bỏ qua, khách xem lại sẽ tự thấy đúng qua
            // broadcastDailyBooked ở trên hoặc lúc tải lại trang).
            if ($oldSlotSummary->isNotEmpty()) {
                $oldSlotSummary->groupBy('date')->each(
                    fn ($slots, $date) => $realtimeService->broadcastSlotsAvailable($room->id, $date, $slots->pluck('timeslot_id')->values()->toArray())
                );
            }
        } elseif (array_key_exists('guest_count', $data)) {
            // Chỉ đổi số khách, KHÔNG đổi khung giờ -> cập nhật số khách trên items hiện có, không
            // tự tính lại giá (đổi giá do guest_count nên đi kèm 'type' để dựng lại đúng phụ thu).
            $order->items()->update(['guest_count' => $data['guest_count']]);
            $updates['guest_count'] = $data['guest_count'];
        }

        // ── Sửa tổng tiền thủ công (áp dụng SAU CÙNG — luôn thắng số vừa tính lại ở trên) ──────
        if (array_key_exists('amount', $data)) {
            $updates['amount']      = (int) $data['amount'];
            $updates['full_amount'] = (int) $data['amount'];
        }

        // Giá đổi (do bất kỳ nhánh nào ở trên) mà đơn đang có link PayOS cũ -> QR cũ sai số tiền.
        if (isset($updates['amount']) && (int) $updates['amount'] !== $oldAmount && $order->checkout_url) {
            $updates['checkout_url']       = null;
            $updates['qr_code']            = null;
            $updates['current_payos_code'] = null;
        }

        if (empty($updates)) {
            return response()->json(['message' => 'Không có trường nào để cập nhật.'], 422);
        }

        $order->update($updates);
        $order->load(['items.product:id,name', 'category:id,name', 'customer:id,fullname,phone', 'guestCccds']);

        return response()->json([
            'order' => $this->toListItem($order) + [
                'surcharge' => (int) $order->surcharge,
                'cccd_front' => $order->cccd_front ? Storage::disk('public')->url($order->cccd_front) : null,
                'cccd_back'  => $order->cccd_back  ? Storage::disk('public')->url($order->cccd_back)  : null,
                'cccd_data'  => $order->cccd_data,
                'guests'     => $order->guestCccds->map(fn ($g) => [
                    'guest_index' => $g->guest_index,
                    'cccd_front'  => Storage::disk('public')->url($g->cccd_front),
                    'cccd_back'   => Storage::disk('public')->url($g->cccd_back),
                    'cccd_data'   => $g->cccd_data,
                ])->values(),
                'deposit' => $order->deposit_percent !== null ? [
                    'percentage'       => (int) $order->deposit_percent,
                    'deposit_amount'   => $order->depositDueAmount(),
                    'remaining_amount' => (int) $order->full_amount - $order->depositDueAmount(),
                ] : null,
            ],
        ]);
    }

    /**
     * POST /api/admin/orders/{order_code}/preview
     * Tính lại giá cho 1 đơn ĐANG SỬA (đổi phòng/khung giờ/khách/dịch vụ) — body GIỐNG HỆT phần
     * "Đổi khung giờ/ngày" của PUT|POST /api/admin/orders/{order_code} (type, room_id, guest_count,
     * slots[]/checkin_date+checkout_date, services[], payment_type) nhưng KHÔNG ghi gì vào DB (không
     * xoá/tạo lại order_items, không đổi Order) — dùng cho FE hiển thị giá mới trước khi bấm "Lưu".
     * Gọi lại liên tục không có tác dụng phụ (idempotent).
     *
     * Khác update() ở đúng 2 điểm để tính đúng SỐ mà không cần đụng DB:
     *   - room_id/guest_count vẫn tuỳ chọn, mặc định lấy theo phòng/số khách HIỆN CÓ của đơn giống
     *     update() — nhưng 'type' + slots[]/checkin_date+checkout_date LUÔN bắt buộc (không suy được
     *     lịch cũ ngược lại thành slots[] một cách an toàn cho mọi trường hợp phòng đổi cấu hình khung
     *     giờ), khác update() vốn cho bỏ qua 'type' để giữ nguyên lịch — preview chỉ dùng khi FE đang
     *     đổi lịch, không dùng để xem giá không đổi gì.
     *   - Kiểm tra trùng khung giờ (trong buildSlotItems/buildDailyItems) tự loại trừ chính order_items
     *     hiện có của đơn này (xem BuildsRoomBooking::computeBookingPreview $excludeOrderId) — vì
     *     preview KHÔNG xoá items cũ trước như update() thật, nếu không loại trừ sẽ luôn báo trùng
     *     với chính lịch hiện tại của đơn khi FE gửi lại y hệt slot cũ.
     *   - payment_type=deposit xét theo payment_method THẬT của đơn (không đổi được qua API sửa đơn),
     *     không phải theo request — giống hệt update().
     *
     * Response CHỈ gồm các khối đã có sẵn ở response tạo/sửa đơn — không có 'order' (không ghi gì để
     * trả lại toàn bộ đơn):
     *   summary { slots_total, promotion_discount, system_discount, discount_amount, slots_final,
     *             guest_surcharge, services_total, total_after_discount, final_amount }
     *   guest_surcharge, system_discount, deposit — cùng cấu trúc với response tạo/sửa đơn.
     */
    public function preview(Request $request, string $orderCode): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::query()
            ->with(['items.product:id,name'])
            ->where('order_code', $orderCode)
            ->first();

        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        $rules = [
            'type'                   => 'required|in:slot,daily,monthly',
            'room_id'                => 'sometimes|string',
            'guest_count'            => 'sometimes|integer|min:1',
            'payment_type'           => 'sometimes|in:full,deposit',
            'services'               => 'sometimes|nullable|array',
            'services.*.service_id'  => 'required_with:services|integer',
            'services.*.quantity'    => 'required_with:services|integer|min:1',
        ];

        if ($request->input('type') === 'slot') {
            $rules['date']                = 'sometimes|date_format:Y-m-d|after_or_equal:today';
            $rules['slots']               = 'required|array|min:1';
            $rules['slots.*.timeslot_id'] = 'required|integer';
            $rules['slots.*.date']        = 'sometimes|date_format:Y-m-d|after_or_equal:today';
        } else {
            $rules['checkin_date']  = 'required|date|after_or_equal:today';
            $rules['checkout_date'] = 'required|date|after:checkin_date';
        }

        if ($request->input('type') === 'daily') {
            $rules['checkin_date']  = 'required|date_format:Y-m-d|after_or_equal:today';
            $rules['checkout_date'] = 'required|date_format:Y-m-d|after:checkin_date';
        }

        $request->validate($rules);

        // room_id không gửi -> dùng đúng phòng hiện có của đơn, giống update().
        $isRoomChange = $request->filled('room_id');
        $productId    = $isRoomChange ? (string) $request->input('room_id') : $order->items->first()?->product_id;

        $room = Product::where('id', $productId)
            ->where('is_activated', true)
            ->with([
                'roomType',
                'additionalServices',
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            ])
            ->first();

        if (! $room) {
            return response()->json([
                'message' => $isRoomChange
                    ? 'Phòng muốn chuyển đến không tồn tại hoặc đã ngừng hoạt động.'
                    : 'Phòng của đơn này không còn tồn tại hoặc đã ngừng hoạt động.',
            ], 422);
        }

        if ($isRoomChange && ! $admin->isSuperAdmin() && ! $admin->belongsToPlatformPartner() && $room->partner_id !== $admin->partner_id) {
            return response()->json(['message' => 'Bạn không có quyền chuyển sang phòng của đối tác khác.'], 403);
        }

        // guest_count không gửi -> giữ nguyên số khách hiện có của đơn, giống update() — buildSlotItems/
        // buildDailyItems/buildGuestSurcharge đọc guest_count qua $request->guest_count.
        if (! $request->filled('guest_count')) {
            $request->merge(['guest_count' => $order->guest_count]);
        }

        return response()->json($this->computeBookingPreview($request, $room, $order->id, $order->payment_method));
    }

    /**
     * DELETE /api/admin/orders/{order_code}/guests/{guest_index}
     * Xoá CCCD của 1 khách đi cùng (guest_index >= 2) — dùng khi giảm guest_count và không còn cần
     * lưu thông tin khách đó nữa (vd đơn từ 3 khách giảm xuống 2, xoá guest_index=3 vừa dư ra).
     * Xoá luôn 2 file ảnh trong storage, không chỉ xoá bản ghi DB.
     *
     * KHÔNG dùng để xoá CCCD khách chính (guest_index=1 — lưu trực tiếp ở Order.cccd_front/back/
     * data, không nằm trong bảng order_guest_cccds) — muốn thay CCCD khách chính, gửi cccd_front/
     * cccd_back mới qua API cập nhật đơn (PUT/POST /api/admin/orders/{order_code}).
     *
     * Không tự động giảm 'guest_count' của đơn — nếu cần, gọi kèm API cập nhật đơn với guest_count
     * mới (endpoint này chỉ dọn CCCD dư ra, tách biệt để admin chủ động, tránh xoá nhầm hàng loạt).
     */
    public function destroyGuestCccd(Request $request, string $orderCode, int $guestIndex): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::where('order_code', $orderCode)->first();

        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        if ($guestIndex < 2) {
            return response()->json([
                'message' => 'guest_index phải từ 2 trở lên (khách đi cùng) — CCCD khách chính sửa qua API cập nhật đơn, không xoá qua đây.',
            ], 422);
        }

        $guestCccd = $order->guestCccds()->where('guest_index', $guestIndex)->first();

        if (! $guestCccd) {
            return response()->json(['message' => 'Không tìm thấy CCCD khách đi cùng với guest_index này.'], 404);
        }

        if ($guestCccd->cccd_front) {
            Storage::disk('public')->delete($guestCccd->cccd_front);
        }
        if ($guestCccd->cccd_back) {
            Storage::disk('public')->delete($guestCccd->cccd_back);
        }

        $guestCccd->delete();

        return response()->json(['message' => 'Đã xoá CCCD khách đi cùng.', 'guest_index' => $guestIndex]);
    }

    /**
     * DELETE /api/admin/orders/{order_code}
     * Xoá HẲN 1 đơn (hard delete — không phải huỷ đơn qua 'status'). Dọn luôn dữ liệu con
     * (order_items, order_services, order_guest_cccds) + toàn bộ ảnh CCCD liên quan trong storage
     * trước khi xoá bản ghi Order, tránh để lại rác (bảng con không có ràng buộc khoá ngoại
     * CASCADE ở DB, xem order_items migration — không tự dọn theo).
     *
     * KHÔNG kiểm tra status đơn — admin toàn quyền xoá kể cả đơn đã paid/deposit (giống triết lý
     * update() ở trên). Cân nhắc kỹ trước khi gọi vì không thể khôi phục.
     */
    public function destroy(Request $request, string $orderCode): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::with(['items', 'services', 'guestCccds'])
            ->where('order_code', $orderCode)
            ->first();

        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        $this->deleteOrderWithRelations($order);

        return response()->json(['message' => 'Đã xoá đơn.', 'order_code' => $orderCode]);
    }

    /**
     * DELETE /api/admin/orders
     * Xoá NHIỀU đơn cùng lúc theo order_code — body: {"order_codes": ["OC001", "OC002", ...]}.
     * Chỉ xoá đơn thuộc phạm vi đối tác của admin đang đăng nhập (super_admin không giới hạn);
     * order_code không tồn tại hoặc không thuộc phạm vi bị BỎ QUA (không lỗi cả request), liệt kê
     * riêng trong 'not_found' của response để FE biết đơn nào chưa xoá được.
     */
    public function destroyBatch(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $data = $request->validate([
            'order_codes'   => 'required|array|min:1',
            'order_codes.*' => 'string',
        ]);

        $query = Order::with(['items', 'services', 'guestCccds'])
            ->whereIn('order_code', $data['order_codes']);

        if (! $admin->isSuperAdmin()) {
            $query->where('partner_id', $admin->partner_id);
        }

        $orders = $query->get();

        foreach ($orders as $order) {
            $this->deleteOrderWithRelations($order);
        }

        $deletedCodes = $orders->pluck('order_code')->values();
        $notFound     = collect($data['order_codes'])->diff($deletedCodes)->values();

        return response()->json([
            'message'   => "Đã xoá {$deletedCodes->count()} đơn.",
            'deleted'   => $deletedCodes,
            'not_found' => $notFound,
        ]);
    }

    /**
     * Dọn dữ liệu con + file CCCD trong storage rồi xoá bản ghi Order — dùng chung cho destroy()
     * (xoá 1 đơn) và destroyBatch() (xoá nhiều đơn) để không lặp lại logic dọn dẹp.
     */
    private function deleteOrderWithRelations(Order $order): void
    {
        foreach ($order->guestCccds as $guestCccd) {
            if ($guestCccd->cccd_front) Storage::disk('public')->delete($guestCccd->cccd_front);
            if ($guestCccd->cccd_back)  Storage::disk('public')->delete($guestCccd->cccd_back);
        }

        if ($order->cccd_front) Storage::disk('public')->delete($order->cccd_front);
        if ($order->cccd_back)  Storage::disk('public')->delete($order->cccd_back);

        $order->guestCccds()->delete();
        $order->items()->delete();
        $order->services()->delete();
        $order->delete();
    }

    private function toListItem(Order $order): array
    {
        return [
            'id'             => $order->id,
            'order_code'     => $order->order_code,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'order_status'   => $order->order_status,
            'payment_method' => $order->payment_method,
            'amount'         => (int) $order->amount,
            'full_amount'    => (int) $order->full_amount,
            'guest_count'    => $order->guest_count,
            'description'       => $order->description,
            'short_description' => $order->short_description,
            'buyer_name'     => $order->buyer_name,
            'buyer_phone'    => $order->buyer_phone,
            'customer'       => $order->customer ? [
                'id'       => $order->customer->id,
                'fullname' => $order->customer->fullname,
                'phone'    => $order->customer->phone,
            ] : null,
            'branch' => $order->category ? [
                'id'   => $order->category->id,
                'name' => $order->category->name,
            ] : null,
            'rooms'        => $order->items->pluck('product.name')->filter()->unique()->values(),
            // id phòng (products.id) của item ĐẦU TIÊN — cùng giả định "đơn thường 1 phòng" như
            // product_image bên dưới, đủ dùng để FE gọi thẳng GET /admin/rooms/{id}/time-slots từ
            // chi tiết đơn mà không phải tra cứu id phòng qua API khác.
            'product_id' => $order->items->first()?->product_id,
            // slot = đặt theo khung giờ, daily = đặt theo ngày — suy từ Product.styles của phòng ở
            // item ĐẦU TIÊN (đơn multi-room hiếm khi lẫn lộn 2 kiểu phòng), cùng quy ước với mọi
            // nơi khác trong hệ thống (styles=1 => slot, styles=2 => daily, xem BuildsRoomBooking).
            'type' => ((int) ($order->items->first()?->product?->styles ?? 1)) === 2 ? 'daily' : 'slot',
            // Ảnh đại diện đơn — ảnh chính của phòng ở item ĐẦU TIÊN (đơn multi-room hiếm khi khác
            // chi nhánh/loại phòng nhau, đủ dùng làm thumbnail cho danh sách, xem chi tiết đủ ảnh
            // từng phòng qua GET /api/admin/orders/{order_code}).
            'product_image' => $this->productImageUrl($order->items->first()?->product),
            'checkin_date' => $order->items->pluck('checkin_date')->filter()->min(),
            'checkout_date' => $order->items->pluck('checkout_date')->filter()->max(),
            'created_at'   => $order->created_at,
        ];
    }

    // Shape đầy đủ cho GET /api/admin/orders/{order_code} — mọi field không đổi theo request nào
    // (không như update(), vốn chỉ trả field vừa gửi), luôn đọc thẳng từ $order hiện có trong DB.
    private function toDetailItem(Order $order): array
    {
        return $this->toListItem($order) + [
            'lock_info'         => $this->buildLockInfo($order, $order->items->first()?->product),
            'note_for_admin'    => $order->note_for_admin,
            'surcharge'         => (int) $order->surcharge,
            'guest_surcharge'   => $this->buildGuestSurchargeInfo($order),
            'checkout_url'      => $order->checkout_url,
            'qr_code'           => $order->qr_code,
            'expired_at'        => $order->expired_at,
            'paid_at'           => $order->paid_at,
            'deposit_paid_at'   => $order->deposit_paid_at,
            'remaining_paid_at' => $order->remaining_paid_at,
            'checked_in_at'     => $order->checked_in_at,
            'checked_out_at'    => $order->checked_out_at,
            'unlock_anytime'    => (bool) $order->unlock_anytime,
            'creator'           => $order->creator ? [
                'id'       => $order->creator->id,
                'fullname' => $order->creator->fullname,
            ] : null,
            'deposit' => $order->deposit_percent !== null ? [
                'percentage'       => (int) $order->deposit_percent,
                'deposit_amount'   => $order->depositDueAmount(),
                'remaining_amount' => (int) $order->full_amount - $order->depositDueAmount(),
            ] : null,
            // Không lặp lại product_id/product_name/product_image/quantity ở từng item — thông tin
            // phòng đã có sẵn ở cấp đơn ('rooms', 'product_image' trong toListItem()); items[] chỉ
            // còn đúng phần khách chọn theo khung giờ/ngày.
            'items' => $order->items->map(fn ($item) => [
                'id'            => $item->id,
                'price'         => (int) $item->price,
                'checkin_date'  => $item->checkin_date,
                'checkout_date' => $item->checkout_date,
                'slot_label'    => $item->slot_label,
                'timeslot_id'   => $this->resolveItemTimeslotId($item),
                'over_night'    => (bool) $item->over_night,
                'guest_count'   => $item->guest_count,
            ])->values(),
            'services' => $order->services->map(fn ($s) => [
                'id'           => $s->id,
                'service_id'   => $s->service_id,
                'service_name' => $s->service_name,
                'price'        => (int) $s->price,
                'quantity'     => $s->quantity,
                'subtotal'     => (int) $s->subtotal,
            ])->values(),
            // Khách chính — ảnh CCCD đã lưu (dù không gửi gì trong request này vẫn xem lại được,
            // khác update() vốn phải gửi kèm cccd_front/back mới thì mới nhận lại URL trong response).
            'cccd_front' => $order->cccd_front ? Storage::disk('public')->url($order->cccd_front) : null,
            'cccd_back'  => $order->cccd_back  ? Storage::disk('public')->url($order->cccd_back)  : null,
            'cccd_data'  => $order->cccd_data,
            // Khách đi cùng (guest_index từ 2) — bảng order_guest_cccds.
            'guests' => $order->guestCccds->map(fn ($g) => [
                'guest_index' => $g->guest_index,
                'cccd_front'  => $g->cccd_front ? Storage::disk('public')->url($g->cccd_front) : null,
                'cccd_back'   => $g->cccd_back  ? Storage::disk('public')->url($g->cccd_back)  : null,
                'cccd_data'   => $g->cccd_data,
            ])->values(),
        ];
    }

    // Pass cổng/pass phòng để admin xem lại khi tra cứu đơn — cùng logic + mốc thời gian chốt đơn
    // với OrderController (client)::buildLockInfo(), để 2 nơi luôn khớp mật khẩu hiển thị cho 1 đơn.
    private function buildLockInfo(Order $order, ?Product $product): ?array
    {
        if (! $product || ! in_array($order->status, ['paid', 'deposit'])) {
            return null;
        }

        $pwdAnchorDate = $order->paid_at ?? $order->deposit_paid_at ?? $order->created_at;

        $manualPwd = ManualLockPassword::getForProductAndDate($product, $pwdAnchorDate);
        if ($manualPwd) {
            return [
                'type'          => 'manual',
                'gate_password' => $manualPwd->gate_password,
                'room_password' => $manualPwd->room_password,
            ];
        }

        if ($product->lock_id && \Modules\TTLock\App\Services\TTLockService::forCategory($order->category_id)) {
            $accessCode = $order->accessCodes()->first();

            return [
                'type'           => 'ttlock',
                'can_unlock'     => true,
                'gate_password'  => $accessCode?->code,
                'status'         => $accessCode?->status,
                'valid_from'     => $accessCode?->valid_from?->toIso8601String(),
                'valid_until'    => $accessCode?->valid_until?->toIso8601String(),
                'gate_location'  => $accessCode?->gate_location,
            ];
        }

        return null;
    }

    // Phụ thu khách vượt ngưỡng miễn phí của phòng (products.room_config: extra_guest_fee/
    // max_free_guests) — TÍNH LẠI từ dữ liệu đã lưu (order.guest_count + order_items), không đọc số
    // đã cộng vào 'amount' lúc tạo/sửa đơn (không persist riêng), cùng công thức với client
    // (OrderController::buildDetail() -> countNights()/guest_surcharge), để 2 nơi luôn khớp số.
    // Trả null nếu phòng không cấu hình phụ thu hoặc guest_count không vượt ngưỡng.
    private function buildGuestSurchargeInfo(Order $order): ?array
    {
        $product = $order->items->first()?->product;
        if (! $product) {
            return null;
        }

        $config    = $product->room_config ?? [];
        $fee       = (int) ($config['extra_guest_fee'] ?? 0);
        $threshold = (int) ($config['max_free_guests'] ?? 2);
        $guests    = (int) $order->guest_count;
        $extra     = max(0, $guests - $threshold);

        if ($fee <= 0 || $extra <= 0) {
            return null;
        }

        $isSlotType = (int) ($product->styles ?? 1) === 1;
        // slot: đếm số NGÀY riêng biệt (nhiều khung giờ cùng ngày chỉ tính 1 đêm); daily: mỗi item
        // đã là 1 đêm nên đếm trực tiếp số item — cùng quy ước với countNights() phía client.
        $nights = $isSlotType
            ? $order->items->pluck('checkin_date')->filter()->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))->unique()->count()
            : $order->items->count();
        $nights = max(1, $nights);

        $total       = $extra * $fee * $nights;
        $nightsLabel = (! $isSlotType && $nights > 1) ? " × {$nights} đêm" : '';

        return [
            'guest_count'    => $guests,
            'threshold'      => $threshold,
            'extra_guests'   => $extra,
            'fee_per_person' => $fee,
            'nights'         => $nights,
            'total'          => $total,
            'label'          => "Phụ thu {$extra} người (trên {$threshold} người){$nightsLabel}",
        ];
    }

    // Suy timeslot_id của 1 order_item (đơn theo giờ) — order_items không lưu timeslot_id trực
    // tiếp, khớp ngược qua giờ bắt đầu (checkin_date, HH:mm:ss) với RoomTimeSlot lặp lại (date IS
    // NULL) của phòng, cùng cách làm với đoạn "Đổi khung giờ/ngày" ở update(). Đơn theo ngày
    // (daily) hoặc item không xác định được khung giờ khớp -> null.
    private function resolveItemTimeslotId($item): ?int
    {
        if (! $item->checkin_date || ! $item->product) {
            return null;
        }

        $startTime = Carbon::parse($item->checkin_date)->format('H:i:s');
        $rts       = $item->product->roomTimeSlots
            ->first(fn ($rts) => $rts->timeSlot && $rts->timeSlot->start_time === $startTime);

        return $rts?->timeslot_id;
    }

    // Ảnh chính của phòng (Spatie MediaLibrary — cùng thứ tự ưu tiên collection với
    // BuildsRoomCard::getMainImageUrl(), dùng cho room card khách hàng, để 2 nơi luôn khớp nhau).
    private function productImageUrl(?Product $product): ?string
    {
        if (! $product) {
            return null;
        }

        $media = $product->getFirstMedia('Ảnh bìa')
              ?? $product->getFirstMedia('Ảnh chính')
              ?? $product->getFirstMedia();

        return $media?->getUrl();
    }
}
