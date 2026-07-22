<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use Modules\Category\App\Filament\Resources\CategoryResource\Forms\CategoryForm;
use Modules\Category\App\Filament\Resources\CategoryResource\RelationManagers\ProductsRelationManager;
use Modules\Category\App\Filament\Resources\CategoryResource\Tables\CategoryTable;
use Modules\Category\App\Filament\Resources\CategoryResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Modules\Category\Entities\Category;

class CategoryResource extends Resource implements HasKnowledgeBase
{
    public static function getDocumentation(): array
    {
        return [
            'category.getting-started'
        ];
    }

    protected static ?string $model = Category::class;

    public static function getNavigationIcon(): string
    {
        return __('category::category.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('category::category.resource.navigation_group');
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getNavigationLabel(): string
    {
        return __('category::category.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('category::category.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('category::category.resource.plural_model_label');
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

        $allowedProductCategoryIds = $user->allowedCategoryIds();
        $allowedPostCategoryIds    = $user->allowedPostCategoryIds();

        return $query->where(function (Builder $q) use ($user, $allowedProductCategoryIds, $allowedPostCategoryIds) {
            // Chi nhánh (category_type='product'): mặc định thấy TOÀN BỘ chi nhánh của đối tác
            // mình (partner_id) — không còn bắt buộc phải được cấp quyền chi nhánh cụ thể qua
            // user_branch_permissions như trước (đó là nguyên nhân đối tác mới tạo không thấy
            // chi nhánh/phòng nào). Nếu user được gán quyền chi nhánh cụ thể thì thu hẹp thêm
            // theo đó (dùng để giới hạn 1 nhân viên chỉ thấy 1/vài chi nhánh trong số của đối tác).
            $q->where('category_type', 'product')
                ->where('partner_id', $user->partner_id)
                ->when(
                    ! empty($allowedProductCategoryIds),
                    fn (Builder $q2) => $q2->whereIn('id', $allowedProductCategoryIds)
                );

            // Category loại 'post' vẫn dùng chung, giữ nguyên yêu cầu quyền chi nhánh cụ thể
            // như trước (không liên quan tới partner_id).
            if (! empty($allowedPostCategoryIds)) {
                $q->orWhereIn('id', $allowedPostCategoryIds);
            }
        });
    }

    public static function form(Form $form): Form
    {
        return CategoryForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return CategoryTable::table($table);
    }

    public static function getRelationManagers(): array
    {
        return [
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategory::route('/'),
            'edit'  => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
