<div>
    @isset($config['component']['layout'])
        @if ($config['component']['layout'] == 'slide')
            @livewire('bladethemev1::post-slide', ['config' => $config])
        @elseif ($config['component']['layout'] == 'grid')
            @livewire('bladethemev1::post-grid', ['config' => $config])
        @endif
    @else
        @livewire('bladethemev1::post-grid', ['config' => $config])
    @endisset
</div>
