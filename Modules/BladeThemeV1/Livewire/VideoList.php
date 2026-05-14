<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class VideoList extends Component
{
    use HandleConfigTrait;

    public function mount($config)
    {
        $this->setConfig($config);

    }

    protected function getVideo(): array
    {
        $videoList = $this->getConfig('video');

        if (!$videoList) {
            return [];
        }

        return collect($videoList)->map(function ($item) {
            return [
                'customer_name' => $item['customer_name'] ?? null,
                'video_date' => $item['video_date'] ?? null,
                'is_main' => $item['is_main'] ?? null,
                'video_type' =>$item['video_type'] ?? null,
                'video' =>$item['video'] ?? null,
                'youtube_url' =>$item['youtube_url'] ?? null,
                'youtube_thumbnail' => $item['youtube_thumbnail'] ?? null,
                'thumbnail' => $item['thumbnail'] ?? null,
                'title' =>$item['title'] ?? null,
            ];
        })->toArray();
    }

    public function render()
    {
        return view('bladethemev1::livewire.video-list', [
            'videos' => $this->getVideo()
        ]);
    }
}
