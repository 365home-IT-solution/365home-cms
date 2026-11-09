<?php

declare(strict_types=1);

namespace App\Services;

use App\Games\TienLen\Card;
use App\Models\TienLenCardDeck;
use App\Models\TienLenPlayerPreference;
use App\Models\TienLenSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bộ bài (mặt bài) Tiến lên — dữ liệu ở bảng tien_len_card_decks, Super Admin thêm / sửa / xoá / bật cho dùng
 * (Filament "Tiến lên — Bộ bài" và /api/admin/games/tien-len/card-decks dùng chung service này).
 *
 *  - Khách chỉ thấy và chọn được bộ đang bật; mọi bộ đều miễn phí. Chỉ đổi hình, không ảnh hưởng luật / điểm.
 *  - API trả sẵn link ảnh từng lá theo bộ của NGƯỜI GỌI (mỗi người thấy bộ mình chọn, kể cả mặt lưng bài người khác).
 *  - Khách chưa chọn / bộ đã chọn bị tắt hoặc xoá ⇒ bộ mặc định (tien_len_settings.default_card_deck).
 *  - Lá chưa có ảnh trong bộ ⇒ dùng ảnh lá đó của bộ mặc định.
 *  - Upload: ảnh từng lá đặt tên theo mã ("AS.png", "10H.png") hoặc kiểu tiếng Việt ("Bich_A.png", "Co_10.png"), hoặc 1 file .zip.
 *    BE tự đổi sang WebP 315×440, lưu ở storage/app/public/tien-len/decks/{key}/.
 */
class TienLenDeckService
{
    public const FALLBACK = 'normal';

    public const CARD_W = 315;

    public const CARD_H = 440;

    private const VN_SUITS = ['bich' => 'S', 'tep' => 'C', 'chuon' => 'C', 'ro' => 'D', 'co' => 'H',
        'spade' => 'S', 'spades' => 'S', 'club' => 'C', 'clubs' => 'C', 'diamond' => 'D', 'diamonds' => 'D', 'heart' => 'H', 'hearts' => 'H'];

    /** @var Collection<string, TienLenCardDeck>|null bộ đang bật, theo key (nhớ trong request) */
    private static ?Collection $active = null;

    /** @var array<string, string> customer_id => key */
    private static array $memo = [];

    public static function forget(): void
    {
        self::$active = null;
        self::$memo   = [];
    }

    // =========================================================
    // ĐỌC
    // =========================================================

    /** @return Collection<string, TienLenCardDeck> */
    public static function active(): Collection
    {
        return self::$active ??= TienLenCardDeck::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->keyBy('key');
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && self::active()->has($key);
    }

    /** @return string[] key các bộ đang bật */
    public static function keys(): array
    {
        return self::active()->keys()->all();
    }

    /** @return array<string, string> key => tên (Select ở Filament) */
    public static function options(): array
    {
        return self::active()->map(fn (TienLenCardDeck $d) => $d->name)->all();
    }

    public static function defaultKey(): ?string
    {
        $key = TienLenSetting::current()->default_card_deck;

        return self::exists($key) ? $key : (self::exists(self::FALLBACK) ? self::FALLBACK : self::active()->keys()->first());
    }

    // Bộ khách đang dùng: đã chọn và bộ còn bật ⇒ bộ đó, còn lại ⇒ bộ mặc định.
    public static function selectedFor(?string $customerId): ?string
    {
        if ($customerId === null) {
            return self::defaultKey();
        }

        if (! isset(self::$memo[$customerId])) {
            $key = TienLenPlayerPreference::whereKey($customerId)->value('card_deck');
            self::$memo[$customerId] = self::exists($key) ? $key : (string) self::defaultKey();
        }

        return self::$memo[$customerId] ?: null;
    }

    // Bộ của người đang gọi API (không đăng nhập / lệnh nền ⇒ bộ mặc định).
    public static function current(): ?string
    {
        $user = request()->user();

        return self::selectedFor($user ? (string) $user->getKey() : null);
    }

    public static function select(string $customerId, string $key): void
    {
        TienLenPlayerPreference::updateOrCreate(['customer_id' => $customerId], ['card_deck' => $key]);
        self::$memo[$customerId] = $key;
    }

    public static function cardUrl(?string $key, string $code): ?string
    {
        $path = self::active()->get((string) $key)?->card_images[$code] ?? null;
        $path ??= self::active()->get((string) self::defaultKey())?->card_images[$code] ?? null;

        return TienLenCardDeck::url($path);
    }

    public static function summary(?string $key): ?array
    {
        $deck = self::active()->get((string) $key);

        return $deck ? [
            'key'      => $deck->key,
            'name'     => $deck->name,
            'back_url' => TienLenCardDeck::url($deck->back_image) ?? TienLenCardDeck::url(self::active()->get((string) self::defaultKey())?->back_image),
        ] : null;
    }

    /** Danh sách bộ đang bật cho màn chọn bộ bài của khách. */
    public static function catalog(?string $selected): array
    {
        return self::active()->values()->map(fn (TienLenCardDeck $d) => self::summary($d->key) + [
            'preview_url'   => TienLenCardDeck::url($d->preview_image),
            'sample_cards'  => array_map(fn (string $c) => ['code' => $c, 'image' => self::cardUrl($d->key, $c)], ['AS', 'KH', 'QD', '2C']),
            'missing_cards' => $d->missingCodes(),
            'is_selected'   => $d->key === $selected,
        ])->all();
    }

    /**
     * Card::payload + link ảnh theo bộ của người gọi.
     *
     * @param  int[]  $ids
     * @return array<int, array{id: int, code: string, image: ?string}>
     */
    public static function cards(array $ids, ?string $key = null): array
    {
        $key ??= self::current();

        return array_map(fn (array $c) => $c + ['image' => self::cardUrl($key, $c['code'])], Card::payload($ids));
    }

    // =========================================================
    // QUẢN TRỊ (Filament + API admin)
    // =========================================================

    /** Payload bộ bài cho màn quản trị. */
    public static function adminPayload(TienLenCardDeck $d): array
    {
        $default = TienLenSetting::current()->default_card_deck;

        return [
            'id'            => $d->id,
            'key'           => $d->key,
            'name'          => $d->name,
            'is_active'     => $d->is_active,
            'is_default'    => $d->key === $default,
            'sort_order'    => $d->sort_order,
            'back_url'      => TienLenCardDeck::url($d->back_image),
            'preview_url'   => TienLenCardDeck::url($d->preview_image),
            'card_count'    => $d->cardCount(),
            'missing_cards' => $d->missingCodes(),
            'cards'         => collect($d->card_images ?? [])->map(fn (string $p) => TienLenCardDeck::url($p))->all(),
            'players_using' => TienLenPlayerPreference::where('card_deck', $d->key)->count(),
            'created_at'    => $d->created_at?->toDateTimeString(),
            'updated_at'    => $d->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * Tạo / sửa bộ bài. $data: name, key (chỉ khi tạo), is_active, sort_order.
     * Ảnh: $cards = file từng lá (chỉ lá được gửi bị thay), $zip = file .zip chứa ảnh lá (+ back / preview nếu đặt tên vậy).
     *
     * @param  UploadedFile[]  $cards
     * @param  string  $errorPrefix  tiền tố khoá lỗi validate (Filament dùng "data.")
     */
    public static function save(?TienLenCardDeck $deck, array $data, array $cards = [], ?UploadedFile $zip = null,
        ?UploadedFile $back = null, ?UploadedFile $preview = null, string $errorPrefix = ''): TienLenCardDeck
    {
        $creating = $deck === null;
        $deck   ??= new TienLenCardDeck();

        if ($creating) {
            $key = Str::slug((string) (($data['key'] ?? null) ?: ($data['name'] ?? '')));
            if ($key === '' || TienLenCardDeck::where('key', $key)->exists()) {
                throw ValidationException::withMessages([$errorPrefix . 'key' => $key === '' ? 'Mã bộ bài không hợp lệ.' : 'Mã bộ bài đã tồn tại.']);
            }
            $deck->key = $key;
        }

        // Tách ảnh: từng lá theo tên file, back / preview trong zip
        $images = [];
        $bad    = [];
        foreach ($cards as $file) {
            $code = self::codeFromFilename($file->getClientOriginalName());
            $code ? $images[$code] = $file->getRealPath() : $bad[] = $file->getClientOriginalName();
        }

        $tmpDir = null;
        if ($zip) {
            [$tmpDir, $zipCards, $zipBack, $zipPreview, $zipBad] = self::extractZip($zip, $errorPrefix);
            $images += $zipCards;
            $bad     = array_merge($bad, $zipBad);
            $backPath    = $back?->getRealPath() ?? $zipBack;
            $previewPath = $preview?->getRealPath() ?? $zipPreview;
        } else {
            $backPath    = $back?->getRealPath();
            $previewPath = $preview?->getRealPath();
        }

        try {
            if ($bad) {
                throw ValidationException::withMessages([$errorPrefix . ($zip ? 'cards_zip' : 'cards') => 'Không nhận ra lá bài từ tên file: ' . implode(', ', array_slice($bad, 0, 10))
                    . (count($bad) > 10 ? '…' : '') . '. Đặt tên theo mã (AS.png, 10H.png) hoặc Bich_A.png / Tep_10.png / Ro_K.png / Co_2.png.']);
            }
            if ($creating && ! $backPath) {
                throw ValidationException::withMessages([$errorPrefix . 'back' => 'Cần ảnh mặt lưng.']);
            }

            $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($creating ? true : $deck->is_active);
            if (! $creating && $deck->is_active && ! $isActive) {
                self::assertNotDefault($deck, $errorPrefix . 'is_active', 'tắt');
            }

            DB::transaction(function () use ($deck, $data, $images, $backPath, $previewPath, $isActive) {
                $deck->fill(['name' => $data['name'] ?? $deck->name, 'sort_order' => (int) ($data['sort_order'] ?? $deck->sort_order ?? 0), 'is_active' => $isActive]);

                $map = $deck->card_images ?? [];
                foreach ($images as $code => $src) {
                    self::deleteFile($map[$code] ?? null);
                    $map[$code] = self::storeImage($src, $deck->key, $code, self::CARD_W, self::CARD_H);
                }
                $deck->card_images = $map;

                if ($backPath) {
                    self::deleteFile($deck->back_image);
                    $deck->back_image = self::storeImage($backPath, $deck->key, 'back', self::CARD_W, self::CARD_H);
                }
                if ($previewPath) {
                    self::deleteFile($deck->preview_image);
                    $deck->preview_image = self::storeImage($previewPath, $deck->key, 'preview', 0, 480);
                }

                $deck->save();
            });
        } finally {
            if ($tmpDir) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($tmpDir);
            }
        }

        return $deck->refresh();
    }

    public static function setActive(TienLenCardDeck $deck, bool $active, string $errorKey = 'is_active'): void
    {
        if (! $active) {
            self::assertNotDefault($deck, $errorKey, 'tắt');
        }

        $deck->update(['is_active' => $active]);
    }

    public static function makeDefault(TienLenCardDeck $deck): void
    {
        if (! $deck->is_active) {
            throw ValidationException::withMessages(['default_card_deck' => 'Bộ bài đang tắt, bật trước rồi mới đặt làm mặc định.']);
        }

        TienLenSetting::current()->update(['default_card_deck' => $deck->key]);
        self::forget();
    }

    // Xoá bộ: khách đang dùng bộ này tự về bộ mặc định. Xoá kèm ảnh đã upload (ảnh có sẵn trong images/ giữ nguyên).
    public static function delete(TienLenCardDeck $deck): void
    {
        self::assertNotDefault($deck, 'deck', 'xoá');

        $deck->delete();
        TienLenPlayerPreference::where('card_deck', $deck->key)->delete();
        Storage::disk('public')->deleteDirectory("tien-len/decks/{$deck->key}");
    }

    private static function assertNotDefault(TienLenCardDeck $deck, string $errorKey, string $verb): void
    {
        if ($deck->key === self::defaultKey() || $deck->key === TienLenSetting::current()->default_card_deck) {
            throw ValidationException::withMessages([$errorKey => "Không thể {$verb} bộ bài mặc định. Đổi bộ mặc định ở Cài đặt Tiến lên trước."]);
        }
    }

    /**
     * "AS.png", "10h.webp", "TH.png", "Bich_A.png", "Co-10.png", "A_spades.png" ⇒ mã lá; không nhận ra ⇒ null.
     */
    public static function codeFromFilename(string $name): ?string
    {
        $base = strtolower(Str::ascii(pathinfo($name, PATHINFO_FILENAME)));
        $base = trim(preg_replace('/\s+/', ' ', $base));
        $rank = '(10|t|[2-9]|a|j|q|k)';

        if (preg_match("/^{$rank}([scdh])$/", $base, $m)) {
            [$r, $s] = [$m[1], strtoupper($m[2])];
        } elseif (preg_match("/^([a-z]+)[ _\-]+{$rank}$/", $base, $m) && isset(self::VN_SUITS[$m[1]])) {
            [$r, $s] = [$m[2], self::VN_SUITS[$m[1]]];
        } elseif (preg_match("/^{$rank}[ _\-]+(?:of[ _\-]+)?([a-z]+)$/", $base, $m) && isset(self::VN_SUITS[$m[2]])) {
            [$r, $s] = [$m[1], self::VN_SUITS[$m[2]]];
        } else {
            return null;
        }

        return strtoupper($r === 't' ? '10' : $r) . $s;
    }

    /** @return array{0: string, 1: array<string, string>, 2: ?string, 3: ?string, 4: string[]} */
    private static function extractZip(UploadedFile $zip, string $errorPrefix): array
    {
        $za = new \ZipArchive();
        if ($za->open($zip->getRealPath()) !== true) {
            throw ValidationException::withMessages([$errorPrefix . 'cards_zip' => 'File .zip không mở được.']);
        }

        $dir = storage_path('app/tmp/tien-len-deck-' . Str::random(12));
        @mkdir($dir, 0775, true);

        $cards = [];
        $back  = $preview = null;
        $bad   = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i);
            $file  = basename($entry);
            $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            // Bỏ thư mục, file ẩn của macOS, Thumbs.db…
            if (str_ends_with($entry, '/') || str_starts_with($file, '.') || str_contains($entry, '__MACOSX') || ! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                continue;
            }

            $target = $dir . '/' . $i . '.' . $ext;
            file_put_contents($target, $za->getFromIndex($i));

            $lower = strtolower(Str::ascii(pathinfo($file, PATHINFO_FILENAME)));
            if (in_array($lower, ['back', 'mat_lung', 'mat lung', 'mat-lung'], true)) {
                $back = $target;
            } elseif (in_array($lower, ['preview', 'tong_quan', 'tong_quan_52_la', 'tong quan'], true) || str_starts_with($lower, 'tong_quan')) {
                $preview = $target;
            } elseif ($code = self::codeFromFilename($file)) {
                $cards[$code] = $target;
            } else {
                $bad[] = $file;
            }
        }
        $za->close();

        return [$dir, $cards, $back, $preview, $bad];
    }

    // Đổi ảnh sang WebP (giữ nền trong suốt) rồi lưu vào disk public. $w = 0 ⇒ giữ tỉ lệ theo chiều cao. Trả đường dẫn tính từ web root.
    private static function storeImage(string $src, string $key, string $name, int $w, int $h): string
    {
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if (! $img) {
            throw ValidationException::withMessages(['cards' => "Ảnh {$name} không đọc được (chỉ nhận PNG, JPG, WebP)."]);
        }

        $w   = $w ?: (int) max(1, round(imagesx($img) * $h / imagesy($img)));
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));

        ob_start();
        imagewebp($out, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        imagedestroy($out);

        // Tên kèm chuỗi ngẫu nhiên để app không dính cache ảnh cũ khi thay ảnh
        $diskPath = "tien-len/decks/{$key}/{$name}-" . Str::lower(Str::random(6)) . '.webp';
        Storage::disk('public')->put($diskPath, $bytes);

        return 'storage/' . $diskPath;
    }

    // Chỉ xoá ảnh đã upload (storage/…); ảnh có sẵn trong images/ thuộc mã nguồn, không xoá.
    private static function deleteFile(?string $path): void
    {
        if ($path && str_starts_with($path, 'storage/')) {
            Storage::disk('public')->delete(substr($path, strlen('storage/')));
        }
    }
}
