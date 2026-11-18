<?php

namespace Modules\VehicleRental\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Models\VehicleDocument;
use Modules\VehicleRental\App\Services\VehicleAvailabilityService;
use Modules\VehicleRental\App\Services\VehicleBookingService;
use Modules\VehicleRental\App\Services\VehicleKycService;
use Modules\VehicleRental\App\Services\VehiclePayOsService;
use Modules\VehicleRental\App\Services\VehiclePricingService;
use Modules\VehicleRental\App\Services\VehicleRoomLinkService;
use Modules\VehicleRental\App\Support\VehicleTransformer;

/**
 * API khách thuê xe. Xem và báo giá xe là công khai; đặt, xem đơn, huỷ, thanh toán BẮT BUỘC đăng nhập (token Sanctum của
 * khách, middleware customer.active) vì cần KYC khi nhận xe.
 */
class VehicleRentalController extends Controller
{
    public function __construct(
        private VehicleAvailabilityService $availability,
        private VehiclePricingService $pricing,
        private VehicleBookingService $bookings,
        private VehiclePayOsService $payos,
        private VehicleRoomLinkService $roomLink,
    ) {
    }

    /** GET /api/vehicle-rentals/config — công tắc hiển thị thuê xe (CMS) + các giới hạn đặt xe để app/website dựng form đúng. */
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'enabled'            => \Modules\VehicleRental\App\Support\VehicleRentalFeature::clientEnabled(),
            'min_lead_minutes'   => (int) config('vehiclerental.min_lead_minutes'),
            'max_rental_days'    => (int) config('vehiclerental.max_rental_days'),
            'max_advance_days'   => (int) config('vehiclerental.max_advance_days'),
            'hold_minutes'       => (int) config('vehiclerental.hold_minutes'),
            'cancellation_tiers' => array_values((array) config('vehiclerental.cancellation_tiers')),
        ]]);
    }

    /** GET /api/vehicle-rentals/vehicles?start_at&end_at[&type][&branch_id] — xe còn trống kèm báo giá. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_at'  => ['required', 'date'],
            'end_at'    => ['required', 'date', 'after:start_at'],
            'type'      => ['nullable', 'in:' . Vehicle::TYPE_MOTORBIKE . ',' . Vehicle::TYPE_CAR],
            'branch_id' => ['nullable', 'integer'],
            'estimated_km' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $start = Carbon::parse($data['start_at']);
        $end = Carbon::parse($data['end_at']);

        $items = [];
        foreach ($this->availability->search($start, $end, $data['branch_id'] ?? null, $data['type'] ?? null)->load(['branch', 'ratePlans']) as $vehicle) {
            // Báo giá theo thời gian (gói giờ/ngày) và/hoặc theo km (nếu xe có gói km). Có ít nhất 1 cách tính mới hiện xe.
            $quote = null;
            $kmQuote = null;
            try {
                $quote = VehicleTransformer::quote($this->pricing->quote($vehicle->ratePlans, $start, $end));
            } catch (DomainException) {
            }
            if ($this->pricing->kmPlan($vehicle->ratePlans)) {
                try {
                    $kmQuote = $this->pricing->quoteKm($vehicle->ratePlans, $start, $end, $data['estimated_km'] ?? null);
                } catch (DomainException) {
                }
            }
            if ($quote === null && $kmQuote === null) {
                continue; // Xe chưa có gói giá phù hợp với thời lượng này.
            }
            $items[] = VehicleTransformer::vehicle($vehicle, $quote, false, $kmQuote);
        }

        return response()->json(['data' => $items]);
    }

    /** GET /api/vehicle-rentals/vehicles/{id}[?start_at&end_at] — chi tiết xe + các gói giá (+ báo giá + còn trống nếu có thời gian). */
    public function show(Request $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::query()->where('status', Vehicle::STATUS_AVAILABLE)->with(['branch', 'ratePlans'])->findOrFail($id);
        $quote = null;
        $kmQuote = null;
        $available = null;

        if ($request->filled('start_at') && $request->filled('end_at')) {
            $request->validate(['start_at' => ['date'], 'end_at' => ['date', 'after:start_at'], 'estimated_km' => ['nullable', 'integer', 'min:1', 'max:100000']]);
            $start = Carbon::parse($request->input('start_at'));
            $end = Carbon::parse($request->input('end_at'));
            $available = $this->availability->isAvailable($vehicle, $start, $end);
            try {
                $quote = VehicleTransformer::quote($this->pricing->quote($vehicle->ratePlans, $start, $end));
            } catch (DomainException $e) {
                $quote = null;
            }
            if ($this->pricing->kmPlan($vehicle->ratePlans)) {
                try {
                    $kmQuote = $this->pricing->quoteKm($vehicle->ratePlans, $start, $end, $request->filled('estimated_km') ? (int) $request->input('estimated_km') : null);
                } catch (DomainException $e) {
                }
            }
        }

        $payload = VehicleTransformer::vehicle($vehicle, $quote, true, $kmQuote);
        if ($available !== null) {
            $payload['available'] = $available;
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * POST /api/vehicle-rental-bookings — tạo đơn chờ thanh toán + QR (đăng nhập).
     * Thuê xe kèm phòng: truyền room_order_code (mã đơn phòng khách đang thấy, ưu tiên) hoặc room_order_id.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id'      => ['required', 'integer'],
            'start_at'        => ['required', 'date'],
            'end_at'          => ['required', 'date', 'after:start_at'],
            'room_order_id'   => ['nullable', 'integer'],
            'room_order_code' => ['nullable', 'string', 'max:50'],
            'pricing'         => ['nullable', 'in:time,km'],
            'estimated_km'    => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $roomOrderId = $data['room_order_id'] ?? null;
        if (! empty($data['room_order_code'])) {
            $order = $this->roomLink->orderFor((string) $request->user()->id, $data['room_order_code']);
            if (! $order) {
                return response()->json(['message' => 'Đơn phòng không hợp lệ hoặc chưa thanh toán.'], 422);
            }
            $roomOrderId = $order->id;
        }

        return $this->createBooking($request, (int) $data['vehicle_id'], Carbon::parse($data['start_at']), Carbon::parse($data['end_at']), $roomOrderId, ['pricing' => $data['pricing'] ?? 'time', 'estimated_km' => $data['estimated_km'] ?? null]);
    }

    /**
     * GET /api/orders/{order_code}/vehicle-rental — bước "Thuê xe?" của luồng đặt phòng: đơn phòng này có thuê xe kèm được không,
     * khung thuê xe gợi ý (theo thời gian lưu trú), điểm nhận trả xe và các đơn thuê xe đã kèm đơn phòng này.
     */
    public function roomOrderSummary(Request $request, string $orderCode): JsonResponse
    {
        $order = $this->roomOrder($request, $orderCode);
        $stay = $this->roomLink->stayWindow($order);
        $suggested = $this->roomLink->suggestedWindow($order);
        $reason = $this->roomLink->ineligibleReason($order);
        $branchId = (int) config('vehiclerental.default_branch_id');

        return response()->json(['data' => [
            'order' => [
                'order_code'    => $order->order_code,
                'status'        => $order->status,
                'order_status'  => $order->order_status,
                'stay_start_at' => $stay ? $stay[0]->toIso8601String() : null,
                'stay_end_at'   => $stay ? $stay[1]->toIso8601String() : null,
            ],
            'eligible'         => $reason === null && $suggested !== null,
            'reason'           => $reason ?? ($suggested === null ? 'Không còn khung giờ phù hợp để thuê xe kèm.' : null),
            'tolerance_hours'  => (int) config('vehiclerental.room_link_tolerance_hours', 6),
            'suggested_window' => $suggested ? ['start_at' => $suggested['start_at']->toIso8601String(), 'end_at' => $suggested['end_at']->toIso8601String()] : null,
            'pickup_branch'    => ['id' => $branchId, 'name' => \Modules\Category\Entities\Category::query()->withoutGlobalScopes()->whereKey($branchId)->value('name')],
            'linked_bookings'  => $this->roomLink->linkedBookings($order, (string) $request->user()->id)
                ->map(fn (VehicleBooking $b) => VehicleTransformer::booking($b) + ['room_order_status' => $order->status])->values(),
        ]]);
    }

    /**
     * GET /api/orders/{order_code}/vehicle-rental/vehicles[?start_at&end_at&type] — xe còn trống kèm báo giá cho khung thuê xe
     * của đơn phòng (mặc định = khung gợi ý theo thời gian lưu trú).
     */
    public function roomOrderVehicles(Request $request, string $orderCode): JsonResponse
    {
        $order = $this->roomOrder($request, $orderCode);
        $data = $request->validate([
            'start_at' => ['nullable', 'date'],
            'end_at'   => ['nullable', 'date', 'after:start_at'],
            'type'     => ['nullable', 'in:' . Vehicle::TYPE_MOTORBIKE . ',' . Vehicle::TYPE_CAR],
        ]);

        if ($reason = $this->roomLink->ineligibleReason($order)) {
            return response()->json(['message' => $reason], 422);
        }

        [$start, $end] = $this->roomWindow($order, $data);
        if (! $start) {
            return response()->json(['message' => 'Không còn khung giờ phù hợp để thuê xe kèm.'], 422);
        }
        try {
            $this->roomLink->assertWithinStay($order, $start, $end);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $items = [];
        foreach ($this->availability->search($start, $end, (int) config('vehiclerental.default_branch_id'), $data['type'] ?? null)->load(['branch', 'ratePlans']) as $vehicle) {
            try {
                $quote = $this->pricing->quote($vehicle->ratePlans, $start, $end);
            } catch (DomainException) {
                continue;
            }
            $items[] = VehicleTransformer::vehicle($vehicle, VehicleTransformer::quote($quote));
        }

        return response()->json(['data' => $items, 'window' => ['start_at' => $start->toIso8601String(), 'end_at' => $end->toIso8601String()]]);
    }

    /**
     * POST /api/orders/{order_code}/vehicle-rental — đặt xe kèm đơn phòng. Body: vehicle_id; start_at, end_at (tuỳ chọn, mặc định
     * khung gợi ý theo thời gian lưu trú). Trả đơn chờ thanh toán + QR như POST /api/vehicle-rental-bookings.
     */
    public function roomOrderBook(Request $request, string $orderCode): JsonResponse
    {
        $order = $this->roomOrder($request, $orderCode);
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'start_at'   => ['nullable', 'date'],
            'end_at'     => ['nullable', 'date', 'after:start_at'],
        ]);

        if ($reason = $this->roomLink->ineligibleReason($order)) {
            return response()->json(['message' => $reason], 422);
        }
        [$start, $end] = $this->roomWindow($order, $data);
        if (! $start) {
            return response()->json(['message' => 'Không còn khung giờ phù hợp để thuê xe kèm.'], 422);
        }

        return $this->createBooking($request, (int) $data['vehicle_id'], $start, $end, $order->id);
    }

    /** Khung thuê xe của đơn phòng: truyền đủ start_at + end_at thì dùng, không thì lấy khung gợi ý. @return array{0: ?Carbon, 1: ?Carbon} */
    private function roomWindow(\Modules\Payment\Entities\Order $order, array $data): array
    {
        if (! empty($data['start_at']) && ! empty($data['end_at'])) {
            return [Carbon::parse($data['start_at']), Carbon::parse($data['end_at'])];
        }
        $suggested = $this->roomLink->suggestedWindow($order);

        return $suggested ? [$suggested['start_at'], $suggested['end_at']] : [null, null];
    }

    private function roomOrder(Request $request, string $orderCode): \Modules\Payment\Entities\Order
    {
        $order = $this->roomLink->orderFor((string) $request->user()->id, $orderCode);
        abort_if(! $order, 404, 'Không tìm thấy đơn phòng.');

        return $order;
    }

    /** Dùng chung cho đặt xe độc lập và đặt xe kèm phòng. */
    private function createBooking(Request $request, int $vehicleId, Carbon $start, Carbon $end, ?int $roomOrderId, array $options = []): JsonResponse
    {
        $vehicle = Vehicle::query()->where('status', Vehicle::STATUS_AVAILABLE)->find($vehicleId);
        if (! $vehicle) {
            return response()->json(['message' => 'Xe không tồn tại hoặc đã ngừng cho thuê.'], 404);
        }

        try {
            $booking = $this->bookings->create((string) $request->user()->id, $vehicle, $start, $end, $roomOrderId, $options);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Tạo link thanh toán lỗi (PayOS) không làm mất đơn: khách gọi lại POST .../payment để lấy QR.
        $paymentError = null;
        try {
            $booking = $this->payos->createLink($booking);
        } catch (\RuntimeException $e) {
            $paymentError = $e->getMessage();
        }

        $payload = VehicleTransformer::booking($booking->load(['vehicle.branch', 'roomOrder']), true);
        if ($paymentError) {
            $payload['payment_error'] = $paymentError;
        }

        return response()->json(['data' => $payload], 201);
    }

    /** GET /api/vehicle-rental-bookings — đơn của tôi. */
    public function index(Request $request): JsonResponse
    {
        $rows = VehicleBooking::query()->with(['vehicle.branch', 'roomOrder'])
            ->where('customer_id', (string) $request->user()->id)
            ->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($b) => VehicleTransformer::booking($b))->values()]);
    }

    /** GET /api/vehicle-rental-bookings/{code} — kèm kyc_blocker: lý do giấy tờ chưa đủ điều kiện nhận xe (null = đủ). */
    public function showBooking(Request $request, string $code): JsonResponse
    {
        $booking = $this->mine($request, $code);
        $payload = VehicleTransformer::booking($booking, true);
        $payload['kyc_blocker'] = $booking->vehicle
            ? app(VehicleKycService::class)->ineligibleReason((string) $request->user()->id, $booking->vehicle, $booking->end_at)
            : null;
        $payload['charges'] = $booking->charges()->get()->map(fn ($c) => VehicleTransformer::charge($c))->values();

        return response()->json(['data' => $payload]);
    }

    /** GET /api/vehicle-rental-documents — trạng thái giấy tờ của tôi (null nếu chưa nộp). */
    public function documentShow(Request $request): JsonResponse
    {
        $doc = VehicleDocument::query()->where('customer_id', (string) $request->user()->id)->first();

        return response()->json(['data' => $doc ? VehicleTransformer::document($doc, false) : null]);
    }

    /**
     * POST /api/vehicle-rental-documents (multipart) — nộp / nộp lại giấy tờ, trạng thái về "chờ duyệt".
     * Body: full_name, id_number, license_number, license_class, license_expires_on; ảnh: id_front, id_back, license_front, license_back, selfie.
     * Có thể chỉ gửi các trường/ảnh cần thay.
     */
    public function documentStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name'          => ['nullable', 'string', 'max:150'],
            'id_number'          => ['nullable', 'string', 'max:30'],
            'license_number'     => ['nullable', 'string', 'max:30'],
            'license_class'      => ['nullable', 'string', 'max:10'],
            'license_expires_on' => ['nullable', 'date'],
            'id_front'           => ['nullable', 'image', 'max:8192'],
            'id_back'            => ['nullable', 'image', 'max:8192'],
            'license_front'      => ['nullable', 'image', 'max:8192'],
            'license_back'       => ['nullable', 'image', 'max:8192'],
            'selfie'             => ['nullable', 'image', 'max:8192'],
        ]);

        $doc = app(VehicleKycService::class)->submit(
            (string) $request->user()->id,
            collect($data)->only(['full_name', 'id_number', 'license_number', 'license_class', 'license_expires_on'])->filter(fn ($v) => $v !== null)->all(),
            $request->allFiles(),
        );

        return response()->json(['data' => VehicleTransformer::document($doc, false)], 201);
    }

    /** POST /api/vehicle-rental-bookings/{code}/payment — lấy lại / tạo lại QR cho đơn chờ thanh toán. */
    public function payment(Request $request, string $code): JsonResponse
    {
        $booking = $this->mine($request, $code);

        if ($booking->status !== VehicleBooking::STATUS_PENDING_PAYMENT) {
            return response()->json(['message' => 'Đơn không ở trạng thái chờ thanh toán.'], 422);
        }
        if ($booking->hold_expires_at && $booking->hold_expires_at->isPast()) {
            return response()->json(['message' => 'Đơn đã hết hạn giữ chỗ, vui lòng đặt lại.'], 422);
        }

        try {
            $booking = $this->payos->createLink($booking);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => VehicleTransformer::booking($booking, true)]);
    }

    /** POST /api/vehicle-rental-bookings/{code}/cancel — body: reason (tuỳ chọn). */
    public function cancel(Request $request, string $code): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $booking = $this->mine($request, $code);

        try {
            $booking = $this->bookings->cancel($booking, $request->input('reason'));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => VehicleTransformer::booking($booking->load('vehicle.branch'))]);
    }

    private function mine(Request $request, string $code): VehicleBooking
    {
        return VehicleBooking::query()->with(['vehicle.branch', 'roomOrder'])
            ->where('code', $code)->where('customer_id', (string) $request->user()->id)->firstOrFail();
    }
}
