<?php

namespace Modules\Form\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @method static pluck(string $string, string $string1)
 */
class Form extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'submit_button_text',
    ];

    public function formFields()
    {
        return $this->hasMany(FormField::class);
    }

    public function submissions()
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function emailSetting()
    {
        return $this->hasOne(EmailSetting::class, 'form_id');
    }

    public function notification()
    {
        return $this->hasOne(FormNotification::class, 'form_id');
    }
}
