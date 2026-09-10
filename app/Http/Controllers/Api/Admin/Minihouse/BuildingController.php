<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Building;

// Toà nhà — xem Modules\Minihouse\App\Filament\Resources\BuildingResource cho bản Filament tương
// ứng. API này CHỈ phục vụ App\Models\User nội bộ (nhân viên/quản lý), không có khái niệm khách
// hàng — cùng auth:sanctum + admin.api với toàn bộ app/Http/Controllers/Api/Admin/*.
class BuildingController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/buildings?search=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_buildings')) {
            return response()->json(['message' => 'Không có quyền xem toà nhà.'], 403);
        }

        $buildings = Building::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $this->permittedBuildingIds($request))
            ->withCount('rooms')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . $request->string('search') . '%'))
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 20));

        $buildings->getCollection()->transform(fn (Building $b) => $this->toListItem($b));

        return response()->json($buildings);
    }

    // GET /api/admin/minihouse/buildings/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_buildings') || ! $this->isBuildingAllowed($request, $id)) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $building = Building::withoutGlobalScopes()->find($id);

        if (! $building) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($building)]);
    }

    // POST /api/admin/minihouse/buildings
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_buildings')) {
            return response()->json(['message' => 'Không có quyền tạo toà nhà.'], 403);
        }

        $data = $request->validate(self::rules());
        $data = self::withDerivedBankName($data);

        $building = Building::create($data);

        // Tài khoản thường (không phải super_admin) tạo toà nhà mới nhưng CHƯA được gán quản lý toà
        // đó — sẽ không thấy lại được nó ở chính API này ngay sau khi tạo (đúng theo đúng ranh giới
        // quyền, không phải bug) — cảnh báo rõ trong response để client tự xử lý (VD tự gán lại).
        $user = $request->user();
        $note = ($user && ! $user->isSuperAdmin() && ! in_array($building->id, $user->rootBuildingIds(), true))
            ? 'Toà nhà đã tạo nhưng tài khoản này chưa được gán quản lý — cần admin gán lại ở trang Tài khoản mới thấy được toà này.'
            : null;

        return response()->json(array_filter([
            'data'  => $this->toDetailItem($building),
            'notice' => $note,
        ]), 201);
    }

    // PUT/PATCH /api/admin/minihouse/buildings/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_buildings') || ! $this->isBuildingAllowed($request, $id)) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $building = Building::withoutGlobalScopes()->find($id);

        if (! $building) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $data = $request->validate(self::rules(forUpdate: true));
        $data = self::withDerivedBankName($data);

        $building->update($data);

        return response()->json(['data' => $this->toDetailItem($building->fresh())]);
    }

    // DELETE /api/admin/minihouse/buildings/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_buildings') || ! $this->isBuildingAllowed($request, $id)) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $building = Building::withoutGlobalScopes()->find($id);

        if (! $building) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $building->delete();

        return response()->json(['message' => 'Đã xoá toà nhà.']);
    }

    // owner_bank_name KHÔNG phải field người dùng tự gõ — bên Filament tự suy ra từ owner_bank_bin
    // qua VietnameseBanks::shortName() (afterStateUpdated), API cũng phải làm y hệt, không thì lưu
    // thiếu tên ngân hàng hiển thị (VD trên phiếu in) dù có mã BIN.
    private static function withDerivedBankName(array $data): array
    {
        if (array_key_exists('owner_bank_bin', $data)) {
            $data['owner_bank_name'] = \Modules\Minihouse\App\Support\VietnameseBanks::shortName($data['owner_bank_bin']);
        }

        return $data;
    }

    // Dùng chung cho store()/update() — trước đây 2 nơi tự liệt kê tay 2 danh sách rule GẦN GIỐNG
    // NHAU nhưng đều THIẾU rất nhiều field thật của Building (payment_method, kiểu chu kỳ hoá đơn,
    // thông tin chủ sở hữu/ngân hàng/PayOS...) — API không tạo/sửa được các field này dù Filament đã
    // có đủ từ lâu, một dạng lỗi "thiếu đồng bộ API/Filament" cùng lớp đã gặp ở nhiều Resource khác.
    private static function rules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes|required' : 'required';

        return [
            'name'                          => "{$required}|string|max:255",
            'address'                       => 'nullable|string|max:255',
            'province'                      => 'nullable|string|max:255',
            'ward'                          => 'nullable|string|max:255',
            'electric_unit_price'           => 'nullable|numeric|min:0',
            'water_unit_price'              => 'nullable|numeric|min:0',
            'note'                          => 'nullable|string',
            'payment_method'                => ['nullable', Rule::in([Building::PAYMENT_METHOD_VIETQR, Building::PAYMENT_METHOD_PAYOS, Building::PAYMENT_METHOD_MOMO, Building::PAYMENT_METHOD_VNPAY])],
            'billing_cycle_type'            => ['nullable', Rule::in([Building::BILLING_CYCLE_CALENDAR_MONTH, Building::BILLING_CYCLE_ANNIVERSARY])],
            'fixed_due_day'                 => 'nullable|integer|min:1|max:28',
            'payment_reminder_days_before'  => 'nullable|integer|min:0|max:30',
            'owner_name'                    => 'nullable|string|max:255',
            'owner_phone'                   => 'nullable|string|max:20',
            'owner_id_card_number'          => 'nullable|string|max:20',
            'owner_email'                   => 'nullable|email|max:255',
            'owner_address'                 => 'nullable|string|max:255',
            'owner_bank_bin'                => 'nullable|string|max:20',
            'owner_bank_account_number'     => 'nullable|string|max:50',
            'owner_bank_account_holder'     => 'nullable|string|max:255',
            'payos_client_id'               => 'nullable|string|max:255',
            'payos_api_key'                 => 'nullable|string|max:255',
            'payos_checksum_key'            => 'nullable|string|max:255',
            'momo_partner_code'             => 'nullable|string|max:255',
            'momo_access_key'               => 'nullable|string|max:255',
            'momo_secret_key'               => 'nullable|string|max:255',
            'vnpay_tmn_code'                => 'nullable|string|max:255',
            'vnpay_hash_secret'             => 'nullable|string|max:255',
        ];
    }

    private function toListItem(Building $building): array
    {
        return [
            'id'                   => $building->id,
            'name'                 => $building->name,
            'address'              => $building->address,
            'rooms_count'          => $building->rooms_count,
            'electric_unit_price'  => $building->electric_unit_price,
            'water_unit_price'     => $building->water_unit_price,
            'payment_method'       => $building->payment_method,
            'billing_cycle_type'   => $building->billing_cycle_type,
        ];
    }

    private function toDetailItem(Building $building): array
    {
        return [
            'id'                            => $building->id,
            'name'                          => $building->name,
            'address'                       => $building->address,
            'province'                      => $building->province,
            'ward'                          => $building->ward,
            'electric_unit_price'           => $building->electric_unit_price,
            'water_unit_price'              => $building->water_unit_price,
            'note'                          => $building->note,
            'image'                         => $building->image,
            'payment_method'                => $building->payment_method,
            'billing_cycle_type'            => $building->billing_cycle_type,
            'fixed_due_day'                 => $building->fixed_due_day,
            'payment_reminder_days_before'  => $building->payment_reminder_days_before,
            'owner_name'                    => $building->owner_name,
            'owner_phone'                   => $building->owner_phone,
            'owner_id_card_number'          => $building->owner_id_card_number,
            'owner_email'                   => $building->owner_email,
            'owner_address'                 => $building->owner_address,
            'owner_bank_bin'                => $building->owner_bank_bin,
            'owner_bank_name'               => $building->owner_bank_name,
            'owner_bank_account_number'     => $building->owner_bank_account_number,
            'owner_bank_account_holder'     => $building->owner_bank_account_holder,
            // KHÔNG trả về payos_api_key/payos_checksum_key thô (bí mật dùng gọi API thay mặt cả hệ
            // thống) — chỉ báo ĐÃ cấu hình hay chưa, giống cách Filament ẩn 2 field này dạng password.
            'payos_client_id'               => $building->payos_client_id,
            'payos_configured'              => filled($building->payos_client_id) && filled($building->payos_api_key) && filled($building->payos_checksum_key),
            // Cùng nguyên tắc PayOS — KHÔNG trả về momo_secret_key/vnpay_hash_secret thô.
            'momo_partner_code'             => $building->momo_partner_code,
            'momo_configured'               => $building->hasOwnMomo(),
            'vnpay_tmn_code'                => $building->vnpay_tmn_code,
            'vnpay_configured'              => $building->hasOwnVnpay(),
            'created_at'                    => $building->created_at?->toIso8601String(),
            'updated_at'                    => $building->updated_at?->toIso8601String(),
        ];
    }
}
