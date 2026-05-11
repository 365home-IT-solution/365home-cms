<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Enums;

use Filament\Forms\Components\Component;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\ColorPickerField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\ContactLinkField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\Footers\FooterColumnField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\FormSelectionField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\HeaderContactField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\MenuSelectionField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\NumberField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\SelectField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\SocialMedia\SocialMediaField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\TextareaField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\TextField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\TextStyleSelectorField;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\ToggleField;

enum FieldInputType: string
{
    case TEXT = 'Text input field';
    case TEXTAREA = 'Textarea field';
    case TOGGLE = 'Toggle field';
    case SELECT = 'Select field';
    case COLOR = 'Color picker field';
    case NUMBER = 'Number input field';
    case FOOTER = 'Footer widget';
    case TEXT_STYLE = 'Text style selector field';

    case HEADER_CONTACTS = 'Header contacts field';

    case SOCIAL_LINKS = 'Social links field';
    case FORM_SELECTION = 'Form selection field';
    case MENU_SELECTION = 'Menu selection field';
    case CONTACT_LINK = 'Contact link field';

    public function createField($config): Component
    {

        return match ($this) {

            self::TEXT => (new TextField($config))->create(),

            self::NUMBER => (new NumberField($config))->create(),

            self::TEXTAREA => (new TextareaField($config))->create(),

            self::TOGGLE => (new ToggleField($config))->create(),

            self::SELECT => (new SelectField($config))->create(),

            self::COLOR => (new ColorPickerField($config))->create(),

            self::FOOTER => (new FooterColumnField($config))->create(),

            self::TEXT_STYLE => (new TextStyleSelectorField($config))->create(),

            self::HEADER_CONTACTS => (new HeaderContactField($config))->create(),

            self::SOCIAL_LINKS => (new SocialMediaField($config))->create(),

            self::FORM_SELECTION => (new FormSelectionField($config))->create(),

            self::MENU_SELECTION => (new MenuSelectionField($config))->create(),

            self::CONTACT_LINK => (new ContactLinkField($config))->create(),
        };
    }

    public function getDataType(): ConfigDataType
    {
        return match ($this) {
            self::NUMBER => ConfigDataType::NUMBER,

            self::TOGGLE => ConfigDataType::BOOLEAN,

            self::CONTACT_LINK,
            self::MENU_SELECTION,
            self::FOOTER,
            self::HEADER_CONTACTS => ConfigDataType::ARRAY,

            default => ConfigDataType::STRING,
        };
    }

    public static function fromString(string $type): self
    {
        return match ($type) {
            'Textarea field' => self::TEXTAREA,
            'Toggle field' => self::TOGGLE,
            'Select field' => self::SELECT,
            'Color picker field' => self::COLOR,
            'Number input field' => self::NUMBER,
            'Footer widget' => self::FOOTER,
            'Text style selector field' => self::TEXT_STYLE,
            'Header contacts field' => self::HEADER_CONTACTS,
            'Social links field' => self::SOCIAL_LINKS,
            'Form selection field' => self::FORM_SELECTION,
            'Menu selection field' => self::MENU_SELECTION,
            'Contact link field' => self::CONTACT_LINK,
            default => self::TEXT
        };
    }
}
