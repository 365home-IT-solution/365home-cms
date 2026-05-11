<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentPostResource\Forms;

use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Auth;
use Modules\Post\Entities\Post;

class CommentPostForm
{

    public static function form(Form $form): Form
    {
        return $form->schema([
            self::allTabComment(),
            Hidden::make('commentable_type')
                ->label('')
                ->default(__('comment::comment-post.form.hidden.commentable_type'))
                ->columnSpan(12),
            Hidden::make('user_id')
                ->label(__('comment::comment-post.form.label.user_id'))
                ->default(function () {
                    $user = User::find(Auth::id());
                    return $user ? $user->id : null;
                })
                ->columnSpan(12),
        ])->columns(12);
    }

    private static function allTabComment(): Tabs
    {
        return Tabs::make('Tabs')
            ->tabs([
                self::infomationMain(),
                self::repliesInfo()->hidden(fn (string $operation): bool => $operation === 'create'),
            ])->columnSpanFull();
    }

    private static function infomationMain(): Tabs\Tab
    {
        return Tabs\Tab::make('Thông tin cơ bản')
            ->icon('heroicon-m-identification')
            ->schema([
                self::nameInfo(),
                self::choosePost(),
                self::sectionToogle(),
                self::contentPost(),
            ])->columns(12);
    }

    private static function nameInfo(): TextInput
    {
        return TextInput::make('name')
            ->label(__('comment::comment-post.form.label.name'))
            ->required()
            ->rules('required|max:50|min:1')
            ->columnSpan(6);
    }

    private static function choosePost(): Select
    {
        return Select::make('commentable_id')
            ->label(__('comment::comment-post.form.label.commentable_id'))
            ->preload()
            ->searchable()
            ->rules('required')
            ->options(function () {
                return Post::query()->pluck('title', 'id')->toArray();
            })->columnSpan(6);
    }

    private static function sectionToogle(): Section
    {
        return Section::make('Trạng thái')->schema([
            Toggle::make('show')
                ->label(__('comment::comment-post.form.label.show'))
                ->onIcon(__('comment::comment-post.form.icons.showOn'))
                ->offIcon(__('comment::comment-post.form.icons.showOff'))
                ->onColor('success')
                ->default(true)
                ->columnSpan(3),
            Toggle::make('pin')
                ->label(__('comment::comment-post.form.label.pin'))
                ->onIcon(__('comment::comment-post.form.icons.pinOn'))
                ->offIcon(__('comment::comment-post.form.icons.pinOff'))
                ->onColor(Color::Fuchsia)
                ->default(false)
                ->columnSpan(3),
        ])->columnSpan(12);
    }

    private static function contentPost(): Textarea
    {
        return Textarea::make('text')
            ->label(__('comment::comment-post.form.label.text'))
            ->rules('required|min:1')
            ->columnSpan(12);
    }

    private static function repliesInfo(): Tabs\Tab
    {
        return Tabs\Tab::make('Người phản hồi')
            ->icon('heroicon-m-user-group')
            ->badgeColor(Color::Amber)
            ->schema([
                self::repeaterReplies(),
            ])->columns(12);
    }

    private static function repeaterReplies(): Repeater
    {
        return Repeater::make('replies')
            ->relationship()
            ->label('')
            ->schema([
                TextInput::make('name')
                    ->label(__('comment::comment-post.form.replies.name'))
                    ->columnSpan(12),
                Toggle::make('show')
                    ->label(__('comment::comment-post.form.replies.show'))
                    ->onIcon(__('comment::comment-post.form.icons.showOn'))
                    ->offIcon(__('comment::comment-post.form.icons.showOff'))
                    ->onColor('success')
                    ->default(true)
                    ->columnSpan(3),
                Toggle::make('pin')
                    ->label(__('comment::comment-post.form.replies.pin'))
                    ->onIcon(__('comment::comment-post.form.icons.pinOn'))
                    ->offIcon(__('comment::comment-post.form.icons.pinOff'))
                    ->onColor(Color::Fuchsia)
                    ->columnSpan(3),
            ])
            ->reorderable()
            ->collapsed()
            ->addable(false)
            ->columnSpanFull();
    }
}
