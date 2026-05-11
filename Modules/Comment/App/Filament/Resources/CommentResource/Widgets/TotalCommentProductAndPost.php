<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentResource\Widgets;

use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Comment\App\Filament\Resources\CommentResource\Pages\StatisticalComment;
use Modules\Comment\Entities\Comment;
use Modules\Comment\Entities\CommentReply;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TotalCommentProductAndPost extends BaseWidget
{
    protected function getTablePage(): string
    {
        return StatisticalComment::class;
    }

    protected function getStats(): array
    {
        $commentableTypes = Config::get('comment.commentable_types', [
            'post' => [
                'model' => 'Modules\Post\Entities\Post',
                'label' => 'Bình luận bài viết',
                'description' => 'bình luận bài viết',
                'icon' => 'heroicon-m-chat-bubble-oval-left',
                'color' => 'primary',
            ],
//            'product' => [
//                'model' => 'Modules\Product\App\Models\Product',
//                'label' => 'Đánh giá sản phẩm',
//                'description' => 'đánh giá sản phẩm',
//                'icon' => 'heroicon-m-chat-bubble-left',
//                'color' => 'success',
//            ],
        ]);

        $stats = [];

        $totalAllComments = $this->getTotalComments();
        $allCommentsChart = $this->getChartData();
        $stats[] = $this->createStat(
            'Tổng đánh giá / bình luận',
            $totalAllComments,
            'Tổng tất cả đánh giá / bình luận (bao gồm phản hồi)',
            'heroicon-m-chat-bubble-bottom-center-text',
            'primary',
            $allCommentsChart
        );

        foreach ($commentableTypes as $type => $config) {
            if ($this->modelExists($config['model'])) {
                $count = $this->getTotalComments($config['model']);
                $chartData = $this->getChartData($config['model']);
                $stats[] = $this->createStat(
                    $config['label'],
                    $count,
                    "Tổng {$config['description']} (bao gồm phản hồi)",
                    $config['icon'],
                    $config['color'],
                    $chartData
                );
            }
        }

        return $stats;
    }

    protected function modelExists(string $modelClass): bool
    {
        return class_exists($modelClass) && method_exists($modelClass, 'getTable');
    }

    protected function createStat(string $title, int $value, string $description, string $icon, string $color, array $chartData): Stat
    {
        return Stat::make($title, $value)
            ->description($description)
            ->descriptionIcon($icon, IconPosition::Before)
            ->chart($chartData)
            ->color($color);
    }

    protected function getChartData(?string $commentableType = null): array
    {
        $days = 7;
        $startDate = Carbon::now()->subDays($days);

        $commentQuery = Comment::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $startDate);

        $replyCommentQuery = CommentReply::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $startDate);

        if ($commentableType) {
            $commentQuery->where('commentable_type', $commentableType);
            $replyCommentQuery->whereHas('comment', function ($query) use ($commentableType) {
                $query->where('commentable_type', $commentableType);
            });
        }

        $commentResults = $commentQuery->groupBy('date')->get();
        $replyCommentResults = $replyCommentQuery->groupBy('date')->get();

        $chartData = array_fill(0, $days, 0);

        foreach ($commentResults as $result) {
            $index = Carbon::parse($result->date)->diffInDays($startDate);
            $chartData[$index] += $result->count;
        }

        foreach ($replyCommentResults as $result) {
            $index = Carbon::parse($result->date)->diffInDays($startDate);
            $chartData[$index] += $result->count;
        }

        return array_reverse($chartData);
    }

    protected function getTotalComments(?string $commentableType = null): int
    {
        $commentQuery = Comment::query();
        $replyCommentQuery = CommentReply::query();

        if ($commentableType) {
            $commentQuery->where('commentable_type', $commentableType);
            $replyCommentQuery->whereHas('comment', function ($query) use ($commentableType) {
                $query->where('commentable_type', $commentableType);
            });
        }

        return $commentQuery->count() + $replyCommentQuery->count();
    }
}