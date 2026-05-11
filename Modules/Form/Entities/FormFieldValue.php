<?php

namespace Modules\Form\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FormFieldValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_submission_id',
        'form_field_id',
        'value',
    ];

    public function submission()
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    public function field()
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }
}
