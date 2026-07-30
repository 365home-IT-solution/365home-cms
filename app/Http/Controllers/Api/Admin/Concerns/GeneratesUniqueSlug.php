<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Concerns;

use Illuminate\Support\Str;

trait GeneratesUniqueSlug
{
    // $ignoreId nhận cả int (Category, Employee...) lẫn string (Product — khoá chính ULID char(36)).
    private function uniqueSlug(string $modelClass, string $name, int|string|null $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (
            $modelClass::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-" . $i++;
        }

        return $slug;
    }
}
