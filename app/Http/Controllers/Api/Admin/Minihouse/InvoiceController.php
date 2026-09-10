<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Services\InvoiceGenerationService;
use Modules\Minihouse\App\Services\InvoiceMomoService;
use Modules\Minihouse\App\Services\InvoicePayOsService;
use Modules\Minihouse\App\Services\InvoiceVnpayService;

// CRUD hoá đơn. status/amount_paid/paid_at là cột CACHE tự đồng bộ từ InvoicePayment (xem
// InvoicePaymentObserver) — API này KHÔNG cho set trực tiếp 3 trường đó lúc tạo/sửa, phải đi qua
// InvoicePaymentController (POST .../payments) như đúng luồng "Ghi nhận thanh toán" ở panel.
class InvoiceController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/invoices?contract_id=&status=&month=YYYY-MM&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_invoices')) {
            return response()->json(['message' => 'Không có quyền xem hoá đơn.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        // withoutGlobalScopes() ở whereHas('contract'/'room') — mặc định vẫn tự áp scope SoftDeletes
        // của Contract/Room bên trong EXISTS subquery, khiến hoá đơn của 1 hợp đồng ĐÃ XOÁ MỀM biến
        // mất khỏi danh sách của tài khoản bị giới hạn theo toà (super_admin không lọc theo
        // $permitted nên không gặp lỗi này — chỉ tài khoản thường mới bị ảnh hưởng).
        $invoices = Invoice::query()
            ->withoutGlobalScopes()
            ->with([
                'contract'      => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->whereHas('contract', fn ($q) => $q->withoutGlobalScopes()->whereHas(
                'room',
                fn ($q2) => $q2->withoutGlobalScopes()->whereIn('building_id', $permitted),
            ))
            ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->integer('contract_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('month'), fn ($q) => $q->whereDate('month', $request->date('month')->startOfMonth()))
            ->orderByDesc('month')
            ->paginate((int) $request->integer('per_page', 20));

        $invoices->getCollection()->transform(fn (Invoice $i) => $this->toListItem($i));

        return response()->json($invoices);
    }

    // GET /api/admin/minihouse/invoices/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_invoices')) {
            return response()->json(['message' => 'Không có quyền xem hoá đơn.'], 403);
        }

        $invoice = Invoice::withoutGlobalScopes()->with($this->invoiceRelations())->find($id);

        if (! $invoice || ! $this->isBuildingAllowed($request, $invoice->contract?->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($invoice)]);
    }

    // POST /api/admin/minihouse/invoices/{id}/qr
    // Tạo mã QR PayOS cho ĐÚNG số tiền còn lại của hoá đơn — khách chuyển khoản quét mã này, PayOS
    // tự gọi webhook (app/Http/Controllers/Api/Minihouse/PayOsWebhookController) xác nhận thanh
    // toán, tự tạo InvoicePayment thật giống hệt ghi nhận tay. Không thay thế "Ghi nhận thanh toán"
    // thủ công (mục /payments) — khách trả tiền mặt vẫn ghi tay bình thường qua đó.
    public function generateQr(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_invoices')) {
            return response()->json(['message' => 'Không có quyền tạo mã QR thanh toán.'], 403);
        }

        $invoice = Invoice::withoutGlobalScopes()->with($this->invoiceRelations())->find($id);

        if (! $invoice || ! $this->isBuildingAllowed($request, $invoice->contract?->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        try {
            $result = InvoicePayOsService::createQr($invoice);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    // POST /api/admin/minihouse/invoices/{id}/momo — cùng nguyên tắc generateQr() ở trên, dùng
    // InvoiceMomoService (KHÔNG có tài khoản chung dự phòng — chỉ hoạt động nếu toà nhà của hoá đơn
    // đã chọn "MoMo" và điền đủ 3 field, xem Building::hasOwnMomo()).
    public function generateMomo(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_invoices')) {
            return response()->json(['message' => 'Không có quyền tạo link thanh toán MoMo.'], 403);
        }

        $invoice = Invoice::withoutGlobalScopes()->with($this->invoiceRelations())->find($id);

        if (! $invoice || ! $this->isBuildingAllowed($request, $invoice->contract?->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        try {
            $result = InvoiceMomoService::createPaymentRequest($invoice);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    // POST /api/admin/minihouse/invoices/{id}/vnpay — cùng nguyên tắc, dùng InvoiceVnpayService.
    public function generateVnpay(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_invoices')) {
            return response()->json(['message' => 'Không có quyền tạo link thanh toán VNPay.'], 403);
        }

        $invoice = Invoice::withoutGlobalScopes()->with($this->invoiceRelations())->find($id);

        if (! $invoice || ! $this->isBuildingAllowed($request, $invoice->contract?->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        try {
            $result = InvoiceVnpayService::createPaymentUrl($invoice, $request->ip());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    // POST /api/admin/minihouse/invoices/generate {month: "2026-09", building_ids?: [1,2]}
    // Mirror ListInvoices::bulkGenerateInvoices (panel) — lập hoá đơn cho mọi hợp đồng "Đang hiệu
    // lực" của tháng chọn, bỏ qua hợp đồng đã có hoá đơn tháng đó. building_ids bỏ trống = TOÀN BỘ
    // toà nhà tài khoản này được quản lý (KHÔNG phải toàn bộ site — service gốc coi null là "tất cả
    // không giới hạn gì", phải tự truyền permittedBuildingIds() vào đây, không thì 1 tài khoản chỉ
    // quản lý 1 toà có thể lập hoá đơn hộ cho toà khác qua API).
    public function generate(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_invoices')) {
            return response()->json(['message' => 'Không có quyền lập hoá đơn.'], 403);
        }

        $data = $request->validate([
            'month'          => 'required|date',
            'building_ids'   => 'nullable|array',
            'building_ids.*' => 'integer|exists:minihouse_buildings,id',
        ]);

        $permitted = $this->permittedBuildingIds($request);
        $requested = $data['building_ids'] ?? null;

        if ($requested) {
            $notAllowed = array_diff($requested, $permitted);

            if (! empty($notAllowed)) {
                return response()->json(['message' => 'Không có quyền lập hoá đơn cho 1 hoặc nhiều toà nhà đã chọn.'], 403);
            }
        }

        $buildingIds = $requested ?: $permitted;
        $month       = Carbon::parse($data['month'])->startOfMonth();

        $result = InvoiceGenerationService::generateForMonth($month, $buildingIds);

        return response()->json([
            'created_count' => $result['created']->count(),
            'skipped_count' => $result['skipped']->count(),
            'created'       => $result['created']->map(fn (Invoice $i) => $this->toListItem($i)),
            'skipped'       => $result['skipped']->map(fn (Contract $c) => ['contract_id' => $c->id, 'room_id' => $c->room_id]),
        ]);
    }

    // POST /api/admin/minihouse/invoices
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_invoices')) {
            return response()->json(['message' => 'Không có quyền tạo hoá đơn.'], 403);
        }

        $data = $request->validate([
            'contract_id'          => 'required|integer|exists:minihouse_contracts,id',
            'month'                => 'required|date',
            'period_start'         => 'required|date',
            'period_end'           => 'required|date|after_or_equal:period_start',
            'room_price'           => 'required|numeric|min:0',
            'electric_start'       => 'nullable|numeric|min:0',
            'electric_end'         => 'nullable|numeric|min:0',
            'electric_unit_price'  => 'nullable|numeric|min:0',
            'water_start'          => 'nullable|numeric|min:0',
            'water_end'            => 'nullable|numeric|min:0',
            'water_unit_price'     => 'nullable|numeric|min:0',
            'service_amount'       => 'nullable|numeric|min:0',
        ]);

        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($data['contract_id']);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không có quyền tạo hoá đơn cho hợp đồng này.'], 403);
        }

        $data['month'] = \Illuminate\Support\Carbon::parse($data['month'])->startOfMonth();

        $exists = Invoice::withoutGlobalScopes()
            ->where('contract_id', $data['contract_id'])
            ->whereYear('month', $data['month']->year)
            ->whereMonth('month', $data['month']->month)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Hợp đồng này đã có hoá đơn tháng đó.'], 422);
        }

        $electricAmount = max(0, ($data['electric_end'] ?? 0) - ($data['electric_start'] ?? 0)) * ($data['electric_unit_price'] ?? 0);
        $waterAmount    = max(0, ($data['water_end'] ?? 0) - ($data['water_start'] ?? 0)) * ($data['water_unit_price'] ?? 0);
        $serviceAmount  = $data['service_amount'] ?? 0;

        $invoice = Invoice::create(array_merge($data, [
            'electric_amount' => round($electricAmount, 2),
            'water_amount'    => round($waterAmount, 2),
            'service_amount'  => round($serviceAmount, 2),
            'total_amount'    => round($data['room_price'] + $electricAmount + $waterAmount + $serviceAmount, 2),
            'status'          => Invoice::STATUS_UNPAID,
        ]));

        return response()->json(['data' => $this->toDetailItem($invoice->fresh($this->invoiceRelations()))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/invoices/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_invoices')) {
            return response()->json(['message' => 'Không có quyền sửa hoá đơn.'], 403);
        }

        $invoice = Invoice::withoutGlobalScopes()->with($this->invoiceRelations())->find($id);

        if (! $invoice || ! $this->isBuildingAllowed($request, $invoice->contract?->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        $data = $request->validate([
            'period_start'         => 'sometimes|required|date',
            'period_end'           => 'sometimes|required|date|after_or_equal:period_start',
            'room_price'           => 'sometimes|required|numeric|min:0',
            'electric_start'       => 'nullable|numeric|min:0',
            'electric_end'         => 'nullable|numeric|min:0',
            'electric_unit_price'  => 'nullable|numeric|min:0',
            'water_start'          => 'nullable|numeric|min:0',
            'water_end'            => 'nullable|numeric|min:0',
            'water_unit_price'     => 'nullable|numeric|min:0',
            'service_amount'       => 'nullable|numeric|min:0',
        ]);

        $roomPrice   = $data['room_price'] ?? $invoice->room_price;
        $eStart      = array_key_exists('electric_start', $data) ? $data['electric_start'] : $invoice->electric_start;
        $eEnd        = array_key_exists('electric_end', $data) ? $data['electric_end'] : $invoice->electric_end;
        $ePrice      = array_key_exists('electric_unit_price', $data) ? $data['electric_unit_price'] : $invoice->electric_unit_price;
        $wStart      = array_key_exists('water_start', $data) ? $data['water_start'] : $invoice->water_start;
        $wEnd        = array_key_exists('water_end', $data) ? $data['water_end'] : $invoice->water_end;
        $wPrice      = array_key_exists('water_unit_price', $data) ? $data['water_unit_price'] : $invoice->water_unit_price;
        $service     = $data['service_amount'] ?? $invoice->service_amount;

        $electricAmount = max(0, ($eEnd ?? 0) - ($eStart ?? 0)) * ($ePrice ?? 0);
        $waterAmount    = max(0, ($wEnd ?? 0) - ($wStart ?? 0)) * ($wPrice ?? 0);

        $invoice->update(array_merge($data, [
            'electric_amount' => round($electricAmount, 2),
            'water_amount'    => round($waterAmount, 2),
            'total_amount'    => round($roomPrice + $electricAmount + $waterAmount + $service, 2),
        ]));

        return response()->json(['data' => $this->toDetailItem($invoice->fresh($this->invoiceRelations()))]);
    }

    // DELETE /api/admin/minihouse/invoices/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_invoices')) {
            return response()->json(['message' => 'Không có quyền xoá hoá đơn.'], 403);
        }

        $invoice = Invoice::withoutGlobalScopes()->with($this->invoiceRelations())->find($id);

        if (! $invoice || ! $this->isBuildingAllowed($request, $invoice->contract?->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hoá đơn.'], 404);
        }

        // InvoiceObserver::deleting() tự xoá hết InvoicePayment (kéo theo Transaction liên kết) khi
        // hoá đơn bị xoá mềm — xem MinihouseServiceProvider::boot().
        $invoice->delete();

        return response()->json(['message' => 'Đã xoá hoá đơn.']);
    }

    // Contract/Room/Building dùng SoftDeletes riêng — eager-load thường vẫn áp scope đó ở TỪNG quan
    // hệ lồng nhau, nên 1 hợp đồng/phòng bị xoá mềm sẽ làm $invoice->contract trả về null, kéo theo
    // isBuildingAllowed() nhận building_id=null và CHẶN NHẦM quyền xem 1 hoá đơn lịch sử hợp lệ (cùng
    // lỗi lớp đã gặp và sửa ở InvoiceContentRenderer/InvoicePrintController/InvoicePayOsService).
    private function invoiceRelations(): array
    {
        return [
            'contract'                => fn ($q) => $q->withoutGlobalScopes(),
            'contract.room'           => fn ($q) => $q->withoutGlobalScopes(),
            'contract.room.building'  => fn ($q) => $q->withoutGlobalScopes(),
            'items',
            'payments',
        ];
    }

    private function toListItem(Invoice $invoice): array
    {
        return [
            'id'             => $invoice->id,
            'contract_id'    => $invoice->contract_id,
            'room_code'      => $invoice->contract?->room?->code,
            'month'          => $invoice->month?->format('Y-m'),
            'total_amount'   => $invoice->total_amount,
            'amount_paid'    => $invoice->amount_paid,
            'remaining'      => $invoice->remainingAmount(),
            'status'         => $invoice->status,
        ];
    }

    private function toDetailItem(Invoice $invoice): array
    {
        return [
            'id'                    => $invoice->id,
            'contract_id'           => $invoice->contract_id,
            'room_code'             => $invoice->contract?->room?->code,
            'building_id'           => $invoice->contract?->room?->building_id,
            'month'                 => $invoice->month?->format('Y-m'),
            'period_start'          => $invoice->period_start?->toDateString(),
            'period_end'            => $invoice->period_end?->toDateString(),
            'room_price'            => $invoice->room_price,
            'electric_start'        => $invoice->electric_start,
            'electric_end'          => $invoice->electric_end,
            'electric_unit_price'   => $invoice->electric_unit_price,
            'electric_amount'       => $invoice->electric_amount,
            'water_start'           => $invoice->water_start,
            'water_end'             => $invoice->water_end,
            'water_unit_price'      => $invoice->water_unit_price,
            'water_amount'          => $invoice->water_amount,
            'service_amount'        => $invoice->service_amount,
            'total_amount'          => $invoice->total_amount,
            'amount_paid'           => $invoice->amount_paid,
            'remaining'             => $invoice->remainingAmount(),
            'status'                => $invoice->status,
            'paid_at'               => $invoice->paid_at?->toIso8601String(),
            'items'                 => $invoice->items->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'amount' => $i->amount]),
            // status/approved_at/approved_by — thiếu 3 trường này thì client KHÔNG phân biệt được 1
            // dòng 'pending' (chưa cộng vào amount_paid ở trên, chờ "Chủ toà nhà" duyệt) với 1 dòng
            // đã 'approved', trong khi GET .../payments (InvoicePaymentController::toItem()) đã trả
            // đủ 3 trường này cho ĐÚNG model InvoicePayment — giữ 2 endpoint đồng nhất.
            'payments'              => $invoice->payments->map(fn ($p) => [
                'id' => $p->id, 'amount' => $p->amount, 'paid_at' => $p->paid_at?->toDateString(),
                'payment_method' => $p->payment_method, 'note' => $p->note,
                'status' => $p->status, 'approved_at' => $p->approved_at?->toIso8601String(), 'approved_by' => $p->approved_by,
            ]),
            'created_at'            => $invoice->created_at?->toIso8601String(),
            'updated_at'            => $invoice->updated_at?->toIso8601String(),
        ];
    }
}
