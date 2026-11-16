<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameSoundPack;
use App\Services\GameSoundPackService;
use App\Support\MiniGameKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Quản lý bộ âm thanh mini game (chỉ Super Admin) — cùng dữ liệu với Filament "Mini game — Âm thanh", xử lý ở GameSoundPackService.
 *
 *  - GET    /api/admin/games/sound-packs?game=tien_len|caro   danh sách (cả bộ đang tắt)
 *  - GET    /api/admin/games/sound-packs/events               danh sách sự kiện âm thanh của từng game
 *  - GET    /api/admin/games/sound-packs/{id}                 chi tiết
 *  - POST   /api/admin/games/sound-packs                      tạo (multipart): game, name, key?, is_active, sort_order, cover, sounds[{sự kiện}], sounds_zip
 *  - POST   /api/admin/games/sound-packs/{id}                 sửa (multipart); thêm remove_sounds[] để xoá file của sự kiện
 *  - POST   /api/admin/games/sound-packs/{id}/default         đặt làm bộ mặc định của game
 *  - DELETE /api/admin/games/sound-packs/{id}                 xoá (không xoá được bộ mặc định)
 */
class GameSoundPackAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);
        $data = $request->validate(['game' => 'sometimes|nullable|in:' . implode(',', MiniGameKey::all())]);

        return response()->json(['data' => GameSoundPack::query()
            ->when($data['game'] ?? null, fn ($q, $game) => $q->where('game', $game))
            ->orderBy('game')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (GameSoundPack $p) => GameSoundPackService::adminPayload($p))->values()]);
    }

    public function events(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => collect(MiniGameKey::all())->mapWithKeys(fn (string $game) => [
            $game => collect(GameSoundPackService::events($game))->map(fn (array $e, string $key) => [
                'key'    => $key,
                'label'  => $e['label'],
                'loop'   => $e['loop'] ?? false,
                'max_kb' => GameSoundPackService::isMusic($key) ? GameSoundPackService::MAX_MUSIC_KB : GameSoundPackService::MAX_KB,
            ])->values(),
        ]), 'extensions' => ['mp3', 'm4a', 'aac', 'ogg', 'wav']]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => GameSoundPackService::adminPayload(GameSoundPack::findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);
        $data = $request->validate($this->rules(true));

        $pack = GameSoundPackService::save(null, $data, $this->soundFiles($request), $request->file('sounds_zip'), $request->file('cover'));

        return response()->json(['data' => GameSoundPackService::adminPayload($pack)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $pack = GameSoundPack::findOrFail($id);
        $data = $request->validate($this->rules(false));

        $pack = GameSoundPackService::save($pack, $data, $this->soundFiles($request), $request->file('sounds_zip'), $request->file('cover'),
            $data['remove_sounds'] ?? []);

        return response()->json(['data' => GameSoundPackService::adminPayload($pack)]);
    }

    public function makeDefault(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $pack = GameSoundPack::findOrFail($id);
        GameSoundPackService::makeDefault($pack);

        return response()->json(['data' => GameSoundPackService::adminPayload($pack->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);
        GameSoundPackService::delete(GameSoundPack::findOrFail($id));

        return response()->json(['message' => 'Đã xoá bộ âm thanh.']);
    }

    /** @return array<string, UploadedFile> */
    private function soundFiles(Request $request): array
    {
        return array_filter((array) $request->file('sounds', []), fn ($f) => $f instanceof UploadedFile);
    }

    private function rules(bool $creating): array
    {
        return [
            'game'            => ($creating ? 'required' : 'prohibited') . '|in:' . implode(',', MiniGameKey::all()),
            'name'            => ($creating ? 'required' : 'sometimes') . '|string|max:100',
            'key'             => 'sometimes|nullable|string|max:50|regex:/^[a-z0-9-]+$/',
            'is_active'       => 'sometimes|boolean',
            'sort_order'      => 'sometimes|integer|min:-100000|max:100000',
            'cover'           => 'sometimes|file|mimes:png,jpg,jpeg,webp|max:5120',
            'sounds'          => 'sometimes|array',
            'sounds.*'        => 'file|max:' . GameSoundPackService::MAX_MUSIC_KB,
            'sounds_zip'      => 'sometimes|file|mimes:zip|max:102400',
            'remove_sounds'   => 'sometimes|array',
            'remove_sounds.*' => 'string|max:50',
        ];
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
