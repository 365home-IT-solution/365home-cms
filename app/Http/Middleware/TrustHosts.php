<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        // TRUSTED_HOSTS (tuỳ chọn, cách nhau dấu phẩy): tin thêm tên miền và mọi subdomain của nó — vd tunnel test webhook ở local
        // (TRUSTED_HOSTS=sharedwithexpose.com). Không đặt thì chỉ tin APP_URL như cũ.
        $extra = array_map(
            fn (string $host) => '^(.+\.)?' . preg_quote($host) . '$',
            array_filter(array_map('trim', explode(',', (string) config('app.trusted_hosts'))))
        );

        return [
            $this->allSubdomainsOfApplicationUrl(),
            ...$extra,
        ];
    }
}
