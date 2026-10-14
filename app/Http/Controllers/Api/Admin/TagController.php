<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Danh sách TIỆN ÍCH phòng — dùng bảng `tags` (Spatie\Tags\Tag, field `tags` trên ProductForm, label
 * hiển thị "Tiện ích" — xem Modules/Product/lang/vi/product.php). KHÔNG phải RoomAmenity (module
 * riêng "room_amenities", dùng cho 1 màn quản lý khác - RoomAmenityAssignResource).
 *
 * name/slug là field translatable (spatie/laravel-translatable) nên đọc thẳng $tag->name là ra
 * chuỗi theo locale hiện tại. Riêng `image` KHÔNG được khai báo translatable trên model Tag của
 * package, nhưng TagForm (Filament) vẫn cố tình lưu dạng JSON {"vi": "path"} giống name/slug (xem
 * TagForm::imagesCard dehydrateStateUsing) — nên phải tự giải mã ở đây thay vì đọc thẳng.
 *
 * Phạm vi theo đối tác (tags.partner_id): NULL = tiện ích MẪU hệ thống (mọi đối tác chọn được, chỉ
 * super_admin sửa/xoá); có partner_id = tiện ích đối tác tự thêm, chỉ đối tác đó thấy/gán/sửa/xoá.
 * Item trả về có is_system + editable để FE ẩn/hiện nút sửa/xoá.
 */
class TagController extends Controller
{
    // Map giá trị ?type= thân thiện (product/post) sang morph class thật lưu ở taggables.taggable_type.
    private const IMAGE_RULE = 'file|mimes:jpg,jpeg,png,webp,avif,svg|max:2048';

    private const TYPE_MORPH_MAP = [
        'product' => \Modules\Product\App\Models\Product::class,
        'post'    => \Modules\Post\Entities\Post::class,
    ];

    /**
     * GET /api/admin/tags
     * Query params:
     *  - search : lọc theo tên
     *  - type   : 'product' (tiện ích dành cho phòng) hoặc 'post' (thẻ dành cho bài viết) — bảng
     *             `tags` dùng CHUNG cho cả Post lẫn Product nên cần lọc mới biết tag nào thuộc bên nào.
     *             Xét theo 2 nguồn: (1) cột tags.type nếu có set đúng giá trị 'product'/'post' —
     *             đây là quy ước gốc của package spatie/laravel-tags nhưng TagForm hiện KHÔNG có
     *             field này nên trong dữ liệu thực tế cột này luôn rỗng; (2) tag ĐÃ được gắn thực
     *             tế vào ít nhất 1 bản ghi Post/Product (bảng taggables.taggable_type) — cùng cách
     *             Modules\Tag\...\TagTable dùng để hiển thị "Bài viết"/"Phòng sử dụng" ở trang
     *             admin. Với dữ liệu hiện tại, (2) là tín hiệu DUY NHẤT thực sự hoạt động; giữ thêm
     *             (1) để không vỡ nếu sau này TagForm được bổ sung field type.
     *  - scope  : system (chỉ mẫu hệ thống) | mine (chỉ tiện ích riêng) — bỏ trống = cả 2.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $request->user();
        $query = $this->visibleQuery($user);

        if ($request->input('scope') === 'system') {
            $query->whereNull('partner_id');
        } elseif ($request->input('scope') === 'mine') {
            $query->whereNotNull('partner_id');
        }

        if ($request->filled('search')) {
            $search = (string) $request->string('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('type')) {
            $type = (string) $request->string('type');

            if (! array_key_exists($type, self::TYPE_MORPH_MAP)) {
                return response()->json([
                    'message' => 'type không hợp lệ. Chỉ nhận: ' . implode(', ', array_keys(self::TYPE_MORPH_MAP)) . '.',
                ], 422);
            }

            $morphClass = self::TYPE_MORPH_MAP[$type];

            $query->where(function ($q) use ($type, $morphClass) {
                $q->where('type', $type)
                    ->orWhereExists(function ($sub) use ($morphClass) {
                        $sub->selectRaw('1')
                            ->from('taggables')
                            ->whereColumn('taggables.tag_id', 'tags.id')
                            ->where('taggables.taggable_type', $morphClass);
                    });
            });
        }

        $tags = $query->orderBy('order_column')->orderBy('id')->get();

        $data = $tags->map(fn (Tag $tag) => $this->toItem($tag, $user))->values();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/admin/tags (multipart/form-data nếu gửi kèm ảnh)
     * Body: name (required), image (file, tuỳ chọn). Tạo tiện ích phòng (type=product) thuộc đối
     * tác của người gọi; super_admin không chọn đối tác → tạo tiện ích mẫu hệ thống. Trùng tên với
     * tiện ích đã dùng được (mẫu hệ thống hoặc của chính đối tác) → 422 kèm tiện ích đó (existing).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'image' => 'nullable|' . self::IMAGE_RULE,
        ]);

        $partnerId = $user->seesAllPartners() ? null : $user->partner_id;
        $name      = trim($data['name']);

        if ($existing = $this->findByName($name, $partnerId)) {
            return response()->json([
                'message'  => 'Tiện ích này đã có sẵn — chọn tiện ích có sẵn thay vì tạo mới.',
                'existing' => $this->toItem($existing, $user),
            ], 422);
        }

        $attributes = ['name' => $name, 'type' => 'product', 'partner_id' => $partnerId];
        if ($request->hasFile('image')) {
            $attributes['image'] = $request->file('image')->store('Amenity', 'public');
        }

        $tag = Tag::create($attributes);

        return response()->json(['data' => $this->toItem($tag->fresh(), $user)], 201);
    }

    /**
     * PUT|POST /api/admin/tags/{id} (POST khi cần gửi kèm ảnh)
     * Body: name, image (file), remove_image=1. Tiện ích mẫu hệ thống → 403 với đối tác.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tag  = $this->visibleQuery($user)->find($id);

        if (! $tag) {
            return response()->json(['message' => 'Không tìm thấy tiện ích.'], 404);
        }

        if (! $this->canEdit($tag, $user)) {
            return response()->json(['message' => 'Tiện ích mẫu của hệ thống — không thể sửa. Hãy thêm tiện ích riêng.'], 403);
        }

        $data = $request->validate([
            'name'         => 'sometimes|required|string|max:255',
            'image'        => 'nullable|' . self::IMAGE_RULE,
            'remove_image' => 'nullable|boolean',
        ]);

        if (isset($data['name'])) {
            $name     = trim($data['name']);
            $existing = $this->findByName($name, $tag->partner_id);

            if ($existing && $existing->id !== $tag->id) {
                return response()->json(['message' => 'Đã có tiện ích khác cùng tên.'], 422);
            }

            $tag->name = $name;
        }

        if ($request->hasFile('image')) {
            $tag->image = $request->file('image')->store('Amenity', 'public');
        } elseif (! empty($data['remove_image'])) {
            $tag->image = null;
        }

        $tag->save();

        return response()->json(['data' => $this->toItem($tag->fresh(), $user)]);
    }

    /**
     * DELETE /api/admin/tags/{id}
     * Gỡ luôn tiện ích khỏi mọi phòng đang gắn. Tiện ích mẫu hệ thống → 403 với đối tác.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tag  = $this->visibleQuery($user)->find($id);

        if (! $tag) {
            return response()->json(['message' => 'Không tìm thấy tiện ích.'], 404);
        }

        if (! $this->canEdit($tag, $user)) {
            return response()->json(['message' => 'Tiện ích mẫu của hệ thống — không thể xoá.'], 403);
        }

        DB::transaction(function () use ($tag) {
            DB::table('taggables')->where('tag_id', $tag->id)->delete();
            $tag->delete();
        });

        return response()->json(['message' => 'Đã xoá tiện ích.']);
    }

    private function visibleQuery(User $user)
    {
        return $user->seesAllPartners() ? Tag::query() : Tag::usableBy($user->partner_id);
    }

    private function canEdit(Tag $tag, User $user): bool
    {
        if ($user->seesAllPartners()) {
            return true;
        }

        return $tag->partner_id !== null && $tag->partner_id === $user->partner_id;
    }

    private function findByName(string $name, ?string $partnerId): ?Tag
    {
        return Tag::usableBy($partnerId)
            ->where('name->' . app()->getLocale(), $name)
            ->first();
    }

    private function toItem(Tag $tag, User $user): array
    {
        $image = $this->extractImage($tag);

        return [
            'id'         => $tag->id,
            'name'       => $tag->name,
            'type'       => $tag->type,
            'image'      => $image,
            'image_url'  => $image ? Storage::disk('public')->url($image) : null,
            'partner_id' => $tag->partner_id,
            'is_system'  => $tag->isSystem(),
            'editable'   => $this->canEdit($tag, $user),
        ];
    }

    private function extractImage(Tag $tag): ?string
    {
        $raw = $tag->getRawOriginal('image');

        if (empty($raw)) {
            return null;
        }

        if (is_string($raw) && str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return $decoded[app()->getLocale()] ?? collect($decoded)->first();
            }
        }

        return $raw;
    }
}
