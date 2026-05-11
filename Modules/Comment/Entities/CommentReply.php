<?php

namespace Modules\Comment\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommentReply extends Model
{
    use HasFactory;

    protected $fillable = ['text', 'account_id', 'name', 'comment_id', 'show', 'pin'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'comment_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

}
