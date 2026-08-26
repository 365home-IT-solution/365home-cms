<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use Modules\Category\App\Filament\Resources\PostCategoryResource\Forms\PostCategoryForm;
use Modules\Category\App\Filament\Resources\PostCategoryResource\Tables\PostCategoryTable;
use Modules\Category\App\Filament\Resources\PostCategoryResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Category\Entities\Category;

class PostCategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-document-text';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Nội dung & Marketing';
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function getNavigationLabel(): string
    {
        return 'Danh mục bài viết';
    }

    public static function getModelLabel(): string
    {
        return 'Danh mục bài viết';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Danh mục bài viết';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    // Trước đây danh mục bài viết (category_type=post) nằm chung 1 resource với chi nhánh/khu
    // vực (category_type=product) qua tab "Bài viết" ở CategoryResource — nay tách hẳn ra đây để
    // không lẫn với chi nhánh. Quyền xem theo đúng logic cũ: allowedPostCategoryIds() (gán trực
    // tiếp qua Phân quyền Chi nhánh + đệ quy con cháu + name-matching từ chi nhánh product).
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->where('category_type', 'post');
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allowedPostCategoryIds = $user->allowedPostCategoryIds();

        if (empty($allowedPostCategoryIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $allowedPostCategoryIds);
    }

    public static function form(Form $form): Form
    {
        return PostCategoryForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PostCategoryTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPostCategory::route('/'),
            'edit'  => Pages\EditPostCategory::route('/{record}/edit'),
        ];
    }
}
