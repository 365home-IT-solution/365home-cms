<?php

namespace Modules\BladeThemeV1\Livewire;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Livewire\Component;
use Modules\Post\Entities\PostRating as PostRatingModel;

class PostRating extends Component
{
    public const VOTER_COOKIE = 'post_rating_voter';

    public string $postId;
    public float $average = 0;
    public int $count = 0;
    public int $userRating = 0;
    public string $voterHash;

    public function mount(string $postId)
    {
        $this->postId = $postId;
        $this->voterHash = $this->resolveVoterHash();
        $this->refreshStats();

        $existing = PostRatingModel::query()
            ->where('post_id', $this->postId)
            ->where('voter_hash', $this->voterHash)
            ->first();

        $this->userRating = $existing->rating ?? 0;
    }

    public function vote(int $rating)
    {
        $rating = max(1, min(5, $rating));

        PostRatingModel::updateOrCreate(
            ['post_id' => $this->postId, 'voter_hash' => $this->voterHash],
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

    // Định danh theo cookie riêng của từng trình duyệt — KHÔNG dùng IP+User-Agent nữa: nhiều
    // người dùng thật ở VN đứng sau cùng 1 IP (4G/wifi công cộng dùng CGNAT) sẽ bị tính chung làm
    // 1 người, người bấm sau vô tình đè lượt bình chọn của người trước. Cookie sống 5 năm, không
    // cần đăng nhập vẫn giữ đúng danh tính qua các lần ghé thăm sau.
    protected function resolveVoterHash(): string
    {
        $existing = request()->cookie(self::VOTER_COOKIE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $new = (string) Str::uuid();
        Cookie::queue(self::VOTER_COOKIE, $new, 60 * 24 * 365 * 5);

        return $new;
    }

    public function render()
    {
        return view('bladethemev1::livewire.post-rating');
    }
}
