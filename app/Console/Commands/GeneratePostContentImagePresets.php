<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PostContentImages;
use Illuminate\Console\Command;
use Modules\Post\Entities\Post;

// Chạy bù 1 lần cho bài viết cũ: sinh bản AVIF thu nhỏ (card/medium/wide) cho mọi ảnh /storage/ chèn
// trong nội dung bài, để trang bài viết dùng srcset thay vì tải nguyên ảnh gốc. Bài lưu từ giờ tự
// sinh qua Post::saved (xem Modules\Post\Entities\Post::boot()).
class GeneratePostContentImagePresets extends Command
{
    protected $signature = 'images:post-content-presets';
    protected $description = 'Sinh bản AVIF thu nhỏ cho ảnh trong nội dung bài viết';

    public function handle(): int
    {
        $query = Post::query()->whereNotNull('content');
        $bar = $this->output->createProgressBar($query->count());
        $images = 0;

        $query->select(['id', 'content'])->chunkById(20, function ($posts) use ($bar, &$images) {
            foreach ($posts as $post) {
                $images += PostContentImages::generate((string) $post->content);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã xử lý {$images} ảnh.");

        return self::SUCCESS;
    }
}
