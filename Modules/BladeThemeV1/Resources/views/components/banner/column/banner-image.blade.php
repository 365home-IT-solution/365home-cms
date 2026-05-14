@props([
    'banner',
    'heightImage',
])
<div style="height: {{$heightImage.'px'}}" class="flex justify-center items-center w-full h-full max-md:relative">
    @if(!empty($banner['image']))
        <img src="{{asset('/storage/'.$banner['image'])}}"
             class="w-full h-full object-cover"
             alt="img"/>
        <div class="md:hidden block absolute inset-0 bg-black opacity-50"></div>
    @endif
</div>
