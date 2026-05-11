<?php

declare(strict_types=1);

namespace Modules\Menu\App\Filament\Resources\MenuResource\Pages;

use Filament\Actions;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Modules\Menu\App\Filament\FilamentMenuBuilderPlugin;
use Modules\Menu\Concerns\HasLocationAction;

class EditMenu extends EditRecord
{
    use HasLocationAction;

    protected static string $view = 'menu::edit-record';

    public static function getResource(): string
    {
        return FilamentMenuBuilderPlugin::get()->getResource();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()->schema($form->getComponents()),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            $this->getLocationAction(),
        ];
    }
}
