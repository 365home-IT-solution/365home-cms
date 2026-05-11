<?php

namespace Modules\Form\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmailSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'to_email',
        'from_email',
        'subject',
        'additional_headers',
        'message_body',
    ];

    public function form()
    {
        return $this->belongsTo(Form::class);
    }
}
