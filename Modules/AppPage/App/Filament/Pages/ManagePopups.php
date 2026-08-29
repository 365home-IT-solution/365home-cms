<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Filament\Pages;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\AppPage\App\Models\PopupImage;

class ManagePopups extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'apppage::filament.pages.manage-popups';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Quản lý API';

    protected static ?string $navigationLabel = 'POPUP';

    protected static ?string $title = 'Quản lý Popup';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'popup';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_ManagePopups') ?? false;
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $images = PopupImage::orderBy('sort_order')
            ->get()
            ->map(fn (PopupImage $item) => [
                'id'    => $item->id,
                'image' => $item->image,
                'url'   => $item->url,
            ])
            ->toArray();

        $this->form->fill(['images' => $images]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Repeater::make('images')
                    ->label('Ảnh popup')
                    ->schema([
                        Hidden::make('id'),

                        FileUpload::make('image')
                            ->label('Ảnh')
                            ->image()
                            ->disk('public')
                            ->directory('popups')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                            ->maxSize(5120)
                            ->imagePreviewHeight('150')
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('url')
                            ->label('URL điều hướng')
                            ->helperText('Dùng cho cả app và website khi bấm vào ảnh (không bắt buộc).')
                            ->placeholder('VD: /rooms/phong-a hoặc myapp://promo/123')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => $state['url'] ?? 'Ảnh popup')
                    ->addActionLabel('Thêm ảnh')
                    ->deleteAction(fn ($action) => $action->requiresConfirmation())
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $items = $this->form->getState()['images'] ?? [];

        $keepIds = [];

        foreach ($items as $index => $item) {
            if (empty($item['image'])) {
                continue;
            }

            $payload = [
                'image'      => $item['image'],
                'url'        => $item['url'] ?: null,
                'sort_order' => $index,
            ];

            $popupImage = ! empty($item['id']) ? PopupImage::find($item['id']) : null;

            if ($popupImage) {
                $popupImage->update($payload);
            } else {
                $popupImage = PopupImage::create($payload);
            }

            $keepIds[] = $popupImage->id;
        }

        PopupImage::whereNotIn('id', $keepIds)->delete();

        Notification::make()
            ->title('Đã lưu danh sách popup')
            ->success()
            ->send();

        $this->fillForm();
    }
}
