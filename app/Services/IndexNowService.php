<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Xem config/services.php để biết vì sao chỉ Bing/Yandex/Seznam/Naver nhận được ping này,
// không phải Google.
class IndexNowService
{
    public function enabled(): bool
    {
        return filled(config('services.indexnow.key'));
    }

    /**
     * @param  string|array<int, string>  $urls
     */
    public function submit(string|array $urls): void
    {
        if (!$this->enabled()) {
            return;
        }

        $urls = array_values(array_filter((array) $urls));
        if (empty($urls)) {
            return;
        }

        $key = config('services.indexnow.key');
        $host = parse_url($urls[0], PHP_URL_HOST);

        try {
            $response = Http::timeout(10)->asJson()->post('https://api.indexnow.org/indexnow', [
                'host' => $host,
                'key' => $key,
                'keyLocation' => url("/{$key}.txt"),
                'urlList' => $urls,
            ]);

            if ($response->failed()) {
                Log::warning('IndexNow submit failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'urls' => $urls,
                ]);
            }
        } catch (\Throwable $e) {
            // Không để lỗi mạng/IndexNow làm hỏng luồng đăng bài — chỉ log lại.
            Log::warning('IndexNow submit exception', [
                'message' => $e->getMessage(),
                'urls' => $urls,
            ]);
        }
    }
}
