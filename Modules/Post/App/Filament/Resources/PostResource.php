<?php

declare(strict_types=1);

namespace Modules\Post\App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use Modules\Post\App\Filament\Resources\PostResource\Forms\PostForm;
use Modules\Post\App\Filament\Resources\PostResource\Tables\PostTable;
use Modules\Post\App\Filament\Resources\PostResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Post\Entities\Post;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;
    
    public static function getNavigationIcon(): string
    {
        return __('post::post.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('post::post.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('post::post.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('post::post.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('post::post.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allowedPostCategoryIds = $user->allowedPostCategoryIds();

        if (empty($allowedPostCategoryIds)) {
            return $query->whereRaw('1 = 0');
        }

        // Lọc bài viết thuộc danh mục ứng với chi nhánh được phép
        return $query->whereHas('categories', function (Builder $q) use ($allowedPostCategoryIds) {
            $q->whereIn('categories.id', $allowedPostCategoryIds);
        });
    }

    public static function form(Form $form): Form
    {
        return PostForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PostTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
