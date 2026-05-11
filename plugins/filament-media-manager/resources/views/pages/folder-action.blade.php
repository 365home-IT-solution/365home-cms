<x-filament-actions::action :action="$action" :badge="$getBadge()" :badge-color="$getBadgeColor()" dynamic-component="filament::button"
    :label="$getLabel()" :size="$getSize()" class="fi-ac-icon-btn-action" color="gray">
    <style>
        .folder-icon-{{ $item->id }} {
            width: 100px;
            height: 70px;
            background-color: {{ $item->color ?? '#f3c623' }};
            border-radius: 5px;
            position: relative;
            margin-top: 20px;
            margin-right: 10px;
            margin-left: 10px;
            margin-bottom: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .folder-icon-{{ $item->id }}::before {
            content: "";
            width: 40px;
            height: 10px;
            background-color: {{ $item->color ?? '#f3c623' }};
            border-radius: 5px 5px 0 0;
            position: absolute;
            top: -10px;
            left: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
    </style>
    <div class="flex flex-col justify-center items-center gap-4">
        <svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="100" height="100" viewBox="0 0 64 64">
            <path fill="orange" d="M30,24H8v-9c0-2.209,1.791-4,4-4h11.046c1.756,0,3.307,1.145,3.823,2.824L30,24z">
            </path>
            <ellipse cx="32" cy="60.993" opacity=".3" rx="22.562" ry="3"></ellipse>
            <path fill="#ffce29"
                d="M50,17H14c-3.314,0-6,2.686-6,6v23c0,3.314,2.686,6,6,6h36c3.314,0,6-2.686,6-6V23	C56,19.686,53.314,17,50,17z">
            </path>
            <path fill="#fff"
                d="M29,22c2.761,0,5-2.238,5-5H14c-3.314,0-6,2.686-6,6v11c2.761,0,5-2.238,5-5v-6 c0-0.552,0.449-1,1-1H29z"
                opacity=".3"></path>
            <path
                d="M56,46V29c-2.761,0-5,2.238-5,5v12c0,0.552-0.449,1-1,1H31c-2.761,0-5,2.238-5,5h24 C53.314,52,56,49.314,56,46z"
                opacity=".15"></path>
            <path fill="none" stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="10"
                stroke-width="3" d="M12,26.5V23c0-1.103,0.897-2,2-2h6.809"></path>
        </svg>
        <div class="flex flex-col items-center justify-center my-2">
            <div>
                <h1 class="font-bold text-xl">{{ $item->getTitle() ?? $item->name }}</h1>
            </div>
            <div class="flex justify-start mt-1">
                <p class="text-gray-600 dark:text-gray-300 text-sm truncate ...">
                    {{ $item->created_at->diffForHumans() }}
                </p>
            </div>
        </div>
    </div>
</x-filament-actions::action>
