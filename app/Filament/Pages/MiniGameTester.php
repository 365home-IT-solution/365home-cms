<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\GameTesterSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

// THỬ MINI GAME — nhúng trang /game-tester (thẻ cào, mảnh ghép, giới thiệu, ví voucher, Tiến lên) ngay trong CMS.
// Chỉ Super Admin. Công tắc ở đây là công tắc chung: tắt ⇒ /game-tester, /tien-len-tester và API /api/admin/games/dev/* đều 404.
class MiniGameTester extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Quản lý API';

    protected static ?string $navigationLabel = 'Thử mini game';

    protected static ?string $title = 'Thử mini game';

    protected static ?string $slug = 'mini-game-tester';

    protected static ?int $navigationSort = 38;

    protected static string $view = 'filament.pages.mini-game-tester';

    public bool $enabled = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        $this->enabled = GameTesterSetting::enabled();
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tienLen')
                ->label('Mở Tiến lên')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => route('tien-len-tester'), shouldOpenInNewTab: true)
                ->visible(fn () => $this->enabled),

            Action::make('newTab')
                ->label('Mở tab mới')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => route('game-tester'), shouldOpenInNewTab: true)
                ->visible(fn () => $this->enabled),

            Action::make('toggle')
                ->label(fn () => $this->enabled ? 'Tắt trang thử' : 'Bật trang thử')
                ->icon(fn () => $this->enabled ? 'heroicon-o-pause' : 'heroicon-o-play')
                ->color(fn () => $this->enabled ? 'danger' : 'primary')
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->enabled
                    ? 'Trang /game-tester, /tien-len-tester và API công cụ thử sẽ trả 404.'
                    : 'Chỉ Super Admin đã đăng nhập CMS mới mở được trang thử.')
                ->action(function (): void {
                    $this->enabled = ! $this->enabled;
                    GameTesterSetting::current()->update(['is_enabled' => $this->enabled]);
                    Notification::make()->title($this->enabled ? 'Đã bật trang thử mini game' : 'Đã tắt trang thử mini game')->success()->send();
                }),
        ];
    }
}
