<?php

namespace App\Observers;

use App\Support\AuditFieldFilter;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Post\Entities\Post;

class PostObserver
{
    public function created(Post $post): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Post',
            record: $post,
            new: AuditFieldFilter::filter($post->getAttributes()),
            label: $post->title,
        );
    }

    // Trước đây chỉ theo dõi title/status/published_at/author_id — sửa NỘI DUNG bài viết (field
    // quan trọng nhất) hoàn toàn không có log. Bỏ whitelist, ghi lại toàn bộ field thay đổi.
    public function updated(Post $post): void
    {
        $changed = AuditFieldFilter::filter($post->getChanges());

        if (empty($changed)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Post',
            record: $post,
            old: array_intersect_key($post->getOriginal(), $changed),
            new: $changed,
            label: $post->title,
        );
    }

    public function deleted(Post $post): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Post',
            record: $post,
            old: AuditFieldFilter::filter($post->getAttributes()),
            label: $post->title,
        );
    }
}
