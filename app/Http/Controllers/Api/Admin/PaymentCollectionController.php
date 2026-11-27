<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Entities\PaymentCollection;

// "Mã thu tiền" qua API — cùng chức năng và cùng PaymentCollectionService với trang CMS "Mã thu tiền". Chỉ Super Admin.
// Admin tạo khoản thu độc lập (số tiền + nội dung) → nhận mã TTxxxxxx, chuỗi QR VietQR và link trang QR để gửi khách; khách chuyển khoản vào tài khoản Pay2S của 365home thì khoản tự chuyển "paid".
class PaymentCollectionController extends Controller
{
    public function __construct(private readonly PaymentCollectionService $collections)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $filters = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', array_keys(PaymentCollection::STATUSES))],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = PaymentCollection::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('code', 'like', '%' . strtoupper($term) . '%')
                ->orWhere('customer_name', 'like', "%{$term}%")->orWhere('customer_phone', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%")))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map->toApi()->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'available' => $this->collections->available(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:' . PaymentCollectionService::MIN_AMOUNT, 'max:' . PaymentCollectionService::MAX_AMOUNT],
            'description' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'expires_in_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
        ]);

        $collection = $this->collections->create($data, (string) $request->user()->id);

        return response()->json(['message' => 'Đã tạo mã thu tiền.', 'data' => $collection->toApi()], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['data' => PaymentCollection::query()->findOrFail($id)->toApi()]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $collection = $this->collections->cancel(PaymentCollection::query()->findOrFail($id));

        return response()->json(['message' => 'Đã huỷ mã thu tiền.', 'data' => $collection->toApi()]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Chỉ Super Admin được tạo hoặc xem mã thu tiền.');
    }
}
