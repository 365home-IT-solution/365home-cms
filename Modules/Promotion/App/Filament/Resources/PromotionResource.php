<?php

declare(strict_types=1);

namespace Modules\Promotion\App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Categorizable;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Promotion\App\Filament\Resources\PromotionResource\Forms\PromotionForm;
use Modules\Promotion\App\Filament\Resources\PromotionResource\Tables\PromotionTable;
use Modules\Promotion\App\Filament\Resources\PromotionResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Promotion\App\Models\Promotion;
class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    public static function getNavigationIcon(): string
    {
        return __('promotion::promotion.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('promotion::promotion.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('promotion::promotion.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('promotion::promotion.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('promotion::promotion.resource.plural_model_label');
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

        $allowedCategoryIds = $user->allowedCategoryIds();

        if (empty($allowedCategoryIds)) {
            return $query->whereRaw('1 = 0');
        }

        // Tìm product_id thuộc các chi nhánh được phép
        $productIds = Categorizable::where('categorizable_type', Product::class)
            ->whereIn('category_id', $allowedCategoryIds)
            ->distinct()
            ->pluck('categorizable_id');

        if ($productIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        // Tìm room_time_slot_id của các phòng đó
        $roomTimeSlotIds = RoomTimeSlot::whereIn('room_id', $productIds)
            ->distinct()
            ->pluck('id');

        if ($roomTimeSlotIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        // Tìm promotion_id gắn với các room_time_slot đó
        $promotionIds = DB::table('promotion_room_time_slot')
            ->whereIn('room_time_slot_id', $roomTimeSlotIds)
            ->distinct()
            ->pluck('promotion_id');

        if ($promotionIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $promotionIds);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_promotion') ?? false;
    }

    public static function form(Form $form): Form
    {
        return PromotionForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PromotionTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotion::route('/'),
            'create' => Pages\CreatePromotion::route('/create'),
            'edit' => Pages\EditPromotion::route('/{record}/edit'),
        ];
    }
}
