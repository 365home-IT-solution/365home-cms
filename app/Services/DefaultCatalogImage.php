<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\BladeThemeV1\App\Models\AdditionService;

/**
 * Ảnh MẶC ĐỊNH cho tiện ích (tags) và dịch vụ đi kèm (additional_services) — để không bao giờ có mục
 * "thiếu ảnh" trên app/web. Ảnh gốc nằm ở public/images/defaults/, được chép vào disk `public` đúng thư
 * mục ảnh thật của từng loại (Amenity/, addition-services/) nên mọi nơi hiển thị (Storage::url) đọc như
 * ảnh bình thường; đối tác tải ảnh riêng thì thay thế.
 */
class DefaultCatalogImage
{
    private const AMENITY = ['src' => 'default-amenity.png', 'path' => 'Amenity/default-amenity.png'];
    private const SERVICE = ['src' => 'default-service.png', 'path' => 'addition-services/default-service.png'];

    public function amenityPath(): string
    {
        return $this->ensure(self::AMENITY);
    }

    public function servicePath(): string
    {
        return $this->ensure(self::SERVICE);
    }

    /** URL đầy đủ của ảnh tiện ích; chưa có ảnh thì trả ảnh mặc định. */
    public function amenityUrl(?string $path): string
    {
        return Storage::disk('public')->url(filled($path) ? $path : $this->amenityPath());
    }

    /** URL đầy đủ của ảnh dịch vụ; chưa có ảnh thì trả ảnh mặc định. */
    public function serviceUrl(?string $path): string
    {
        return Storage::disk('public')->url(filled($path) ? $path : $this->servicePath());
    }

    /**
     * Gán ảnh mặc định cho mọi tiện ích/dịch vụ đang thiếu ảnh. $dryRun = true chỉ đếm, không ghi.
     *
     * @return array{tags:int, services:int}
     */
    public function fillMissing(bool $dryRun = false): array
    {
        $tags = 0;

        foreach (Tag::query()->get() as $tag) {
            if ($this->tagHasImage($tag)) {
                continue;
            }

            $tags++;

            if (! $dryRun) {
                // updateQuietly: không ghi nhật ký thao tác cho việc vá hàng loạt.
                DB::table('tags')->where('id', $tag->id)->update([
                    'image' => json_encode([app()->getLocale() => $this->amenityPath()], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        $query = AdditionService::withoutGlobalScopes()->where(fn ($q) => $q->whereNull('image')->orWhere('image', ''));
        $services = (clone $query)->count();

        if (! $dryRun && $services) {
            $query->update(['image' => $this->servicePath()]);
        }

        return ['tags' => $tags, 'services' => $services];
    }

    /** Tiện ích đã có ảnh chưa — cột image lưu JSON theo locale (hoặc chuỗi thường ở dữ liệu cũ). */
    public function tagHasImage(Tag $tag): bool
    {
        $raw = $tag->getRawOriginal('image');

        if (blank($raw)) {
            return false;
        }

        if (is_string($raw) && str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) && collect($decoded)->filter()->isNotEmpty();
        }

        return true;
    }

    /** @param array{src:string,path:string} $def */
    private function ensure(array $def): string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($def['path'])) {
            $source = public_path('images/defaults/' . $def['src']);

            if (File::exists($source)) {
                $disk->put($def['path'], File::get($source));
            }
        }

        return $def['path'];
    }
}
