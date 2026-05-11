<?php

namespace Modules\Form\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FormField extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'type',
        'label',
        'name',
        'options',
        'sort_order',
        'is_required',
        'min_length',
        'max_length',
    ];
    
    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function fieldValues()
    {
        return $this->hasMany(FormFieldValue::class, 'form_field_id');
    }
}
