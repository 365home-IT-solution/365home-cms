<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\Product\App\Models\Product;
use Modules\Category\Entities\Category;
use Carbon\Carbon;

class Products extends Component
{
    use HandleConfigTrait;
    public $style;
    public $configuredCategories = [];
    public $parentCategory;
    public $childCategories;

    public function mount($config, $uniqueId)
    {
        $this->setConfig($config);
        $this->uniqueId = $uniqueId;
        $listRoom = json_decode($config['list_room'] ?? '{}', true);
        $categoriesWithKeys = $listRoom['categories'] ?? [];
        $categoriesConfig = array_values($categoriesWithKeys);
        $tempChildCategories = collect();

        foreach ($categoriesConfig as $configItem) {
            $categoryId = $configItem['category_id'] ?? null;
            $productIds = $configItem['products'] ?? [];
            $grandChild = Category::with('parent')->find($categoryId);

            if (!$grandChild) {
                continue;
            }
            if ($grandChild->parent) {
                $tempChildCategories->put($grandChild->parent->id, $grandChild->parent);
            }
            $products = [];
            if (!empty($productIds)) {
                $products = $this->getRooms($productIds);
            }
            $this->configuredCategories[] = [
                'category' => $grandChild,
                'products' => $products
            ];
        }

        $this->childCategories = $tempChildCategories;
        $firstChild = $this->childCategories->first();
        if ($firstChild && $firstChild->parent_id) {
            $this->parentCategory = Category::find($firstChild->parent_id);
        } else {
            $this->parentCategory = null;
        }
    }

public function getRooms($productIds)
{
    $products = Product::with([
        'categories',
        'tags',
        'media',
        'roomTimeSlots.timeSlot',
        'orderItems' => function ($query) {
            $query->where('checkout_date', '>', now())
                ->whereHas('order', fn($q) => $q->whereIn('status', ['pending', 'paid', 'shipped', 'confirmed']));
        },
        'orderItems.order',
    ])
        ->where('is_activated', 1)
        ->whereIn('id', $productIds)
        ->get()
        ->map(function ($product) {
            $product->pid = $product->id;
            $product->pname = $product->name;
            $product->pslug = $product->slug;
            $product->psd = $product->short_description;
            $product->cate_id = $product->categories->pluck('id')->join(',');
            $product->cate_name = $product->categories->pluck('name')->join(',');
            $product->cate_slug = $product->categories->pluck('slug')->join(',');
            $product->tag_name = $product->tags->map(fn($tag) => $tag->getTranslation('name', 'vi'))->join(',');
            $product->tag_image = $product->tags->map(fn($tag) => $tag->getTranslation('image', 'vi'))->join(',');
            $product->media_file = $product->media_file;
            
            $product->time_price_pairs = $product->roomTimeSlots
                ->map(function ($slot) {
                    if (!$slot->timeSlot || empty($slot->timeSlot->start_time) || empty($slot->timeSlot->end_time)) {
                        return null;
                    }
                    try {
                        $startTime = Carbon::parse($slot->timeSlot->start_time);
                        $endTime = Carbon::parse($slot->timeSlot->end_time);
                        
                        // Tính tổng số phút chênh lệch
                        $totalMinutes = $startTime->diffInMinutes($endTime);
                        
                        // Tính giờ và phút
                        $hours = floor($totalMinutes / 60);
                        $minutes = $totalMinutes % 60;
                        
                        // Format: "giờ.phút" để dễ xử lý ở blade
                        $timeValue = $hours + ($minutes / 100);
                        
                        return "{$timeValue}:{$slot->price}";

                    } catch (\Exception $e) {
                        return null;
                    }
                })
                ->filter()
                ->join(',');
            
            // Chỉ thêm giá cả ngày nếu có full_booking_discount
            $fullBookingDiscount = $product->full_booking_discount;
            if (!empty($fullBookingDiscount) && $product->time_price_pairs) {
                // Tính tổng giá gốc của tất cả khung giờ
                $totalOriginalPrice = $product->roomTimeSlots->sum('price');
                
                // Kiểm tra xem là giảm theo % hay giảm cố định
                $discountValue = trim($fullBookingDiscount);
                
                if (str_contains($discountValue, '%')) {
                    // Giảm theo phần trăm
                    $percentValue = floatval(str_replace('%', '', $discountValue));
                    if ($percentValue > 0 && $percentValue < 100) {
                        $fullDayPrice = $totalOriginalPrice * (1 - ($percentValue / 100));
                    } else {
                        $fullDayPrice = $totalOriginalPrice;
                    }
                } else {
                    // Giảm cố định (số tiền)
                    $fixedDiscount = floatval(str_replace([',', '.'], '', $discountValue));
                    $fullDayPrice = max(0, $totalOriginalPrice - $fixedDiscount);
                }
                
                // Thêm giá cả ngày vào cuối chuỗi với timeValue = 999
                $product->time_price_pairs .= ",999:{$fullDayPrice}";
            }
            
            return $product;
        });
    return $products->unique('pid')->values();
}

    public function render()
    {
        return view('bladethemev1::livewire.products');
    }
}