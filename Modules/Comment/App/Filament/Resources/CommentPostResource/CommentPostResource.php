<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentPostResource;

use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Comment\App\Filament\Resources\CommentPostResource\Tables\Actions\CommentPostInfolist;
use Modules\Comment\App\Filament\Resources\CommentPostResource\Forms\CommentPostForm;
use Modules\Comment\App\Filament\Resources\CommentPostResource\Tables\CommentPostTable;
use Modules\Comment\Entities\Comment;

class CommentPostResource extends Resource
{
    protected static ?string $model = Comment::class;

    // Nhóm "Nội dung" đang ẩn tạm khỏi menu — bật lại bằng cách xoá method này.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationIcon(): string
    {
        return __('comment::comment-post.resource.navigation_icon');
    }
    public static function getNavigationGroup(): ?string
    {
        return __('comment::comment-post.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('comment::comment-post.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('comment::comment-post.resource.model_label');
    }

    public static function getPluraModelLabel(): string
    {
        return __('comment::comment-post.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('commentable_type', __('comment::comment-post.resource.commentable_type'))->count();
    }

    public static function form(Form $form): Form
    {
        return CommentPostForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return CommentPostTable::table($table);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return CommentPostInfolist::infolist($infolist);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommentPost::route('/'),
            'create' => Pages\CreateCommentPost::route('/create'),
            'view' => Pages\ViewCommentPost::route('/{record}'),
            'edit' => Pages\EditCommentPost::route('/{record}/edit'),
        ];
    }
}
