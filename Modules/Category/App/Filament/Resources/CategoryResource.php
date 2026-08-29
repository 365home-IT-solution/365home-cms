<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use Modules\Category\App\Filament\Resources\CategoryResource\Forms\CategoryForm;
use Modules\Category\App\Filament\Resources\CategoryResource\RelationManagers\ChildrenRelationManager;
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

    // Resource này chỉ còn quản lý chi nhánh/khu vực (category_type=product) — danh mục bài viết
    // (category_type=post) đã tách sang PostCategoryResource riêng, không còn lẫn ở đây nữa.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->where('category_type', 'product');
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allowedProductCategoryIds = $user->allowedCategoryIds();
        $visibleProductIds         = static::visibleProductCategoryIds($user, $allowedProductCategoryIds);

        // Chi nhánh (category_type='product') + khu vực con: mặc định thấy TOÀN BỘ chi nhánh
        // của đối tác mình — không còn bắt buộc phải được cấp quyền chi nhánh cụ thể qua
        // user_branch_permissions như trước (đó là nguyên nhân đối tác mới tạo không thấy
        // chi nhánh/phòng nào). Nếu user được gán quyền chi nhánh cụ thể thì thu hẹp thêm
        // theo đó (dùng để giới hạn 1 nhân viên chỉ thấy 1/vài chi nhánh trong số của đối tác).
        return $query->whereIn('id', $visibleProductIds);
    }

    /**
     * Id chi nhánh (partner_id đáng tin cậy — luôn set đúng lúc tạo/sửa chi nhánh) + toàn bộ khu
     * vực con (mọi cấp, đệ quy theo parent_id). KHÔNG lọc khu vực con bằng cột partner_id của
     * chính nó — cột đó có thể lệch/null với dữ liệu tạo trước khi CategoryObserver cascade
     * partner_id xuống tới cấp con (xem CategoryObserver::saved()), lọc trực tiếp sẽ ẩn mất khu
     * vực con của 1 chi nhánh hợp lệ (chi nhánh cha hiện đúng nhưng danh sách con trống/thiếu).
     */
    private static function visibleProductCategoryIds($user, array $allowedProductCategoryIds): array
    {
        $branchIds = Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->where('partner_id', $user->partner_id)
            ->pluck('id')
            ->toArray();

        if (! empty($allowedProductCategoryIds)) {
            $branchIds = array_values(array_intersect($branchIds, $allowedProductCategoryIds));
        }

        $visibleIds   = $branchIds;
        $currentLevel = $branchIds;
        while (! empty($currentLevel)) {
            $children = Category::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            if (empty($children)) {
                break;
            }
            $visibleIds   = array_merge($visibleIds, $children);
            $currentLevel = $children;
        }

        return array_unique($visibleIds);
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
            ChildrenRelationManager::class,
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
