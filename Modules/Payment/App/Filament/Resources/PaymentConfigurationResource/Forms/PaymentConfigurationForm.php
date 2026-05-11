<?php

namespace Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Forms;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

class PaymentConfigurationForm
{
    public static function form(Form $form): Form
    {
        return $form
        ->schema([
            Section::make(__('payment::payment-configuration.form.section.title'))
                ->schema([
                    TextInput::make('client_id')
                        ->label(__('payment::payment-configuration.form.label.client_id'))
                        ->prefixIcon(__('payment::payment-configuration.form.prefix_icon.client_id'))
                        ->password()
                        ->revealable()
                        ->required(),
                    TextInput::make('api_key')
                        ->label(__('payment::payment-configuration.form.label.api_key'))
                        ->prefixIcon(__('payment::payment-configuration.form.prefix_icon.api_key'))
                        ->password()
                        ->revealable()
                        ->required(),
                    TextInput::make('checksum_key')
                        ->label(__('payment::payment-configuration.form.label.checksum_key'))
                        ->prefixIcon(__('payment::payment-configuration.form.prefix_icon.checksum_key'))
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->columns(1),
        ]);
    }
}