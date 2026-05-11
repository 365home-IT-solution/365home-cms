<?php

declare(strict_types=1);

namespace Modules\QA\App\Filament\Resources;

use Modules\QA\App\Filament\Resources\QAResource\Forms\QAForm;
use Modules\QA\App\Filament\Resources\QAResource\Tables\QATable;
use Modules\QA\App\Filament\Resources\QAResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\QA\App\Models\QA;

class QAResource extends Resource
{
    protected static ?string $model = QA::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';
    
    protected static ?string $navigationLabel = 'Quáº£n lÃ½ Q&A';

    public static function getNavigationLabel(): string
    {
        return 'QA';
    }

    public static function getModelLabel(): string
    {
        return 'QA';
    }

    public static function getPluralModelLabel(): string
    {
        return 'QA';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }
    
    public static function form(Form $form): Form
    {
        return QAForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return QATable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQA::route('/'),
            'create' => Pages\CreateQA::route('/create'),
            'edit' => Pages\EditQA::route('/{record}/edit'),
        ];
    }
}
