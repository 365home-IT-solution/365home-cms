<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// Ảnh đính kèm đánh giá/phản hồi — dùng chung cho RoomRating (Homestay) và TenantFeedback
// (Minihouse). Model dùng trait PHẢI: implements HasMedia, use InteractsWithMedia, và khai báo
// 4 hằng số IMAGE_COLLECTION / MAX_IMAGES / MAX_IMAGE_KB / IMAGE_MIMES.
trait HasReviewImages
{
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(static::IMAGE_COLLECTION);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (['thumb' => 240, 'card' => 720] as $name => $size) {
            $this->addMediaConversion($name)
                ->fit(Fit::Max, $size, $size)
                ->format('webp')
                ->quality(75)
                ->nonQueued();
        }
    }

    /**
     * Luật validate cho trường ảnh gửi lên (images[] + images.*).
     *
     * @return array<string, array<int, string>>
     */
    public static function imageRules(string $field = 'images'): array
    {
        return [
            $field        => ['nullable', 'array', 'max:' . static::MAX_IMAGES],
            $field . '.*' => ['image', 'mimes:' . implode(',', static::IMAGE_MIMES), 'max:' . static::MAX_IMAGE_KB],
        ];
    }

    /**
     * Gắn các file ảnh đã validate vào bản ghi.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     */
    public function addReviewImages(array $files): void
    {
        foreach ($files as $file) {
            $this->addMedia($file)->toMediaCollection(static::IMAGE_COLLECTION);
        }
    }

    /**
     * Danh sách ảnh dạng mảng cho response API: [{id, url, thumb_url}].
     *
     * @return array<int, array{id:int,url:string,thumb_url:string}>
     */
    public function imagesPayload(): array
    {
        return $this->getMedia(static::IMAGE_COLLECTION)
            ->map(fn (Media $m) => [
                'id'        => $m->id,
                'url'       => $m->getUrl(),
                'thumb_url' => $m->hasGeneratedConversion('thumb') ? $m->getUrl('thumb') : $m->getUrl(),
            ])
            ->values()
            ->all();
    }
}
