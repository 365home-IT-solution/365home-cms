<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentProductResource;

use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Comment\App\Filament\Resources\CommentProductResource\Forms\CommentProductForm;
use Modules\Comment\App\Filament\Resources\CommentProductResource\Tables\Actions\CommentProductInfolist;
use Modules\Comment\App\Filament\Resources\CommentProductResource\Tables\CommentProductTable;
use Modules\Comment\Entities\Comment;

class CommentProductResource extends Resource
{
    protected static ?string $model = Comment::class;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationIcon(): string
    {
        return __('comment::comment-product.resource.navigation_icon');
    }
    public static function getNavigationGroup(): ?string
    {
        return __('comment::comment-product.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('comment::comment-product.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('comment::comment-product.resource.model_label');
    }

    public static function getPluraModelLabel(): string
    {
        return __('comment::comment-product.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('commentable_type', __('comment::comment-product.resource.commentable_type'))->count();
    }

    public static function form(Form $form): Form
    {
        return CommentProductForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return CommentProductTable::table($table);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return CommentProductInfolist::infolist($infolist);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommentProducts::route('/'),
            'create' => Pages\CreateCommentProduct::route('/create'),
            'view' => Pages\ViewCommentProduct::route('/{record}'),
            'edit' => Pages\EditCommentProduct::route('/{record}/edit'),
        ];
    }
}
