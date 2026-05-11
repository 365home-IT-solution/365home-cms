<?php
namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Post\Entities\Post;
use Modules\BladeThemeV1\Traits\HandleColorTrait;

class PostSearch extends Component
{
    use HandleColorTrait;

    public $searchQuery = '';
    public $searchResults = [];
    public bool $isOpen = false;

    // Hàm tự động chạy khi searchQuery thay đổi
    public function updatedSearchQuery()
    {
        // Mở khung kết quả khi searchQuery không rỗng
        $this->isOpen = strlen($this->searchQuery) >= 1;

        if ($this->isOpen) {
            $this->searchResults = Post::where('status', 'published')
                ->where(function ($query) {
                    $query->where('title', 'like', '%' . $this->searchQuery . '%')
                        ->orWhere('content', 'like', '%' . $this->searchQuery . '%');
                })
                ->with(['categories', 'media'])
                ->latest()
                ->limit(10)
                ->get();
        } else {
            $this->searchResults = [];
        }
    }

    // Hàm ẩn khung kết quả
    public function closeSearchResults()
    {
        $this->isOpen = false;
    }

    // Render component
    public function render()
    {
        return view('bladethemev1::livewire.post-search', [
            'searchResults' => $this->searchResults
        ]);
    }
}
