<?php

namespace TomatoPHP\FilamentMediaManager\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Folder extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'parent_id',
        'model_type',
        'model_id',
        'name',
        'collection',
        'description',
        'icon',
        'color',
        'is_protected',
        'password',
        'is_hidden',
        'is_favorite',
        'is_public',
        'has_user_access',
        'user_id',
        'user_type',
    ];

    protected $casts = [
        'is_protected' => 'boolean',
        'is_hidden' => 'boolean',
        'is_favorite' => 'boolean',
        'is_public' => 'boolean',
        'has_user_access' => 'boolean'
    ];

    public function model()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->morphTo();
    }

    public function users()
    {
        return $this->morphedByMany(User::class, 'model', 'folder_has_models', 'folder_id', 'model_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('user', function ($query) {
            if (filament('filament-media-manager')->allowUserAccess && auth()->check()) {
                $query
                    ->where('user_id', auth()->id())
                    ->orWhere('is_public', false)
                    ->where('has_user_access', true)
                    ->whereHas('users', function ($query) {
                        $query->where('model_id', auth()->id())
                            ->where('model_type', get_class(auth()->user()));
                    })
                    ->orWhere('is_public', true);
            }
        });
    }

    public function folders()
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function getTitle(): ?string
    {
        if ($this->model_type && $this->model_id) {
            $modelClass = $this->model_type;
            $model = $modelClass::find($this->model_id);

            if ($model) {
                if (method_exists($model, 'getTitle')) {
                    return $model->getTitle();
                } elseif (isset($model->title)) {
                    return $model->title;
                } elseif (isset($model->name)) {
                    return $model->name;
                } elseif (method_exists($model, 'getName')) {
                    return $model->getName();
                }
            }
        }

        return $this->file_name ?? null;
    }
}
