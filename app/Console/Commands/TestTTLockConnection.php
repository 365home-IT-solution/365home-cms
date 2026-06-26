<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Modules\TTLock\App\Services\TTLockService;

class TestTTLockConnection extends Command
{
    protected $signature   = 'ttlock:test';
    protected $description = 'Kiểm tra kết nối TTLock API từ server này';

    public function handle(): int
    {
        $ttlock = new TTLockService(
            config('services.ttlock.client_id'),
            config('services.ttlock.client_secret'),
            config('services.ttlock.username'),
            config('services.ttlock.password'),
            config('services.ttlock.api_base', 'https://cnapi.ttlock.com'),
        );

        $this->info('=== Kiểm tra kết nối TTLock ===');

        // 1. Ping cnapi (token endpoint)
        $this->line('');
        $this->line('1. Ping cnapi.ttlock.com (token)...');
        try {
            $r = Http::timeout(15)->withOptions(['verify' => false])
                ->get('https://cnapi.ttlock.com');
            $this->info("   HTTP status: " . $r->status());
        } catch (\Exception $e) {
            $this->error("   THẤT BẠI: " . $e->getMessage());
        }

        // 2. Ping euapi (lock API endpoint)
        $this->line('');
        $this->line('2. Ping euapi.ttlock.com (lock API)...');
        try {
            $r = Http::timeout(15)->withOptions(['verify' => false])
                ->get('https://euapi.ttlock.com');
            $this->info("   HTTP status: " . $r->status());
        } catch (\Exception $e) {
            $this->error("   THẤT BẠI: " . $e->getMessage());
        }

        // 3. Lấy token
        $this->line('');
        $this->line('3. Lấy access token (verify=false)...');
        try {
            $clientId     = config('services.ttlock.client_id');
            $clientSecret = config('services.ttlock.client_secret');
            $username     = config('services.ttlock.username');
            $password     = config('services.ttlock.password');

            $this->line("   client_id: " . ($clientId ? substr($clientId, 0, 8) . '...' : 'TRỐNG'));
            $this->line("   username:  " . ($username ?: 'TRỐNG'));

            $r = Http::timeout(20)->withOptions(['verify' => false])
                ->asForm()->post('https://cnapi.ttlock.com/oauth2/token', [
                    'clientId'     => $clientId,
                    'clientSecret' => $clientSecret,
                    'username'     => $username,
                    'password'     => $password,
                ]);

            $data = $r->json();
            if (isset($data['access_token'])) {
                $this->info("   Token OK: " . substr($data['access_token'], 0, 10) . '...');
            } else {
                $this->error("   Token THẤT BẠI: " . json_encode($data));
            }
        } catch (\Exception $e) {
            $this->error("   THẤT BẠI: " . $e->getMessage());
        }

        // 4. Test với TTLockService::getAccessToken()
        $this->line('');
        $this->line('4. TTLockService::getAccessToken()...');
        try {
            $token = $ttlock->getAccessToken();
            if ($token) {
                $this->info("   Token từ service: " . substr($token, 0, 10) . '...');
            } else {
                $this->error("   Không lấy được token từ service!");
            }
        } catch (\Exception $e) {
            $this->error("   THẤT BẠI: " . $e->getMessage());
        }

        // 5. Test getLockList
        $this->line('');
        $this->line('5. TTLockService::getLockList()...');
        try {
            $locks = $ttlock->getLockList();
            if (!empty($locks)) {
                $this->info("   Tìm thấy " . count($locks) . " khóa:");
                foreach (array_slice($locks, 0, 3) as $lock) {
                    $this->line("   - [{$lock['lockId']}] " . ($lock['lockAlias'] ?? $lock['lockName'] ?? '?'));
                }
            } else {
                $this->error("   Không lấy được danh sách khóa (empty)!");
            }
        } catch (\Exception $e) {
            $this->error("   THẤT BẠI: " . $e->getMessage());
        }

        // 6. Test getLockList thủ công (raw) để xem response
        $this->line('');
        $this->line('6. Raw getLockList (debug response)...');
        try {
            $apiBase = config('services.ttlock.api_base', 'https://euapi.ttlock.com');
            $token   = $ttlock->getAccessToken();
            $clientId = config('services.ttlock.client_id');

            $r = Http::timeout(20)->withOptions(['verify' => false])
                ->get("{$apiBase}/v3/lock/list", [
                    'clientId'    => $clientId,
                    'accessToken' => $token,
                    'pageNo'      => 1,
                    'pageSize'    => 20,
                    'date'        => (int) round(microtime(true) * 1000),
                ]);

            $this->line("   HTTP status: " . $r->status());
            $this->line("   Response: " . json_encode($r->json(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->error("   THẤT BẠI: " . $e->getMessage());
        }

        $this->line('');
        $this->info('=== Xong ===');
        return self::SUCCESS;
    }
}
