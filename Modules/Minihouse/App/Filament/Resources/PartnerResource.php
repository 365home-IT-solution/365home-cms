<?php

namespace Modules\Minihouse\App\Filament\Resources;

use App\Filament\Resources\PartnerResource\Forms\PartnerForm;
use App\Filament\Resources\PartnerResource\RelationManagers\LegalDocumentsRelationManager;
use App\Filament\Resources\PartnerResource\Tables\PartnerTable;
use App\Models\Partner;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Minihouse\App\Filament\Resources\PartnerResource\Pages;

class PartnerResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Đối tác MiniHouse';

    protected static ?string $modelLabel = 'Đối tác MiniHouse';

    protected static ?string $pluralModelLabel = 'Đối tác MiniHouse';

    protected static ?string $slug = 'partners';

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return PartnerForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PartnerTable::table($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('partner_type', Partner::TYPE_MINIHOUSE);
    }

    public static function getRelations(): array
    {
        return [LegalDocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPartners::route('/'), 'create' => Pages\CreatePartner::route('/create'), 'edit' => Pages\EditPartner::route('/{record}/edit')];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record->partner_type === Partner::TYPE_MINIHOUSE;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }
}
