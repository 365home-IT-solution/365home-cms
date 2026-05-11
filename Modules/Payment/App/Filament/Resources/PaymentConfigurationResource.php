<?php

namespace Modules\Payment\App\Filament\Resources;

use Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Forms\PaymentConfigurationForm;
use Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Tables\PaymentConfigurationTable;
use Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Payment\Entities\PaymentConfiguration;

class PaymentConfigurationResource extends Resource
{
    protected static ?string $model = PaymentConfiguration::class;

    public static function getNavigationIcon(): string
    {
        return __('payment::payment-configuration.resource.navigation_icon');
    }
    public static function getNavigationGroup(): ?string
    {
        return __('payment::payment-configuration.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('payment::payment-configuration.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('payment::payment-configuration.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment::payment-configuration.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_payment::configuration') ?? false;
    }

    public static function form(Form $form): Form
    {
        return PaymentConfigurationForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PaymentConfigurationTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentConfiguration::route('/'),
            'create' => Pages\CreatePaymentConfiguration::route('/create'),
            'edit' => Pages\EditPaymentConfiguration::route('/{record}/edit'),
        ];
    }
}