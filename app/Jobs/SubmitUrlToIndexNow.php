<?php

namespace App\Jobs;

use App\Services\IndexNowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SubmitUrlToIndexNow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $urls
     */
    public function __construct(public array $urls)
    {
    }

    public function handle(IndexNowService $indexNow): void
    {
        $indexNow->submit($this->urls);
    }
}
