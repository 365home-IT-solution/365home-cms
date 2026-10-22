<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CollectionCampaign;
use App\Models\CollectionCustomerPiece;
use App\Models\CollectionPiece;
use App\Models\CollectionRedemption;
use App\Services\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;

/**
 * Quản trị chiến dịch sưu tầm mảnh ghép (chỉ Super Admin) — xem App\Services\CollectionService.
 *
 *  - GET|POST /api/admin/games/collections                 danh sách / tạo chiến dịch (cover_image: file tuỳ chọn)
 *  - GET|PUT|POST(sửa)|DELETE /api/admin/games/collections/{id}   chi tiết / sửa / xoá — đã có người nhận mảnh thì xoá = tắt (is_active = false)
 *  - PUT|POST /api/admin/games/collections/{id}/pieces     chi nhánh tham gia + ảnh mảnh (gửi danh sách cuối: pieces[i][branch_id], pieces[i][image] file)
 *  - GET /api/admin/games/collections/{id}/stats           số người có 1, 2, 3… mảnh; số người đã đổi; chi nhánh được ghé nhiều nhất
 * Chiến dịch đã bắt đầu và đã có người nhận mảnh thì KHÔNG sửa được danh sách chi nhánh / pieces_required (422).
 */
class CollectionAdminController extends Controller
{
    public function __construct(private readonly CollectionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => CollectionCampaign::withCount('pieces')->orderByDesc('start_at')->get()
            ->map(fn (CollectionCampaign $c) => $this->payload($c))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => $this->payload(CollectionCampaign::findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $data = $this->validated($request);
        if ($request->hasFile('cover_image')) {
            $data['cover_image'] = $request->file('cover_image')->store('collections', 'public');
        }

        return response()->json(['data' => $this->payload(CollectionCampaign::create($data)->fresh())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $campaign = CollectionCampaign::findOrFail($id);
        $data     = $this->validated($request, $campaign);

        if (isset($data['pieces_required']) && (int) $data['pieces_required'] !== $campaign->pieces_required) {
            $this->guardLocked($campaign);
            if ((int) $data['pieces_required'] > $campaign->pieces()->count()) {
                throw ValidationException::withMessages(['pieces_required' => ['Số mảnh cần đủ không được lớn hơn số chi nhánh tham gia.']]);
            }
        }

        if ($request->hasFile('cover_image')) {
            $data['cover_image'] = $request->file('cover_image')->store('collections', 'public');
        }

        $campaign->update($data);

        return response()->json(['data' => $this->payload($campaign->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $campaign = CollectionCampaign::findOrFail($id);

        if ($campaign->customerPieces()->exists()) {
            $campaign->update(['is_active' => false]);

            return response()->json(['message' => 'Chiến dịch đã có người nhận mảnh nên chỉ được tắt, không xoá.', 'data' => $this->payload($campaign->fresh())]);
        }

        $campaign->pieces()->delete();
        $campaign->delete();

        return response()->json(['message' => 'Đã xoá chiến dịch.']);
    }

    public function syncPieces(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $campaign = CollectionCampaign::findOrFail($id);
        $this->guardLocked($campaign);

        $request->validate([
            'pieces'             => 'required|array|min:1',
            'pieces.*.branch_id' => 'required|integer|distinct',
            'pieces.*.image'     => 'nullable|image|max:5120',
        ]);

        $rows = collect($request->input('pieces'));
        $ids  = $rows->pluck('branch_id')->map(fn ($v) => (int) $v)->all();

        // Chi nhánh = danh mục kiểu product ở bất kỳ cấp nào (đơn nằm ở chi nhánh gốc hoặc danh mục con như LUMEN, PIXEL…)
        $valid = Category::withoutGlobalScopes()->where('category_type', 'product')->whereIn('id', $ids)->pluck('id')->all();
        if (count($valid) !== count($ids)) {
            throw ValidationException::withMessages(['pieces' => ['Có chi nhánh không tồn tại.']]);
        }

        // Không chọn cùng lúc danh mục cha và con: đơn ở con luôn tính cho mảnh con, mảnh cha sẽ mơ hồ.
        foreach ($ids as $id) {
            if (count(array_intersect(array_slice(CollectionService::categoryChain($id), 1), $ids)) > 0) {
                throw ValidationException::withMessages(['pieces' => ['Không chọn đồng thời một chi nhánh và chi nhánh con của nó.']]);
            }
        }

        if ($campaign->pieces_required > count($ids)) {
            throw ValidationException::withMessages(['pieces' => ['Số chi nhánh tham gia phải ≥ số mảnh cần đủ (' . $campaign->pieces_required . ').']]);
        }

        DB::transaction(function () use ($request, $campaign, $ids) {
            CollectionPiece::where('campaign_id', $campaign->id)->whereNotIn('branch_id', $ids)->delete();

            foreach ($ids as $i => $branchId) {
                $piece = CollectionPiece::firstOrNew(['campaign_id' => $campaign->id, 'branch_id' => $branchId]);
                $piece->sort_order = $i;

                if ($request->hasFile("pieces.$i.image")) {
                    if ($piece->image && ! str_starts_with($piece->image, 'http')) {
                        Storage::disk('public')->delete($piece->image);
                    }
                    $piece->image = $request->file("pieces.$i.image")->store('collections/pieces', 'public');
                }

                $piece->save();
            }
        });

        return response()->json(['data' => $this->payload($campaign->fresh())]);
    }

    public function stats(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $campaign = CollectionCampaign::with('pieces.branch')->findOrFail($id);

        $perCustomer = CollectionCustomerPiece::where('campaign_id', $id)->selectRaw('customer_id, COUNT(*) as c')->groupBy('customer_id')->pluck('c');
        $byCount     = $perCustomer->countBy()->sortKeys()->map(fn ($customers, $pieces) => ['pieces' => (int) $pieces, 'customers' => $customers])->values();

        $visits = CollectionCustomerPiece::where('campaign_id', $id)->selectRaw('branch_id, COUNT(*) as c')->groupBy('branch_id')->pluck('c', 'branch_id');

        return response()->json(['data' => [
            'participants'         => $perCustomer->count(),
            'customers_by_pieces'  => $byCount,
            'ready_to_redeem'      => $perCustomer->filter(fn ($c) => $c >= $campaign->pieces_required)->count(),
            'redeemed'             => CollectionRedemption::where('campaign_id', $id)->count(),
            'branches'             => $campaign->pieces->map(fn ($p) => [
                'branch_id' => $p->branch_id, 'branch_name' => $p->branch?->name, 'collected' => (int) ($visits[$p->branch_id] ?? 0),
            ])->sortByDesc('collected')->values(),
        ]]);
    }

    private function payload(CollectionCampaign $c): array
    {
        $c->loadMissing('pieces.branch');

        return $c->only(['id', 'name', 'description', 'reward_label', 'coupon_type', 'coupon_value', 'min_order_value', 'max_discount', 'validity_days', 'pieces_required', 'is_active'])
            + [
                'cover_url'    => CollectionService::imageUrl($c->cover_image),
                'start_at'     => $c->start_at->toDateTimeString(),
                'end_at'       => $c->end_at->toDateTimeString(),
                'redeem_until' => $c->redeem_until->toDateTimeString(),
                'is_locked'    => $c->isLocked(),
                'pieces'       => $c->pieces->map(fn ($p) => [
                    'branch_id' => $p->branch_id, 'branch_name' => $p->branch?->name, 'branch_slug' => $p->branch?->slug, 'image_url' => CollectionService::imageUrl($p->image),
                ])->values(),
            ];
    }

    private function validated(Request $request, ?CollectionCampaign $campaign = null): array
    {
        $required = $campaign ? 'sometimes' : 'required';

        $data = $request->validate([
            'name'            => "{$required}|string|max:255",
            'description'     => 'nullable|string',
            'cover_image'     => 'nullable|image|max:5120',
            'start_at'        => "{$required}|date",
            'end_at'          => "{$required}|date",
            'redeem_until'    => "{$required}|date",
            'pieces_required' => "{$required}|integer|min:1",
            'reward_label'    => "{$required}|string|max:255",
            'coupon_type'     => "{$required}|in:fixed,percentage",
            'coupon_value'    => "{$required}|numeric|min:1",
            'min_order_value' => 'nullable|numeric|min:0',
            'max_discount'    => 'nullable|numeric|min:0',
            'validity_days'   => 'sometimes|integer|min:1|max:365',
            'is_active'       => 'sometimes|boolean',
        ]);
        unset($data['cover_image']);

        if (($data['coupon_type'] ?? $campaign?->coupon_type) === 'percentage' && (float) ($data['coupon_value'] ?? $campaign?->coupon_value) > 100) {
            throw ValidationException::withMessages(['coupon_value' => ['Giảm theo phần trăm không được quá 100.']]);
        }

        $start  = $data['start_at'] ?? $campaign?->start_at;
        $end    = $data['end_at'] ?? $campaign?->end_at;
        $redeem = $data['redeem_until'] ?? $campaign?->redeem_until;

        if (now()->parse($end)->lte(now()->parse($start))) {
            throw ValidationException::withMessages(['end_at' => ['Ngày kết thúc phải sau ngày bắt đầu.']]);
        }
        if (now()->parse($redeem)->lt(now()->parse($end))) {
            throw ValidationException::withMessages(['redeem_until' => ['Hạn đổi thưởng không được sớm hơn ngày kết thúc chiến dịch.']]);
        }

        return $data;
    }

    private function guardLocked(CollectionCampaign $campaign): void
    {
        if ($campaign->isLocked()) {
            throw ValidationException::withMessages(['campaign' => ['Chiến dịch đã bắt đầu và có người nhận mảnh, không thể đổi chi nhánh tham gia / số mảnh cần đủ.']]);
        }
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
