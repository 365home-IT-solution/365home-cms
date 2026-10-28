<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RoomCopyException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Product\App\Models\PriceBoardItem;
use Modules\Product\App\Models\PriceBoardTimeSlot;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

/**
 * Sao chép 1 phòng thành N phòng mới (VD 1 loại phòng có nhiều phòng vật lý 101, 102, 103 — mỗi
 * phòng vật lý là 1 Product riêng vì lịch đặt, khoá TTLock, dọn phòng đều tính theo Product).
 *
 * Tên phòng mới tăng dần theo số cuối tên phòng gốc ("Double 101" → "Double 102", "Double 103"…;
 * tên không có số → "Standard 2", "Standard 3"…), bỏ qua các số đã có phòng cùng đối tác dùng.
 *
 * Phòng mới nhận nguyên: nội dung, giá + khung giờ (room_time_slots kèm KM/mã giảm giá gắn theo
 * khung giờ/ngày), vị trí trong bảng giá (price_board_*), chi nhánh, tiện ích, ảnh.
 * KHÔNG chép những gì gắn với phòng vật lý: khoá TTLock, mật mã thủ công, khoá khẩn cấp, ngày bị
 * chặn (settings.blocked_dates), đánh giá, đơn đặt, nhật ký dọn phòng.
 *
 * Phòng mới đi qua Product::create như tạo tay — đối tác sao chép thì phòng mới cũng vào hàng
 * chờ duyệt (ApprovalService) như mọi phòng mới khác.
 */
class RoomCopyService
{
    public const MAX_COPIES = 50;

    /** Cột của products không mang sang phòng mới (gắn với phòng vật lý / định danh / trạng thái). */
    private const SKIP_PRODUCT_COLUMNS = [
        'slug', 'lock_id', 'lock_id_checkout', 'has_manual_lock', 'unlock_both_locks',
        'emergency_locked_at', 'emergency_locked_by', 'emergency_lock_reason',
        'rating_score', 'legacy_minihouse_room_id', 'approval_status', 'deleted_at',
    ];

    /** Bảng gắn trực tiếp theo phòng được chép nguyên dòng => cột trỏ về phòng. */
    private const ROOM_ROWS = [
        'room_amenity_assigns'       => 'room_id',
        'room_services'              => 'product_id',
        'room_specials'              => 'product_id',
        'coupon_products'            => 'product_id',
        'price_board_rooms'          => 'product_id',
        'price_board_sheet_products' => 'product_id',
    ];

    /**
     * Tên các phòng sẽ được tạo — dùng cho cả xem trước trên form lẫn lúc tạo thật.
     *
     * @return list<string>
     */
    public function previewNames(Product $source, int $quantity, ?int $startNumber = null): array
    {
        [$base, $number, $width] = $this->nameParts($source->name);

        $start = $startNumber ?? $this->nextFreeNumber($source, $base, $number);

        return array_map(
            fn (int $i) => $base . str_pad((string) ($start + $i), $width, '0', STR_PAD_LEFT),
            range(0, max(1, $quantity) - 1)
        );
    }

    /**
     * Tên trong danh sách đã có phòng cùng đối tác dùng — chỉ xảy ra khi tự nhập số bắt đầu (số tự
     * động luôn bỏ qua số đã dùng, xem nextFreeNumber()).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function takenNames(Product $source, array $names): array
    {
        return Product::withoutGlobalScopes()
            ->where('partner_id', $source->partner_id)
            ->whereIn('name', $names)
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Product>
     *
     * @throws RoomCopyException
     */
    public function copy(Product $source, int $quantity, ?int $startNumber = null): Collection
    {
        // Mỗi đối tác chỉ một lượt sao chép cùng lúc — hai lượt song song sẽ chọn trùng số và trùng tên phòng.
        $lock = Cache::lock('room-copy:' . $source->partner_id, 300);

        if (! $lock->get()) {
            throw RoomCopyException::busy();
        }

        try {
            // Mỗi phòng chép file ảnh riêng: nhiều phòng có thể lâu hơn giới hạn thời gian mặc định của máy chủ.
            @set_time_limit(300);

            return $this->copyAll($source, $quantity, $startNumber);
        } finally {
            $lock->release();
        }
    }

    /** @return Collection<int, Product> */
    private function copyAll(Product $source, int $quantity, ?int $startNumber): Collection
    {
        $quantity = max(1, min(self::MAX_COPIES, $quantity));
        $source   = Product::withoutGlobalScopes()->with(['categories:id', 'tags:id', 'media'])->findOrFail($source->getKey());
        $names    = $this->previewNames($source, $quantity, $startNumber);

        if ($taken = $this->takenNames($source, $names)) {
            throw RoomCopyException::nameTaken($taken);
        }

        $sortOrder = (int) Product::withoutGlobalScopes()->where('partner_id', $source->partner_id)->max('sort_order');

        $copies = collect();

        foreach ($names as $i => $name) {
            try {
                $copy = DB::transaction(fn () => $this->copyOne($source, $name, $sortOrder + $i + 1));
                // Ghi nhận ngay khi phòng đã tạo — lỗi ở bước sau (ảnh…) vẫn báo đúng phòng này đã có.
                $copies->push($copy);

                app(BranchServiceSync::class)->syncRoom($copy);
                $this->copyMedia($source, $copy);
                $this->copyRoomImages($source, $copy);
            } catch (\Throwable $e) {
                report($e);

                throw RoomCopyException::interrupted($copies->pluck('name')->all(), $e);
            }
        }

        return $copies;
    }

    private function copyOne(Product $source, string $name, int $sortOrder): Product
    {
        $copy = $source->replicate(array_merge(self::SKIP_PRODUCT_COLUMNS, ['created_at', 'updated_at']));
        $copy->name                = $name;
        $copy->slug                = $this->uniqueSlug($name);
        $copy->sort_order          = $sortOrder;
        $copy->housekeeping_status = 'available';
        $copy->save();

        $copy->categories()->sync($source->categories->pluck('id')->all());
        $copy->tags()->sync($source->tags->pluck('id')->all());

        foreach (self::ROOM_ROWS as $table => $column) {
            $this->copyRows($table, $column, $source->getKey(), $copy->getKey());
        }

        RoomTimeSlot::withoutAuditLog(fn () => $this->copyTimeSlots($source, $copy));
        PriceBoardItem::withoutAuditLog(fn () => $this->copyPriceBoardItems($source, $copy));

        return $copy;
    }

    private function copyTimeSlots(Product $source, Product $copy): void
    {
        RoomTimeSlot::where('room_id', $source->getKey())->orderBy('id')->each(function (RoomTimeSlot $slot) use ($copy) {
            $new = $slot->replicate(['created_at', 'updated_at']);
            $new->room_id = $copy->getKey();

            // Ngày bị chặn là của phòng gốc (đang sửa, có khách thuê ngoài…) — phòng mới mở bán bình thường.
            if (is_array($new->settings) && array_key_exists('blocked_dates', $new->settings)) {
                $new->settings = array_merge($new->settings, ['blocked_dates' => []]);
            }

            $new->save();

            $this->copyRows('promotion_room_time_slot', 'room_time_slot_id', $slot->getKey(), $new->getKey());
            $this->copyRows('coupon_room_time_slot', 'room_time_slot_id', $slot->getKey(), $new->getKey());
        });
    }

    private function copyPriceBoardItems(Product $source, Product $copy): void
    {
        PriceBoardItem::where('product_id', $source->getKey())->orderBy('id')->each(function (PriceBoardItem $item) use ($copy) {
            $new = $item->replicate(['created_at', 'updated_at']);
            $new->product_id = $copy->getKey();
            $new->save();

            PriceBoardTimeSlot::where('price_board_item_id', $item->getKey())->each(function (PriceBoardTimeSlot $slot) use ($new) {
                $copySlot = $slot->replicate(['created_at', 'updated_at']);
                $copySlot->price_board_item_id = $new->getKey();
                $copySlot->save();
            });
        });
    }

    /** Chép mọi dòng của $table có $column = $fromId sang $toId (bỏ id tự tăng, đặt lại timestamps). */
    private function copyRows(string $table, string $column, string|int $fromId, string|int $toId): void
    {
        $rows = DB::table($table)->where($column, $fromId)->get();

        if ($rows->isEmpty()) {
            return;
        }

        $now = now();

        DB::table($table)->insert($rows->map(function ($row) use ($column, $toId, $now) {
            $data = (array) $row;
            unset($data['id']);
            $data[$column] = $toId;

            foreach (['created_at', 'updated_at'] as $ts) {
                if (array_key_exists($ts, $data)) {
                    $data[$ts] = $now;
                }
            }

            return $data;
        })->all());
    }

    /**
     * Chép thẳng file gốc + các bản chuyển đổi (thumb/card/wide/full) sang thư mục media mới, không
     * dùng $media->copy() — cách đó mã hoá lại 4 bản AVIF đồng bộ cho từng ảnh, nhân N phòng sẽ quá
     * giờ request. Ảnh gốc mất / 0 byte (xem sự cố ảnh 08/2026) thì bỏ qua, không nhân bản ảnh hỏng.
     */
    private function copyMedia(Product $source, Product $copy): void
    {
        foreach ($source->media as $media) {
            $disk = Storage::disk($media->disk);

            if (! $disk->exists($media->getPathRelativeToRoot()) || $disk->size($media->getPathRelativeToRoot()) === 0) {
                continue;
            }

            /** @var Media $new */
            $new = $media->replicate(['uuid', 'created_at', 'updated_at']);
            $new->uuid       = (string) Str::uuid();
            $new->model_type = $copy->getMorphClass();
            $new->model_id   = $copy->getKey();

            try {
                $new->save();
                $this->copyMediaFiles($media, $new);
            } catch (\Throwable $e) {
                report($e);
                $new->exists && $new->delete();
            }
        }
    }

    private function copyMediaFiles(Media $from, Media $to): void
    {
        $fromPaths = PathGeneratorFactory::create($from);
        $toPaths   = PathGeneratorFactory::create($to);

        $dirs = [
            [$from->disk, $fromPaths->getPath($from), $toPaths->getPath($to)],
            [$from->conversions_disk ?: $from->disk, $fromPaths->getPathForConversions($from), $toPaths->getPathForConversions($to)],
            [$from->conversions_disk ?: $from->disk, $fromPaths->getPathForResponsiveImages($from), $toPaths->getPathForResponsiveImages($to)],
        ];

        $copied = [];

        foreach ($dirs as [$diskName, $fromDir, $toDir]) {
            $disk = Storage::disk($diskName);

            foreach ($disk->allFiles($fromDir) as $file) {
                $target = $toDir . Str::after($file, $fromDir);

                // Thư mục conversions/responsive mặc định nằm TRONG thư mục gốc — allFiles() đã gặp rồi.
                if (isset($copied[$diskName . ':' . $target])) {
                    continue;
                }

                $disk->copy($file, $target);
                $copied[$diskName . ':' . $target] = true;
            }
        }
    }

    /**
     * Ảnh ở bảng room_images (trang "Ảnh phòng"): path là JSON — mảng path, hoặc mảng nhóm
     * {title, description, images: [...]}. Mỗi file được chép thành file RIÊNG cho phòng mới, không
     * trỏ chung file với phòng gốc — xoá ảnh ở phòng này không được làm mất ảnh ở phòng kia.
     */
    private function copyRoomImages(Product $source, Product $copy): void
    {
        foreach (DB::table('room_images')->where('room_id', $source->getKey())->orderBy('id')->get() as $row) {
            try {
                $this->copyRoomImageRow($row, $copy);
            } catch (\Throwable $e) {
                report($e); // ví dụ đầy ổ đĩa giữa chừng: phòng vẫn được tạo, ảnh lỗi bị bỏ qua và ghi log
            }
        }
    }

    private function copyRoomImageRow(object $row, Product $copy): void
    {
        $disk  = Storage::disk($row->disk ?: 'public');
        $paths = json_decode((string) $row->path, true);

        if (! is_array($paths)) {
            return;
        }

        $copyFile = function (mixed $path) use ($disk): ?string {
            if (! is_string($path) || ! $disk->exists($path) || $disk->size($path) === 0) {
                return null;
            }

            $ext    = pathinfo($path, PATHINFO_EXTENSION);
            $target = trim(dirname($path), './') . '/' . Str::ulid() . ($ext !== '' ? '.' . $ext : '');
            $target = ltrim($target, '/');

            return $disk->copy($path, $target) ? $target : null;
        };

        $newPaths = [];

        foreach ($paths as $entry) {
            if (is_array($entry)) {
                $entry['images'] = array_values(array_filter(array_map($copyFile, (array) ($entry['images'] ?? []))));
                $newPaths[]      = $entry;
            } elseif ($new = $copyFile($entry)) {
                $newPaths[] = $new;
            }
        }

        $data = (array) $row;
        unset($data['id']);

        DB::table('room_images')->insert(array_merge($data, [
            'room_id'    => $copy->getKey(),
            'path'       => json_encode($newPaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** @return array{0: string, 1: int, 2: int} [phần tên trước số, số cuối tên, độ dài số (giữ số 0 đầu)] */
    private function nameParts(string $name): array
    {
        $name = trim($name);

        if (preg_match('/^(.*?)(\d+)$/u', $name, $m)) {
            return [$m[1], (int) $m[2], strlen($m[2])];
        }

        return [$name . ' ', 1, 1];
    }

    /** Số kế tiếp sau số lớn nhất mà các phòng cùng đối tác, cùng phần tên đang dùng. */
    private function nextFreeNumber(Product $source, string $base, int $number): int
    {
        $pattern = '/^' . preg_quote($base, '/') . '(\d+)$/u';

        $used = Product::withoutGlobalScopes()
            ->where('partner_id', $source->partner_id)
            ->where('name', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $base) . '%')
            ->pluck('name')
            ->map(fn (string $n) => preg_match($pattern, trim($n), $m) ? (int) $m[1] : null)
            ->filter(fn ($n) => $n !== null);

        return max($used->push($number)->all()) + 1;
    }

    /** Slug duy nhất trên TOÀN bảng — cột slug UNIQUE tính cả phòng đã xoá mềm và phòng MiniHouse. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'phong';
        $slug = $base;
        $i    = 1;

        while (DB::table('products')->where('slug', $slug)->exists()) {
            $slug = "{$base}-" . $i++;
        }

        return $slug;
    }
}
