<?php

declare(strict_types=1);

namespace Modules\Post\App\Filament\Resources\PostResource\Pages;

use Illuminate\Support\Facades\Auth;
use Modules\Post\App\Filament\Resources\PostResource;
use Filament\Resources\Pages\CreateRecord;
use Modules\Category\Entities\Category;

/**
 * @property \Illuminate\Support\Collection $tagsToAttach
 * @property \Illuminate\Support\Collection $categoriesToAttach
 */
class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // dd($data);
        $data['author_id'] = Auth::id();
        $categoryIds = $data['categories'] ?? [];
        $this->categoriesToAttach = Category::whereIn('id', $categoryIds)->get();
        unset($data['categories']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->categories()->sync($this->categoriesToAttach->pluck('id')->toArray());
    }
}
