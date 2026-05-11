<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\ScoreBoard\Entities\ScoreBoard as SB;
use Modules\BladeThemeV1\Traits\HandleColorTrait;

class ScoreBoard extends Component
{
    use HandleColorTrait, WithPagination;

    public $config;
    public $primaryColor;
    public $perPage = 5;
    public $minReal = 0;
    public $maxReal = 1100;
    public $minM = 0;
    public $maxM = 1100;
    public $gender = '';
    #[Url(as: 'trang', history: true)]
    public $page = 1;
    public $search = '';
    public $sortPoints = '';

    protected $queryString = [
        'minReal' => ['except' => 0],
        'maxReal' => ['except' => 1100],
        'minM' => ['except' => 0],
        'maxM' => ['except' => 1100],
        'gender' => ['except' => ''],
        'search' => ['except' => ''],
        'sortPoints' => ['except' => ''],
    ];

    public function mount($config)
    {
        $this->config = $config['component'] ?? [];
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $this->perPage =  $this->config['per_page'] ?? $this->perPage;
    }

    public function fetchScoreBoard()
    {
        $query = SB::select('id', 'name', 'sort', 'gender', 'point_real', 'point_min', 'count_join', 'note')
            ->where('name', 'like', '%' . $this->search . '%');

        $query->whereBetween('point_real', [(int)$this->minReal, (int)$this->maxReal]);

        $query->WhereBetween('point_min', [(int)$this->minM, (int)$this->maxM]);

        $query->when($this->gender, function ($q) {
            return $q->where('gender', $this->gender);
        });

        $query->when($this->sortPoints === 'points_asc', function ($q) {
            return $q->orderBy('point_real', 'asc');
        });

        $query->when($this->sortPoints === 'points_desc', function ($q) {
            return $q->orderBy('point_real', 'desc');
        });

        $query->when($this->sortPoints === 'min_asc', function ($q) {
            return $q->orderBy('point_min', 'asc');
        });

        $query->when($this->sortPoints === 'min_desc', function ($q) {
            return $q->orderBy('point_min', 'desc');
        });

        return $query->orderBy('sort', 'asc')->latest()->paginate($this->perPage);
    }

    #[On('pageChanged')]
    public function updatePage($trang)
    {
        $this->page = $trang;
    }
    
    public function searchPage()
    {
        $this->resetPage();
    }
    
    public function genderPage()
    {
        $this->resetPage();
    }

    public function sortPointPage()
    {
        $this->resetPage();
    }

    public function rangePointPage()
    {
        $this->resetPage();
    }

    public function resetAllFilters()
    {
        $this->reset(['minReal', 'maxReal', 'minM', 'maxM', 'gender', 'search', 'sortPoints', 'page']);
        $this->resetPage();
    }

    public function removeFilter($filter)
    {
        switch ($filter) {
            case 'gender':
                $this->gender = '';
                break;
            case 'points':
                $this->minReal = 0;
                $this->maxReal = 1100;
                $this->minM = 0;
                $this->maxM = 1100;
                break;
            case 'sort':
                $this->sortPoints = '';
                break;
            case 'search':
                $this->search = '';
                break;
        }
        $this->dispatch('filtersReset');
        
    }

    public function render()
    {
        $scoreBoard = $this->fetchScoreBoard();
        return view('bladethemev1::livewire.score-board', [
            'scoreBoards' => $scoreBoard,
        ]);
    }
}