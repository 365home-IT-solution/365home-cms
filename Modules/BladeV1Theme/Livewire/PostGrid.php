<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Modules\BladeThemeV1\Traits\HandleCalculateTrait;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\BladeThemeV1\Services\Post\PostService;
class PostGrid extends Component
{
    use WithPagination, HandleConfigTrait, HandleCalculateTrait;

    public $perPage = 8;
    #[Url(as: 'trang', history: true)]
    public $page = 1;
    public $smColumns;
    public $mdColumns;
    public $lgColumns;
    protected PostService $postService;

    protected $queryString = [
        'page' => ['as' => 'trang', 'except' => ''],
    ];

    public function boot(PostService $postService)
    {
        $this->postService = $postService;
    }

    public function mount($config)
    {
        $this->setConfig($config);
        $this->calculateColumns();
        $this->perPage = $this->getConfig('per_page') ?? $this->perPage;
    }

    public function fetchData()
    {
        return $this->postService->fetchPostsGrid([
            'category' => $this->getConfig('category'),
            'posts' => $this->getConfig('posts'),
            'per_page' => $this->perPage
        ]);
    }

    public function calculateColumns()
    {
        $columns = $this->calculateColumnsTrait($this->config);
        $this->smColumns = $columns['sm'];
        $this->mdColumns = $columns['md'];
        $this->lgColumns = $columns['lg'];
    }
    #[On('pageChanged')]
    public function updatePage($trang)
    {
        $this->page = $trang;
    }

    public function render()
    {
        $posts = $this->fetchData();
        return view(
            'bladethemev1::livewire.post-grid',
            [
                'style' => $this->getConfig('style', 'card'),
                'posts' => $posts,
            ]
        );
    }
}
