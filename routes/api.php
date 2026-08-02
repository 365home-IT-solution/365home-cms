<?php

use App\Http\Controllers\Api\Auth\ZaloOtpController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\GraphQLController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\GuestBookingController;
use App\Http\Controllers\Api\GuestNotificationController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderServiceController;
use App\Http\Controllers\Api\AskUserController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\ProvinceController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\WardController;
use App\Http\Controllers\Api\LockRecordCallbackController;
use App\Http\Controllers\Api\UnlockController;
use App\Http\Controllers\Api\DailyRoomController;
use App\Http\Controllers\Api\DailyRoomHoldController;
use App\Http\Controllers\Api\TimeSlotHoldController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomTypeController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;
use Modules\AppPage\App\Http\Controllers\AppPageController;

/*
|--------------------------------------------------------------------------
| TTLock Callback
| Callback URL cần đăng ký trong TTLock Management Center > Application
| URL: https://365home.vn/api/lock/callback
|--------------------------------------------------------------------------
*/
Route::post('lock/callback', [LockRecordCallbackController::class, 'handle'])
    ->name('lock.callback');

/*
|--------------------------------------------------------------------------
| Zalo OTP Auth
| POST /api/auth/send-otp   → Gửi OTP về Zalo của khách
| POST /api/auth/verify-otp → Xác nhận OTP, trả Sanctum token
| POST /api/auth/logout     → Đăng xuất (xoá token hiện tại)
| GET  /api/auth/me         → Thông tin user đang đăng nhập
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Room Types
| GET /api/room-types → Danh sách danh mục phòng (dùng cho select/dropdown)
|--------------------------------------------------------------------------
*/
Route::get('room-types',      [RoomTypeController::class, 'index'])->name('api.room-types.index');
Route::get('v1/room-types/{id}', [RoomTypeController::class, 'show'])->name('api.v1.room-types.show')->whereNumber('id');

/*
|--------------------------------------------------------------------------
| Branches (Chi nhánh)
| GET /api/v1/branches              → Danh sách chi nhánh (?province_id= để lọc theo tỉnh)
| GET /api/v1/branches/{id}         → Chi tiết 1 chi nhánh
|--------------------------------------------------------------------------
*/
Route::get('v1/branches',      [BranchController::class, 'index'])->name('api.v1.branches.index');
Route::get('v1/branches/{id}', [BranchController::class, 'show'])->name('api.v1.branches.show')->whereNumber('id');

/*
|--------------------------------------------------------------------------
| V1 — Home
| GET /api/v1/home                          → Full sections (banner + room_list)
| GET /api/v1/home?tab={id}                 → Banner + room_list lọc theo tab
| GET /api/v1/home?province={id}            → Sections lọc theo tỉnh/thành phố
|
| GET /api/v1/provinces                     → Danh sách tỉnh grouped (Phổ biến + A-Z)
| GET /api/v1/provinces/detect?lat=&lng=    → Tỉnh gần nhất theo GPS
| GET /api/v1/provinces/{id}/branches       → Chi nhánh theo tỉnh (có total_room)
| GET /api/v1/provinces/{slug}              → Chi tiết tỉnh + chi nhánh (slug)
|
| GET /api/v1/ask-user/{id}                 → Nội dung thông báo khi vào app
|
| GET /api/v1/branches/{slug}/time-slots?days=15 → Lịch đặt phòng đầy đủ 1 chi nhánh (mọi phòng
|                                                    theo khung giờ x ngày, kèm giá/khuyến mãi/
|                                                    trạng thái đã đặt) — days tối đa 31.
| GET /api/v1/branches/{branch}/daily-rooms      → Danh sách phòng THEO NGÀY (styles=2) thuộc 1
|                                                    chi nhánh — {branch} nhận id, slug, hoặc tên.
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('home', HomeController::class)->name('home')->middleware('throttle:public-api');

    Route::get('branches/{slug}/time-slots', [BranchController::class, 'timeSlots'])
        ->name('branches.time-slots')
        ->middleware('throttle:public-api');

    Route::get('branches/{branch}/daily-rooms', [BranchController::class, 'dailyRooms'])
        ->name('branches.daily-rooms')
        ->middleware('throttle:public-api');

    Route::prefix('provinces')->name('provinces.')->group(function () {
        Route::get('/',       [ProvinceController::class, 'index'])->name('index');
        Route::get('detect',  [ProvinceController::class, 'detect'])->name('detect');
        Route::get('select',  [ProvinceController::class, 'getSelected'])->name('select.get');
        Route::post('select', [ProvinceController::class, 'select'])->name('select');
        Route::get('{id}',    [ProvinceController::class, 'show'])->name('show')->whereNumber('id');
    });

    Route::get('guest/provinces/{id}', [ProvinceController::class, 'showInfo'])->name('guest.provinces.show')->whereNumber('id');

    Route::get('wards/{code}', [WardController::class, 'showByCode'])->name('wards.show')->whereNumber('code');

    Route::get('ask-user/{id}', [AskUserController::class, 'show'])->name('ask-user.show')->whereNumber('id');

    Route::get('config',     ConfigController::class)->name('config')->middleware('throttle:config');
    Route::get('config/map', [ConfigController::class, 'map'])->name('config.map')->middleware('throttle:config');

    /*
    |--------------------------------------------------------------------------
    | Search
    | GET /api/v1/search/suggestions → Gợi ý điểm đến (màn nhập liệu)
    | GET /api/v1/search             → Kết quả tìm kiếm + marker bản đồ
    | GET /api/v1/search/locations   → Autocomplete địa điểm khi gõ
    |--------------------------------------------------------------------------
    */
    Route::prefix('search')->name('search.')->middleware('throttle:public-api')->group(function () {
        Route::get('suggestions', [SearchController::class, 'suggestions'])->name('suggestions');
        Route::get('locations',   [SearchController::class, 'locations'])->name('locations');
        Route::get('branches',    [SearchController::class, 'branches'])->name('branches');
        Route::get('/',           [SearchController::class, 'index'])->name('index');
    });
});

/*
|--------------------------------------------------------------------------
| GraphQL (pilot — chỉ phục vụ trang kết quả tìm kiếm)
| POST /api/graphql — xem App\Http\Controllers\Api\GraphQLController
|--------------------------------------------------------------------------
*/
Route::post('graphql', [GraphQLController::class, 'handle'])->name('graphql')->middleware('throttle:public-api');

/*
|--------------------------------------------------------------------------
| V2 — Wards (phường / xã)
| GET /api/v2/ward                       → Danh sách tất cả phường/xã
| GET /api/v2/ward?province_code=92      → Lọc theo mã tỉnh
| GET /api/v2/ward?search=bình           → Tìm theo tên
| GET /api/v2/ward?division_type=phường  → Lọc loại đơn vị
| GET /api/v2/ward/{code}               → Chi tiết 1 phường/xã
|--------------------------------------------------------------------------
*/
Route::prefix('v2')->name('api.v2.')->group(function () {
    Route::get('ward',        [WardController::class, 'index'])->name('ward.index');
    Route::get('ward/{code}', [WardController::class, 'show'])->name('ward.show')->whereNumber('code');
});

/*
|--------------------------------------------------------------------------
| App Pages
| GET /api/pages/{slug} → Nội dung trang theo slug (home, payment, ...)
|--------------------------------------------------------------------------
*/
Route::get('pages/{slug}', [AppPageController::class, 'show'])->name('api.pages.show');

/*
|--------------------------------------------------------------------------
| Rooms
| GET /api/rooms/{slug} → Chi tiết phòng (amenities, services, specials,
|                          prices, promotions, coupons)
|--------------------------------------------------------------------------
*/
Route::get('rooms/{id}', [RoomController::class, 'show'])->name('api.rooms.show');
Route::get('slots', [RoomController::class, 'slots'])->name('api.slots');
Route::get('rooms/{id}/guest-surcharge-preview', [RoomController::class, 'guestSurchargePreview'])->name('api.rooms.guest-surcharge-preview');

/*
|--------------------------------------------------------------------------
| Daily Rooms (phòng theo ngày — styles=2)
| GET /api/rooms/{id}/dates?month=YYYY-MM
|     → Lịch tháng: giá, availability, promotion từng ngày (dùng cho calendar)
| GET /api/rooms/{id}/price-preview?checkin=YYYY-MM-DD&checkout=YYYY-MM-DD
|     → Tính giá tạm tính: per-night breakdown, availability, tổng, deposit
|--------------------------------------------------------------------------
*/
Route::get('rooms/{id}/dates',         [DailyRoomController::class, 'dates'])->name('api.rooms.daily.dates');
Route::get('rooms/{id}/price-preview', [DailyRoomController::class, 'pricePreview'])->name('api.rooms.daily.price-preview');
Route::post('rooms/{id}/hold',         [DailyRoomHoldController::class, 'hold'])->name('api.rooms.daily.hold');
Route::delete('rooms/{id}/hold',       [DailyRoomHoldController::class, 'release'])->name('api.rooms.daily.hold.release');

/*
|--------------------------------------------------------------------------
| Time-slot Hold — "đang chọn" tạm thời cho phòng theo khung giờ (style 1)
| POST   /api/rooms/{id}/time-slot-hold → Giữ 1 ô khung giờ x ngày (TTL 10 phút)
| DELETE /api/rooms/{id}/time-slot-hold → Bỏ giữ 1 ô
| Dùng cùng với GET /api/v1/branches/{slug}/time-slots?session_id=... để biết ô nào
| đang bị người khác giữ (held=true, is_selectable=false).
|--------------------------------------------------------------------------
*/
Route::post('rooms/{id}/time-slot-hold',   [TimeSlotHoldController::class, 'hold'])->name('api.rooms.time-slot.hold')->middleware('throttle:hold-slot');
Route::delete('rooms/{id}/time-slot-hold', [TimeSlotHoldController::class, 'release'])->name('api.rooms.time-slot.hold.release')->middleware('throttle:hold-slot');

/*
|--------------------------------------------------------------------------
| Room Ratings — Đánh giá sao
| GET    /api/rooms/{id}/ratings → Danh sách đánh giá + summary (public)
| POST   /api/rooms/{id}/ratings → Tạo / cập nhật đánh giá (auth)
| DELETE /api/rooms/{id}/ratings → Xoá đánh giá của mình (auth)
|--------------------------------------------------------------------------
*/
Route::get('rooms/{id}/ratings', [RatingController::class, 'index'])->name('api.rooms.ratings.index');

Route::middleware(['auth:sanctum', 'customer.active'])->group(function () {
    Route::post('rooms/{id}/ratings',   [RatingController::class, 'store'])->name('api.rooms.ratings.store');
    Route::delete('rooms/{id}/ratings', [RatingController::class, 'destroy'])->name('api.rooms.ratings.destroy');
});

/*
|--------------------------------------------------------------------------
| Dev / Test bypass — chỉ hoạt động khi OTP_BYPASS_ENABLED=true
| GET /api/auth/dev-otp/{phone} → trả mã OTP hiện tại từ cache
|--------------------------------------------------------------------------
*/
if (config('app.otp_bypass_enabled', false)) {
    Route::get('auth/dev-otp/{phone}', function (string $phone) {
        $service = app(\App\Services\ZaloOtpService::class);
        $normalized = $service->normalizePhone($phone);
        $otp = \Illuminate\Support\Facades\Cache::get('zalo_otp:' . $normalized);
        if (! $otp) {
            return response()->json(['message' => 'Không có OTP nào đang chờ cho số này.'], 404);
        }
        return response()->json(['phone' => $normalized, 'otp' => $otp]);
    })->name('api.auth.dev-otp');
}

Route::prefix('auth')->name('api.auth.')->group(function () {
    // Đăng nhập
    Route::post('send-otp',   [ZaloOtpController::class, 'sendOtp'])->name('send-otp')->middleware('throttle:otp-send');
    Route::post('verify-otp', [ZaloOtpController::class, 'verifyOtp'])->name('verify-otp')->middleware('throttle:otp-verify');
    Route::post('login',      [ZaloOtpController::class, 'login'])->name('login')->middleware('throttle:6,1');

    // Đăng ký
    Route::post('register', [ZaloOtpController::class, 'register'])->name('register')->middleware('throttle:register');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout',     [ZaloOtpController::class, 'logout'])->name('logout');
        Route::get('me',          [ZaloOtpController::class, 'me'])->name('me');

        // Các route sau chặn nếu tài khoản bị vô hiệu hóa
        Route::middleware('customer.active')->group(function () {
            Route::post('me',              [ZaloOtpController::class, 'update'])->name('me.update');
            Route::post('change-password', [ZaloOtpController::class, 'changePassword'])->name('change-password');
            Route::post('deactivate',      [ZaloOtpController::class, 'deactivate'])->name('deactivate');
            Route::delete('account',       [ZaloOtpController::class, 'deleteAccount'])->name('delete-account');
            Route::delete('companions/{id}', [ZaloOtpController::class, 'deleteCompanion'])->name('companions.destroy')->whereNumber('id');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Device Token (FCM)
    | POST   /api/auth/device-token → Lưu token thiết bị (sau đăng nhập / token mới)
    | DELETE /api/auth/device-token → Xóa token (đăng xuất / tắt thông báo)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'customer.active'])->group(function () {
        Route::post('device-token',   [DeviceTokenController::class, 'store'])->name('device-token.store');
        Route::delete('device-token', [DeviceTokenController::class, 'destroy'])->name('device-token.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Orders / Booking
| POST /api/orders → Tạo đơn đặt phòng (slot hoặc monthly)
|                    - Nếu có token Sanctum: lấy buyer từ customer
|                    - Nếu không có token:  bắt buộc buyer_name + buyer_phone
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('orders',             [OrderController::class,        'index'])->name('api.orders.index');
    Route::get('orders/{order_code}',[OrderController::class,        'show'])->name('api.orders.show');

    // Các route sau chặn nếu tài khoản bị vô hiệu hóa
    Route::middleware('customer.active')->group(function () {
        Route::post('orders',                                   [BookingController::class,     'store'])->name('api.orders.store');
        Route::post('orders/{order_code}',                   [OrderController::class,       'update'])->name('api.orders.update');
        Route::get('orders/{order_code}/payment-qr',         [OrderController::class,       'paymentQr'])->name('api.orders.payment-qr');
        Route::post('orders/{order_code}/retry-payment',    [OrderController::class, 'retryPayment'])->name('api.orders.retry-payment');
        Route::post('orders/{order_code}/remaining-payment',[OrderController::class, 'remainingPayment'])->name('api.orders.remaining-payment');
        Route::post('orders/{order}/services',              [OrderServiceController::class,'store'])->name('api.orders.services.store');
        Route::post('orders/{order_code}/extra',             [OrderController::class,       'addExtra'])->name('api.orders.extra');
        Route::post('orders/{order_code}/extra-charge-qr',   [OrderController::class,       'extraChargeQr'])->name('api.orders.extra-charge-qr');
        Route::post('orders/{order_code}/unlock',           [UnlockController::class,      'unlock'])->name('api.orders.unlock')->middleware('throttle:10,1');
        Route::get('coupons/mine',             [CouponController::class,     'mine'])->name('api.coupons.mine');
        Route::post('coupons/validate',        [CouponController::class,     'check'])->name('api.coupons.validate');
    });
});

/*
|--------------------------------------------------------------------------
| Guest Booking (không cần đăng nhập)
| POST   /api/guest/orders                                        → Đặt phòng theo giờ / ngày (slot / daily)
|                                                                      payment_type=full   → 100% (mặc định)
|                                                                      payment_type=deposit → 50% (chỉ daily)
| POST   /api/guest/orders/{order_code}                           → Cập nhật đơn (xác thực bằng buyer_phone)
| GET    /api/guest/orders?phone=xxx                              → Tra cứu danh sách đơn theo SĐT
| GET    /api/guest/orders/{order_code}?phone=xx                  → Xem chi tiết đơn theo SĐT
| POST   /api/guest/orders/{order_code}/remaining-payment         → Thanh toán phần còn lại (đặt cọc)
| GET    /api/guest/orders/{order_code}/payment-status?phone=xx  → App polling trạng thái thanh toán
|--------------------------------------------------------------------------
*/
Route::prefix('guest')->name('api.guest.')->group(function () {
    Route::post('orders',                                        [GuestBookingController::class, 'store'])->name('orders.store');
    Route::post('orders/{order_code}',                           [GuestBookingController::class, 'update'])->name('orders.update');
    Route::get('orders',                                         [GuestBookingController::class, 'lookup'])->name('orders.lookup');
    Route::get('orders/{order_code}',                            [GuestBookingController::class, 'show'])->name('orders.show');
    Route::post('orders/{order_code}/remaining-payment',         [GuestBookingController::class, 'remainingPayment'])->name('orders.remaining-payment');
    Route::post('orders/{order_code}/extra',                     [GuestBookingController::class, 'addExtra'])->name('orders.extra');
    Route::post('orders/{order_code}/extra-charge-qr',           [GuestBookingController::class, 'extraChargeQr'])->name('orders.extra-charge-qr');
    Route::get('orders/{order_code}/payment-status',             [GuestBookingController::class, 'paymentStatus'])->name('orders.payment-status');
    Route::post('orders/{order_code}/unlock',                    [UnlockController::class,       'unlockGuest'])->name('orders.unlock')->middleware('throttle:10,1');

    /*
    |--------------------------------------------------------------------------
    | Guest Notifications (xác thực bằng device_token)
    | GET  /api/guest/notifications?device_token=xxx          → Danh sách thông báo
    | POST /api/guest/notifications/read-all                  → Đánh dấu tất cả đã xem
    | POST /api/guest/notifications/{id}/read                 → Đánh dấu 1 thông báo đã xem
    |--------------------------------------------------------------------------
    */
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/',           [GuestNotificationController::class, 'index'])->name('index');
        Route::post('read-all',   [GuestNotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('{id}/read',  [GuestNotificationController::class, 'markRead'])->name('read');
    });
});

/*
|--------------------------------------------------------------------------
| Notifications
| GET  /api/notifications           → Danh sách thông báo đã nhận (có is_read)
| POST /api/notifications/{id}/read → Đánh dấu 1 thông báo đã xem
| POST /api/notifications/read-all  → Đánh dấu tất cả đã xem
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('notifications')->name('api.notifications.')->group(function () {
    Route::get('/',          [NotificationController::class, 'index'])->name('index');
    Route::post('read-all',  [NotificationController::class, 'markAllRead'])->name('read-all');
    Route::post('{id}/read', [NotificationController::class, 'markRead'])->name('read');
});

/*
|--------------------------------------------------------------------------
| Chat — Tin nhắn giữa khách và admin
| GET    /api/chat                        → Lấy / tạo conversation + 20 tin nhắn mới nhất
| GET    /api/chat/messages               → Load thêm tin cũ (?before_id=&limit=)
| POST   /api/chat/messages               → Khách gửi tin nhắn
| POST   /api/chat/read                   → Đánh dấu tất cả tin từ admin là đã đọc
|
| GET    /api/admin/chat                  → Danh sách conversation (admin)
| GET    /api/admin/chat/{id}             → Chi tiết + tin nhắn (admin)
| POST   /api/admin/chat/{id}/messages    → Admin gửi tin nhắn
| POST   /api/admin/chat/{id}/read        → Admin đánh dấu đã đọc
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'customer.active'])->prefix('chat')->name('api.chat.')->group(function () {
    Route::get('/',                        [ChatController::class, 'show'])->name('show');
    Route::get('/order/{order_code}',      [ChatController::class, 'showByOrder'])->name('order');
    Route::get('/messages',                [ChatController::class, 'messages'])->name('messages');
    Route::post('/messages',               [ChatController::class, 'send'])->name('send');
    Route::post('/read',                   [ChatController::class, 'read'])->name('read');
});

/*
|--------------------------------------------------------------------------
| Membership — Hạng thành viên
| GET /api/membership/tiers       → Danh sách hạng (public)
| GET /api/membership             → Hạng + coupon cá nhân của customer (auth)
|--------------------------------------------------------------------------
*/
Route::get('membership/tiers', [MembershipController::class, 'tiers'])->name('api.membership.tiers');

Route::middleware(['auth:sanctum', 'customer.active'])->group(function () {
    Route::get('membership', [MembershipController::class, 'show'])->name('api.membership.show');
});

/*
|--------------------------------------------------------------------------
| Wishlist
| GET  /api/wishlist                    → Danh sách phòng đã lưu
| POST /api/wishlist/{product}/toggle   → Thêm / bỏ khỏi wishlist
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'customer.active'])->prefix('wishlist')->name('api.wishlist.')->group(function () {
    Route::get('/',            [WishlistController::class, 'index'])->name('index');
    Route::post('{id}/toggle', [WishlistController::class, 'toggle'])->name('toggle');
});

