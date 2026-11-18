<?php

namespace Modules\VehicleRental\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBlock;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Models\VehicleRatePlan;
use Modules\VehicleRental\App\Services\VehicleAvailabilityService;
use Modules\VehicleRental\App\Models\VehicleCharge;
use Modules\VehicleRental\App\Models\VehicleDocument;
use Modules\VehicleRental\App\Services\VehicleBookingService;
use Modules\VehicleRental\App\Services\VehicleHandoverService;
use Modules\VehicleRental\App\Services\VehicleKycService;
use Modules\VehicleRental\App\Services\VehicleRatePlanDefaults;
use Modules\VehicleRental\App\Models\VehicleGpsAlert;
use Modules\VehicleRental\App\Services\VehicleGpsAlertService;
use Modules\VehicleRental\App\Services\VehicleGpsService;
use Modules\VehicleRental\App\Services\VehicleScheduleService;
use Modules\VehicleRental\App\Support\VehicleRentalAccess;
use Modules\VehicleRental\App\Support\VehicleTransformer;

/**
 * API admin thuê xe — bản REST của màn CMS "Thuê xe" (VehicleResource, VehicleBookingResource), dùng chung service.
 * Đội xe, gói giá, khoá xe: chỉ Super Admin (guard()). Đơn thuê, giấy tờ, giao/nhận/tất toán: Super Admin hoặc vai trò
 * "Nhân viên thuê xe" (staffGuard()) — các endpoint vận hành nằm ở VehicleRentalOperationsController.
 */
class VehicleRentalAdminController extends Controller
{
    /** Ảnh biên bản vừa lưu trong request này (để dọn nếu thao tác bị từ chối). @var string[] */
    private array $storedPhotos = [];

    public function __construct(
        private VehicleAvailabilityService $availability,
        private VehicleBookingService $bookings,
        private VehicleHandoverService $handover,
        private VehicleKycService $kyc,
        private VehicleScheduleService $schedule,
        private VehicleGpsService $gps,
        private VehicleGpsAlertService $gpsAlerts,
    ) {
    }

    /**
     * Kiểm tra quyền theo QUYỀN của hệ thống Vai trò & Phân quyền (xem VehicleRentalAccess). 403 nếu thiếu quyền.
     * Ability: view_vehicles · manage_vehicles · view_bookings · operate_bookings · documents · schedule · alerts · vehicle_gps.
     */
    private function deny(Request $request, string $ability): ?JsonResponse
    {
        $u = $request->user();
        [$allowed, $permission] = match ($ability) {
            'view_vehicles'    => [VehicleRentalAccess::canViewVehicles($u), 'view_rental_vehicles'],
            'manage_vehicles'  => [VehicleRentalAccess::canManageVehicles($u), 'manage_rental_vehicles'],
            'view_bookings'    => [VehicleRentalAccess::canViewBookings($u), 'view_rental_bookings'],
            'operate_bookings' => [VehicleRentalAccess::canOperateBookings($u), 'operate_rental_bookings'],
            'documents'        => [VehicleRentalAccess::canReviewDocuments($u), 'review_rental_documents'],
            'schedule'         => [VehicleRentalAccess::canViewSchedule($u), 'view_rental_schedule'],
            'alerts'           => [VehicleRentalAccess::canViewGpsAlerts($u), 'view_rental_gps_alerts'],
            'vehicle_gps'      => [VehicleRentalAccess::canViewVehicles($u) || VehicleRentalAccess::canViewBookings($u), 'view_rental_vehicles'],
        };

        return $allowed ? null : response()->json(['message' => 'Bạn không có quyền thực hiện thao tác này (cần quyền ' . $permission . ').'], 403);
    }

    /** GET /api/admin/vehicle-rental/vehicles?type&status&branch_id&q */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request, 'view_vehicles')) {
            return $denied;
        }

        $rows = Vehicle::query()->with(['branch', 'ratePlans'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('plate', 'like', '%' . Vehicle::normalizePlate((string) $request->input('q')) . '%')
                ->orWhere('brand', 'like', '%' . $request->input('q') . '%')
                ->orWhere('model', 'like', '%' . $request->input('q') . '%')))
            ->orderBy('sort_order')->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Vehicle $v) => $this->adminVehicle($v))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /** GET /api/admin/vehicle-rental/vehicles/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->deny($request, 'view_vehicles')) {
            return $denied;
        }
        $vehicle = Vehicle::query()->with(['branch', 'ratePlans', 'blocks'])->findOrFail($id);

        return response()->json(['data' => $this->adminVehicle($vehicle, true)]);
    }

    /** POST /api/admin/vehicle-rental/vehicles — tạo xe, mặc định tự sinh bộ gói giá mẫu (seed_default_plans=false để tắt). */
    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request, 'manage_vehicles')) {
            return $denied;
        }
        $data = $this->validated($request, null);
        $seed = $request->boolean('seed_default_plans', true);
        $motrakId = $data['motrak_vehicle_id'] ?? null;
        unset($data['motrak_vehicle_id']);

        $vehicle = Vehicle::create($data + ['branch_id' => $data['branch_id'] ?? config('vehiclerental.default_branch_id')]);
        if ($seed) {
            VehicleRatePlanDefaults::seed($vehicle);
        }

        // Gắn GPS: theo motrak_vehicle_id; không gửi thì tự gắn nếu biển số khớp duy nhất một thiết bị chưa ghép.
        $linker = app(\Modules\VehicleRental\App\Services\VehicleGpsLinker::class);
        try {
            $motrakId ? $linker->link($vehicle, (int) $motrakId) : $linker->autoLinkByPlate($vehicle);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => $this->adminVehicle($vehicle->load(['branch', 'ratePlans']), true)], 422);
        }

        return response()->json(['data' => $this->adminVehicle($vehicle->load(['branch', 'ratePlans']), true)], 201);
    }

    /** PUT /api/admin/vehicle-rental/vehicles/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->deny($request, 'manage_vehicles')) {
            return $denied;
        }
        $vehicle = Vehicle::query()->findOrFail($id);
        $data = $this->validated($request, $vehicle);
        $hasLink = array_key_exists('motrak_vehicle_id', $data);
        $motrakId = $data['motrak_vehicle_id'] ?? null;
        unset($data['motrak_vehicle_id']);
        $vehicle->update($data);

        if ($hasLink) { // gửi null = bỏ gắn GPS; không gửi = giữ nguyên
            try {
                app(\Modules\VehicleRental\App\Services\VehicleGpsLinker::class)->link($vehicle, $motrakId ? (int) $motrakId : null);
            } catch (DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json(['data' => $this->adminVehicle($vehicle->load(['branch', 'ratePlans', 'blocks']), true)]);
    }

    /**
     * POST /api/admin/vehicle-rental/vehicles/{id}/rentable — nút bật/tắt nhanh. Body: enabled (bool).
     * Bật = status "available"; tắt = "inactive". Đơn đã đặt không bị huỷ, chỉ chặn đặt mới (trả kèm số đơn sắp tới).
     */
    public function rentable(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->deny($request, 'manage_vehicles')) {
            return $denied;
        }
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $vehicle = Vehicle::query()->findOrFail($id);
        $vehicle->setRentable((bool) $data['enabled']);

        return response()->json(['data' => $this->adminVehicle($vehicle->load(['branch', 'ratePlans']), true), 'upcoming_bookings' => $vehicle->upcomingBookingsCount()]);
    }

    /** DELETE /api/admin/vehicle-rental/vehicles/{id} — chặn khi còn đơn đang giữ xe. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->deny($request, 'manage_vehicles')) {
            return $denied;
        }
        $vehicle = Vehicle::query()->findOrFail($id);
        if ($vehicle->bookings()->whereIn('status', VehicleBooking::BLOCKING_STATUSES)->exists()) {
            return response()->json(['message' => 'Xe còn đơn thuê đang giữ. Hoàn tất/huỷ các đơn đó hoặc chuyển xe sang "Ngừng cho thuê".'], 422);
        }
        $vehicle->delete();

        return response()->json(['message' => 'Đã xoá xe.']);
    }

    /**
     * PUT /api/admin/vehicle-rental/vehicles/{id}/rate-plans — đặt lại TOÀN BỘ gói giá của xe.
     * Body: plans[] { id?, name, unit(hour|day), duration_hours, price, weekend_price?, included_km?, extra_km_price,
     * overtime_grace_minutes, overtime_hour_price, is_active, sort_order }. Gói có id được sửa, không id được tạo mới,
     * gói không còn trong danh sách bị xoá. Đơn đã đặt không đổi giá (lưu snapshot).
     */
    public function syncRatePlans(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->deny($request, 'manage_vehicles')) {
            return $denied;
        }
        $vehicle = Vehicle::query()->findOrFail($id);

        $request->validate([
            'plans'                            => ['present', 'array'],
            'plans.*.id'                       => ['nullable', 'integer'],
            'plans.*.name'                     => ['required', 'string', 'max:100'],
            'plans.*.unit'                     => ['required', 'in:hour,day,km'],
            'plans.*.duration_hours'           => ['required_unless:plans.*.unit,km', 'nullable', 'integer', 'min:1', 'max:24'],
            'plans.*.min_km'                   => ['nullable', 'integer', 'min:1'],
            'plans.*.price'                    => ['required', 'integer', 'min:1'],
            'plans.*.weekend_price'            => ['nullable', 'integer', 'min:1'],
            'plans.*.included_km'              => ['nullable', 'integer', 'min:0'],
            'plans.*.extra_km_price'           => ['nullable', 'integer', 'min:0'],
            'plans.*.overtime_grace_minutes'   => ['nullable', 'integer', 'min:0'],
            'plans.*.overtime_hour_price'      => ['nullable', 'integer', 'min:0'],
            'plans.*.is_active'                => ['nullable', 'boolean'],
            'plans.*.sort_order'               => ['nullable', 'integer'],
        ]);

        $keep = [];
        foreach ($request->input('plans', []) as $i => $row) {
            $attrs = [
                'name'                   => $row['name'],
                'unit'                   => $row['unit'],
                'duration_hours'         => $row['unit'] === VehicleRatePlan::UNIT_DAY ? 24 : (int) ($row['duration_hours'] ?? 24),
                'price'                  => (int) $row['price'],
                'weekend_price'          => $row['unit'] === VehicleRatePlan::UNIT_DAY ? ($row['weekend_price'] ?? null) : null,
                'included_km'            => $row['unit'] === VehicleRatePlan::UNIT_KM ? null : ($row['included_km'] ?? null),
                'extra_km_price'         => $row['unit'] === VehicleRatePlan::UNIT_KM ? 0 : (int) ($row['extra_km_price'] ?? 0),
                'min_km'                 => $row['unit'] === VehicleRatePlan::UNIT_KM ? ($row['min_km'] ?? null) : null,
                'overtime_grace_minutes' => (int) ($row['overtime_grace_minutes'] ?? 30),
                'overtime_hour_price'    => (int) ($row['overtime_hour_price'] ?? 0),
                'is_active'              => (bool) ($row['is_active'] ?? true),
                'sort_order'             => (int) ($row['sort_order'] ?? $i),
            ];
            $plan = ! empty($row['id']) ? $vehicle->ratePlans()->whereKey($row['id'])->first() : null;
            $plan ? $plan->update($attrs) : $plan = $vehicle->ratePlans()->create($attrs);
            $keep[] = $plan->id;
        }
        $vehicle->ratePlans()->whereNotIn('id', $keep)->delete();

        return response()->json(['data' => $vehicle->ratePlans()->get()->map(fn ($p) => VehicleTransformer::plan($p))->values()]);
    }

    /** GET /api/admin/vehicle-rental/vehicles/{id}/blocks */
    public function blocks(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->deny($request, 'view_vehicles')) {
            return $denied;
        }
        $vehicle = Vehicle::query()->findOrFail($id);

        return response()->json(['data' => $vehicle->blocks()->orderByDesc('start_at')->get()]);
    }

    /** POST /api/admin/vehicle-rental/vehicles/{id}/blocks — body: start_at, end_at, reason?. Không cho khoá chồng đơn đang giữ xe. */
    public function storeBlock(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->deny($request, 'manage_vehicles')) {
            return $denied;
        }
        $vehicle = Vehicle::query()->findOrFail($id);
        $data = $request->validate([
            'start_at' => ['required', 'date'],
            'end_at'   => ['required', 'date', 'after:start_at'],
            'reason'   => ['nullable', 'string', 'max:255'],
        ]);

        $codes = $this->availability->conflictingBookings(Carbon::parse($data['start_at']), Carbon::parse($data['end_at']))
            ->where('vehicle_id', $vehicle->id)->pluck('code');
        if ($codes->isNotEmpty()) {
            return response()->json(['message' => 'Khoảng khoá trùng đơn đang giữ xe: ' . $codes->implode(', ')], 422);
        }

        $block = $vehicle->blocks()->create($data + ['created_by' => (string) $request->user()->id]);

        return response()->json(['data' => $block], 201);
    }

    /** DELETE /api/admin/vehicle-rental/vehicles/{id}/blocks/{blockId} */
    public function destroyBlock(Request $request, int $id, int $blockId): JsonResponse
    {
        if ($denied = $this->deny($request, 'manage_vehicles')) {
            return $denied;
        }
        VehicleBlock::query()->where('vehicle_id', $id)->findOrFail($blockId)->delete();

        return response()->json(['message' => 'Đã xoá khoảng khoá.']);
    }

    /** GET /api/admin/vehicle-rental/bookings?status&vehicle_id&q&needs_refund=1 */
    public function bookingsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request, 'view_bookings')) {
            return $denied;
        }

        $rows = VehicleBooking::query()->with(['vehicle.branch', 'customer', 'roomOrder'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('code', 'like', '%' . $request->input('q') . '%'))
            ->when($request->boolean('needs_refund'), fn ($q) => $q->where('refund_amount', '>', 0)->whereNull('refunded_at'))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (VehicleBooking $b) => $this->adminBooking($b))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /** GET /api/admin/vehicle-rental/bookings/{code} */
    public function bookingShow(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'view_bookings')) {
            return $denied;
        }

        return response()->json(['data' => $this->adminBooking($this->booking($code), true)]);
    }

    /** POST /api/admin/vehicle-rental/bookings/{code}/cancel — nhân viên huỷ đơn: hoàn 100% số đã thu. */
    public function bookingCancel(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'operate_bookings')) {
            return $denied;
        }
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $booking = $this->bookings->cancel($this->booking($code), $request->input('reason'), true);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->adminBooking($booking->load(['vehicle.branch', 'customer']), true)]);
    }

    /** POST /api/admin/vehicle-rental/bookings/{code}/confirm-payment — xác nhận thủ công đã nhận đủ tiền (body: note tuỳ chọn). */
    public function bookingConfirmPayment(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'operate_bookings')) {
            return $denied;
        }
        $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        try {
            $booking = $this->bookings->confirmPaymentManually($this->booking($code), (string) $request->user()->id, $request->input('note'));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->adminBooking($booking->load(['vehicle.branch', 'customer']), true)]);
    }

    /** POST /api/admin/vehicle-rental/bookings/{code}/refunded — đánh dấu đã chuyển khoản hoàn tiền cho khách. */
    public function bookingRefunded(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'operate_bookings')) {
            return $denied;
        }

        return $this->run($request, $code, fn (VehicleBooking $b) => $this->bookings->markRefunded($b));
    }

    private function booking(string $code): VehicleBooking
    {
        return VehicleBooking::query()->with(['vehicle.branch', 'customer', 'roomOrder'])->where('code', $code)->firstOrFail();
    }

    private function adminVehicle(Vehicle $v, bool $detail = false): array
    {
        $data = VehicleTransformer::vehicle($v, null, false) + [
            'status'                  => $v->status,
            'odometer_km'             => $v->odometer_km,
            'registration_expires_on' => $v->registration_expires_on?->toDateString(),
            'insurance_expires_on'    => $v->insurance_expires_on?->toDateString(),
            'motrak_vehicle_id'       => app(\Modules\VehicleRental\App\Services\VehicleGpsLinker::class)->currentId($v),
            'sort_order'              => $v->sort_order,
            'rate_plans'              => $v->ratePlans->map(fn ($p) => VehicleTransformer::plan($p))->values()->all(),
        ];
        if ($detail) {
            $data['blocks'] = $v->relationLoaded('blocks') ? $v->blocks->sortByDesc('start_at')->values() : [];
            $data['gps'] = $this->gps->current($v);
        }

        return $data;
    }

    private function adminBooking(VehicleBooking $b, bool $detail = false): array
    {
        $data = VehicleTransformer::booking($b, $detail) + [
            'customer' => $b->customer ? ['id' => $b->customer->id, 'fullname' => $b->customer->fullname, 'phone' => $b->customer->phone] : null,
        ];
        if ($detail) {
            $data['pricing_snapshot'] = $b->pricing_snapshot;
            $data['handovers'] = $b->handovers()->get()->map(fn ($h) => VehicleTransformer::handover($h))->values();
            $data['charges'] = $b->charges()->get()->map(fn ($c) => VehicleTransformer::charge($c))->values();
            $data['settlement_preview'] = $this->handover->settlementPreview($b);
            $data['kyc_blocker'] = $b->vehicle ? $this->kyc->ineligibleReason($b->customer_id, $b->vehicle, $b->end_at) : null;
            $data['gps'] = $this->gps->bookingSummary($b);
        }

        return $data;
    }

    /**
     * GET /api/admin/vehicle-rental/schedule?from&to&branch_id&type — lịch xe dạng Gantt (cùng dữ liệu với trang CMS "Lịch xe").
     * Mỗi xe một hàng kèm các đơn thuê và khoảng khoá xe giao với [from, to). Mặc định từ hôm nay, 14 ngày; tối đa 62 ngày.
     */
    public function schedule(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request, 'schedule')) {
            return $denied;
        }
        $request->validate([
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'type'      => ['nullable', Rule::in([Vehicle::TYPE_MOTORBIKE, Vehicle::TYPE_CAR])],
        ]);

        try {
            [$from, $to] = $this->schedule->normalizeRange($request->input('from'), $request->input('to'));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->schedule->build($from, $to, $request->integer('branch_id') ?: null, $request->input('type'))]);
    }

    // ---- Vận hành quầy: giấy tờ, giao xe, nhận xe, phụ phí, tất toán ----

    /** GET /api/admin/vehicle-rental/documents?status=pending|approved|rejected */
    public function documentsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request, 'documents')) {
            return $denied;
        }

        $rows = VehicleDocument::query()->with('customer')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByRaw("status = 'pending' desc")->orderByDesc('updated_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (VehicleDocument $d) => VehicleTransformer::document($d))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /** GET /api/admin/vehicle-rental/documents/{customerId} */
    public function documentShow(Request $request, string $customerId): JsonResponse
    {
        if ($denied = $this->deny($request, 'documents')) {
            return $denied;
        }

        return response()->json(['data' => VehicleTransformer::document(VehicleDocument::query()->with('customer')->where('customer_id', $customerId)->firstOrFail())]);
    }

    /** POST /api/admin/vehicle-rental/documents/{customerId}/approve */
    public function documentApprove(Request $request, string $customerId): JsonResponse
    {
        if ($denied = $this->deny($request, 'documents')) {
            return $denied;
        }
        $doc = VehicleDocument::query()->where('customer_id', $customerId)->firstOrFail();

        try {
            $this->kyc->approve($doc, (string) $request->user()->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => VehicleTransformer::document($doc->fresh('customer'))]);
    }

    /** POST /api/admin/vehicle-rental/documents/{customerId}/reject — body: reason */
    public function documentReject(Request $request, string $customerId): JsonResponse
    {
        if ($denied = $this->deny($request, 'documents')) {
            return $denied;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $doc = VehicleDocument::query()->where('customer_id', $customerId)->firstOrFail();
        $this->kyc->reject($doc, $data['reason'], (string) $request->user()->id);

        return response()->json(['data' => VehicleTransformer::document($doc->fresh('customer'))]);
    }

    /**
     * POST /api/admin/vehicle-rental/bookings/{code}/pickup — giao xe (multipart).
     * Body: odometer_km, deposit_collected (số cọc đã thu tại quầy, >= cọc của xe), fuel_level (0-100)?, notes?, photos[]?
     */
    public function pickup(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'operate_bookings')) {
            return $denied;
        }
        $data = $request->validate([
            'odometer_km'       => ['required', 'integer', 'min:0'],
            'deposit_collected' => ['required', 'integer', 'min:0'],
            'fuel_level'        => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'photos'            => ['nullable', 'array', 'max:12'],
            'photos.*'          => ['image', 'max:8192'],
        ]);

        return $this->run($request, $code, fn (VehicleBooking $b) => $this->handover->pickup($b, (string) $request->user()->id, [
            'odometer_km' => $data['odometer_km'], 'deposit_collected' => $data['deposit_collected'],
            'fuel_level' => $data['fuel_level'] ?? null, 'notes' => $data['notes'] ?? null, 'photos' => $this->storePhotos($request),
        ]));
    }

    /**
     * POST /api/admin/vehicle-rental/bookings/{code}/return — nhận lại xe (multipart). Tự tính trễ giờ + km vượt.
     * Body: odometer_km, fuel_level?, notes?, photos[]?, charges[]? { type, label, amount, note? } (phụ phí thủ công)
     */
    public function returnVehicle(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'operate_bookings')) {
            return $denied;
        }
        $data = $request->validate([
            'odometer_km'       => ['required', 'integer', 'min:0'],
            'fuel_level'        => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'photos'            => ['nullable', 'array', 'max:12'],
            'photos.*'          => ['image', 'max:8192'],
            'charges'           => ['nullable', 'array', 'max:20'],
            'charges.*.type'    => ['required', 'in:' . implode(',', array_keys(VehicleCharge::TYPES))],
            'charges.*.label'   => ['required', 'string', 'max:150'],
            'charges.*.amount'  => ['required', 'integer', 'min:1'],
            'charges.*.note'    => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->run($request, $code, fn (VehicleBooking $b) => $this->handover->returnVehicle($b, (string) $request->user()->id, [
            'odometer_km' => $data['odometer_km'], 'fuel_level' => $data['fuel_level'] ?? null, 'notes' => $data['notes'] ?? null,
            'photos' => $this->storePhotos($request), 'charges' => $data['charges'] ?? [],
        ]));
    }

    /** POST /api/admin/vehicle-rental/bookings/{code}/charges — thêm phụ phí (đã nhận xe, chưa tất toán). Body: type, label, amount, note? */
    public function chargeStore(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'operate_bookings')) {
            return $denied;
        }
        $data = $request->validate([
            'type'   => ['required', 'in:' . implode(',', array_keys(VehicleCharge::TYPES))],
            'label'  => ['required', 'string', 'max:150'],
            'amount' => ['required', 'integer', 'min:1'],
            'note'   => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->run($request, $code, function (VehicleBooking $b) use ($data, $request) {
            $this->handover->addCharge($b, $data['type'], $data['label'], (int) $data['amount'], $data['note'] ?? null, [], (string) $request->user()->id);

            return $b->fresh();
        });
    }

    /** DELETE /api/admin/vehicle-rental/bookings/{code}/charges/{chargeId} */
    public function chargeDestroy(Request $request, string $code, int $chargeId): JsonResponse
    {
        if ($denied = $this->deny($request, 'operate_bookings')) {
            return $denied;
        }

        return $this->run($request, $code, function (VehicleBooking $b) use ($chargeId) {
            $this->handover->removeCharge($b, $chargeId);

            return $b->fresh();
        });
    }

    /** POST /api/admin/vehicle-rental/bookings/{code}/settle — body: collected_due (bool, đã thu phần phụ phí vượt cọc tại quầy) */
    public function settle(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'operate_bookings')) {
            return $denied;
        }
        $request->validate(['collected_due' => ['nullable', 'boolean']]);

        return $this->run($request, $code, fn (VehicleBooking $b) => $this->handover->settle($b, (string) $request->user()->id, $request->boolean('collected_due')));
    }

    /** POST /api/admin/vehicle-rental/bookings/{code}/no-show */
    public function noShow(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'operate_bookings')) {
            return $denied;
        }

        return $this->run($request, $code, fn (VehicleBooking $b) => $this->handover->markNoShow($b));
    }

    // ---- GPS của xe thuê (dữ liệu đã đồng bộ từ Motrak; nhân viên thuê xe + Super Admin) ----

    /** GET /api/admin/vehicle-rental/vehicles/{id}/gps — vị trí GPS hiện tại của xe (linked=false nếu chưa ghép thiết bị). Chỉ đọc DB, không gọi Motrak. */
    public function vehicleGps(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->deny($request, 'vehicle_gps')) {
            return $denied;
        }

        return response()->json(['data' => $this->gps->current(Vehicle::query()->findOrFail($id))]);
    }

    /** GET /api/admin/vehicle-rental/bookings/{code}/track — hành trình GPS của chuyến thuê + so sánh km + tóm tắt. Chỉ gồm các điểm 365Home đã lưu. */
    public function bookingTrack(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->deny($request, 'view_bookings')) {
            return $denied;
        }

        return response()->json(['data' => $this->gps->track($this->booking($code))]);
    }

    /** GET /api/admin/vehicle-rental/gps-alerts?status=open|acknowledged|all&type&vehicle_id&booking_code&per_page */
    public function gpsAlerts(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request, 'alerts')) {
            return $denied;
        }
        $f = $request->validate([
            'status'       => ['nullable', 'in:open,acknowledged,all'],
            'type'         => ['nullable', 'in:' . implode(',', array_keys(VehicleGpsAlert::TYPES))],
            'vehicle_id'   => ['nullable', 'integer'],
            'booking_code' => ['nullable', 'string', 'max:30'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = VehicleGpsAlert::query()->with(['vehicle', 'booking'])
            ->when(($f['status'] ?? 'open') === 'open', fn ($q) => $q->whereNull('acknowledged_at'))
            ->when(($f['status'] ?? null) === 'acknowledged', fn ($q) => $q->whereNotNull('acknowledged_at'))
            ->when(! empty($f['type']), fn ($q) => $q->where('type', $f['type']))
            ->when(! empty($f['vehicle_id']), fn ($q) => $q->where('vehicle_id', $f['vehicle_id']))
            ->when(! empty($f['booking_code']), fn ($q) => $q->whereHas('booking', fn ($b) => $b->where('code', $f['booking_code'])))
            ->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 20));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (VehicleGpsAlert $a) => VehicleTransformer::gpsAlert($a))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /** POST /api/admin/vehicle-rental/gps-alerts/{id}/ack — đánh dấu đã xử lý cảnh báo. */
    public function gpsAlertAck(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->deny($request, 'alerts')) {
            return $denied;
        }
        $alert = $this->gpsAlerts->acknowledge(VehicleGpsAlert::query()->with(['vehicle', 'booking'])->findOrFail($id), (string) $request->user()->id);

        return response()->json(['data' => VehicleTransformer::gpsAlert($alert)]);
    }

    /** Chạy 1 thao tác nghiệp vụ trên đơn, trả đơn đầy đủ hoặc 422 kèm lý do. */
    private function run(Request $request, string $code, \Closure $operation): JsonResponse
    {
        $booking = $this->booking($code);

        try {
            $operation($booking);
        } catch (DomainException $e) {
            // Thao tác bị từ chối: ảnh vừa tải lên (giao/nhận xe) không gắn vào biên bản nào → xoá để khỏi mồ côi trên đĩa.
            foreach ($this->storedPhotos as $path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            $this->storedPhotos = [];
        }

        return response()->json(['data' => $this->adminBooking($this->booking($code), true)]);
    }

    /** @return string[] đường dẫn ảnh đã lưu (đĩa public, thư mục rental-handovers) */
    private function storePhotos(Request $request): array
    {
        return $this->storedPhotos = collect($request->file('photos', []))->map(fn ($f) => $f->store('rental-handovers', 'public'))->values()->all();
    }

    private function validated(Request $request, ?Vehicle $vehicle): array
    {
        $required = $vehicle ? 'sometimes' : 'required';

        $data = $request->validate([
            'branch_id'               => ['nullable', 'integer', 'exists:categories,id'],
            'type'                    => [$required, Rule::in([Vehicle::TYPE_MOTORBIKE, Vehicle::TYPE_CAR])],
            'brand'                   => [$required, 'string', 'max:100'],
            'model'                   => [$required, 'string', 'max:100'],
            'year'                    => ['nullable', 'integer', 'min:1990', 'max:' . ((int) date('Y') + 1)],
            'plate_display'           => [$required, 'string', 'max:30'],
            'seats'                   => ['nullable', 'integer', 'min:1', 'max:60'],
            'transmission'            => ['nullable', Rule::in(['auto', 'manual'])],
            'fuel'                    => ['nullable', Rule::in(['petrol', 'diesel', 'electric', 'hybrid'])],
            'color'                   => ['nullable', 'string', 'max:50'],
            'description'             => ['nullable', 'string'],
            'status'                  => ['sometimes', Rule::in([Vehicle::STATUS_AVAILABLE, Vehicle::STATUS_MAINTENANCE, Vehicle::STATUS_INACTIVE])],
            'odometer_km'             => ['sometimes', 'integer', 'min:0'],
            'deposit_amount'          => ['sometimes', 'integer', 'min:0'],
            'registration_expires_on' => ['nullable', 'date'],
            'insurance_expires_on'    => ['nullable', 'date'],
            'motrak_vehicle_id'       => ['sometimes', 'nullable', 'integer'],
            'sort_order'              => ['sometimes', 'integer'],
        ]);

        if (isset($data['plate_display'])) {
            $taken = Vehicle::withTrashed()->where('plate', Vehicle::normalizePlate($data['plate_display']))
                ->when($vehicle, fn ($q) => $q->where('id', '!=', $vehicle->id))->exists();
            if ($taken) {
                throw \Illuminate\Validation\ValidationException::withMessages(['plate_display' => 'Biển số này đã có trong đội xe.']);
            }
        }

        return $data;
    }
}
