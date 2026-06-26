<?php

namespace Modules\BladeThemeV1\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Maps extends Component
{
    use HandleConfigTrait;
    public $cateLevel1;
    public $cateLevel3;
    public $placeUrl;
    public ?string $descriptionMap = null;

    public function mount($config)
    {
        $this->setConfig($config);

        $this->cateLevel1     = $this->getConfig('place');
        $this->cateLevel3     = $this->getConfig('place_2');
        $this->placeUrl       = $this->getConfig('place_url');
        $this->descriptionMap = $this->getConfig('description-map');
    }

    public function getCate1()
    {
        return DB::table('categories')
            ->select('id', 'name', 'status')
            ->where('id', $this->cateLevel1)
            ->where('status', 1)
            ->first();
    }

    public function getCate2()
    {
        return DB::table('categories')
            ->select('id', 'name', 'slug', 'parent_id')
            ->where('id', $this->cateLevel3)
            ->first()
            ? DB::table('categories')
                ->select('id', 'name', 'slug')
                ->where('id', DB::table('categories')->where('id', $this->cateLevel3)->value('parent_id'))
                ->first()
            : null;
    }

    public function getCate3()
    {
        return DB::table('categories')
            ->select('id', 'name', 'slug', 'parent_id')
            ->where('id', $this->cateLevel3)
            ->first();
    }



    public function render()
    {
        return view('bladethemev1::livewire.maps', [
            'nameCate1'      => $this->getCate1(),
            'nameCate2'      => $this->getCate2(),
            'nameCate3'      => $this->getCate3(),
            'descriptionMap' => $this->descriptionMap,
        ]);
    }
}
