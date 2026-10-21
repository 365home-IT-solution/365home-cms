<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PhoneBranchBlock;
use App\Models\User;
use App\Services\CustomerBranchBlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Chặn SĐT đặt phòng theo chi nhánh — cùng nghiệp vụ với Filament "Chặn SĐT đặt phòng" (PhoneBranchBlockResource).
// Mọi thao tác chỉ trong phạm vi chi nhánh của user (User::rootProductCategoryIds()).
class PhoneBranchBlockController extends Controller
{
    public function __construct(private CustomerBranchBlockService $blocks) {}

    // GET /api/admin/phone-blocks?search=&branch_id=&categories=&per_page=
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $branchIds = $request->filled('categories')
            ? ($this->blocks->branchIdsFromSlugs((string) $request->string('categories')) ?: [-1])
            : ($request->filled('branch_id') ? [$request->integer('branch_id')] : null);

        $search = preg_replace('/\D+/', '', (string) $request->input('search'));

        $page = $this->blocks->phoneBlocksQuery($user)
            ->with(['branch:id,name,slug', 'blocker:id,fullname'])
            ->when($branchIds, fn ($q) => $q->whereIn('category_id', $branchIds))
            ->when($search !== '', fn ($q) => $q->where('phone', 'like', '%' . (str_starts_with($search, '84') && strlen($search) > 9 ? '0' . substr($search, 2) : $search) . '%'))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        $names = $this->customerNames($page->getCollection()->pluck('phone')->unique()->all());

        return response()->json($page->through(fn (PhoneBranchBlock $b) => $this->format($b, $names)));
    }

    // POST /api/admin/phone-blocks
    // Body JSON: items[] — mỗi item { phones: ["0912345678", ...], branch_ids: [1, 2] HOẶC categories: "slug1,slug2",
    // reason?: "..." }. Mỗi SĐT trong item bị chặn ở mọi chi nhánh của item (đã chặn thì giữ, cập nhật lý do nếu gửi).
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.phones'     => 'required|array|min:1',
            'items.*.phones.*'   => 'required|string|max:30',
            'items.*.branch_ids' => 'sometimes|array',
            'items.*.branch_ids.*' => 'integer',
            'items.*.categories' => 'sometimes|nullable|string',
            'items.*.reason'     => 'sometimes|nullable|string|max:500',
        ]);

        $scope  = array_map('intval', $user->rootProductCategoryIds());
        $errors = [];
        $plans  = [];

        foreach ($data['items'] as $i => $item) {
            $branchIds = isset($item['categories'])
                ? $this->blocks->branchIdsFromSlugs((string) $item['categories'])
                : array_map('intval', $item['branch_ids'] ?? []);

            if (! $branchIds) {
                $errors["items.{$i}.branch_ids"] = ['Chọn ít nhất 1 chi nhánh.'];
            } elseif ($out = array_diff($branchIds, $scope)) {
                $errors["items.{$i}.branch_ids"] = ['Chi nhánh không thuộc quyền quản lý của bạn: ' . implode(', ', $out)];
            }

            foreach ($item['phones'] as $j => $phone) {
                if (! CustomerBranchBlockService::normalizePhone($phone)) {
                    $errors["items.{$i}.phones.{$j}"] = ["SĐT \"{$phone}\" không hợp lệ."];
                }
            }

            $plans[] = [$item['phones'], $branchIds, $item['reason'] ?? null];
        }

        if ($errors) {
            return response()->json(['message' => 'Dữ liệu không hợp lệ.', 'errors' => $errors], 422);
        }

        $saved = 0;
        foreach ($plans as [$phones, $branchIds, $reason]) {
            $saved += $this->blocks->blockPhones($phones, $branchIds, $reason, $user)['saved'];
        }

        return response()->json(['message' => "Đã lưu {$saved} lượt chặn (SĐT × chi nhánh).", 'saved' => $saved], 201);
    }

    // POST /api/admin/phone-blocks/{id} — body: category_id?, reason?
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user  = $request->user();
        $block = $this->blocks->phoneBlocksQuery($user)->find($id);

        if (! $block) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate([
            'category_id' => ['sometimes', 'integer', 'in:' . implode(',', $user->rootProductCategoryIds() ?: [-1]),
                \Illuminate\Validation\Rule::unique('phone_branch_blocks', 'category_id')->where('phone', $block->phone)->ignore($block->id)],
            'reason'      => 'sometimes|nullable|string|max:500',
        ], ['category_id.unique' => 'SĐT này đã bị chặn ở chi nhánh đó.', 'category_id.in' => 'Chi nhánh không thuộc quyền quản lý của bạn.']);

        $block->update($data + ['blocked_by' => $user->id]);

        $block->load(['branch:id,name,slug', 'blocker:id,fullname']);

        return response()->json(['phone_block' => $this->format($block, $this->customerNames([$block->phone]))]);
    }

    // DELETE /api/admin/phone-blocks/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        $block = $this->blocks->phoneBlocksQuery($request->user())->find($id);

        if (! $block) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $block->delete();

        return response()->json(['message' => 'Đã bỏ chặn.']);
    }

    // POST /api/admin/phone-blocks/delete-batch — body: ids[] HOẶC phones[] (bỏ chặn mọi chi nhánh trong phạm vi quyền
    // của các SĐT này), có thể kèm branch_ids[] để chỉ bỏ chặn ở các chi nhánh đó.
    public function destroyBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'          => 'required_without:phones|array',
            'ids.*'        => 'integer',
            'phones'       => 'required_without:ids|array',
            'phones.*'     => 'string|max:30',
            'branch_ids'   => 'sometimes|array',
            'branch_ids.*' => 'integer',
        ]);

        $phones = array_values(array_filter(array_map(fn ($p) => CustomerBranchBlockService::normalizePhone($p), $data['phones'] ?? [])));

        $deleted = $this->blocks->phoneBlocksQuery($request->user())
            ->when(isset($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->when(! isset($data['ids']), fn ($q) => $q->whereIn('phone', $phones ?: ['-']))
            ->when(! empty($data['branch_ids']), fn ($q) => $q->whereIn('category_id', $data['branch_ids']))
            ->get()
            ->each->delete()
            ->count();

        return response()->json(['message' => "Đã bỏ chặn {$deleted} lượt.", 'deleted' => $deleted]);
    }

    private function format(PhoneBranchBlock $b, array $names): array
    {
        return [
            'id'            => $b->id,
            'phone'         => $b->phone,
            'customer_name' => $names[$b->phone] ?? null,
            'branch'        => $b->branch ? ['id' => (int) $b->branch->id, 'name' => $b->branch->name, 'slug' => $b->branch->slug] : null,
            'reason'        => $b->reason,
            'blocked_by'    => $b->blocker?->fullname,
            'created_at'    => $b->created_at?->toIso8601String(),
        ];
    }

    /** SĐT chuẩn hoá → tên khách có hồ sơ trùng SĐT (khách lưu SĐT nhiều dạng 0…/84…/+84…). */
    private function customerNames(array $phones): array
    {
        $variants = collect($phones)->flatMap(fn (string $p) => [$p, '84' . substr($p, 1), '+84' . substr($p, 1)])->all();

        return Customer::whereIn('phone', $variants ?: ['-'])->get(['phone', 'fullname'])
            ->mapWithKeys(fn (Customer $c) => [CustomerBranchBlockService::normalizePhone($c->phone) => $c->fullname])
            ->all();
    }
}
