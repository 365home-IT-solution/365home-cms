<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerStatusLog;
use App\Models\RoomRating;
use App\Models\User;
use App\Services\PartnerContractWorkflowService;
use App\Services\PartnerLegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Category\Entities\Category;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerController extends Controller
{
    public function index(Request $request, PartnerLegalDocumentService $documents): JsonResponse
    {
        $this->superAdmin($request);
        $partners = Partner::query()->where('partner_type', $this->partnerType($request))
            ->where('id', '!=', \Modules\Minihouse\App\Support\HomestayBridge::PARTNER_ID)
            ->latest()->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($partners->through(fn (Partner $partner) => $this->format($partner, $documents)));
    }

    public function store(Request $request, PartnerLegalDocumentService $documents): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate([...$this->rules(), 'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id']], $this->messages());
        // Gói dùng thử được chọn lúc tạo (không phải cột của partners). Bỏ trống → gói mặc định của loại đối tác.
        $planId = $data['plan_id'] ?? null;
        unset($data['plan_id']);
        $plan = $planId ? \App\Models\SubscriptionPlan::findOrFail($planId) : null;
        if ($plan && ($plan->partner_type !== $this->partnerType($request) || ! $plan->is_active)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['plan_id' => 'Gói không áp dụng cho loại đối tác này.']);
        }
        $partner = Partner::create([...$data, 'partner_type' => $this->partnerType($request), 'name' => $data['legal_name'], 'created_by' => $request->user()->id, 'verification_status' => 'pending', 'contract_status' => 'draft', 'status' => true]);
        PartnerStatusLog::create(['partner_id' => $partner->id, 'to_status' => 'pending', 'note' => 'Hồ sơ đối tác được tạo qua API.', 'changed_by' => $request->user()->id]);
        if ($plan) {
            app(\App\Services\SubscriptionService::class)->startTrialWithPlan($partner, $plan);
        }

        return response()->json(['message' => 'Đã tạo đối tác.', 'data' => $this->format($partner, $documents)], 201);
    }

    public function show(Request $request, Partner $partner, PartnerLegalDocumentService $documents): JsonResponse
    {
        abort_if($partner->isSystemPartner(), 404);
        $this->partnerAccess($request, $partner);

        return response()->json(['data' => $this->format($partner, $documents)]);
    }

    public function update(Request $request, Partner $partner, PartnerLegalDocumentService $documents): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate($this->rules(true, $partner), $this->messages());
        if (isset($data['legal_name'])) {
            $data['name'] = $data['legal_name'];
        }
        $partner->update($data);

        return response()->json(['message' => 'Đã cập nhật đối tác.', 'data' => $this->format($partner->fresh(), $documents)]);
    }

    public function contract(Request $request, Partner $partner, PartnerLegalDocumentService $documents): JsonResponse
    {
        $this->partnerAccess($request, $partner);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $version = $partner->contractVersions()->first();

        return response()->json(['data' => [
            'partner_id' => $partner->id,
            'contract_status' => $partner->contract_status,
            'can_create_contract' => $documents->isContractEligible($partner),
            'version_id' => $version?->id,
            'content_hash' => $version?->content_hash,
            'partner_confirmed_at' => $version?->partner_confirmed_at?->toIso8601String(),
            'platform_signed_at' => $version?->platform_signed_at?->toIso8601String(),
            'is_fully_signed' => $version?->isFullySigned() ?? false,
        ]]);
    }

    public function createContract(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $result = $workflow->createAndSend($partner, $request->user());

        return response()->json(['message' => 'Đã tạo hợp đồng và xử lý gửi email.', 'data' => [
            'version_id' => $result['version']->id, 'contract_code' => $partner->fresh()->contract_code, 'content_hash' => $result['version']->content_hash,
            'mail_sent' => $result['mailSent'], 'email' => $result['email'], 'signing_url' => $result['signingUrl'],
        ]], 201);
    }

    public function platformSign(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): StreamedResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $result = $workflow->platformSign($partner, $request->user(), (string) $request->ip(), (string) $request->userAgent());

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    public function financial(Request $request, Partner $partner): JsonResponse
    {
        $this->partnerAccess($request, $partner);

        return response()->json(['data' => $this->financialData($partner)]);
    }

    public function updateFinancial(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate([
            'bank_code' => ['nullable', 'string', Rule::in(\App\Support\Banks::codes())], 'bank_name' => ['nullable', 'string', Rule::in(\App\Support\Banks::shortNames())], 'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/'], 'bank_account_holder' => ['nullable', 'string', 'max:255'],
            'momo_phone' => ['nullable', 'string', 'max:30', 'regex:/^(0|\+84)[0-9]{9,10}$/'], 'zalopay_id' => ['nullable', 'string', 'max:100'],
            'vnpay_id' => ['nullable', 'string', 'max:100'], 'paypal_email' => ['nullable', 'email', 'max:255'],
            'wise_account' => ['nullable', 'string', 'max:255'], 'swift_code' => ['nullable', 'string', 'max:50'],
            'payment_cycle' => ['nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'bank_card_image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], $this->messages());
        // Ngân hàng CHỌN từ danh sách: bank_code → lưu tên chuẩn vào bank_name.
        if (! empty($data['bank_code'])) {
            $data['bank_name'] = \App\Support\Banks::find($data['bank_code'])['short_name'];
        }
        $partner->update(collect($data)->except(['bank_card_image', 'bank_code'])->all());
        if ($request->hasFile('bank_card_image')) {
            $partner->addMediaFromRequest('bank_card_image')->toMediaCollection('bank_card_image');
        }

        return response()->json(['message' => 'Đã cập nhật thông tin tài chính.', 'data' => $this->financialData($partner->fresh())]);
    }

    public function facilities(Request $request, Partner $partner): JsonResponse
    {
        $this->partnerAccess($request, $partner);
        $items = Category::query()->where('partner_id', $partner->id)->whereNull('parent_id')->where('category_type', 'product')->withCount('products')->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn (Category $item) => $this->facilityData($item)), 'summary' => [
            'total_facilities' => $items->count(), 'active_facilities' => $items->where('status', true)->count(),
            'total_rooms' => Product::query()->where('partner_id', $partner->id)->count(),
        ]]);
    }

    public function storeFacility(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate($this->facilityRules());
        $facility = Category::create([...$data, 'slug' => $data['slug'] ?? Str::slug($data['name']), 'category_type' => 'product', 'parent_id' => null, 'partner_id' => $partner->id]);

        return response()->json(['message' => 'Đã tạo cơ sở lưu trú.', 'data' => $this->facilityData($facility)], 201);
    }

    public function showFacility(Request $request, Partner $partner, Category $facility): JsonResponse
    {
        $this->partnerAccess($request, $partner);
        $this->assertFacility($partner, $facility);

        return response()->json(['data' => $this->facilityData($facility)]);
    }

    public function updateFacility(Request $request, Partner $partner, Category $facility): JsonResponse
    {
        $this->superAdmin($request);
        $this->assertFacility($partner, $facility);
        $facility->update($request->validate($this->facilityRules(true)));

        return response()->json(['message' => 'Đã cập nhật cơ sở lưu trú.', 'data' => $this->facilityData($facility->fresh())]);
    }

    public function branchAssignments(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $options = $partner->assignableBranchesQuery()
            ->with('partner:id,legal_name,name')
            ->orderBy('name')
            ->get(['id', 'name', 'partner_id'])
            ->map(fn (Category $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'partner_id' => $branch->partner_id,
                'partner_name' => $branch->partner?->legal_name ?: $branch->partner?->name,
            ]);

        return response()->json(['data' => ['selected_ids' => $options->where('partner_id', $partner->id)->pluck('id')->values(), 'options' => $options->values()]]);
    }

    // Homestay (PUT /api/admin/partners/{partner}/branch-assignments) nhận branch_ids; MiniHouse
    // (PUT /api/admin/minihouse/partners/{partner}/building-assignments) nhận building_ids, vẫn chấp
    // nhận branch_ids cho client cũ. Danh sách gửi lên là TOÀN BỘ chi nhánh/tòa nhà của đối tác sau khi
    // lưu — cái nào đang thuộc đối tác mà không có trong danh sách sẽ bị bỏ gán (xem
    // Partner::releasedBranchPartnerId()).
    public function updateBranchAssignments(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $field = $partner->isMinihouse() && $request->has('building_ids') ? 'building_ids' : 'branch_ids';
        $data = $request->validate([$field => ['present', 'array'], $field.'.*' => ['integer', 'distinct']]);
        $ids = array_values(array_unique(array_map('intval', $data[$field])));
        $label = $partner->isMinihouse() ? 'tòa nhà' : 'chi nhánh';

        $assignableIds = $partner->assignableBranchesQuery()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($invalid = array_values(array_diff($ids, $assignableIds))) {
            return response()->json([
                'message' => $partner->isMinihouse()
                    ? 'Chỉ được gán tòa nhà đang thuộc đối tác MiniHouse.'
                    : 'Chỉ được gán chi nhánh Homestay hoặc chi nhánh chưa có đối tác.',
                'invalid_ids' => $invalid,
            ], 422);
        }

        $releasedPartnerId = $partner->releasedBranchPartnerId();
        $removed = Category::query()->whereNull('parent_id')->where('category_type', 'product')
            ->where('partner_id', $partner->id)->whereNotIn('id', $ids)->pluck('id')->all();
        if ($removed && $releasedPartnerId === $partner->id) {
            return response()->json([
                'message' => 'Không thể bỏ gán tòa nhà khỏi đối tác MiniHouse nội bộ — hãy gán tòa nhà đó sang đối tác MiniHouse khác.',
                'invalid_ids' => $removed,
            ], 422);
        }

        DB::transaction(function () use ($partner, $ids, $releasedPartnerId) {
            Category::query()->whereNull('parent_id')->where('category_type', 'product')->where(fn ($q) => $q->where('partner_id', $partner->id)->orWhereIn('id', $ids))->get()->each(function (Category $branch) use ($partner, $ids, $releasedPartnerId) {
                $newPartnerId = in_array((int) $branch->id, $ids, true) ? $partner->id : $releasedPartnerId;
                if ($branch->partner_id !== $newPartnerId) {
                    $branch->update(['partner_id' => $newPartnerId]);
                }
            });
        });

        return response()->json(['message' => "Đã cập nhật {$label} của đối tác.", 'data' => ['selected_ids' => $ids, 'released_ids' => $removed, 'released_to_partner_id' => $removed ? $releasedPartnerId : null]]);
    }

    public function userAssignments(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $users = User::query()->whereDoesntHave('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name')))
            ->where(fn ($query) => $query->whereNull('partner_id')->orWhereHas('partner', fn ($partnerQuery) => $partnerQuery->where('partner_type', $partner->partner_type)))
            ->orderBy('fullname')->get(['id', 'fullname', 'email', 'partner_id']);

        return response()->json(['data' => ['selected_ids' => $users->where('partner_id', $partner->id)->pluck('id')->values(), 'options' => $users]]);
    }

    public function updateUserAssignments(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['uuid', 'distinct', Rule::exists('users', 'id')]]);
        $ids = $data['user_ids'];
        abort_if(User::query()->whereIn('id', $ids)->whereHas('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name')))->exists(), 422, 'Không thể gán tài khoản Super Admin.');
        abort_if(User::query()->whereIn('id', $ids)->whereHas('partner', fn ($query) => $query->where('partner_type', '!=', $partner->partner_type))->exists(), 422, 'Không thể chuyển người dùng giữa Homestay và MiniHouse.');
        DB::transaction(function () use ($partner, $ids) {
            User::query()->where(fn ($q) => $q->where('partner_id', $partner->id)->orWhereIn('id', $ids))->get()->each(function (User $user) use ($partner, $ids) {
                $newPartnerId = in_array($user->id, $ids, true) ? $partner->id : null;
                if ($user->partner_id === $newPartnerId) {
                    return;
                }
                $user->update(['partner_id' => $newPartnerId]);
                $permissions = UserBranchPermission::where('user_id', $user->id);
                if ($newPartnerId === null) {
                    $permissions->get()->each->delete();
                } else {
                    $permissions->whereHas('branch', fn ($q) => $q->where('partner_id', '!=', $newPartnerId))->get()->each->delete();
                }
            });
        });

        return response()->json(['message' => 'Đã cập nhật người dùng của đối tác.', 'data' => ['selected_ids' => $ids]]);
    }

    private function financialData(Partner $partner): array
    {
        $media = $partner->getFirstMedia('bank_card_image');

        return ['bank_code' => \App\Support\Banks::findByShortName($partner->bank_name)['code'] ?? null, ...$partner->only(['bank_name', 'bank_branch', 'bank_account_number', 'bank_account_holder', 'momo_phone', 'zalopay_id', 'vnpay_id', 'paypal_email', 'wise_account', 'swift_code', 'payment_cycle']),
            'bank_card_image' => $media ? ['name' => $media->file_name, 'mime_type' => $media->mime_type, 'size' => $media->size, 'url' => $media->getUrl()] : null,
        ];
    }

    private function facilityRules(bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:255'], 'slug' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'], 'status' => ['sometimes', 'boolean'],
            'lodging_type' => ['nullable', Rule::in(['hotel', 'villa', 'homestay', 'apartment'])],
            'timezone' => ['nullable', Rule::in(['Asia/Ho_Chi_Minh'])], 'operation_manager_name' => ['nullable', 'string', 'max:255'],
            'area_sqm' => ['nullable', 'numeric', 'min:0'], 'established_year' => ['nullable', 'integer', 'min:1990', 'max:'.now()->year],
            'checkin_time' => ['nullable', 'date_format:H:i'], 'checkout_time' => ['nullable', 'date_format:H:i'],
            'default_policy' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function facilityData(Category $facility): array
    {
        $roomIds = $facility->products()->pluck('products.id');

        return [...$facility->only(['id', 'partner_id', 'name', 'slug', 'description', 'status', 'lodging_type', 'timezone', 'operation_manager_name', 'area_sqm', 'established_year', 'checkin_time', 'checkout_time', 'default_policy']),
            'room_count' => $facility->products_count ?? $roomIds->count(),
            'monthly_revenue' => (float) Order::query()->where('category_id', $facility->id)->where('status', 'paid')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum(DB::raw('COALESCE(amount, full_amount)')),
            'rating' => ['average' => ($average = RoomRating::query()->whereIn('room_id', $roomIds)->avg('star')) !== null ? (float) $average : null, 'count' => RoomRating::query()->whereIn('room_id', $roomIds)->count()],
        ];
    }

    private function assertFacility(Partner $partner, Category $facility): void
    {
        abort_unless($facility->partner_id === $partner->id && $facility->parent_id === null && $facility->category_type === 'product', 404);
    }

    // Thông báo tiếng Việt cho các quy tắc định dạng (mặc định hiện tên trường tiếng Anh/“today” khó hiểu).
    private function messages(): array
    {
        return [
            'tax_code.regex' => 'Mã số thuế gồm 10 số (hoặc 13 số cho đơn vị phụ thuộc, vd 0312345678-001).',
            'phone.regex' => 'Số điện thoại phải dạng 0xxxxxxxxx hoặc +84xxxxxxxxx.',
            'representative_phone_secondary.regex' => 'Số điện thoại phải dạng 0xxxxxxxxx hoặc +84xxxxxxxxx.',
            'momo_phone.regex' => 'Số điện thoại MoMo phải dạng 0xxxxxxxxx hoặc +84xxxxxxxxx.',
            'representative_id_number.regex' => 'Số giấy tờ gồm 6–20 chữ/số, không dấu cách.',
            'representative_dob.before_or_equal' => 'Người đại diện phải đủ 18 tuổi.',
            'business_license_date.before_or_equal' => 'Ngày cấp giấy phép kinh doanh không được ở tương lai.',
            'contract_expires_at.after' => 'Ngày hết hạn hợp đồng phải sau hôm nay.',
            'bank_account_number.regex' => 'Số tài khoản chỉ gồm 6–20 chữ số.',
            'bank_code.in' => 'Ngân hàng không có trong danh sách — vui lòng chọn lại.',
            'bank_name.in' => 'Ngân hàng không có trong danh sách — vui lòng chọn lại.',
        ];
    }

    private function rules(bool $update = false, ?Partner $partner = null): array
    {
        $sometimes = $update ? 'sometimes' : 'required';
        $vnPhone = 'regex:/^(0|\+84)[0-9]{9,10}$/';

        return [
            'legal_name' => [$sometimes, 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'regex:/^\d{10}(-?\d{3})?$/'],
            'phone' => ['nullable', 'string', 'max:30', $vnPhone], 'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'], 'representative_name' => [$sometimes, 'string', 'max:255'],
            'representative_dob' => ['nullable', 'date', 'before_or_equal:' . now()->subYears(18)->toDateString()],
            'representative_id_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{6,20}$/'],
            'representative_position' => ['nullable', 'string', 'max:100'],
            'representative_id_issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'representative_id_issued_place' => ['nullable', 'string', 'max:255'],
            'representative_phone_secondary' => ['nullable', 'string', 'max:30', $vnPhone],
            'business_license_date' => ['nullable', 'date', 'before_or_equal:today'], 'business_license_issuer' => ['nullable', 'string', 'max:255'],
            'contract_type' => ['nullable', Rule::in(['e_contract', 'paper'])],
            'contract_expires_at' => ['nullable', 'date', 'after:today'],
            'commission_rate' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && app(\App\Services\PartnerContractWorkflowService::class)->commissionValue((string) $value) === null) {
                    $fail('Tỷ lệ hoa hồng phải là số từ 0 đến 100 (vd 10 hoặc 10%).');
                }
            }],
            'cancellation_policy' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function format(Partner $partner, PartnerLegalDocumentService $documents): array
    {
        $sub = $partner->subscription()->with('plan:id,code,name')->first();

        return ['subscription' => $sub ? ['plan' => $sub->plan?->only(['id', 'code', 'name']), 'status' => $sub->state(), 'is_trial' => $sub->is_trial, 'expires_at' => $sub->expires_at?->toIso8601String()] : null, ...$partner->only(['id', 'partner_type', 'legal_name', 'tax_code', 'phone', 'email', 'address', 'representative_name', 'representative_dob', 'representative_position', 'representative_id_number', 'representative_id_issued_at', 'representative_id_issued_place', 'business_license_date', 'business_license_issuer', 'verification_status', 'contract_code', 'contract_type', 'contract_status', 'contract_signed_at', 'contract_expires_at', 'commission_rate']), 'verification' => $documents->readiness($partner)];
    }

    private function partnerType(Request $request): string
    {
        return $request->is('api/admin/minihouse/*') ? Partner::TYPE_MINIHOUSE : Partner::TYPE_HOMESTAY;
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }

    private function partnerAccess(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner(), 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->partner_id === $partner->id, 403);
    }
}
