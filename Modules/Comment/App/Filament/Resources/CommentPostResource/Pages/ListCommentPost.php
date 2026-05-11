<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentPostResource\Pages;

use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Comment\App\Filament\Resources\CommentPostResource\CommentPostResource;

class ListCommentPost extends ListRecords
{
    protected static string $resource = CommentPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make('Bình luận bài viết')
                ->button()
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('primary'),
        ];
    }

    public function getTabs(): array
    {
        return [
            null => Tab::make('Tất cả'),
            'on' => Tab::make('Hiển thị')
                ->icon('heroicon-o-eye')
                ->query(fn ($query) => $query->where('show', true)),
            'off' => Tab::make('Ẩn')
                ->icon('heroicon-o-eye-slash')
                ->query(fn ($query) => $query->where('show', false)),
            'replies' => Tab::make('Có phản hồi')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->query(fn (Builder $query) => $query->whereHas('replies')),
        ];
    }

}
