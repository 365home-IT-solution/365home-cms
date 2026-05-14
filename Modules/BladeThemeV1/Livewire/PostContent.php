<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Post\Entities\Post;
use Modules\BladeThemeV1\Traits\HandleColorTrait;

class PostContent extends Component
{
    use HandleColorTrait;

    public $post;
    public $slug;
    public $primaryColor;

    public function mount($slug)
    {
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $this->slug = $slug;
        $this->post = $this->fetchPost();
    }

    public function fetchPost()
    {
        $query = Post::with(['tags', 'categories', 'media']);

        try {
            return $query->where('slug', $this->slug)->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404);
        }
    }

    public function render()
    {
        return view('bladethemev1::livewire.post-content', [
            'post' => $this->post,
        ]);
    }
}