<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameSticker;
use App\Models\GameStickerPack;
use App\Services\GameStickerService;
use App\Support\MiniGameKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quản lý bộ sticker chat mini game (chỉ Super Admin) — cùng dữ liệu với Filament "Mini game — Sticker", xử lý ở GameStickerService.
 *
 *  - GET    /api/admin/games/sticker-packs?game=                  danh sách bộ (cả bộ đang tắt)
 *  - GET    /api/admin/games/sticker-packs/{id}                   chi tiết + sticker
 *  - POST   /api/admin/games/sticker-packs                        tạo (multipart): name, key?, games[], is_active, sort_order, icon, stickers[], stickers_zip
 *  - POST   /api/admin/games/sticker-packs/{id}                   sửa (multipart); stickers[] / stickers_zip được thêm vào cuối bộ
 *  - PUT    /api/admin/games/sticker-packs/{id}/stickers/order    xếp lại thứ tự: ids[]
 *  - DELETE /api/admin/games/sticker-packs/{id}/stickers/{sid}    xoá 1 sticker
 *  - DELETE /api/admin/games/sticker-packs/{id}                   xoá cả bộ
 */
class GameStickerAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);
        $data = $request->validate(['game' => 'sometimes|nullable|in:' . implode(',', MiniGameKey::all())]);

        return response()->json(['data' => GameStickerPack::query()
            ->when($data['game'] ?? null, fn ($q, $game) => $q->whereJsonContains('games', $game))
            ->orderBy('sort_order')->orderBy('id')->with('stickers')->get()
            ->map(fn (GameStickerPack $p) => GameStickerService::adminPayload($p, false))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => GameStickerService::adminPayload(GameStickerPack::findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);
        $data = $request->validate($this->rules(true));

        $pack = GameStickerService::save(null, $data, $request->file('stickers', []), $request->file('stickers_zip'), $request->file('icon'));

        return response()->json(['data' => GameStickerService::adminPayload($pack)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $pack = GameStickerPack::findOrFail($id);
        $data = $request->validate($this->rules(false));

        $pack = GameStickerService::save($pack, $data, $request->file('stickers', []), $request->file('stickers_zip'), $request->file('icon'));

        return response()->json(['data' => GameStickerService::adminPayload($pack)]);
    }

    public function reorder(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $pack = GameStickerPack::findOrFail($id);
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);

        GameStickerService::reorder($pack, $data['ids']);

        return response()->json(['data' => GameStickerService::adminPayload($pack->fresh())]);
    }

    public function destroySticker(Request $request, int $id, int $stickerId): JsonResponse
    {
        $this->authorizeSuper($request);
        GameStickerService::deleteSticker(GameSticker::where('pack_id', $id)->findOrFail($stickerId));

        return response()->json(['data' => GameStickerService::adminPayload(GameStickerPack::findOrFail($id))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);
        GameStickerService::delete(GameStickerPack::findOrFail($id));

        return response()->json(['message' => 'Đã xoá bộ sticker.']);
    }

    private function rules(bool $creating): array
    {
        $image = 'file|mimes:png,jpg,jpeg,webp,gif|max:' . GameStickerService::MAX_KB;

        return [
            'name'         => ($creating ? 'required' : 'sometimes') . '|string|max:100',
            'key'          => 'sometimes|nullable|string|max:50|regex:/^[a-z0-9-]+$/',
            'games'        => ($creating ? 'required' : 'sometimes') . '|array|min:1',
            'games.*'      => 'in:' . implode(',', MiniGameKey::all()),
            'is_active'    => 'sometimes|boolean',
            'sort_order'   => 'sometimes|integer|min:-100000|max:100000',
            'icon'         => 'sometimes|' . $image,
            'stickers'     => 'sometimes|array|max:' . GameStickerService::MAX_PER_PACK,
            'stickers.*'   => $image,
            'stickers_zip' => 'sometimes|file|mimes:zip|max:102400',
        ];
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
