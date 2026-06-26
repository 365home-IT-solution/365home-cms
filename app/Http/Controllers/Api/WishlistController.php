<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\App\Models\Product;

class WishlistController extends Controller
{
    use BuildsRoomCard;

    public function index(Request $request): JsonResponse
    {
        $rooms = $request->user()
            ->wishlists()
            ->with(['product.roomTimeSlots.timeSlot', 'product.mainImage', 'product.roomType'])
            ->get()
            ->pluck('product')
            ->filter()
            ->values()
            ->map(fn ($room) => $this->mapRoom($room, true));

        return response()->json(['data' => $rooms]);
    }

    public function toggle(Request $request, string $id): JsonResponse
    {
        $product = Product::where('id', $id)->where('is_activated', true)->firstOrFail();

        $user   = $request->user();
        $exists = $user->wishlists()->where('product_id', $product->id)->exists();

        if ($exists) {
            $user->wishlists()->where('product_id', $product->id)->delete();
            $status = false;
        } else {
            $user->wishlists()->create(['product_id' => $product->id]);
            $status = true;
        }

        return response()->json([
            'customer_id'      => $user->id,
            'room_id'          => $product->id,
            'wishlist_status'  => $status,
            'message'          => $status
                ? 'Đã thêm vào danh sách yêu thích'
                : 'Đã xoá khỏi danh sách yêu thích',
        ]);
    }
}
