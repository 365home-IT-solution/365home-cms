<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Transaction;

// CRUD cơ bản cho Hợp đồng + 4 luồng nghiệp vụ nâng cao mirror ĐÚNG logic bản Filament (xem
// EditContract::getHeaderActions()): Gia hạn, Thanh lý/Hoàn cọc, Huỷ hợp đồng, Chuyển phòng.
class ContractController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/contracts?status=&room_id=&tenant_id=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_contracts')) {
            return response()->json(['message' => 'Không có quyền xem hợp đồng.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        $contracts = Contract::query()
            ->withoutGlobalScopes()
            ->with(['room:id,code,building_id', 'tenant:id,fullname'])
            ->whereHas('room', fn ($q) => $q->whereIn('building_id', $permitted))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('room_id'), fn ($q) => $q->where('room_id', $request->integer('room_id')))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        $contracts->getCollection()->transform(fn (Contract $c) => $this->toListItem($c));

        return response()->json($contracts);
    }

    // GET /api/admin/minihouse/contracts/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_contracts')) {
            return response()->json(['message' => 'Không có quyền xem hợp đồng.'], 403);
        }

        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes(), 'tenant' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($contract)]);
    }

    // POST /api/admin/minihouse/contracts
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_contracts')) {
            return response()->json(['message' => 'Không có quyền tạo hợp đồng.'], 403);
        }

        $data = $request->validate([
            'room_id'              => 'required|integer|exists:minihouse_rooms,id',
            'tenant_id'            => 'required|integer|exists:minihouse_tenants,id',
            'start_date'           => 'required|date',
            'end_date'             => 'nullable|date|after:start_date',
            'monthly_price'        => 'required|numeric|min:0',
            'deposit_amount'       => 'nullable|numeric|min:0',
            'status'               => ['nullable', Rule::in([Contract::STATUS_ACTIVE, Contract::STATUS_EXPIRED, Contract::STATUS_CANCELLED])],
            'electric_unit_price'  => 'nullable|numeric|min:0',
            'water_unit_price'     => 'nullable|numeric|min:0',
            'reason_for_stay'      => 'nullable|string|max:255',
            'custom_reason'        => 'nullable|string|max:255',
        ]);

        $room = Room::withoutGlobalScopes()->find($data['room_id']);

        if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
            return response()->json(['message' => 'Không có quyền tạo hợp đồng cho phòng của toà nhà này.'], 403);
        }

        $data['status'] ??= Contract::STATUS_ACTIVE;

        // minihouse_contracts.deposit_amount là NOT NULL DEFAULT 0 — DEFAULT chỉ áp dụng khi CỘT
        // ĐƯỢC BỎ QUA hoàn toàn lúc insert, không áp dụng nếu client gửi thẳng "deposit_amount":
        // null (rule 'nullable' vẫn cho qua giá trị null này) — Eloquent sẽ insert NULL tường minh,
        // vỡ ràng buộc NOT NULL, ném ra PDOException/500 y hệt lỗi đã sửa ở ContractForm (Filament).
        $data['deposit_amount'] ??= 0;

        // Chặn 1 phòng có 2 hợp đồng "Đang hiệu lực" cùng lúc — giống rule ở ContractForm.
        if ($data['status'] === Contract::STATUS_ACTIVE) {
            $conflict = Contract::withoutGlobalScopes()->where('room_id', $data['room_id'])->where('status', Contract::STATUS_ACTIVE)->exists();

            if ($conflict) {
                return response()->json(['message' => 'Phòng này đang có hợp đồng khác còn hiệu lực.'], 422);
            }
        }

        // Contract/TenantObserver tự đồng bộ Room.status/Tenant.room_id/ContractTenant khi tạo —
        // xem MinihouseServiceProvider::boot(). Không cần tự làm lại ở đây.
        $contract = Contract::create($data);

        return response()->json(['data' => $this->toDetailItem($contract->fresh(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes(), 'tenant' => fn ($q) => $q->withoutGlobalScopes()]))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/contracts/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền sửa hợp đồng.'], 403);
        }

        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $data = $request->validate([
            'room_id'              => 'sometimes|required|integer|exists:minihouse_rooms,id',
            'tenant_id'            => 'sometimes|required|integer|exists:minihouse_tenants,id',
            'start_date'           => 'sometimes|required|date',
            'end_date'             => 'nullable|date|after:start_date',
            'monthly_price'        => 'sometimes|required|numeric|min:0',
            'deposit_amount'       => 'nullable|numeric|min:0',
            'status'               => ['sometimes', Rule::in([Contract::STATUS_ACTIVE, Contract::STATUS_EXPIRED, Contract::STATUS_CANCELLED])],
            'electric_unit_price'  => 'nullable|numeric|min:0',
            'water_unit_price'     => 'nullable|numeric|min:0',
            'reason_for_stay'      => 'nullable|string|max:255',
            'custom_reason'        => 'nullable|string|max:255',
        ]);

        if (isset($data['room_id'])) {
            $room = Room::withoutGlobalScopes()->find($data['room_id']);

            if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
                return response()->json(['message' => 'Không có quyền chuyển hợp đồng sang phòng của toà nhà này.'], 403);
            }
        }

        // Chỉ ép về 0 khi client CÓ gửi key này nhưng để null — nếu key không có mặt trong request
        // thì bỏ qua hoàn toàn (giữ đúng ngữ nghĩa 'sometimes', không đụng giá trị cũ trong DB).
        if (array_key_exists('deposit_amount', $data) && $data['deposit_amount'] === null) {
            $data['deposit_amount'] = 0;
        }

        $contract->update($data);

        return response()->json(['data' => $this->toDetailItem($contract->fresh(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes(), 'tenant' => fn ($q) => $q->withoutGlobalScopes()]))]);
    }

    // DELETE /api/admin/minihouse/contracts/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_contracts')) {
            return response()->json(['message' => 'Không có quyền xoá hợp đồng.'], 403);
        }

        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $contract->delete();

        return response()->json(['message' => 'Đã xoá hợp đồng.']);
    }

    // POST /api/admin/minihouse/contracts/{id}/renew
    // Mirror EditContract's renewContract: ghi 1 dòng lịch sử ContractRenewal TRƯỚC khi cập nhật,
    // để sau này còn tra lại được đã gia hạn bao nhiêu lần, giá cũ là bao nhiêu.
    public function renew(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền gia hạn hợp đồng.'], 403);
        }

        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        if ($contract->status !== Contract::STATUS_ACTIVE) {
            return response()->json(['message' => 'Chỉ gia hạn được hợp đồng đang hiệu lực.'], 422);
        }

        $data = $request->validate([
            'new_end_date'      => [
                'required', 'date',
                function ($attribute, $value, $fail) use ($contract) {
                    if ($contract->end_date && \Illuminate\Support\Carbon::parse($value)->lte($contract->end_date)) {
                        $fail('Ngày kết thúc mới phải sau ngày kết thúc hiện tại (' . $contract->end_date->format('d/m/Y') . ').');
                    }
                },
            ],
            'new_monthly_price' => 'required|numeric|min:0',
            'note'              => 'nullable|string',
        ]);

        $contract->renewals()->create([
            'old_end_date'      => $contract->end_date,
            'new_end_date'      => $data['new_end_date'],
            'old_monthly_price' => $contract->monthly_price,
            'new_monthly_price' => $data['new_monthly_price'],
            'note'              => $data['note'] ?? null,
            'created_by'        => $request->user()->id,
        ]);

        $contract->update([
            'end_date'      => $data['new_end_date'],
            'monthly_price' => $data['new_monthly_price'],
        ]);

        return response()->json(['data' => $this->toDetailItem($contract->fresh(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes(), 'tenant' => fn ($q) => $q->withoutGlobalScopes()]))]);
    }

    // POST /api/admin/minihouse/contracts/{id}/checkout
    // Mirror EditContract's checkoutContract: gợi ý số tiền hoàn cọc = cọc - tổng còn nợ (gồm cả
    // hoá đơn "partial", không chỉ "unpaid" — xem note trong toDetailItem). Đổi status=expired,
    // ContractObserver tự trả phòng về "Trống".
    public function checkout(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền thanh lý hợp đồng.'], 403);
        }

        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        if ($contract->status !== Contract::STATUS_ACTIVE) {
            return response()->json(['message' => 'Chỉ thanh lý được hợp đồng đang hiệu lực.'], 422);
        }

        $unpaidTotal = (float) Invoice::where('contract_id', $contract->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->get()
            ->sum(fn (Invoice $invoice) => $invoice->remainingAmount());
        $suggested = max(0, (float) $contract->deposit_amount - $unpaidTotal);

        $data = $request->validate([
            'checkout_at'               => 'required|date',
            'deposit_refunded_amount'   => 'nullable|numeric|min:0',
            'deposit_deduction_reason'  => 'nullable|string',
        ]);

        $data['deposit_refunded_amount'] ??= $suggested;

        $contract->update([
            ...$data,
            'status' => Contract::STATUS_EXPIRED,
        ]);

        $this->recordDepositRefundTransaction($contract, (float) $data['deposit_refunded_amount'], 'Hoàn cọc khi thanh lý hợp đồng #' . $contract->id);

        return response()->json([
            'data'              => $this->toDetailItem($contract->fresh(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes(), 'tenant' => fn ($q) => $q->withoutGlobalScopes()])),
            'suggested_refund'  => $suggested,
            'unpaid_total'      => $unpaidTotal,
        ]);
    }

    // POST /api/admin/minihouse/contracts/{id}/cancel — mirror EditContract::cancelContract, dùng
    // cho chấm dứt hợp đồng SỚM/không tiếp tục thuê (khác "Thanh lý" dùng khi hợp đồng hết hạn/thuê
    // xong bình thường) — bắt buộc có lý do huỷ, xử lý hoàn cọc/trả phòng giống hệt Thanh lý.
    public function cancel(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền huỷ hợp đồng.'], 403);
        }

        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        if ($contract->status !== Contract::STATUS_ACTIVE) {
            return response()->json(['message' => 'Chỉ huỷ được hợp đồng đang hiệu lực.'], 422);
        }

        $unpaidTotal = (float) Invoice::where('contract_id', $contract->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->get()
            ->sum(fn (Invoice $invoice) => $invoice->remainingAmount());
        $suggested = max(0, (float) $contract->deposit_amount - $unpaidTotal);

        $data = $request->validate([
            'cancel_reason'             => 'required|string',
            'checkout_at'               => 'required|date',
            'deposit_refunded_amount'   => 'nullable|numeric|min:0',
            'deposit_deduction_reason'  => 'nullable|string',
        ]);

        $depositRefunded = $data['deposit_refunded_amount'] ?? $suggested;

        // Contract chưa có cột riêng cho "lý do huỷ" — ghép chung vào deposit_deduction_reason (có
        // tiền tố rõ ràng), giống hệt EditContract::cancelContract() bên Filament.
        $note = 'Lý do huỷ: ' . $data['cancel_reason'] . (filled($data['deposit_deduction_reason'] ?? null) ? '. Trừ cọc: ' . $data['deposit_deduction_reason'] : '');

        $contract->update([
            'checkout_at'              => $data['checkout_at'],
            'deposit_refunded_amount'  => $depositRefunded,
            'deposit_deduction_reason' => $note,
            'status'                   => Contract::STATUS_CANCELLED,
        ]);

        $this->recordDepositRefundTransaction($contract, (float) $depositRefunded, 'Hoàn cọc khi huỷ hợp đồng #' . $contract->id);

        return response()->json([
            'data'              => $this->toDetailItem($contract->fresh(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes(), 'tenant' => fn ($q) => $q->withoutGlobalScopes()])),
            'suggested_refund'  => $suggested,
            'unpaid_total'      => $unpaidTotal,
        ]);
    }

    // Dùng chung cho checkout()/cancel() — xem EditContract::recordDepositRefundTransaction() (bản
    // Filament), giữ 2 nơi luôn nhất quán: hoàn cọc phải tự hiện trong sổ Thu Chi, không chỉ cập
    // nhật mỗi field trên Contract.
    private function recordDepositRefundTransaction(Contract $contract, float $amount, string $note): void
    {
        if ($amount <= 0) {
            return;
        }

        Transaction::create([
            'contract_id'      => $contract->id,
            'building_id'      => $contract->room?->building_id,
            'type'             => Transaction::TYPE_OUT,
            'category'         => Transaction::CATEGORY_DEPOSIT_REFUND,
            'amount'           => $amount,
            'transaction_date' => $contract->checkout_at ?? now(),
            'note'             => $note,
        ]);
    }

    // POST /api/admin/minihouse/contracts/{id}/transfer-room
    // Mirror EditContract's transferRoom: hợp đồng CŨ kết thúc (status=expired, tự trả phòng cũ về
    // "Trống"), tạo NGAY hợp đồng MỚI cho phòng mới mang theo cọc + người ở cùng + phụ thu (nếu
    // cùng toà nhà) — KHÔNG hoàn cọc như "Thanh lý", coi như 1 lần thuê liên tục chỉ đổi phòng.
    public function transferRoom(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền chuyển phòng.'], 403);
        }

        $old = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $old || ! $this->isBuildingAllowed($request, $old->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        if ($old->status !== Contract::STATUS_ACTIVE) {
            return response()->json(['message' => 'Chỉ chuyển phòng được hợp đồng đang hiệu lực.'], 422);
        }

        $data = $request->validate([
            'new_room_id'        => 'required|integer|exists:minihouse_rooms,id',
            'transfer_at'        => 'required|date',
            'new_monthly_price'  => 'required|numeric|min:0',
        ]);

        $newRoom = Room::withoutGlobalScopes()->find($data['new_room_id']);

        if (! $newRoom || $newRoom->status !== Room::STATUS_EMPTY || $newRoom->id === $old->room_id) {
            return response()->json(['message' => 'Phòng mới phải đang "Trống" và khác phòng hiện tại.'], 422);
        }

        // Phòng mới có thể ở toà nhà KHÁC toà đang quản lý (VD chuyển liên toà) — vẫn phải được
        // phép quản lý toà đó mới cho chuyển tới, không thì 1 tài khoản bị giới hạn có thể "đẩy"
        // khách sang toà mình không được quản lý.
        if (! $this->isBuildingAllowed($request, $newRoom->building_id)) {
            return response()->json(['message' => 'Không có quyền chuyển khách sang toà nhà của phòng này.'], 403);
        }

        $result = DB::transaction(function () use ($old, $newRoom, $data) {
            $sameBuilding = $newRoom->building_id === $old->room?->building_id;

            $old->update([
                'status'      => Contract::STATUS_EXPIRED,
                'checkout_at' => $data['transfer_at'],
            ]);

            $new = Contract::create([
                'room_id'                       => $newRoom->id,
                'tenant_id'                      => $old->tenant_id,
                'start_date'                     => $data['transfer_at'],
                'end_date'                       => $old->end_date,
                'monthly_price'                  => $data['new_monthly_price'],
                'deposit_amount'                 => $old->deposit_amount,
                'status'                         => Contract::STATUS_ACTIVE,
                'electric_unit_price'            => $sameBuilding ? $old->electric_unit_price : null,
                'water_unit_price'               => $sameBuilding ? $old->water_unit_price : null,
                'transferred_from_contract_id'   => $old->id,
            ]);

            $old->update(['transferred_to_contract_id' => $new->id]);

            foreach ($old->occupantEntries as $occupant) {
                ContractTenant::create([
                    'contract_id'             => $new->id,
                    'tenant_id'               => $occupant->tenant_id,
                    'role'                    => ContractTenant::ROLE_OCCUPANT,
                    'relationship_to_primary' => $occupant->relationship_to_primary,
                ]);
            }

            if ($sameBuilding) {
                $new->surcharges()->sync($old->surcharges()->pluck('minihouse_surcharges.id'));
            }

            return $new;
        });

        return response()->json(['data' => $this->toDetailItem($result->fresh(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes(), 'tenant' => fn ($q) => $q->withoutGlobalScopes()]))], 201);
    }

    private function toListItem(Contract $contract): array
    {
        return [
            'id'            => $contract->id,
            'room_id'       => $contract->room_id,
            'room_code'     => $contract->room?->code,
            'tenant_id'     => $contract->tenant_id,
            'tenant_name'   => $contract->tenant?->fullname,
            'start_date'    => $contract->start_date?->toDateString(),
            'end_date'      => $contract->end_date?->toDateString(),
            'monthly_price' => $contract->monthly_price,
            'status'        => $contract->status,
        ];
    }

    private function toDetailItem(Contract $contract): array
    {
        return [
            'id'                            => $contract->id,
            'room_id'                       => $contract->room_id,
            'room_code'                     => $contract->room?->code,
            'building_id'                   => $contract->room?->building_id,
            'building_name'                 => $contract->room?->building?->name,
            'tenant_id'                     => $contract->tenant_id,
            'tenant_name'                   => $contract->tenant?->fullname,
            'start_date'                    => $contract->start_date?->toDateString(),
            'end_date'                      => $contract->end_date?->toDateString(),
            'monthly_price'                 => $contract->monthly_price,
            'deposit_amount'                => $contract->deposit_amount,
            'status'                        => $contract->status,
            'electric_unit_price'           => $contract->electric_unit_price,
            'water_unit_price'              => $contract->water_unit_price,
            'reason_for_stay'               => $contract->reason_for_stay,
            'custom_reason'                 => $contract->custom_reason,
            'checkout_at'                   => $contract->checkout_at?->toDateString(),
            'deposit_refunded_amount'       => $contract->deposit_refunded_amount,
            'deposit_deduction_reason'      => $contract->deposit_deduction_reason,
            'transferred_to_contract_id'    => $contract->transferred_to_contract_id,
            'transferred_from_contract_id'  => $contract->transferred_from_contract_id,
            'created_at'                    => $contract->created_at?->toIso8601String(),
            'updated_at'                    => $contract->updated_at?->toIso8601String(),
        ];
    }
}
