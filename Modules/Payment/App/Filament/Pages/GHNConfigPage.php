<?php

namespace Modules\Payment\App\Filament\Pages;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Payment\Entities\GHNConfiguration;

class GHNConfigPage extends Page
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('payment::ghn-config.page.navigation_label');
    }
    public static function getNavigationGroup(): ?string
    {
        return __('payment::ghn-config.page.navigation_group');
    }

    protected static ?string $slug = 'ghn-config';
    public static function getNavigationIcon(): string
    {
        return __('payment::ghn-config.page.navigation_icon');
    }
    protected static ?string $title = 'Cấu hình địa chỉ';
    protected static string $view = 'payment::filament.pages.ghn-config-page';

    public $api_token;
    public $client_id;
    public $shop_id;
    public $required_note;
    public $return_phone;
    public $return_address;
    public $return_district_id;
    public $return_ward_code;
    public $from_name;
    public $from_phone;
    public $from_address;
    public $from_ward_name;
    public $from_district_name;
    public $from_province_name;


    public function mount()
    {
        $config = GHNConfiguration::first();

        if ($config) {
            $this->api_token = $config->api_token;
            $this->client_id = $config->client_id;
            $this->shop_id = $config->shop_id;
            $this->required_note = $config->required_note;
            $this->return_phone = $config->return_phone;
            $this->return_address = $config->return_address;
            $this->return_district_id = $config->return_district_id;
            $this->return_ward_code = $config->return_ward_code;
            $this->from_name = $config->from_name;
            $this->from_phone = $config->from_phone;
            $this->from_address = $config->from_address;
            $this->from_ward_name = $config->from_ward_name;
            $this->from_district_name = $config->from_district_name;
            $this->from_province_name = $config->from_province_name;
        }
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make(__('payment::ghn-config.form.section.title'))
                ->schema([
                    TextInput::make('api_token')
                        ->label(__('payment::ghn-config.form.label.api_token'))
                        ->password()
                        ->revealable(),
                    TextInput::make('client_id')
                        ->label(__('payment::ghn-config.form.label.client_id')),
                    TextInput::make('shop_id')
                        ->label(__('payment::ghn-config.form.label.shop_id')),
                    Select::make('required_note')
                        ->label(__('payment::ghn-config.form.label.required_note'))
                        ->options([
                            'CHOTHUHANG' => (__('payment::ghn-config.form.options.required_note.CHOTHUHANG')),
                            'CHOXEMHANGKHONGTHU' => (__('payment::ghn-config.form.options.required_note.CHOXEMHANGKHONGTHU')),
                            'KHONGCHOXEMHANG' => (__('payment::ghn-config.form.options.required_note.KHONGCHOXEMHANG')),
                        ])
                        ->required(),
                    TextInput::make('return_phone')
                        ->label(__('payment::ghn-config.form.label.return_phone'))
                        ->placeholder(__('payment::ghn-config.form.placeholder.return_phone'))
                        ->required(),
                    TextInput::make('return_address')
                        ->label(__('payment::ghn-config.form.label.return_address'))
                        ->placeholder(__('payment::ghn-config.form.placeholder.return_address'))
                        ->required(),
                    TextInput::make('return_district_id')
                        ->label(__('payment::ghn-config.form.label.return_district_id'))
                        ->helperText(__('payment::ghn-config.form.helper_text.return_district_id')),
                    TextInput::make('return_ward_code')
                        ->label(__('payment::ghn-config.form.label.return_ward_code'))
                        ->helperText(__('payment::ghn-config.form.helper_text.return_ward_code')),
                    TextInput::make('from_name')
                        ->label(__('payment::ghn-config.form.label.from_name'))
                        ->placeholder(__('payment::ghn-config.form.placeholder.from_name'))
                        ->required(),
                    TextInput::make('from_phone')
                        ->label(__('payment::ghn-config.form.label.from_phone'))
                        ->placeholder(__('payment::ghn-config.form.placeholder.from_phone'))
                        ->required(),
                    TextInput::make('from_address')
                        ->label(__('payment::ghn-config.form.label.from_address'))
                        ->placeholder(__('payment::ghn-config.form.placeholder.from_address'))
                        ->required(),
                    TextInput::make('from_ward_name')
                        ->label(__('payment::ghn-config.form.label.from_ward_name'))
                        ->placeholder(__('payment::ghn-config.form.placeholder.from_ward_name')),
                    TextInput::make('from_district_name')
                        ->label(__('payment::ghn-config.form.label.from_district_name'))
                        ->placeholder(__('payment::ghn-config.form.placeholder.from_district_name')),
                    TextInput::make('from_province_name')
                        ->label(__('payment::ghn-config.form.label.from_province_name'))
                        ->placeholder(__('payment::ghn-config.form.placeholder.from_province_name')),
                ])
                ->columns(3),
        ];
    }

    public function save()
    {
        GHNConfiguration::updateOrCreate([], [
            'api_token' => $this->api_token,
            'client_id' => $this->client_id,
            'shop_id' => $this->shop_id,
            'required_note' => $this->required_note,
            'return_phone' => $this->return_phone,
            'return_address' => $this->return_address,
            'return_district_id' => $this->return_district_id,
            'return_ward_code' => $this->return_ward_code,
            'from_name' => $this->from_name,
            'from_phone' => $this->from_phone,
            'from_address' => $this->from_address,
            'from_ward_name' => $this->from_ward_name,
            'from_district_name' => $this->from_district_name,
            'from_province_name' => $this->from_province_name,
        ]);
        Notification::make()
            ->title(__('payment::ghn-config.form.notification.saved'))
            ->success()
            ->send();
    }
}
