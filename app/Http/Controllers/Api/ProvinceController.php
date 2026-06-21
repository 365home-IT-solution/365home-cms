<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class ProvinceController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $province = Province::where('slug', $slug)->first();

        if (! $province) {
            return response()->json(['message' => 'Province not found.'], 404);
        }

        $branches = $province->branches()
            ->where('status', true)
            ->with('category')
            ->get()
            ->map(fn ($branch) => [
                'id'          => $branch->category->id,
                'name'        => $branch->category->name,
                'slug'        => $branch->category->slug,
                'description' => $branch->category->description,
                'image_url'   => $branch->category->image
                    ? Storage::disk('public')->url($branch->category->image)
                    : null,
            ])
            ->values()
            ->toArray();

        return response()->json([
            'province' => [
                'id'        => $province->id,
                'name'      => $province->name,
                'slug'      => $province->slug,
                'image_url' => $province->image
                    ? Storage::disk('public')->url($province->image)
                    : null,
                'branches'  => $branches,
            ],
        ]);
    }
}
