<?php

declare(strict_types=1);

namespace Modules\Menu\Livewire;

use Illuminate\Support\Str;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Modules\Menu\Entities\Menu;
use Modules\Menu\Enums\LinkTarget;
use Modules\Page\Entities\Page;

class CreateCustomLink extends Component implements HasForms
{
    use InteractsWithForms;

    public Menu $menu;

    public ?string $title = null;

    public ?string $url = null;

    public string $target = LinkTarget::Self->value;

    public ?string $page_id = null;

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string'],
            'url' => ['required', 'string'],
            'target' => ['required', 'string', Rule::in(LinkTarget::cases())],
            'page_id' => ['required', 'integer', 'exists:pages,id'],
        ]);

        $this->menu
            ->menuItems()
            ->create([
                'title' => $this->title,
                'url' => $this->url,
                'target' => $this->target,
                'order' => $this->menu->menuItems->max('order') + 1,
                'page_id' => $this->page_id
            ]);

        Notification::make()
            ->title(__('menu::menu-builder.notifications.created.title'))
            ->success()
            ->send();

        $this->reset('title', 'url', 'target', 'page_id');
        $this->dispatch('menu:created');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')
                    ->label(__('menu::menu-builder.form.title'))
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                        // if (($get('url') ?? '') !== Str::slug($old)) {
                        //     return;
                        // }
                        $set('url', '/' . Str::slug($state));
                    })
                    ->required(),
                TextInput::make('url')
                    ->label(__('menu::menu-builder.form.url'))
                    ->required()
                    ->prefix('/')
                    ->afterStateUpdated(function (Set $set, ?string $state) {
                        $set('url', '/' . ltrim($state, '/'));
                    }),
                Select::make('page_id')
                    ->label('Chọn trang')
                    ->required()
                    ->options(Page::all()->pluck('title', 'id'))
                    ->searchable(),
                Select::make('target')
                    ->label(__('menu::menu-builder.open_in.label'))
                    ->options(LinkTarget::class)
                    ->default(LinkTarget::Self)
                    ->searchable()
            ]);
    }

    public function render(): View
    {
        return view('menu::livewire.create-custom-link');
    }
}
