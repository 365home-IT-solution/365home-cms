<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Post\Entities\PostRating as PostRatingModel;

class PostRating extends Component
{
    public string $postId;
    public float $average = 0;
    public int $count = 0;
    public int $userRating = 0;

    public function mount(string $postId)
    {
        $this->postId = $postId;
        $this->refreshStats();

        $existing = PostRatingModel::query()
            ->where('post_id', $this->postId)
            ->where('voter_hash', $this->voterHash())
            ->first();

        $this->userRating = $existing->rating ?? 0;
    }

    public function vote(int $rating)
    {
        $rating = max(1, min(5, $rating));

        PostRatingModel::updateOrCreate(
            ['post_id' => $this->postId, 'voter_hash' => $this->voterHash()],
            ['rating' => $rating]
        );

        $this->userRating = $rating;
        $this->refreshStats();
    }

    protected function refreshStats(): void
    {
        $ratings = PostRatingModel::query()->where('post_id', $this->postId);
        $this->count = $ratings->count();
        $this->average = $this->count > 0 ? round((float) $ratings->avg('rating'), 1) : 0;
    }

    // IP + User-Agent thay vì cookie/tài khoản: độc giả không cần đăng nhập vẫn đánh giá được,
    // đổi ý bấm lại vẫn cập nhật đúng lượt cũ của họ (unique post_id+voter_hash ở migration).
    protected function voterHash(): string
    {
        return hash('sha256', request()->ip() . '|' . request()->userAgent());
    }

    public function render()
    {
        return view('bladethemev1::livewire.post-rating');
    }
}
