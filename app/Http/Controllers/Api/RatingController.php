<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\RoomRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Product\App\Models\Product;

class RatingController extends Controller
{
    public function index(string $roomId): JsonResponse
    {
        $room = $this->findRoom($roomId);

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $ratings = RoomRating::where('room_id', $roomId)
            ->with(['customer:id,fullname', 'media'])
            ->latest()
            ->paginate(10);

        $distribution = RoomRating::where('room_id', $roomId)
            ->selectRaw('star, COUNT(*) as count')
            ->groupBy('star')
            ->pluck('count', 'star');

        $summary = [
            'average'      => $room->rating_score !== null ? (float) $room->rating_score : null,
            'total_count'  => $ratings->total(),
            'distribution' => [
                5 => (int) ($distribution[5] ?? 0),
                4 => (int) ($distribution[4] ?? 0),
                3 => (int) ($distribution[3] ?? 0),
                2 => (int) ($distribution[2] ?? 0),
                1 => (int) ($distribution[1] ?? 0),
            ],
        ];

        $user = auth('sanctum')->user();
        $myRating = $user
            ? RoomRating::with('media')->where('customer_id', $user->id)->where('room_id', $roomId)->first()
            : null;

        return response()->json([
            'summary' => $summary,
            'my_rating' => $myRating ? [
                'id'      => $myRating->id,
                'star'    => $myRating->star,
                'comment' => $myRating->comment,
                'images'  => $myRating->imagesPayload(),
            ] : null,
            'data'    => $ratings->getCollection()->map(fn ($r) => [
                'id'          => $r->id,
                'user_name'   => $r->customer?->fullname ?? 'Ẩn danh',
                'star'        => $r->star,
                'comment'     => $r->comment,
                'images'      => $r->imagesPayload(),
                'admin_reply' => $r->admin_reply,
                'replied_at'  => $r->replied_at?->toISOString(),
                'created_at'  => $r->created_at?->toISOString(),
            ]),
            'meta' => [
                'current_page' => $ratings->currentPage(),
                'last_page'    => $ratings->lastPage(),
                'per_page'     => $ratings->perPage(),
                'total'        => $ratings->total(),
            ],
        ]);
    }

    public function store(Request $request, string $roomId): JsonResponse
    {
        $room = $this->findRoom($roomId);

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        // Ảnh gửi dạng multipart: images[] (file) để thêm, remove_image_ids[] (id ảnh đã có) để gỡ.
        $data = $request->validate([
            'star'               => ['required', 'integer', 'min:1', 'max:5'],
            'comment'            => ['nullable', 'string', 'max:1000'],
            'images'             => ['nullable', 'array', 'max:' . RoomRating::MAX_IMAGES],
            'images.*'           => ['image', 'mimes:' . implode(',', RoomRating::IMAGE_MIMES), 'max:' . RoomRating::MAX_IMAGE_KB],
            'remove_image_ids'   => ['nullable', 'array'],
            'remove_image_ids.*' => ['integer'],
        ]);

        $user = auth('sanctum')->user();

        $current = RoomRating::with('media')
            ->where('customer_id', $user->id)
            ->where('room_id', $roomId)
            ->first();

        $existed = $current !== null;

        // Chỉ được gỡ ảnh thuộc đánh giá CỦA CHÍNH khách này; id lạ bị bỏ qua.
        $removeIds = $current
            ? $current->getMedia(RoomRating::IMAGE_COLLECTION)
                ->pluck('id')
                ->intersect($data['remove_image_ids'] ?? [])
                ->values()
            : collect();

        $keptCount = ($current?->getMedia(RoomRating::IMAGE_COLLECTION)->count() ?? 0) - $removeIds->count();
        $newFiles  = $request->file('images', []);

        if ($keptCount + count($newFiles) > RoomRating::MAX_IMAGES) {
            return response()->json([
                'message' => 'Mỗi đánh giá tối đa ' . RoomRating::MAX_IMAGES . ' ảnh.',
                'errors'  => ['images' => ['Mỗi đánh giá tối đa ' . RoomRating::MAX_IMAGES . ' ảnh (hiện có ' . $keptCount . ' ảnh giữ lại).']],
            ], 422);
        }

        $rating = RoomRating::updateOrCreate(
            ['customer_id' => $user->id, 'room_id' => $roomId],
            // Có gửi 'comment' mới ghi đè — gửi lại chỉ để thêm/gỡ ảnh thì giữ nguyên nhận xét cũ.
            ['star' => $data['star']] + (array_key_exists('comment', $data) ? ['comment' => $data['comment']] : []),
        );

        foreach ($removeIds as $mediaId) {
            $rating->deleteMedia($mediaId);
        }

        foreach ($newFiles as $file) {
            $rating->addMedia($file)->toMediaCollection(RoomRating::IMAGE_COLLECTION);
        }

        $rating->load('media');

        $this->recalcRatingScore($roomId);

        $room->refresh();

        $status = $existed ? 200 : 201;

        return response()->json([
            'rating' => [
                'id'         => $rating->id,
                'star'       => $rating->star,
                'comment'    => $rating->comment,
                'images'     => $rating->imagesPayload(),
                'created_at' => $rating->created_at?->toISOString(),
            ],
            'room_rating_score' => $room->rating_score !== null ? (float) $room->rating_score : null,
        ], $status);
    }

    public function destroy(string $roomId): JsonResponse
    {
        $room = $this->findRoom($roomId);

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $user = auth('sanctum')->user();

        $rating = RoomRating::where('customer_id', $user->id)
            ->where('room_id', $roomId)
            ->first();

        if (! $rating) {
            return response()->json(['message' => 'Bạn chưa đánh giá phòng này.'], 404);
        }

        $rating->delete();

        $this->recalcRatingScore($roomId);

        return response()->json(['message' => 'Đã xoá đánh giá.']);
    }

    protected function recalcRatingScore(string $roomId): void
    {
        $avg = RoomRating::where('room_id', $roomId)->avg('star');

        // withoutGlobalScopes(): điểm của phòng MiniHouse cũng phải được cập nhật (Product có scope loại MiniHouse).
        Product::withoutGlobalScopes()->where('id', $roomId)->update([
            'rating_score' => $avg !== null ? round((float) $avg, 1) : null,
        ]);
    }

    // Phòng được phép đánh giá — lớp con (MiniHouse) ghi đè để tra đúng tập phòng của mình.
    protected function findRoom(string $roomId): ?Product
    {
        return Product::where('id', $roomId)->where('is_activated', true)->first();
    }
}
