<?php

namespace Modules\Form\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FormSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'is_viewed',
        'viewed_by',
        'viewed_at',
    ];
    
    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function formFieldValues()
    {
        return $this->hasMany(FormFieldValue::class, 'form_submission_id');
    }

    public function viewedByUser()
    {
        return $this->belongsTo(User::class, 'viewed_by');
    }
}
