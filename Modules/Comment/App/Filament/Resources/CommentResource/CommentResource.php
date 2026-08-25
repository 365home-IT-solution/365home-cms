<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentResource;

use Filament\Resources\Resource;
use Modules\Comment\Entities\Comment;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    // Nhóm "Nội dung" đang ẩn tạm khỏi menu — bật lại bằng cách xoá method này.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationIcon(): string
    {
        return __('comment::comment-main.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('comment::comment-main.resource.navigation_group');
    }


    public static function getNavigationLabel(): string
    {
        return __('comment::comment-main.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('comment::comment-main.resource.model_label');
    }

    public static function getPluraModelLabel(): string
    {
        return __('comment::comment-main.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\StatisticalComment::route('/'),
            'create' => Pages\CreateComment::route('/create'),
            'edit' => Pages\EditComment::route('/{record}/edit'),
        ];
    }
}
