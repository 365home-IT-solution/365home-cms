<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class ContactLinkField extends BaseField
{
    protected function getContactBlocks(): array
    {
        return [
            $this->getHotlineBlock(),
            $this->getZaloBlock(),
            $this->getMessengerBlock(),
            $this->getEmailBlock()
        ];
    }

    protected function getCommonToggle(): Toggle
    {
        return Toggle::make('visible')
            ->label('Hiển thị')
            ->default(true)
            ->inline(false)
            ->onIcon('heroicon-o-eye')
            ->offIcon('heroicon-o-eye-slash');
    }

    protected function getCommonIconUpload(): FileUpload
    {
        return  FileUpload::make('icon')
            ->label('Icon tùy chỉnh')
            ->image()
            ->imageEditor()
            ->directory('contact-icons')
            ->maxSize(1024) // 1MB
            ->acceptedFileTypes(['image/png', 'image/jpeg'])
            ->columnSpanFull();
    }

    protected function getHotlineBlock(): Builder\Block
    {
        return Builder\Block::make('hotline')
            ->label('Hotline')
            ->icon('heroicon-o-phone')
            ->schema([
                Grid::make()
                    ->schema([
                        TextInput::make('value')
                            ->label('Số điện thoại')
                            ->placeholder('0123456789')
                            ->tel()
                            ->regex('/^[0-9]+$/')
                            ->helperText('Nhập số điện thoại không khoảng trắng')
                            ->columnSpan(2),

                        $this->getCommonToggle(),
                        $this->getCommonIconUpload(),
                    ])
                    ->columns(3),
            ]);
    }

    protected function getZaloBlock(): Builder\Block
    {
        return Builder\Block::make('zalo')
            ->label('Zalo')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->schema([
                Grid::make()
                    ->schema([
                        TextInput::make('value')
                            ->label('Zalo ID/Số điện thoại')
                            ->placeholder('zalo.me/username hoặc số điện thoại')
                            ->helperText('Nhập Zalo ID hoặc số điện thoại')
                            ->columnSpan(2),

                        $this->getCommonToggle(),
                        $this->getCommonIconUpload(),
                    ])
                    ->columns(3),
            ]);
    }

    protected function getMessengerBlock(): Builder\Block
    {
        return Builder\Block::make('messenger')
            ->label('Facebook Messenger')
            ->icon('heroicon-o-chat-bubble-oval-left-ellipsis')
            ->schema([
                Grid::make()
                    ->schema([
                        TextInput::make('value')
                            ->label('Facebook Page ID')
                            ->placeholder('ID của Facebook Page')
                            ->helperText('Nhập Facebook Page ID để kích hoạt chat messenger')
                            ->columnSpan(2),

                        $this->getCommonToggle(),
                        $this->getCommonIconUpload(),
                    ])
                    ->columns(3),
            ]);
    }

    protected function getEmailBlock(): Builder\Block
    {
        return Builder\Block::make('email')
            ->label('Email')
            ->icon('heroicon-o-envelope')
            ->schema([
                Grid::make()
                    ->schema([
                        TextInput::make('value')
                            ->label('Địa chỉ email')
                            ->placeholder('example@domain.com')
                            ->email()
                            ->helperText('Nhập địa chỉ email hợp lệ')
                            ->columnSpan(2),

                        $this->getCommonToggle(),
                        $this->getCommonIconUpload(),
                    ])
                    ->columns(3),
            ]);
    }

    public function create(): Component
    {
        return $this->addCommonAttributes(
            Builder::make("config.{$this->config->key}")
                ->label($this->config->label)
                ->blockIcons(true)
                ->blocks($this->getContactBlocks())
                ->addActionLabel('Thêm nút liên hệ')
                ->reorderable(true)
                ->collapsible()
                ->collapsed(true)
                ->blockNumbers(false)
                ->maxItems(5)
        );
    }
}
