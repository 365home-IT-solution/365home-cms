<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\BladeThemeV1\Services\Post\PostService;
use Modules\BladeThemeV1\Services\Post\Layouts\ColumnCalculatorService;

class PostPage extends Component
{
    use HandleColorTrait, WithPagination, HandleConfigTrait;

    public $showSidebar = false;
    public $primaryColor;
    protected $style = ['grid', 'list'];
    public $currentStyle = 'grid';
    public $perPage = 8;
    public $smColumns = 1;
    public $mdColumns = 2;
    public $lgColumns = 3;

    public $searchCate = '';
    #[Url(as: 'trang', history: true)]
    public $page = 1;
    #[Url(as: 'tim-kiem')]
    public $search = '';
    #[Url(as: 'danh-muc')]
    public $selectedCategory = '';
    #[Url(as: 'sap-xep')]
    public $sortBy = 'newest';
    public $highlight = false;

    #[On('pageChanged')]
    public function updatePage($trang)
    {
        $this->page = $trang;
    }

    protected $queryString = [
        'selectedCategory' => ['as' => 'danh-muc', 'except' => ''],
        'sortBy' => ['as' => 'sap-xep', 'except' => 'newest'],
        'page' => ['as' => 'trang', 'except' => ''],
    ];

    public function setStyle($style)
    {
        if (in_array($style, $this->style)) {
            $this->currentStyle = $style;
        }
    }

    public function boot(PostService $postService)
    {
        $this->postService = $postService;
    }

    public function mount(array $config): void
    {
        $this->setConfig($config);
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $this->perPage = $this->getConfig('number-post') ?? $this->perPage;
        $this->showSidebar = $this->shouldShowSidebar();
        $this->calculateColumns();
    }

    protected function calculateColumns(): void
    {
        $columnCalculator = new ColumnCalculatorService(
            $this->showSidebar,
            $this->getConfig('columns')
        );

        $columns = $columnCalculator->calculateColumns();

        $this->smColumns = $columns['sm'];
        $this->mdColumns = $columns['md'];
        $this->lgColumns = $columns['lg'];
    }

    protected function getGridClasses(): string
    {
        $columnCalculator = new ColumnCalculatorService(
            $this->showSidebar,
            $this->getConfig('columns')
        );

        return $columnCalculator->getGridClasses($this->currentStyle);
    }

    protected function shouldShowSidebar(): bool
    {
        return $this->getConfig('show_sidebar') ?? false;
    }

    public function fetchPosts()
    {
        return $this->postService->fetchPosts(
            $this->search,
            $this->selectedCategory,
            $this->sortBy,
            $this->perPage
        );
    }

    public function getCategories()
    {
        return $this->postService->getCategories($this->searchCate);
    }

    public function getNewPosts()
    {
        return $this->postService->newPost();
    }

    public function resetAllFilters()
    {
        $this->reset('selectedCategory');
        $this->reset('searchCate');
        $this->resetPage();
    }
    public function setSelectedCategory($category)
    {
        $this->selectedCategory = $category;
        $this->resetPage();
    }


    public function render()
    {
        $posts = $this->fetchPosts();
        $categories = $this->getCategories();
        $newPost = $this->getNewPosts();

        return view('bladethemev1::livewire.post-page', [
            'posts' => $posts,
            'categories' => $categories,
            'postNews' => $newPost,
            'gridClass' => $this->getGridClasses(),
            'locateSidebar' => $this->getConfig('location-sidebar') ?? '',
            'showPostNew' => $this->getConfig('new_post') ?? 0,
            'showCategories' => $this->getConfig('post_category') ?? 0,
            'showSocial' => $this->getConfig('link_social') ?? '',
        ]);
    }
}
