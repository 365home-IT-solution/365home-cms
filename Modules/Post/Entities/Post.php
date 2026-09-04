<?php

namespace Modules\Post\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;
use Modules\Category\Entities\Category;
use Modules\Category\Traits\Categorizable;
use Modules\Comment\Entities\Comment;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Tags\HasTags;

class Post extends Model implements HasMedia
{
    use HasFactory,
        Categorizable,
        InteractsWithMedia,
        HasTags,
        HasUlids;

    protected $fillable = [
        'title',
        'slug',
        'summary',
        'content',
        'status',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'published_at',
        'author_id',
        'editor_id',
    
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($post) {
            if (empty($post->author_id)) {
                $post->author_id = Auth::id();
            }
        });

        static::deleting(function ($post) {
            $post->comments()->delete();
        });
    }

    // Normalize seo_keywords: DB may store JSON array or comma string → always return comma string
    public function getSeoKeywordsAttribute($value): string
    {
        if (empty($value)) return '';
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return implode(', ', $decoded);
        }
        return $value;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function categories()
    {
        return $this->morphToMany(Category::class, 'categorizable');
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function ratings()
    {
        return $this->hasMany(PostRating::class);
    }

    public function ratingAverage(): float
    {
        return round((float) $this->ratings()->avg('rating'), 1);
    }

    public function ratingCount(): int
    {
        return $this->ratings()->count();
    }

    // Cùng convention với Modules\Product\App\Models\Product::registerMediaConversions() — dùng
    // chung tên/kích thước để srcset responsive trên frontend đọc được nhất quán giữa các loại
    // media. Ảnh cũ đã upload trước khi thêm hàm này cần chạy lại:
    // php artisan media-library:regenerate --only="Modules\Post\Entities\Post"
    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (['thumb' => 240, 'card' => 480, 'wide' => 1080, 'full' => 1440] as $name => $size) {
            $this->addMediaConversion($name)
                ->fit(Fit::Max, $size, $size)
                ->format('avif')
                ->quality(72)
                ->nonQueued();
        }
    }
}
