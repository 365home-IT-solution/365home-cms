<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Categorizable;
use Modules\Post\Entities\Post;
use Modules\Product\App\Models\Product;
use Modules\Tag\App\Filament\Resources\TagResource\Forms\TagForm;
use Modules\Tag\App\Filament\Resources\TagResource\Tables\TagTable;
use Modules\Tag\App\Filament\Resources\TagResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use App\Models\Tag;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    public static function getNavigationIcon(): string
    {
        return __('tag::tag.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('tag::tag.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('tag::tag.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('tag::tag.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tag::tag.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->seesAllPartners()) {
            return $query;
        }

        // tags.partner_id: NULL = tiện ích mẫu hệ thống, có giá trị = tiện ích đối tác tự thêm — đối
        // tác không thấy tiện ích riêng của đối tác khác.
        $query->usableBy($user->partner_id);

        $allowedCategoryIds = $user->allowedCategoryIds();

        // Tag là dữ liệu dùng chung (không có partner_id) — chưa gán quyền chi nhánh cụ thể thì
        // vẫn thấy toàn bộ tag thay vì bị chặn hoàn toàn.
        if (empty($allowedCategoryIds)) {
            return $query;
        }

        // Tìm product_id thuộc các chi nhánh được phép
        $productIds = Categorizable::where('categorizable_type', Product::class)
            ->whereIn('category_id', $allowedCategoryIds)
            ->distinct()
            ->pluck('categorizable_id');

        // Tiện ích riêng của chính đối tác luôn hiện (kể cả chưa gắn phòng nào), để quản lý được.
        if ($productIds->isEmpty()) {
            return $query->where('partner_id', $user->partner_id);
        }

        // Tìm tag_id được gắn với các product đó
        $tagIds = DB::table('taggables')
            ->where('taggable_type', Product::class)
            ->whereIn('taggable_id', $productIds)
            ->distinct()
            ->pluck('tag_id');

        return $query->where(fn (Builder $q) => $q->whereIn('id', $tagIds)->orWhere('partner_id', $user->partner_id));
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return parent::canEdit($record) && \Modules\Tag\App\Filament\Resources\TagResource\Tables\Actions\TagAction::editable($record);
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return parent::canDelete($record) && \Modules\Tag\App\Filament\Resources\TagResource\Tables\Actions\TagAction::editable($record);
    }

    public static function form(Form $form): Form
    {
        return TagForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return TagTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTag::route('/'),
        ];
    }
}
