<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GamePlayerPreference;
use App\Models\GameSoundPack;
use App\Support\MiniGameKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bộ âm thanh mini game (Tiến lên, Caro) — Filament "Mini game — Âm thanh" và /api/admin/games/sound-packs dùng chung service này.
 *
 *  - Mỗi game có danh sách sự kiện cố định (EVENTS). Bộ âm thanh gán 1 file cho từng sự kiện; app tự phát khi gặp sự kiện tương ứng.
 *  - Khách chọn bộ (chỉ bộ đang bật), chưa chọn / bộ bị tắt hoặc xoá ⇒ bộ mặc định của game.
 *  - Sự kiện bộ đang dùng chưa có file ⇒ lấy file của bộ mặc định; vẫn không có ⇒ url = null (app dùng âm thanh có sẵn trong app).
 *  - File lưu nguyên định dạng (mp3, m4a, aac, ogg, wav) ở storage/app/public/games/sounds/{game}/{key}/, tên kèm chuỗi ngẫu nhiên
 *    để app không dính cache file cũ. version = thời điểm sửa cuối, app so để biết khi nào cần tải lại.
 */
class GameSoundPackService
{
    public const EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav'];

    /** Dung lượng tối đa mỗi file (KB) — nhạc nền lớn hơn hiệu ứng. */
    public const MAX_KB       = 2048;
    public const MAX_MUSIC_KB = 10240;

    /**
     * game => sự kiện => mô tả. Hiện CHỈ quản lý nhạc nền (user chốt 2026-11-16) — hiệu ứng (đánh bài, đặt quân…) app tự đóng gói.
     * Muốn cho admin đổi thêm hiệu ứng thì thêm sự kiện vào đây, API / CMS tự hiện theo.
     *
     * @var array<string, array<string, array{label: string, loop?: bool}>>
     */
    public const EVENTS = [
        MiniGameKey::TIEN_LEN => [
            'bgm' => ['label' => 'Nhạc nền', 'loop' => true],
        ],
        MiniGameKey::CARO => [
            'bgm' => ['label' => 'Nhạc nền', 'loop' => true],
        ],
    ];

    // =========================================================
    // ĐỌC
    // =========================================================

    /** @return array<string, array{label: string, loop?: bool}> */
    public static function events(string $game): array
    {
        return self::EVENTS[$game] ?? [];
    }

    public static function isMusic(string $event): bool
    {
        return $event === 'bgm';
    }

    /** @return Collection<string, GameSoundPack> bộ đang bật của game, theo key */
    public static function active(string $game): Collection
    {
        return GameSoundPack::where('game', $game)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->keyBy('key');
    }

    public static function defaultPack(string $game): ?GameSoundPack
    {
        return GameSoundPack::where('game', $game)->where('is_active', true)->where('is_default', true)->first();
    }

    // Bộ khách đang dùng: đã chọn và còn bật ⇒ bộ đó, còn lại ⇒ bộ mặc định (có thể null khi game chưa có bộ nào).
    public static function selectedFor(string $game, string $customerId): ?GameSoundPack
    {
        $key = GamePlayerPreference::where('customer_id', $customerId)->where('game', $game)->value('sound_pack');

        return ($key ? self::active($game)->get($key) : null) ?? self::defaultPack($game);
    }

    /** @return array{sfx_enabled: bool, music_enabled: bool, sfx_volume: int, music_volume: int} */
    public static function settingsFor(string $game, string $customerId): array
    {
        $pref = GamePlayerPreference::where('customer_id', $customerId)->where('game', $game)->first();

        return $pref ? $pref->only(array_keys(GamePlayerPreference::DEFAULTS)) : GamePlayerPreference::DEFAULTS;
    }

    // Link từng sự kiện của bộ (thiếu ⇒ bộ mặc định ⇒ null).
    public static function soundUrls(GameSoundPack $pack, ?GameSoundPack $default = null): array
    {
        $default ??= self::defaultPack($pack->game);

        return collect(self::events($pack->game))->mapWithKeys(fn (array $_, string $event) => [
            $event => GameSoundPack::url($pack->sounds[$event] ?? $default?->sounds[$event] ?? null),
        ])->all();
    }

    /** Màn chọn bộ âm thanh của khách: danh sách bộ, bộ đang dùng, tuỳ chọn âm lượng, danh sách sự kiện. */
    public static function catalog(string $game, string $customerId): array
    {
        $default  = self::defaultPack($game);
        $selected = self::selectedFor($game, $customerId);

        return [
            'selected' => $selected?->key,
            'settings' => self::settingsFor($game, $customerId),
            'events'   => collect(self::events($game))->map(fn (array $e, string $key) => ['key' => $key, 'label' => $e['label'], 'loop' => $e['loop'] ?? false])->values()->all(),
            'packs'    => self::active($game)->values()->map(fn (GameSoundPack $p) => [
                'key'         => $p->key,
                'name'        => $p->name,
                'cover_url'   => GameSoundPack::url($p->cover_image),
                'is_default'  => $p->is_default,
                'is_selected' => $p->key === $selected?->key,
                'version'     => $p->updated_at?->timestamp,
                'sounds'      => self::soundUrls($p, $default),
            ])->all(),
        ];
    }

    public static function select(string $game, string $customerId, string $key): void
    {
        if (! self::active($game)->has($key)) {
            throw ValidationException::withMessages(['pack' => 'Bộ âm thanh không tồn tại.']);
        }

        self::upsertPreference($game, $customerId, ['sound_pack' => $key]);
    }

    /** @param array{sfx_enabled?: bool, music_enabled?: bool, sfx_volume?: int, music_volume?: int} $settings */
    public static function updateSettings(string $game, string $customerId, array $settings): void
    {
        self::upsertPreference($game, $customerId, array_intersect_key($settings, GamePlayerPreference::DEFAULTS));
    }

    private static function upsertPreference(string $game, string $customerId, array $values): void
    {
        $where = ['customer_id' => $customerId, 'game' => $game];
        $query = GamePlayerPreference::query()->where($where);

        if ($query->exists()) {
            $query->update($values + ['updated_at' => now()]);
        } else {
            GamePlayerPreference::query()->insert($where + $values + GamePlayerPreference::DEFAULTS + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    // =========================================================
    // QUẢN TRỊ (Filament + API admin)
    // =========================================================

    public static function adminPayload(GameSoundPack $p): array
    {
        $sounds = $p->sounds ?? [];

        return [
            'id'            => $p->id,
            'game'          => $p->game,
            'key'           => $p->key,
            'name'          => $p->name,
            'is_active'     => $p->is_active,
            'is_default'    => $p->is_default,
            'sort_order'    => $p->sort_order,
            'cover_url'     => GameSoundPack::url($p->cover_image),
            'sounds'        => collect(self::events($p->game))->mapWithKeys(fn (array $_, string $e) => [$e => GameSoundPack::url($sounds[$e] ?? null)])->all(),
            'missing'       => self::missingEvents($p),
            'players_using' => GamePlayerPreference::where('game', $p->game)->where('sound_pack', $p->key)->count(),
            'version'       => $p->updated_at?->timestamp,
            'created_at'    => $p->created_at?->toDateTimeString(),
            'updated_at'    => $p->updated_at?->toDateTimeString(),
        ];
    }

    /** @return string[] sự kiện chưa có file */
    public static function missingEvents(GameSoundPack $p): array
    {
        return array_values(array_filter(array_keys(self::events($p->game)), fn (string $e) => empty($p->sounds[$e] ?? null)));
    }

    /**
     * Tạo / sửa bộ âm thanh. $data: game + key (chỉ khi tạo), name, is_active, sort_order.
     *
     * @param  array<string, UploadedFile>  $sounds  sự kiện => file (chỉ sự kiện được gửi bị thay)
     * @param  ?UploadedFile  $zip  file .zip, tên từng file là mã sự kiện (deal.mp3, bgm.m4a…)
     * @param  string[]  $remove  sự kiện cần xoá file
     * @param  string  $errorPrefix  tiền tố khoá lỗi validate (Filament dùng "data.")
     */
    public static function save(?GameSoundPack $pack, array $data, array $sounds = [], ?UploadedFile $zip = null, ?UploadedFile $cover = null,
        array $remove = [], string $errorPrefix = ''): GameSoundPack
    {
        $creating = $pack === null;
        $pack   ??= new GameSoundPack();

        if ($creating) {
            $game = (string) ($data['game'] ?? '');
            if (! in_array($game, MiniGameKey::all(), true)) {
                throw ValidationException::withMessages([$errorPrefix . 'game' => 'Game không hợp lệ.']);
            }
            $key = Str::slug((string) (($data['key'] ?? null) ?: ($data['name'] ?? '')));
            if ($key === '' || GameSoundPack::where('game', $game)->where('key', $key)->exists()) {
                throw ValidationException::withMessages([$errorPrefix . 'key' => $key === '' ? 'Mã bộ âm thanh không hợp lệ.' : 'Mã bộ âm thanh đã tồn tại.']);
            }
            $pack->game = $game;
            $pack->key  = $key;
        }

        $events = self::events($pack->game);
        $files  = [];
        foreach ($sounds as $event => $file) {
            if (! isset($events[$event])) {
                throw ValidationException::withMessages([$errorPrefix . 'sounds' => "Sự kiện \"{$event}\" không có trong game này."]);
            }
            $files[$event] = [$file->getRealPath(), $file->getClientOriginalName(), (int) $file->getSize(), $file->getMimeType()];
        }

        $tmpDir = null;
        try {
            if ($zip) {
                [$tmpDir, $zipFiles] = self::extractZip($zip, $events, $errorPrefix . 'sounds_zip');
                $files += $zipFiles;
            }

            foreach ($files as $event => [$path, $name, $size, $mime]) {
                self::assertAudio($event, $name, $size, $mime, $errorPrefix . ($zip && ! isset($sounds[$event]) ? 'sounds_zip' : "sounds.{$event}"));
            }

            if ($cover && ! in_array(strtolower($cover->getClientOriginalExtension()), ['png', 'jpg', 'jpeg', 'webp'], true)) {
                throw ValidationException::withMessages([$errorPrefix . 'cover' => 'Ảnh bìa chỉ nhận PNG, JPG, WebP.']);
            }

            $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($creating ? true : $pack->is_active);
            if (! $creating && $pack->is_default && ! $isActive) {
                throw ValidationException::withMessages([$errorPrefix . 'is_active' => 'Không thể tắt bộ âm thanh mặc định. Đặt bộ khác làm mặc định trước.']);
            }

            DB::transaction(function () use ($pack, $data, $files, $cover, $remove, $isActive, $creating) {
                $pack->fill([
                    'name'       => $data['name'] ?? $pack->name,
                    'sort_order' => (int) ($data['sort_order'] ?? $pack->sort_order ?? 0),
                    'is_active'  => $isActive,
                ]);

                $map = $pack->sounds ?? [];
                foreach (array_diff($remove, array_keys($files)) as $event) {
                    self::deleteFile($map[$event] ?? null);
                    unset($map[$event]);
                }
                foreach ($files as $event => [$path, $name]) {
                    self::deleteFile($map[$event] ?? null);
                    $map[$event] = self::storeFile($path, $name, $pack, $event);
                }
                $pack->sounds = $map;

                if ($cover) {
                    self::deleteFile($pack->cover_image);
                    $pack->cover_image = self::storeCover($cover, $pack);
                }

                // Bộ đầu tiên của game tự thành bộ mặc định
                if ($creating && $isActive && ! GameSoundPack::where('game', $pack->game)->where('is_default', true)->exists()) {
                    $pack->is_default = true;
                }

                $pack->save();
                $pack->touch(); // đổi file mà các cột khác giữ nguyên vẫn tăng version
            });
        } finally {
            if ($tmpDir) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($tmpDir);
            }
        }

        return $pack->refresh();
    }

    public static function setActive(GameSoundPack $pack, bool $active, string $errorKey = 'is_active'): void
    {
        if (! $active && $pack->is_default) {
            throw ValidationException::withMessages([$errorKey => 'Không thể tắt bộ âm thanh mặc định. Đặt bộ khác làm mặc định trước.']);
        }

        $pack->update(['is_active' => $active]);
    }

    public static function makeDefault(GameSoundPack $pack): void
    {
        if (! $pack->is_active) {
            throw ValidationException::withMessages(['is_default' => 'Bộ âm thanh đang tắt, bật trước rồi mới đặt làm mặc định.']);
        }

        DB::transaction(function () use ($pack) {
            GameSoundPack::where('game', $pack->game)->where('id', '!=', $pack->id)->where('is_default', true)->update(['is_default' => false]);
            $pack->update(['is_default' => true]);
        });
    }

    // Xoá bộ: khách đang dùng tự về bộ mặc định, xoá luôn file đã tải lên.
    public static function delete(GameSoundPack $pack): void
    {
        if ($pack->is_default) {
            throw ValidationException::withMessages(['pack' => 'Không thể xoá bộ âm thanh mặc định. Đặt bộ khác làm mặc định trước.']);
        }

        $pack->delete();
        GamePlayerPreference::where('game', $pack->game)->where('sound_pack', $pack->key)->update(['sound_pack' => null]);
        Storage::disk('public')->deleteDirectory(self::dir($pack));
    }

    private static function assertAudio(string $event, string $name, int $size, ?string $mime, string $errorKey): void
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $okMime = $mime === null || str_starts_with($mime, 'audio/') || in_array($mime, ['video/mp4', 'application/ogg', 'video/ogg', 'application/octet-stream'], true);
        if (! in_array($ext, self::EXTENSIONS, true) || ! $okMime) {
            throw ValidationException::withMessages([$errorKey => "File {$name}: chỉ nhận " . implode(', ', ['mp3', 'm4a', 'aac', 'ogg', 'wav']) . '.']);
        }

        $max = self::isMusic($event) ? self::MAX_MUSIC_KB : self::MAX_KB;
        if ($size > $max * 1024) {
            throw ValidationException::withMessages([$errorKey => "File {$name} vượt quá " . ($max / 1024) . ' MB.']);
        }
    }

    /** @return array{0: string, 1: array<string, array{0: string, 1: string, 2: int, 3: ?string}>} */
    private static function extractZip(UploadedFile $zip, array $events, string $errorKey): array
    {
        $za = new \ZipArchive();
        if ($za->open($zip->getRealPath()) !== true) {
            throw ValidationException::withMessages([$errorKey => 'File .zip không mở được.']);
        }

        $dir   = storage_path('app/tmp/game-sounds-' . Str::random(12));
        @mkdir($dir, 0775, true);
        $files = [];
        $bad   = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i);
            $file  = basename($entry);
            if (str_ends_with($entry, '/') || str_starts_with($file, '.') || str_contains($entry, '__MACOSX')) {
                continue;
            }

            $event = strtolower(pathinfo($file, PATHINFO_FILENAME));
            if (! isset($events[$event])) {
                $bad[] = $file;
                continue;
            }

            $target = $dir . '/' . $i . '.' . strtolower(pathinfo($file, PATHINFO_EXTENSION));
            file_put_contents($target, $za->getFromIndex($i));
            $files[$event] = [$target, $file, (int) filesize($target), null];
        }
        $za->close();

        if ($bad) {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);
            throw ValidationException::withMessages([$errorKey => 'Không nhận ra sự kiện từ tên file: ' . implode(', ', array_slice($bad, 0, 10))
                . '. Đặt tên file theo mã sự kiện: ' . implode(', ', array_keys($events)) . '.']);
        }

        return [$dir, $files];
    }

    private static function dir(GameSoundPack $pack): string
    {
        return "games/sounds/{$pack->game}/{$pack->key}";
    }

    private static function storeFile(string $src, string $originalName, GameSoundPack $pack, string $event): string
    {
        $ext      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext      = $ext === 'oga' ? 'ogg' : $ext;
        $diskPath = self::dir($pack) . "/{$event}-" . Str::lower(Str::random(6)) . ".{$ext}";
        Storage::disk('public')->put($diskPath, (string) file_get_contents($src));

        return 'storage/' . $diskPath;
    }

    private static function storeCover(UploadedFile $file, GameSoundPack $pack): string
    {
        $ext      = strtolower($file->getClientOriginalExtension());
        $diskPath = self::dir($pack) . '/cover-' . Str::lower(Str::random(6)) . ".{$ext}";
        Storage::disk('public')->put($diskPath, (string) file_get_contents($file->getRealPath()));

        return 'storage/' . $diskPath;
    }

    private static function deleteFile(?string $path): void
    {
        if ($path && str_starts_with($path, 'storage/')) {
            Storage::disk('public')->delete(substr($path, strlen('storage/')));
        }
    }
}
