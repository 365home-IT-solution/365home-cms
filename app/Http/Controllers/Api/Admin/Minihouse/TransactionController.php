<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Transaction;

// Sổ Thu Chi. Dòng nào có invoice_payment_id (tự sinh từ 1 lần thanh toán hoá đơn — xem
// InvoicePaymentObserver) thì KHOÁ không cho sửa/xoá trực tiếp ở đây — giống hệt hành vi ẩn nút Sửa/
// Xoá ở TransactionTable panel Filament — phải sửa/xoá đúng lần thanh toán đó qua
// InvoicePaymentController.
class TransactionController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/transactions?building_id=&type=&category=&from=&to=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_transactions')) {
            return response()->json(['message' => 'Không có quyền xem thu chi.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        if ($request->filled('building_id') && ! in_array((int) $request->integer('building_id'), $permitted, true)) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        $transactions = Transaction::query()
            ->withoutGlobalScopes()
            ->with(['building:id,name', 'contract.room:id,code'])
            ->whereIn('building_id', $permitted)
            ->when($request->filled('building_id'), fn ($q) => $q->where('building_id', $request->integer('building_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('to')))
            ->orderByDesc('transaction_date')
            ->paginate((int) $request->integer('per_page', 20));

        $transactions->getCollection()->transform(fn (Transaction $t) => $this->toListItem($t));

        return response()->json($transactions);
    }

    // GET /api/admin/minihouse/transactions/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_transactions')) {
            return response()->json(['message' => 'Không có quyền xem thu chi.'], 403);
        }

        $transaction = Transaction::withoutGlobalScopes()->with(['building', 'contract.room'])->find($id);

        if (! $transaction || ! $this->isBuildingAllowed($request, $transaction->building_id)) {
            return response()->json(['message' => 'Không tìm thấy giao dịch.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($transaction)]);
    }

    // POST /api/admin/minihouse/transactions
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_transactions')) {
            return response()->json(['message' => 'Không có quyền tạo giao dịch.'], 403);
        }

        $data = $request->validate([
            'building_id'      => 'required|integer|exists:minihouse_buildings,id',
            'contract_id'      => 'nullable|integer|exists:minihouse_contracts,id',
            'type'             => ['required', Rule::in([Transaction::TYPE_IN, Transaction::TYPE_OUT])],
            'category'         => ['nullable', Rule::in([Transaction::CATEGORY_REPAIR, Transaction::CATEGORY_OPERATION, Transaction::CATEGORY_OTHER])],
            'amount'           => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'note'             => 'nullable|string',
            'receipt_image'    => 'nullable|string|max:2048',
        ]);

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo giao dịch cho toà nhà này.'], 403);
        }

        $transaction = Transaction::create($data);

        return response()->json(['data' => $this->toDetailItem($transaction->fresh(['building', 'contract.room']))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/transactions/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_transactions')) {
            return response()->json(['message' => 'Không có quyền sửa giao dịch.'], 403);
        }

        $transaction = Transaction::withoutGlobalScopes()->find($id);

        if (! $transaction || ! $this->isBuildingAllowed($request, $transaction->building_id)) {
            return response()->json(['message' => 'Không tìm thấy giao dịch.'], 404);
        }

        if ($transaction->invoice_payment_id) {
            return response()->json(['message' => 'Dòng tự động sinh từ thanh toán hoá đơn — sửa đúng lần thanh toán đó, không sửa trực tiếp ở đây.'], 422);
        }

        $data = $request->validate([
            'building_id'      => 'sometimes|required|integer|exists:minihouse_buildings,id',
            'contract_id'      => 'nullable|integer|exists:minihouse_contracts,id',
            'type'             => ['sometimes', Rule::in([Transaction::TYPE_IN, Transaction::TYPE_OUT])],
            'category'         => ['nullable', Rule::in([Transaction::CATEGORY_REPAIR, Transaction::CATEGORY_OPERATION, Transaction::CATEGORY_OTHER])],
            'amount'           => 'sometimes|required|numeric|min:0.01',
            'transaction_date' => 'sometimes|required|date',
            'note'             => 'nullable|string',
            'receipt_image'    => 'nullable|string|max:2048',
        ]);

        if (isset($data['building_id']) && ! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền chuyển giao dịch sang toà nhà này.'], 403);
        }

        $transaction->update($data);

        return response()->json(['data' => $this->toDetailItem($transaction->fresh(['building', 'contract.room']))]);
    }

    // DELETE /api/admin/minihouse/transactions/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_transactions')) {
            return response()->json(['message' => 'Không có quyền xoá giao dịch.'], 403);
        }

        $transaction = Transaction::withoutGlobalScopes()->find($id);

        if (! $transaction || ! $this->isBuildingAllowed($request, $transaction->building_id)) {
            return response()->json(['message' => 'Không tìm thấy giao dịch.'], 404);
        }

        if ($transaction->invoice_payment_id) {
            return response()->json(['message' => 'Dòng tự động sinh từ thanh toán hoá đơn — xoá đúng lần thanh toán đó, không xoá trực tiếp ở đây.'], 422);
        }

        $transaction->delete();

        return response()->json(['message' => 'Đã xoá giao dịch.']);
    }

    private function toListItem(Transaction $transaction): array
    {
        return [
            'id'               => $transaction->id,
            'building_id'      => $transaction->building_id,
            'building_name'    => $transaction->building?->name,
            'room_code'        => $transaction->contract?->room?->code,
            'type'             => $transaction->type,
            'category'         => $transaction->category,
            'amount'           => $transaction->amount,
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'is_auto'          => filled($transaction->invoice_payment_id),
        ];
    }

    private function toDetailItem(Transaction $transaction): array
    {
        return array_merge($this->toListItem($transaction), [
            'contract_id'         => $transaction->contract_id,
            'invoice_payment_id'  => $transaction->invoice_payment_id,
            'note'                => $transaction->note,
            'receipt_image'       => $transaction->receipt_image,
            'created_at'          => $transaction->created_at?->toIso8601String(),
            'updated_at'          => $transaction->updated_at?->toIso8601String(),
        ]);
    }
}
