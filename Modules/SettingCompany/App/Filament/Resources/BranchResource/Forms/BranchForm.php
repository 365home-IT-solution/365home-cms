<?php

declare(strict_types=1);

namespace Modules\SettingCompany\App\Filament\Resources\BranchResource\Forms;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Modules\SettingCompany\Entities\Business;

class BranchForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('settingcompany::branch.form.sections.company_info'))
                    ->schema([
                        Select::make('business_id')
                            ->relationship('business', 'name')
                            ->default(fn () => Business::first()?->id)
                            ->label(__('settingcompany::branch.form.label.business.label'))
                            ->placeholder(__('settingcompany::branch.form.label.business.placeholder'))
                            ->required()
                    ])
                    ->columns(1),
                Section::make(__('settingcompany::branch.form.sections.branch_info'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('settingcompany::branch.form.label.name.label'))
                            ->placeholder(__('settingcompany::branch.form.label.name.placeholder'))
                            ->required()
                            ->rules(['string', 'max:255']),
                        TextInput::make('address')
                            ->label(__('settingcompany::branch.form.label.address.label'))
                            ->placeholder(__('settingcompany::branch.form.label.address.placeholder'))
                            ->required()
                            ->rules(['string', 'max:255']),
                        TextInput::make('phone')
                            ->label(__('settingcompany::branch.form.label.phone.label'))
                            ->placeholder(__('settingcompany::branch.form.label.phone.placeholder'))
                            ->required(),
                        TextInput::make('email')
                            ->label(__('settingcompany::branch.form.label.email.label'))
                            ->placeholder(__('settingcompany::branch.form.label.email.placeholder'))
                            ->required()
                            ->rules(['email']),
                        Select::make('status')
                            ->label(__('settingcompany::branch.form.label.status.label'))
                            ->options([
                                '1' => __('settingcompany::branch.form.label.status.options.active'),
                                '0' => __('settingcompany::branch.form.label.status.options.inactive'),
                            ])
                            ->required()
                    ])
                    ->columns(2),
            ]);
    }
}
