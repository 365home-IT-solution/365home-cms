<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\PhoneBranchBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;

/**
 * Khoá khách hàng đặt phòng theo chi nhánh (category gốc, category_type=product — bảng customer_branch_blocks).
 *
 * Quản lý: Filament (Khách hàng → "Khoá đặt phòng") và POST /api/admin/customers/{id}/branch-blocks — cả 2 đi qua
 * sync(), chỉ đụng tới chi nhánh trong phạm vi quyền của người thao tác (User::rootProductCategoryIds()).
 *
 * Chặn theo SĐT (bảng phone_branch_blocks — SĐT không cần có hồ sơ): Filament "Chặn SĐT đặt phòng"
 * (PhoneBranchBlockResource) và /api/admin/phone-blocks — đi qua blockPhones().
 *
 * Kiểm tra ở MỌI luồng tạo đơn: app khách (Api\BookingController), khách vãng lai (Api\GuestBookingController —
 * khớp theo SĐT để không lách được bằng cách đặt không đăng nhập), web (ProductDetail), nhân viên đặt hộ
 * (Api\Admin\BookingController, Filament CreateOrder).
 */
class CustomerBranchBlockService
{
    public const ERROR_CODE = 'CUSTOMER_BRANCH_BLOCKED';

    /** @var array<string, list<int>> rootProductCategoryIds() theo user — tránh truy vấn lại cho từng dòng danh sách. */
    private static array $scopeCache = [];

    public function __construct(private BranchServiceSync $branches) {}

    /** Chi nhánh đang khoá khách (theo hồ sơ $customer, hoặc hồ sơ trùng SĐT $phone) mà phòng $roomId thuộc về. */
    public function blockedBranchForRoom(?Customer $customer, ?string $phone, ?string $roomId): ?Category
    {
        if (! $roomId) {
            return null;
        }

        return $this->blockedBranchFor($customer, $phone, $this->branches->rootBranchIdsForRoomId($roomId));
    }

    /** Như blockedBranchForRoom() nhưng theo category bất kỳ (có thể là khu vực con) — dùng ở Filament CreateOrder. */
    public function blockedBranchForCategories(?Customer $customer, ?string $phone, array $categoryIds): ?Category
    {
        return $this->blockedBranchFor($customer, $phone, $this->branches->rootBranchIdsForCategoryIds($categoryIds));
    }

    public function message(Category $branch): string
    {
        return "Tài khoản của bạn hiện không thể đặt phòng tại chi nhánh {$branch->name}. Vui lòng chọn chi nhánh khác hoặc liên hệ CSKH để được hỗ trợ.";
    }

    /** 403 CUSTOMER_BRANCH_BLOCKED cho API (cùng kiểu EscrowService::salesBlockResponse()), null nếu không bị khoá. */
    public function bookingBlockResponse(?Customer $customer, ?string $phone, ?string $roomId): ?JsonResponse
    {
        $branch = $this->blockedBranchForRoom($customer, $phone, $roomId);

        return $branch
            ? response()->json([
                'message' => $this->message($branch),
                'code'    => self::ERROR_CODE,
                'branch'  => ['id' => $branch->id, 'name' => $branch->name, 'slug' => $branch->slug],
            ], 403)
            : null;
    }

    /**
     * Đặt lại danh sách chi nhánh khoá của khách = $branchIds, CHỈ trong phạm vi quyền của $by: chi nhánh ngoài phạm vi
     * (đối tác khác khoá) giữ nguyên, id gửi lên ngoài phạm vi bị bỏ qua. Chi nhánh đã khoá từ trước giữ nguyên lý do cũ
     * trừ khi có $reason mới.
     */
    public function sync(Customer $customer, array $branchIds, ?string $reason, User $by): void
    {
        $scope    = array_map('intval', $by->rootProductCategoryIds());
        $selected = array_values(array_intersect(array_map('intval', $branchIds), $scope));
        $reason   = filled($reason) ? trim((string) $reason) : null;

        DB::transaction(function () use ($customer, $scope, $selected, $reason, $by) {
            $customer->blockedBranches()->detach(array_values(array_diff($scope, $selected)));

            $existing = $customer->blockedBranches()->pluck('categories.id')->map(fn ($id) => (int) $id)->all();

            foreach ($selected as $branchId) {
                if (! in_array($branchId, $existing, true)) {
                    $customer->blockedBranches()->attach($branchId, ['reason' => $reason, 'blocked_by' => $by->id]);
                } elseif ($reason !== null) {
                    $customer->blockedBranches()->updateExistingPivot($branchId, ['reason' => $reason, 'blocked_by' => $by->id]);
                }
            }
        });

        $customer->unsetRelation('blockedBranches');
    }

    /**
     * Chặn các SĐT $phones đặt phòng ở các chi nhánh $branchIds (bảng phone_branch_blocks — SĐT không cần có hồ sơ khách).
     * Chỉ chi nhánh trong phạm vi quyền của $by; cặp SĐT + chi nhánh đã chặn thì cập nhật lý do (nếu có lý do mới).
     *
     * @return array{saved:int, invalid:list<string>}
     */
    public function blockPhones(array $phones, array $branchIds, ?string $reason, User $by): array
    {
        $scope     = array_map('intval', $by->rootProductCategoryIds());
        $branchIds = array_values(array_intersect(array_map('intval', $branchIds), $scope));
        $reason    = filled($reason) ? trim((string) $reason) : null;

        $valid   = [];
        $invalid = [];
        foreach ($phones as $phone) {
            if ($normalized = self::normalizePhone((string) $phone)) {
                $valid[$normalized] = $normalized;
            } elseif (filled($phone)) {
                $invalid[] = (string) $phone;
            }
        }

        $saved = 0;
        DB::transaction(function () use ($valid, $branchIds, $reason, $by, &$saved) {
            foreach ($valid as $phone) {
                foreach ($branchIds as $branchId) {
                    $block = PhoneBranchBlock::firstOrNew(['phone' => $phone, 'category_id' => $branchId]);
                    if (! $block->exists || $reason !== null) {
                        $block->fill(['reason' => $reason ?? $block->reason, 'blocked_by' => $by->id])->save();
                    }
                    $saved++;
                }
            }
        });

        return ['saved' => $saved, 'invalid' => $invalid];
    }

    /** Dòng chặn SĐT mà $user được xem/sửa/xoá: chi nhánh trong phạm vi quyền. */
    public function phoneBlocksQuery(User $user): Builder
    {
        $query = PhoneBranchBlock::query();

        return $user->seesAllPartners() ? $query : $query->whereIn('category_id', $user->rootProductCategoryIds() ?: [-1]);
    }

    /**
     * Chi nhánh khách đang bị khoá — $viewer (nhân viên) chỉ thấy chi nhánh trong phạm vi quyền của mình; null = tất cả
     * (dùng cho chính khách hàng ở app). $includePhoneBlocks: gộp cả chặn theo SĐT (source = 'phone') — form "Khoá đặt
     * phòng" của khách KHÔNG gộp, vì sync() chỉ quản lý khoá theo hồ sơ (source = 'customer').
     *
     * @return list<array{id:int, name:string, slug:?string, reason:?string, blocked_at:?string, source:string}>
     */
    public function listFor(Customer $customer, ?User $viewer = null, bool $includePhoneBlocks = false): array
    {
        $scope = $viewer && ! $viewer->seesAllPartners()
            ? (self::$scopeCache[$viewer->id] ??= array_map('intval', $viewer->rootProductCategoryIds()))
            : null;

        $branches = $customer->relationLoaded('blockedBranches')
            ? $customer->blockedBranches
            : $customer->blockedBranches()->get(['categories.id', 'categories.name', 'categories.slug']);

        $rows = $branches->map(fn (Category $c) => [
            'id'         => (int) $c->id,
            'name'       => $c->name,
            'slug'       => $c->slug,
            'reason'     => $c->pivot->reason,
            'blocked_at' => $c->pivot->created_at?->toIso8601String(),
            'source'     => 'customer',
        ]);

        if ($includePhoneBlocks && ($phone = self::normalizePhone($customer->phone))) {
            $taken = $rows->pluck('id')->all();
            $rows  = $rows->concat(
                PhoneBranchBlock::with('branch:id,name,slug')->where('phone', $phone)->get()
                    ->filter(fn (PhoneBranchBlock $b) => $b->branch && ! in_array((int) $b->category_id, $taken, true))
                    ->map(fn (PhoneBranchBlock $b) => [
                        'id'         => (int) $b->category_id,
                        'name'       => $b->branch->name,
                        'slug'       => $b->branch->slug,
                        'reason'     => $b->reason,
                        'blocked_at' => $b->created_at?->toIso8601String(),
                        'source'     => 'phone',
                    ])
            );
        }

        return $rows
            ->filter(fn (array $r) => $scope === null || in_array($r['id'], $scope, true))
            ->values()
            ->all();
    }

    /** Slug chi nhánh GỐC (cách nhau dấu phẩy — field 'categories' của POST /api/admin/login) → id. */
    public function branchIdsFromSlugs(string $slugsParam): array
    {
        $slugs = array_values(array_filter(array_map('trim', explode(',', $slugsParam))));

        return $slugs
            ? Category::whereNull('parent_id')->where('category_type', 'product')->whereIn('slug', $slugs)->pluck('id')->all()
            : [];
    }

    /** SĐT Việt Nam về dạng chuẩn 0xxxxxxxxx (nhận 0…/+84…/84…, có dấu cách/chấm/gạch); null nếu không hợp lệ. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        $local = match (true) {
            str_starts_with($digits, '84') && strlen($digits) === 11 => '0' . substr($digits, 2),
            strlen($digits) === 9 && ! str_starts_with($digits, '0') => '0' . $digits,
            default                                                  => $digits,
        };

        return preg_match('/^0\d{9}$/', $local) ? $local : null;
    }

    private function blockedBranchFor(?Customer $customer, ?string $phone, array $branchIds): ?Category
    {
        $branchIds = array_values(array_filter(array_map('intval', $branchIds)));
        if (! $branchIds) {
            return null;
        }

        // 1) Chặn theo SĐT (SĐT người đặt + SĐT hồ sơ khách).
        $phones    = array_values(array_unique(array_filter([self::normalizePhone($phone), self::normalizePhone($customer?->phone)])));
        $blockedId = $phones
            ? PhoneBranchBlock::whereIn('phone', $phones)->whereIn('category_id', $branchIds)->value('category_id')
            : null;

        // 2) Khoá theo hồ sơ khách (chính khách đó, hoặc hồ sơ trùng SĐT người đặt).
        if (! $blockedId) {
            $customerIds = array_filter([$customer?->id]);
            if ($variants = self::phoneVariants($phone)) {
                $customerIds = array_merge($customerIds, Customer::whereIn('phone', $variants)->pluck('id')->all());
            }

            $blockedId = $customerIds
                ? DB::table('customer_branch_blocks')
                    ->whereIn('customer_id', array_unique($customerIds))
                    ->whereIn('category_id', $branchIds)
                    ->value('category_id')
                : null;
        }

        return $blockedId ? Category::withoutGlobalScopes()->find($blockedId) : null;
    }

    /** 0912345678 ⇄ +84912345678 ⇄ 84912345678 — SĐT khách lưu không thống nhất 1 dạng. */
    private static function phoneVariants(?string $phone): array
    {
        if (! $local = self::normalizePhone($phone)) {
            return [];
        }
        $intl = substr($local, 1);

        return array_values(array_unique([$local, '+84' . $intl, '84' . $intl, trim((string) $phone)]));
    }
}
