<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\RoomRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

/**
 * Quản lý ĐÁNH GIÁ PHÒNG (bảng `room_ratings`, model App\Models\RoomRating) — xem, phản hồi VÀ xoá
 * đánh giá của khách (vd spam, ngôn từ không phù hợp). Admin không TẠO/SỬA nội dung đánh giá của
 * khách — chỉ xem/xoá/trả lời (admin_reply). Khách xem được phản hồi qua GET /api/rooms/{id}/ratings
 * (Api\RatingController::index(), field 'admin_reply'/'replied_at').
 */
class RatingController extends Controller
{
    /**
     * GET /api/admin/ratings
     * Query params: ?room_id=&room_slug=&categories=slug1,slug2&star=1-5&has_reply=0|1&search=&per_page=
     * - room_slug: slug của phòng (products.slug) — lọc đúng 1 phòng, thay cho phải biết room_id.
     * - categories: SLUG chi nhánh (categories.slug), chọn nhiều cách nhau bằng dấu phẩy — lọc THÊM
     *   về đúng 1/nhiều chi nhánh cụ thể, vẫn phải nằm trong phạm vi quyền của admin. Slug không
     *   khớp category nào thì bị bỏ qua (không báo lỗi).
     *
     * KHÔNG truyền 'categories': mặc định vẫn tự giới hạn theo phạm vi chi nhánh admin đang đăng
     * nhập được phép xem (User::allowedCategoryIds() — chi nhánh CHA được gán + toàn bộ chi nhánh
     * CON). Super_admin hoặc admin không bị gán quyền chi nhánh cụ thể (allowedCategoryIds() rỗng)
     * thì thấy TẤT CẢ — cùng quy ước với Api\Admin\ChatController::resolveCategoryFilter().
     */
    public function index(Request $request): JsonResponse
    {
        $query = RoomRating::with(['customer:id,fullname,phone', 'room:id,name', 'repliedBy:id,fullname'])
            ->orderByDesc('created_at');

        if ($request->filled('room_id')) {
            $query->where('room_id', $request->input('room_id'));
        }

        if ($request->filled('room_slug')) {
            $query->whereHas('room', fn ($q) => $q->where('slug', $request->string('room_slug')));
        }

        $categoryIds = $this->resolveCategoryFilter($request);
        if ($categoryIds !== null) {
            if (empty($categoryIds)) {
                // Phạm vi quyền/lọc thu hẹp về rỗng → không thấy đánh giá nào, KHÁC với null
                // (không giới hạn gì).
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('room.categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));
            }
        }

        if ($request->filled('star')) {
            $query->where('star', $request->integer('star'));
        }

        if ($request->has('has_reply')) {
            $request->boolean('has_reply')
                ? $query->whereNotNull('admin_reply')
                : $query->whereNull('admin_reply');
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q
                ->where('comment', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('fullname', 'like', "%{$search}%")));
        }

        $ratings = $query->paginate($request->integer('per_page', 20));
        $ratings->getCollection()->transform(fn (RoomRating $r) => $this->toItem($r));

        return response()->json($ratings);
    }

    /**
     * GET /api/admin/ratings/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $rating = RoomRating::with(['customer:id,fullname,phone', 'room:id,name', 'repliedBy:id,fullname'])->find($id);

        if (! $rating || ! $this->userCanAccess($request, $rating)) {
            return response()->json(['message' => 'Không tìm thấy đánh giá.'], 404);
        }

        return response()->json(['data' => $this->toItem($rating)]);
    }

    /**
     * POST /api/admin/ratings/{id}/reply
     * Body: reply (required) — gọi lại nhiều lần sẽ THAY THẾ phản hồi cũ (sửa phản hồi).
     */
    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reply' => 'required|string|max:1000',
        ]);

        $rating = RoomRating::find($id);

        if (! $rating || ! $this->userCanAccess($request, $rating)) {
            return response()->json(['message' => 'Không tìm thấy đánh giá.'], 404);
        }

        $rating->update([
            'admin_reply' => $data['reply'],
            'replied_by'  => $request->user()->id,
            'replied_at'  => now(),
        ]);

        $rating->load(['customer:id,fullname,phone', 'room:id,name', 'repliedBy:id,fullname']);

        return response()->json(['data' => $this->toItem($rating)]);
    }

    /**
     * DELETE /api/admin/ratings/{id}/reply
     * Gỡ phản hồi đã trả lời — không xoá đánh giá của khách, chỉ xoá phần trả lời của admin.
     */
    public function deleteReply(Request $request, int $id): JsonResponse
    {
        $rating = RoomRating::find($id);

        if (! $rating || ! $this->userCanAccess($request, $rating)) {
            return response()->json(['message' => 'Không tìm thấy đánh giá.'], 404);
        }

        $rating->update(['admin_reply' => null, 'replied_by' => null, 'replied_at' => null]);

        return response()->json(['message' => 'Đã gỡ phản hồi.']);
    }

    /**
     * DELETE /api/admin/ratings/{id}
     * Xoá hẳn đánh giá của khách (vd spam, ngôn từ không phù hợp) — tính lại rating_score của
     * phòng sau khi xoá, khớp Api\RatingController::destroy() (luồng khách tự xoá đánh giá của
     * chính mình).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $rating = RoomRating::find($id);

        if (! $rating || ! $this->userCanAccess($request, $rating)) {
            return response()->json(['message' => 'Không tìm thấy đánh giá.'], 404);
        }

        $roomId = $rating->room_id;
        $rating->delete();

        $this->recalcRatingScore($roomId);

        return response()->json(['message' => 'Đã xoá đánh giá.']);
    }

    /**
     * Tính danh sách category_id cuối cùng dùng để lọc index() — null nghĩa là "không lọc gì cả"
     * (khác [] = "lọc về không có gì"). Cùng quy ước với Api\Admin\ChatController::resolveCategoryFilter().
     *
     * @return array<int>|null
     */
    private function resolveCategoryFilter(Request $request): ?array
    {
        $requested = null;

        if ($request->filled('categories')) {
            $slugs = collect(explode(',', (string) $request->query('categories')))
                ->map(fn ($v) => trim($v))
                ->filter()
                ->unique()
                ->values();

            $requested = Category::whereIn('slug', $slugs)->pluck('id')->all();
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return $requested;
        }

        $allowed = $user->allowedCategoryIds();

        if ($requested !== null) {
            return empty($allowed) ? $requested : array_values(array_intersect($requested, $allowed));
        }

        return empty($allowed) ? null : $allowed;
    }

    /**
     * Kiểm tra admin có được phép xem/thao tác 1 đánh giá cụ thể không — dựa trên chi nhánh của
     * phòng được đánh giá. Super_admin hoặc admin không bị gán quyền chi nhánh cụ thể
     * (allowedCategoryIds() rỗng) luôn được phép.
     */
    private function userCanAccess(Request $request, RoomRating $rating): bool
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return true;
        }

        $allowed = $user->allowedCategoryIds();
        if (empty($allowed)) {
            return true;
        }

        $rating->loadMissing('room.categories');
        $roomCategoryIds = $rating->room?->categories->pluck('id')->all() ?? [];

        return (bool) array_intersect($roomCategoryIds, $allowed);
    }

    private function recalcRatingScore(string $roomId): void
    {
        $avg = RoomRating::where('room_id', $roomId)->avg('star');

        Product::where('id', $roomId)->update([
            'rating_score' => $avg !== null ? round((float) $avg, 1) : null,
        ]);
    }

    private function toItem(RoomRating $r): array
    {
        return [
            'id'         => $r->id,
            'star'       => $r->star,
            'comment'    => $r->comment,
            'created_at' => optional($r->created_at)->toISOString(),
            'customer'   => $r->customer ? [
                'id'       => $r->customer->id,
                'fullname' => $r->customer->fullname,
                'phone'    => $r->customer->phone,
            ] : null,
            'room' => $r->room ? [
                'id'   => $r->room->id,
                'name' => $r->room->name,
            ] : null,
            'admin_reply' => $r->admin_reply,
            'replied_at'  => optional($r->replied_at)->toISOString(),
            'replied_by'  => $r->repliedBy ? [
                'id'       => $r->repliedBy->id,
                'fullname' => $r->repliedBy->fullname,
            ] : null,
        ];
    }
}
