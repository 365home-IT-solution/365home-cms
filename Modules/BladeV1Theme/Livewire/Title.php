<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Title extends Component
{
    use HandleConfigTrait;

    public function mount($config)
    {
        $this->setConfig($config);

    }

    public function getSize($size){

        if ($size == 'h1') {
            return 'md:text-4xl text-2xl';
        } elseif ($size == 'h2') {
            return 'md:text-3xl text-xl';
        } elseif ($size == 'h3') {
            return 'md:text-2xl text-lg';
        } elseif ($size == 'h4') {
            return 'md:text-xl text-base';
        } elseif ($size == 'h5') {
            return 'md:text-lg text-sm';
        } elseif ($size == 'h6') {
            return 'md:text-base text-xs';
        }
    }

    public function getStyle($style){
        if($style == 'left'){
            return 'me-auto text-start';
            return '';
        }elseif($style == 'right'){
            return 'ms-auto text-end';
        }else{
            return 'mx-auto text-center';
        }
    }

    public function render()
    {
        $size = $this->getConfig('size','h2');
        $fontSize = $this->getSize($size);

        $style = $this->getConfig('title_style');
        $titleStyle = $this->getStyle($style);

        $title = $this->getConfig('title');
        $titleColor = $this->getConfig('title_color');
        $description = $this->getConfig('description');
        $descriptionColor = $this->getConfig('description_color');

        return view('bladethemev1::livewire.title',[
            'title' => $title,
            'title_color' => $titleColor,
            'description' => $description,
            'description_color' => $descriptionColor,
            'font_size' => $fontSize,
            'title_style' => $titleStyle
        ]);
    }
}
