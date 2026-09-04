<?php

namespace Modules\Post\Entities;

use Illuminate\Database\Eloquent\Model;

class PostRating extends Model
{
    protected $fillable = [
        'post_id',
        'rating',
        'voter_hash',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
