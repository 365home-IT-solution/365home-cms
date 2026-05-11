<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\IconPosition;

class HeaderContactField extends BaseField
{
    protected const CONTACT_TYPES = [
        'phone' => 'Số điện thoại',
        'email' => 'Email',
        'address' => 'Địa chỉ',
        'hotline' => 'Hotline',
        'working_hours' => 'Giờ làm việc',
        'website' => 'Website',
        'custom' => 'Tùy chỉnh'
    ];

    public function create(): \Filament\Forms\Components\Component
    {
        return $this->addCommonAttributes(
            Repeater::make("config.{$this->config->key}")
                ->label($this->config->label)
                ->schema([
                    Grid::make()
                        ->schema([
                            $this->createContactTypeField(),
                            $this->createContactKeyField(),
                            $this->createContactValueField(),
                        ])
                        ->columns(12)
                ])
                ->columnSpanFull()
                ->collapsible()
                ->collapsed()
                ->itemLabel(fn(array $state): ?string =>
                isset($state['contact_type']) && isset(self::CONTACT_TYPES[$state['contact_type']])
                    ? self::CONTACT_TYPES[$state['contact_type']] . ': ' . ($state['contact_value'] ?? 'Chưa có giá trị')
                    : 'Liên hệ mới'
                )
                ->addActionLabel('Thêm thông tin liên hệ')
                ->reorderableWithButtons()
                ->defaultItems(0)
                ->maxItems(10)
                ->columnSpanFull()
                ->cloneable()
                ->deleteAction(
                    fn ($action) => $action
                        ->icon('heroicon-m-trash')
                        ->iconPosition(IconPosition::After)
                        ->size(ActionSize::Small)
                )
                ->reorderAction(
                    fn ($action) => $action
                        ->icon('heroicon-m-arrows-up-down')
                        ->iconPosition(IconPosition::After)
                        ->size(ActionSize::Small)
                )
                ->cloneAction(
                    fn ($action) => $action
                        ->icon('heroicon-m-square-2-stack')
                        ->iconPosition(IconPosition::After)
                        ->size(ActionSize::Small)
                )
        );
    }

    private function createContactTypeField(): Select
    {
        return Select::make('contact_type')
            ->label('Loại liên hệ')
            ->options(self::CONTACT_TYPES)
            ->default('custom')
            ->required()
            ->live()
            ->prefixIcon(fn (Get $get): string => match ($get('contact_type') ?: '') {
                'phone' => 'heroicon-o-phone',
                'email' => 'heroicon-o-envelope',
                'address' => 'heroicon-o-map-pin',
                'hotline' => 'heroicon-o-phone-arrow-up-right',
                'working_hours' => 'heroicon-o-clock',
                'website' => 'heroicon-o-globe-alt',
                default => 'heroicon-o-plus',
            })
            ->afterStateUpdated(function (Get $get, Set $set, $state) {
                if (!$state || $state === 'custom') {
                    $set('contact_key', '');
                    return;
                }

                if (isset(self::CONTACT_TYPES[$state])) {
                    $set('contact_key', self::CONTACT_TYPES[$state]);
                }
            })
            ->columnSpan(4);
    }

    private function createContactKeyField(): TextInput
    {
        return TextInput::make('contact_key')
            ->label('Tên hiển thị')
            ->placeholder('Ví dụ: Hotline hỗ trợ 24/7')
            ->maxLength(50)
            ->columnSpan(3)
            ->disabled(fn (Get $get) => $get('contact_type') && $get('contact_type') !== 'custom')
            ->helperText(fn (Get $get) =>
            $get('contact_type') && $get('contact_type') !== 'custom'
                ? 'Tên được tự động điền theo loại liên hệ'
                : 'Nhập tên hiển thị tùy chỉnh'
            );
    }

    private function createContactValueField(): TextInput
    {
        return TextInput::make('contact_value')
            ->label('Giá trị')
            ->columnSpan(5)
            ->placeholder(function (Get $get) {
                return match ($get('contact_type') ?: '') {
                    'phone', 'hotline' => '0xxxxxxxxx',
                    'email' => 'example@domain.com',
                    'address' => 'Địa chỉ chi tiết...',
                    'working_hours' => 'Thứ 2 - Thứ 6: 8:00 - 17:00',
                    'website' => 'https://example.com',
                    default => 'Nhập giá trị...'
                };
            })
            ->rules(function (Get $get) {
                return match ($get('contact_type') ?: '') {
                    'phone', 'hotline' => ['regex:/^[0-9\-\+\s\(\)]{10,20}$/'],
                    'email' => ['email'],
                    'website' => ['url'],
                    default => ['max:255']
                };
            })
            ->helperText(function (Get $get) {
                return match ($get('contact_type') ?: '') {
                    'phone', 'hotline' => 'Định dạng: 0xxxxxxxxx hoặc +84xxxxxxxxx',
                    'email' => 'Định dạng email hợp lệ',
                    'website' => 'URL đầy đủ bắt đầu bằng http:// hoặc https://',
                    default => null
                };
            });
    }
}
