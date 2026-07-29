<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;

class CustomerController extends Controller
{
    private const LIST_RELATIONS = ['province:id,name', 'membershipTier:id,name'];

    // GET /api/admin/customers
    // Khách hàng KHÔNG thuộc riêng đối tác nào (xem App\Models\Concerns\BelongsToPartner) — mọi
    // admin/nhân viên đã đăng nhập đều xem được toàn bộ danh sách, không lọc theo partner_id.
    // ?search= (tên/SĐT), ?status=, ?membership_tier_id=, ?page=
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::query()
            ->with(self::LIST_RELATIONS)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($q2) use ($search) {
                    $q2->where('fullname', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('membership_tier_id'), fn ($q) => $q->where('membership_tier_id', $request->integer('membership_tier_id')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20))
            ->through(fn (Customer $customer) => $this->formatCustomer($customer));

        return response()->json($customers);
    }

    // GET /api/admin/customers/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $customer = Customer::with([...self::LIST_RELATIONS, 'companions'])->find($id);

        if (! $customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng.'], 404);
        }

        return response()->json(['customer' => $this->formatCustomer($customer)]);
    }

    // POST /api/admin/customers (multipart/form-data nếu có kèm ảnh CCCD)
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), $this->messages());

        $customer = Customer::create($this->mainFields($data));

        $this->handleCccd($request, $customer);

        return response()->json(['customer' => $this->formatCustomer($customer->fresh(self::LIST_RELATIONS))], 201);
    }

    // POST /api/admin/customers/{id} (dùng POST thay PUT — PHP không tự parse multipart cho method PUT thật)
    public function update(Request $request, string $id): JsonResponse
    {
        $customer = Customer::find($id);

        if (! $customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng.'], 404);
        }

        $data = $request->validate($this->rules(true), $this->messages());

        $customer->update($this->mainFields($data, true));

        $this->handleCccd($request, $customer);

        return response()->json(['customer' => $this->formatCustomer($customer->fresh(self::LIST_RELATIONS))]);
    }

    private function rules(bool $isUpdate = false): array
    {
        $required    = $isUpdate ? 'sometimes|required' : 'required';
        // Regex chứa dấu "|" nên phải viết dạng mảng — xem ghi chú chi tiết ở
        // EmployeeController::rules() (field phone).
        $requiredArr = $isUpdate ? ['sometimes', 'required'] : ['required'];
        $ignoreId    = $isUpdate ? ',' . request()->route('id') : '';

        return [
            'fullname'           => "{$required}|string|max:255",
            // SĐT Việt Nam: 0xxxxxxxxx hoặc +84xxxxxxxxx.
            'phone'              => [...$requiredArr, 'string', 'max:20', 'regex:/^(0|\+84)[0-9]{9}$/', 'unique:customers,phone' . $ignoreId],
            'date_of_birth'      => 'nullable|date_format:d-m-Y',
            'status'             => 'sometimes|in:active,inactive',
            'password'           => 'nullable|string|min:6',
            'province_id'        => 'nullable|integer|exists:provinces,id',
            'membership_tier_id' => 'nullable|integer|exists:membership_tiers,id',
            'cccd_front'         => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'cccd_back'          => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
        ];
    }

    private function messages(): array
    {
        return [
            'phone.regex' => 'Số điện thoại không đúng định dạng (ví dụ 0912345678 hoặc +84912345678).',
        ];
    }

    private function mainFields(array $data, bool $isUpdate = false): array
    {
        $fields = collect($data)->except(['cccd_front', 'cccd_back'])->toArray();

        if (isset($fields['date_of_birth'])) {
            $fields['date_of_birth'] = Carbon::createFromFormat('d-m-Y', $fields['date_of_birth'])->format('Y-m-d');
        }

        if (! $isUpdate) {
            $fields['status'] = $fields['status'] ?? Customer::STATUS_ACTIVE;
        }

        return $fields;
    }

    // Giống luồng tạo/sửa đơn của Admin\BookingController — chỉ lưu + quét khi có ĐỦ CẢ 2 mặt,
    // quét lỗi không chặn request (lễ tân có thể xác minh/sửa tay cccd_data sau).
    private function handleCccd(Request $request, Customer $customer): void
    {
        if (! $request->hasFile('cccd_front') || ! $request->hasFile('cccd_back')) {
            return;
        }

        $front = $request->file('cccd_front')->store('cccd', 'public');
        $back  = $request->file('cccd_back')->store('cccd', 'public');

        $data = null;
        try {
            $data = app(CccdScannerService::class)->scanPaths($front, $back);
        } catch (\Throwable $e) {
            Log::warning('Admin API: quét CCCD khách hàng thất bại', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }

        $customer->update([
            'cccd_front' => $front,
            'cccd_back'  => $back,
            'cccd_data'  => $data,
        ]);
    }

    private function formatCustomer(Customer $customer): array
    {
        $data = $customer->toArray();

        $data['cccd_front_url'] = $customer->cccd_front ? Storage::disk('public')->url($customer->cccd_front) : null;
        $data['cccd_back_url']  = $customer->cccd_back  ? Storage::disk('public')->url($customer->cccd_back)  : null;

        return $data;
    }
}
