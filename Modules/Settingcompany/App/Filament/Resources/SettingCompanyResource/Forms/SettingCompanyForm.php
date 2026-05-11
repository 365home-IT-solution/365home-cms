<?php

declare(strict_types=1);

namespace Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Forms;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;

class SettingCompanyForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('settingcompany::setting_company.form.sections.general_information'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('settingcompany::setting_company.form.label.name'))
                            ->placeholder(__('settingcompany::setting_company.form.placeholder.name'))
                            ->rules(['required', 'string', 'max:255']),
                        TextInput::make('address')
                            ->label(__('settingcompany::setting_company.form.label.address'))
                            ->placeholder(__('settingcompany::setting_company.form.placeholder.address'))
                            ->rules(['required', 'string', 'max:255']),
                        TextInput::make('phone')
                            ->label(__('settingcompany::setting_company.form.label.phone'))
                            ->placeholder(__('settingcompany::setting_company.form.placeholder.address')),
                        TextInput::make('email')
                            ->label(__('settingcompany::setting_company.form.label.email'))
                            ->placeholder(__('settingcompany::setting_company.form.placeholder.email'))
                            ->rules(['required', 'email']),
                        TextInput::make('website')
                            ->label(__('settingcompany::setting_company.form.label.website'))
                            ->placeholder(__('settingcompany::setting_company.form.placeholder.website'))
                            ->nullable()
                            ->rules(['nullable', 'url']),
                    ])
                    ->columns(2),
                Section::make(__('settingcompany::setting_company.form.sections.additional_information'))
                    ->schema([
                        TextInput::make('tax_code')
                            ->label(__('settingcompany::setting_company.form.label.tax_code'))
                            ->placeholder(__('settingcompany::setting_company.form.placeholder.tax_code'))
                            ->nullable()
                            ->rules(['nullable', 'string', 'max:50']),
                        Textarea::make('description')
                            ->label(__('settingcompany::setting_company.form.label.description'))
                            ->placeholder(__('settingcompany::setting_company.form.placeholder.description'))
                            ->nullable(),
                        FileUpload::make('image')
                            ->label('Ảnh bộ công thương (nếu có)')
                            ->image()
                            ->imageEditor()
                            ->directory('InfomationCompany')
                            ->imagePreviewHeight('100')
                            ->maxSize(1024)
                            ->nullable()
                            ->hint('Tải lên hình ảnh xác thực từ Bộ Công Thương nếu có'),
                    ])
                    ->columns(1),
            ]);
    }
}
