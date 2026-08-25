<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Menu\Entities\MenuItem;
use Illuminate\Support\Facades\Request;
use Modules\BladeThemeV1\Traits\HandleColorTrait;

class Breadcrumb extends Component
{
    use HandleColorTrait;

    public $primaryColor;
    public $breadcrumbs = [];
    public $slug;

    // $parents — chuỗi mắt xích trung gian tuỳ chọn (vd [['title' => 'Bài viết', 'url' => ...]], hoặc
    // nhiều mắt xích cho trang chi tiết phòng: Homestay > khu vực > chi nhánh) chèn giữa "Trang chủ"
    // và crumb $name/$slug cuối cùng. Không truyền thì giữ nguyên hành vi cũ (chỉ 1 crumb) cho các
    // trang dùng chung component này (template/category detail).
    public function mount($slug = null, $name = null, $parents = [])
    {
        $this->slug = $slug;

        if ($this->slug) {
            $this->breadcrumbs = array_values($parents);
            $this->breadcrumbs[] = ['title' => $name, 'url' => url($this->slug)];
        } else {
            $this->generateBreadcrumbs();
        }

        $this->primaryColor = $this->getFilamentPrimaryColor();
    }

    private function generateBreadcrumbs()
    {
        $currentUrl = Request::url();
        $menuItems = MenuItem::with('children')->orderBy('order')->get();
        $this->breadcrumbs = $this->findBreadcrumbPath($menuItems, $currentUrl);
    }

    private function findBreadcrumbPath($items, $targetUrl, $path = [])
    {
        foreach ($items as $item) {
            if ($item->target === '_parent') {
                continue;
            }
            $itemUrl = $item->getFullUrl();
            $newPath = array_merge($path, [['title' => $item->title, 'url' => $itemUrl]]);

            if ($itemUrl === $targetUrl) {
                return $newPath;
            }

            if ($item->children->isNotEmpty()) {
                $result = $this->findBreadcrumbPath($item->children, $targetUrl, $newPath);
                if ($result) {
                    return $result;
                }
            }
        }

        return null;
    }

    public function render()
    {
        return view('bladethemev1::livewire.breadcrumb');
    }
}