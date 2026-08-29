<?php

namespace Modules\Comment\Entities;

use App\Models\Concerns\LogsAuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\App\Models\Product;

class Comment extends Model
{
    use HasFactory, LogsAuditTrail;

    protected $fillable = ['text', 'account_id', 'name', 'show', 'pin', 'commentable_id', 'commentable_type'];

    public function commentable()
    {
        return $this->morphTo();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CommentReply::class, 'comment_id');
    }
}
