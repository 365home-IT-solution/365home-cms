<div>
    @if(!empty($config['component']['layout']))
        @if ($config['component']['layout'] == 'slide')
            @livewire('bladethemev1::product-slide', ['config' => $config])
        @elseif ($config['component']['layout'] == 'grid')
            @livewire('bladethemev1::product-grid', ['config' => $config])
        @endif
    @else
        @livewire('bladethemev1::product-grid', ['config' => $config])
    @endif
</div>