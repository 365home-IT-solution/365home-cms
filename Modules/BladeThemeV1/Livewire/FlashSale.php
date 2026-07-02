<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Product\App\Models\Product;
use Carbon\Carbon;

class FlashSale extends Component
{
    public array $products = [];

    public function mount(): void
    {
        $this->products = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->where('discount', '>', 0)
            ->whereHas('roomType', fn ($q) => $q->where('is_active', true))
            ->with(['categories', 'roomTimeSlots.timeSlot', 'media'])
            ->orderByDesc('discount')
            ->limit(12)
            ->get()
            ->map(function ($product) {
                $category  = $product->categories->first();
                $mediaFile = $product->media_file;

                $minPrice = null;
                $minTime  = null;
                foreach ($product->roomTimeSlots as $slot) {
                    if (!$slot->timeSlot) continue;
                    try {
                        $start = Carbon::parse($slot->timeSlot->start_time);
                        $end   = Carbon::parse($slot->timeSlot->end_time);
                        $mins  = $start->diffInMinutes($end);
                        $h     = floor($mins / 60);
                        $m     = $mins % 60;
                        $disp  = $m > 0 ? "{$h}h{$m}" : "{$h}h";
                        if ($minPrice === null || $slot->price < $minPrice) {
                            $minPrice = $slot->price;
                            $minTime  = $disp;
                        }
                    } catch (\Exception) {}
                }

                $discount      = (float) $product->discount;
                $originalPrice = $minPrice ?? (float) $product->price;
                $salePrice     = max(0, $originalPrice - $discount);

                return [
                    'slug'           => $product->slug,
                    'name'           => $product->name,
                    'image'          => $mediaFile ? asset('storage/' . $mediaFile) : null,
                    'category'       => $category?->name ?? '',
                    'original_price' => $originalPrice,
                    'discount'       => $discount,
                    'sale_price'     => $salePrice,
                    'time_unit'      => $minTime,
                ];
            })
            ->values()
            ->toArray();
    }

    public function render()
    {
        return view('bladethemev1::livewire.flash-sale');
    }
}
