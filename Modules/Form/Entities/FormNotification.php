<?php

namespace Modules\Form\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FormNotification extends Model
{
    use HasFactory;

    protected $fillable = ['form_id', 'success_message', 'error_message'];

    public function form()
    {
        return $this->belongsTo(Form::class);
    }
}
