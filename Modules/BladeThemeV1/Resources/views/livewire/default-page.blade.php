<div class="w-full transition duration-500 overflow-x-hidden ease-in-out">
    <div class="relative w-full flex max-md:flex-col">

        <div class="float-left relative overflow-hidden md:w-1/2 w-full md:h-dvh md:border-r-4 zoom"
             style="border-color: #00a65e;">
            <a href="{{$first_page_cta_link ?? '/'}}">
                <img class="w-full object-cover md:h-screen h-auto block"
                     src="https://tuanhaison.com/diendantennisdongnai/upload/hinhanh/intro1-1844.jpg" alt="">
            </a>
            @if($first_page_title)
                <a href="{{$first_page_cta_link ?? '/'}}" style="font: 15px u"
                   class="md:hidden absolute bottom-0 py-3 bg-[#00a65e] w-full text-center text-white font-bold">{{$first_page_title}}</a>
            @endif
        </div>

        <div class="float-right relative overflow-hidden md:w-1/2 w-full md:h-dvh safety md:border-l-4 zoom"
             style="border-color: #00adee;">
            <a href="{{$second_page_cta_link ?? '/'}}">
                <img class="w-full object-cover md:h-screen h-auto block"
                     src="https://tuanhaison.com/diendantennisdongnai/upload/hinhanh/bao-ho-lao-dong-dong-nai-3408-5263.jpg"
                     alt="">
            </a>
            @if($second_page_title)
                <a href="{{$second_page_cta_link ?? '/'}}" style="font: 15px u"
                   class="md:hidden absolute bottom-0 py-3 bg-[#00adee] w-full text-center text-white font-bold">{{$second_page_title}}</a>
            @endif
        </div>

        <!-- Logo hình tròn ở giữa -->
        <div style="transform: translateX(-50%) translateY(-50%); -webkit-transform: translateX(-50%) translateY(-50%);"
             class="md:flex absolute z-50 top-1/2 left-1/2 hidden w-full justify-center items-center">

            <div class="text-right" style="width: calc(50% - 80px);">
                <div style="background-color: #00a65e; padding: 0 50px; margin-right: -30px;"
                     class="rounded-l-full inline-flex">
                    @if($first_page_title)
                        <a href="{{$first_page_cta_link ?? '/'}}" style="font: 15px / 70px u; font-weight: 900;"
                           class="lg:!text-xl md:!text-[15px] !lg:leading-[70px] !leading-[30px] lg:py-5 py-3 text-white hover:text-yellow-200 uppercase whitespace-nowrap">
                            {{$first_page_title}}
                        </a>
                    @endif
                </div>
            </div>

            <div style="height: 160px" class="w-[160px] rediant relative z-50 overflow-hidden rounded-full p-2">
                <img src="{{ asset('storage/logos/logo.png') }}" alt="Logo" style="border-radius: 50%;"
                     class="h-full bg-white w-full">
            </div>

            <div style="width: calc(50% - 80px);">
                <h3 style="padding:0 50px; background: #00adee;" class="ml-[-30px] rounded-r-full inline-flex ">
                    @if($second_page_title)
                        <a href="{{$second_page_cta_link ?? '/'}}" style="font: 15px / 70px u; font-weight: 900;"
                           class="cursor-pointer lg:!text-xl md:!text-[15px] !lg:leading-[70px] !leading-[30px] lg:py-5 py-3 text-white hover:text-yellow-200 uppercase whitespace-nowrap">
                            {{$second_page_title}}
                        </a>
                    @endif
                </h3>
            </div>
        </div>
    </div>
</div>