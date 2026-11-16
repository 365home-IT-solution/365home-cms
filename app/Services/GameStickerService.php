<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GameSticker;
use App\Models\GameStickerPack;
use App\Support\MiniGameKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bộ sticker trong chat mini game — Filament "Mini game — Sticker" và /api/admin/games/sticker-packs dùng chung service này.
 *
 *  - Bộ sticker gắn với 1 hoặc nhiều game (games), khách chỉ thấy bộ đang bật của game đang chơi.
 *  - Ảnh lưu nguyên file (giữ được GIF / WebP động) ở storage/app/public/games/stickers/{key}/, tối đa 1 MB / ảnh.
 *  - Gửi sticker trong trận: app gửi sticker_id, server kiểm tra sticker thuộc bộ đang bật của game đó (GameChatService).
 */
class GameStickerService
{
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public const MAX_KB = 1024;

    public const MAX_PER_PACK = 200;

    // =========================================================
    // KHÁCH
    // =========================================================

    /** Các bộ đang bật của game + sticker trong từng bộ. */
    public static function catalog(string $game): array
    {
        $packs = GameStickerPack::where('is_active', true)->whereJsonContains('games', $game)
            ->orderBy('sort_order')->orderBy('id')->with('stickers')->get();

        return [
            // Đổi bất kỳ bộ nào ⇒ version đổi, app tải lại danh sách
            'version' => (int) $packs->max(fn (GameStickerPack $p) => max($p->updated_at?->timestamp ?? 0, (int) $p->stickers->max(fn ($s) => $s->updated_at?->timestamp))),
            'packs'   => $packs->filter(fn (GameStickerPack $p) => $p->stickers->isNotEmpty())->values()->map(fn (GameStickerPack $p) => [
                'key'      => $p->key,
                'name'     => $p->name,
                'icon_url' => self::url($p->icon_image) ?? $p->stickers->first()?->url(),
                'stickers' => $p->stickers->map(fn (GameSticker $s) => ['id' => $s->id, 'url' => $s->url()])->values()->all(),
            ])->all(),
        ];
    }

    // Sticker dùng được trong game (bộ đang bật + có game này), không có ⇒ null.
    public static function usable(int $stickerId, string $game): ?GameSticker
    {
        $sticker = GameSticker::with('pack')->find($stickerId);

        return $sticker && $sticker->pack?->is_active && $sticker->pack->supports($game) ? $sticker : null;
    }

    // =========================================================
    // QUẢN TRỊ (Filament + API admin)
    // =========================================================

    public static function adminPayload(GameStickerPack $p, bool $withStickers = true): array
    {
        $p->loadMissing('stickers');

        return [
            'id'             => $p->id,
            'key'            => $p->key,
            'name'           => $p->name,
            'games'          => array_values($p->games ?? []),
            'is_active'      => $p->is_active,
            'sort_order'     => $p->sort_order,
            'icon_url'       => self::url($p->icon_image),
            'sticker_count'  => $p->stickers->count(),
            'created_at'     => $p->created_at?->toDateTimeString(),
            'updated_at'     => $p->updated_at?->toDateTimeString(),
        ] + ($withStickers ? [
            'stickers' => $p->stickers->map(fn (GameSticker $s) => ['id' => $s->id, 'url' => $s->url(), 'sort_order' => $s->sort_order])->values()->all(),
        ] : []);
    }

    /**
     * Tạo / sửa bộ sticker. $data: key (chỉ khi tạo), name, games, is_active, sort_order.
     *
     * @param  UploadedFile[]  $stickers  ảnh sticker thêm vào cuối bộ
     * @param  ?UploadedFile  $zip  file .zip chứa ảnh sticker (thứ tự theo tên file)
     */
    public static function save(?GameStickerPack $pack, array $data, array $stickers = [], ?UploadedFile $zip = null, ?UploadedFile $icon = null,
        string $errorPrefix = ''): GameStickerPack
    {
        $creating = $pack === null;
        $pack   ??= new GameStickerPack();

        if ($creating) {
            $key = Str::slug((string) (($data['key'] ?? null) ?: ($data['name'] ?? '')));
            if ($key === '' || GameStickerPack::where('key', $key)->exists()) {
                throw ValidationException::withMessages([$errorPrefix . 'key' => $key === '' ? 'Mã bộ sticker không hợp lệ.' : 'Mã bộ sticker đã tồn tại.']);
            }
            $pack->key = $key;
        }

        if (array_key_exists('games', $data) || $creating) {
            $games = array_values(array_intersect(MiniGameKey::all(), (array) ($data['games'] ?? [])));
            if ($games === []) {
                throw ValidationException::withMessages([$errorPrefix . 'games' => 'Chọn ít nhất 1 game dùng bộ sticker này.']);
            }
            $pack->games = $games;
        }

        $files = array_map(fn (UploadedFile $f) => [$f->getRealPath(), $f->getClientOriginalName(), (int) $f->getSize()], $stickers);

        $tmpDir = null;
        try {
            if ($zip) {
                [$tmpDir, $zipFiles] = self::extractZip($zip, $errorPrefix . 'stickers_zip');
                $files = array_merge($files, $zipFiles);
            }

            foreach ($files as [$path, $name, $size]) {
                self::assertImage($name, $size, $errorPrefix . ($zip ? 'stickers_zip' : 'stickers'));
            }
            if ($icon) {
                self::assertImage($icon->getClientOriginalName(), (int) $icon->getSize(), $errorPrefix . 'icon');
            }

            $existing = $creating ? 0 : $pack->stickers()->count();
            if ($existing + count($files) > self::MAX_PER_PACK) {
                throw ValidationException::withMessages([$errorPrefix . 'stickers' => 'Mỗi bộ tối đa ' . self::MAX_PER_PACK . ' sticker.']);
            }

            DB::transaction(function () use ($pack, $data, $files, $icon) {
                $pack->fill([
                    'name'       => $data['name'] ?? $pack->name,
                    'sort_order' => (int) ($data['sort_order'] ?? $pack->sort_order ?? 0),
                    'is_active'  => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($pack->exists ? $pack->is_active : true),
                ]);

                if ($icon) {
                    self::deleteFile($pack->icon_image);
                    $pack->icon_image = self::store($icon->getRealPath(), $icon->getClientOriginalName(), $pack->key, 'icon');
                }
                $pack->save();

                $order = (int) $pack->stickers()->max('sort_order');
                foreach ($files as [$path, $name]) {
                    $pack->stickers()->create(['image' => self::store($path, $name, $pack->key, 's'), 'sort_order' => ++$order]);
                }
                $pack->touch();
            });
        } finally {
            if ($tmpDir) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($tmpDir);
            }
        }

        return $pack->refresh()->load('stickers');
    }

    /** @param int[] $ids thứ tự mới; sticker không có trong danh sách giữ nguyên, xếp sau. */
    public static function reorder(GameStickerPack $pack, array $ids): void
    {
        DB::transaction(function () use ($pack, $ids) {
            $order = 0;
            foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
                GameSticker::where('pack_id', $pack->id)->whereKey($id)->update(['sort_order' => ++$order]);
            }
            GameSticker::where('pack_id', $pack->id)->whereNotIn('id', $ids ?: [0])->orderBy('sort_order')->orderBy('id')->get()
                ->each(fn (GameSticker $s) => $s->update(['sort_order' => ++$order]));
            $pack->touch();
        });
    }

    public static function deleteSticker(GameSticker $sticker): void
    {
        self::deleteFile($sticker->image);
        $sticker->delete();
        $sticker->pack?->touch();
    }

    public static function delete(GameStickerPack $pack): void
    {
        $pack->delete();
        Storage::disk('public')->deleteDirectory("games/stickers/{$pack->key}");
    }

    public static function url(?string $path): ?string
    {
        return $path ? asset($path) : null;
    }

    private static function assertImage(string $name, int $size, string $errorKey): void
    {
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            throw ValidationException::withMessages([$errorKey => "File {$name}: chỉ nhận PNG, JPG, WebP, GIF."]);
        }
        if ($size > self::MAX_KB * 1024) {
            throw ValidationException::withMessages([$errorKey => "File {$name} vượt quá 1 MB."]);
        }
    }

    /** @return array{0: string, 1: array<int, array{0: string, 1: string, 2: int}>} */
    private static function extractZip(UploadedFile $zip, string $errorKey): array
    {
        $za = new \ZipArchive();
        if ($za->open($zip->getRealPath()) !== true) {
            throw ValidationException::withMessages([$errorKey => 'File .zip không mở được.']);
        }

        $dir   = storage_path('app/tmp/game-stickers-' . Str::random(12));
        @mkdir($dir, 0775, true);
        $files = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i);
            $file  = basename($entry);
            $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (str_ends_with($entry, '/') || str_starts_with($file, '.') || str_contains($entry, '__MACOSX') || ! in_array($ext, self::EXTENSIONS, true)) {
                continue;
            }

            $target = $dir . '/' . $i . '.' . $ext;
            file_put_contents($target, $za->getFromIndex($i));
            $files[$file] = [$target, $file, (int) filesize($target)];
        }
        $za->close();

        ksort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return [$dir, array_values($files)];
    }

    private static function store(string $src, string $originalName, string $key, string $prefix): string
    {
        $ext      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $diskPath = "games/stickers/{$key}/{$prefix}-" . Str::lower(Str::random(10)) . ".{$ext}";
        Storage::disk('public')->put($diskPath, (string) file_get_contents($src));

        return 'storage/' . $diskPath;
    }

    private static function deleteFile(?string $path): void
    {
        if ($path && str_starts_with($path, 'storage/')) {
            Storage::disk('public')->delete(substr($path, strlen('storage/')));
        }
    }
}
