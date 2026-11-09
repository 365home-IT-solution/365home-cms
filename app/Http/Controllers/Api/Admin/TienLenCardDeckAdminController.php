<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TienLenCardDeck;
use App\Services\TienLenDeckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quản lý bộ bài Tiến lên (chỉ Super Admin) — cùng dữ liệu với Filament "Tiến lên — Bộ bài", xử lý ở TienLenDeckService.
 *
 *  - GET    /api/admin/games/tien-len/card-decks                 danh sách (cả bộ đang tắt)
 *  - GET    /api/admin/games/tien-len/card-decks/{id}            chi tiết + link ảnh 52 lá
 *  - POST   /api/admin/games/tien-len/card-decks                 tạo (multipart): name, key?, is_active, sort_order, back, preview, cards[], cards_zip
 *  - POST   /api/admin/games/tien-len/card-decks/{id}            sửa (multipart — PHP không đọc file trong PUT); PUT/PATCH dùng được khi không gửi file
 *  - POST   /api/admin/games/tien-len/card-decks/{id}/default    đặt làm bộ mặc định
 *  - DELETE /api/admin/games/tien-len/card-decks/{id}            xoá (không xoá được bộ mặc định)
 */
class TienLenCardDeckAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => TienLenCardDeck::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (TienLenCardDeck $d) => TienLenDeckService::adminPayload($d))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => TienLenDeckService::adminPayload(TienLenCardDeck::findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);
        $data = $request->validate($this->rules(true));

        $deck = TienLenDeckService::save(null, $data, $request->file('cards', []), $request->file('cards_zip'), $request->file('back'), $request->file('preview'));

        return response()->json(['data' => TienLenDeckService::adminPayload($deck)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $deck = TienLenCardDeck::findOrFail($id);
        $data = $request->validate($this->rules(false));

        $deck = TienLenDeckService::save($deck, $data, $request->file('cards', []), $request->file('cards_zip'), $request->file('back'), $request->file('preview'));

        return response()->json(['data' => TienLenDeckService::adminPayload($deck)]);
    }

    public function makeDefault(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $deck = TienLenCardDeck::findOrFail($id);
        TienLenDeckService::makeDefault($deck);

        return response()->json(['data' => TienLenDeckService::adminPayload($deck->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);
        TienLenDeckService::delete(TienLenCardDeck::findOrFail($id));

        return response()->json(['message' => 'Đã xoá bộ bài.']);
    }

    private function rules(bool $creating): array
    {
        $image = 'file|mimes:png,jpg,jpeg,webp|max:10240';

        return [
            'name'       => ($creating ? 'required' : 'sometimes') . '|string|max:100',
            'key'        => 'sometimes|nullable|string|max:50|regex:/^[a-z0-9-]+$/',
            'is_active'  => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:-100000|max:100000',
            'back'       => ($creating ? 'required_without:cards_zip' : 'sometimes') . '|' . $image,
            'preview'    => 'sometimes|' . $image,
            'cards'      => 'sometimes|array|max:52',
            'cards.*'    => $image,
            'cards_zip'  => 'sometimes|file|mimes:zip|max:102400',
        ];
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
