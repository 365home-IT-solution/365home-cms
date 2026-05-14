<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Post\Entities\Post;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostDetail extends Component
{
    use HandleColorTrait;

    public $slug;
    public $post;
    public $relatedPosts;
    public $config = [];
    public $primaryColor;
    public $style;

    public function mount($slug)
    {
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $this->slug = $slug;
        $this->post = $this->fetchPost();
        $this->relatedPosts = $this->fetchRelatedPosts();
        $this->style = Cache::get('post_config_style', '');

          // Fetch chessboard data if post has a chessboard
        if ($this->post->chessboard_status == 1 && $this->post->chessboard_id) {
            $this->fetchChessboardData($this->post->chessboard_id);
        }
    }

    public function fetchPost()
    {
        $query = Post::with(['tags', 'categories', 'media', 'user'])->where('status', 'published');

        if (isset($this->config['category'])) {
            $categoryIds = json_decode($this->config['category'], true);
            if (is_array($categoryIds) && !empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }
        }

        try {
            return $query->where('slug', $this->slug)->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404);
        }
    }

    public function fetchRelatedPosts()
    {
        $categoryIds = $this->post->categories->pluck('id')->toArray();
        $tagIds = $this->post->tags->pluck('id')->toArray();

        $categoryPosts = Post::with(['tags', 'categories', 'media'])
            ->where('status', 'published')
            ->where('id', '!=', $this->post->id)
            ->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            })
            ->latest()
            ->limit(6)
            ->get();

        $remainingCount = 6 - $categoryPosts->count();

        $tagPosts = collect();
        if ($remainingCount > 0) {
            $tagPosts = Post::with(['tags', 'categories', 'media'])
                ->where('status', 'published')
                ->where('id', '!=', $this->post->id)
                ->whereHas('tags', function ($q) use ($tagIds) {
                    $q->whereIn('tags.id', $tagIds);
                })
                ->whereNotIn('id', $categoryPosts->pluck('id')) // tránh trùng lặp
                ->latest()
                ->limit($remainingCount)
                ->get();
        }

        return $categoryPosts->merge($tagPosts);
    }

    public function fetchLatestPosts()
    {
        return Post::with(['tags', 'categories', 'media'])
            ->where('status', 'published')
            ->where('id', '!=', $this->post->id)
            ->latest('published_at')
            ->limit(5)
            ->get();
    }

 public function render()
{
    $url = url()->current();
    $title = $this->post->title;

    return view('bladethemev1::livewire.post-detail', [
        'post' => $this->post,
        'categories' => $this->post->categories,
        'relatedPosts' => $this->relatedPosts,
        'latestPosts' => $this->fetchLatestPosts(),
        'style' => $this->style,
        'url' => $url,
        'title' => $title,
    ]);
}
}
