<?php

namespace App\Observers;

use Modules\AuditLog\Services\AuditLogger;
use Modules\Post\Entities\Post;

class PostObserver
{
    private const TRACKED_FIELDS = ['title', 'status', 'published_at', 'author_id'];

    public function created(Post $post): void
    {
        AuditLogger::log(
            action: 'create',
            module: 'Post',
            record: $post,
            new: $post->only(['title', 'status', 'published_at']),
            label: $post->title,
        );
    }

    public function updated(Post $post): void
    {
        $changed = array_keys($post->getChanges());
        $tracked = array_intersect($changed, self::TRACKED_FIELDS);

        if (empty($tracked)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'Post',
            record: $post,
            old: array_intersect_key($post->getOriginal(), array_flip($tracked)),
            new: array_intersect_key($post->getChanges(), array_flip($tracked)),
            label: $post->title,
        );
    }

    public function deleted(Post $post): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Post',
            record: $post,
            old: $post->only(['title', 'status']),
            label: $post->title,
        );
    }
}
