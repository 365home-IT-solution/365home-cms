<?php

namespace TomatoPHP\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Builder;

class Media extends \Spatie\MediaLibrary\MediaCollections\Models\Media
{
    protected static function booted(): void
    {
        static::addGlobalScope('folder', function (Builder $query) {
            $folder = Folder::find(session()->get('folder_id'));
            if($folder){
                if(!$folder->model_type){
                    $query->where('collection_name', $folder->collection);
                }
                else {
                    $query
                        ->where('model_type', $folder->model_type)
                        ->where('model_id', $folder->model_id)
                        ->where('collection_name', $folder->collection);
                }
            }
        });
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