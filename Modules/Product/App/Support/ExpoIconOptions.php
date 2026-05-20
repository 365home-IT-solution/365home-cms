<?php

declare(strict_types=1);

namespace Modules\Product\App\Support;

class ExpoIconOptions
{
    /**
     * Trả về danh sách icon theo format "IconSet/icon_name" => "IconSet › icon_name"
     * dùng cho Select::make() trong Filament.
     */
    public static function all(): array
    {
        $icons = [
            // ── MaterialIcons ──────────────────────────────────────────────
            'MaterialIcons/schedule'          => 'MaterialIcons › schedule',
            'MaterialIcons/access-time'       => 'MaterialIcons › access-time',
            'MaterialIcons/today'             => 'MaterialIcons › today',
            'MaterialIcons/calendar-today'    => 'MaterialIcons › calendar-today',
            'MaterialIcons/event'             => 'MaterialIcons › event',
            'MaterialIcons/date-range'        => 'MaterialIcons › date-range',
            'MaterialIcons/home'              => 'MaterialIcons › home',
            'MaterialIcons/hotel'             => 'MaterialIcons › hotel',
            'MaterialIcons/bed'               => 'MaterialIcons › bed',
            'MaterialIcons/king-bed'          => 'MaterialIcons › king-bed',
            'MaterialIcons/single-bed'        => 'MaterialIcons › single-bed',
            'MaterialIcons/meeting-room'      => 'MaterialIcons › meeting-room',
            'MaterialIcons/room'              => 'MaterialIcons › room',
            'MaterialIcons/night-shelter'     => 'MaterialIcons › night-shelter',
            'MaterialIcons/apartment'         => 'MaterialIcons › apartment',
            'MaterialIcons/villa'             => 'MaterialIcons › villa',
            'MaterialIcons/cottage'           => 'MaterialIcons › cottage',
            'MaterialIcons/beach-access'      => 'MaterialIcons › beach-access',
            'MaterialIcons/pool'              => 'MaterialIcons › pool',
            'MaterialIcons/spa'               => 'MaterialIcons › spa',
            'MaterialIcons/fitness-center'    => 'MaterialIcons › fitness-center',
            'MaterialIcons/restaurant'        => 'MaterialIcons › restaurant',
            'MaterialIcons/local-cafe'        => 'MaterialIcons › local-cafe',
            'MaterialIcons/wifi'              => 'MaterialIcons › wifi',
            'MaterialIcons/tv'                => 'MaterialIcons › tv',
            'MaterialIcons/ac-unit'           => 'MaterialIcons › ac-unit',
            'MaterialIcons/kitchen'           => 'MaterialIcons › kitchen',
            'MaterialIcons/bathtub'           => 'MaterialIcons › bathtub',
            'MaterialIcons/local-parking'     => 'MaterialIcons › local-parking',
            'MaterialIcons/elevator'          => 'MaterialIcons › elevator',
            'MaterialIcons/business'          => 'MaterialIcons › business',
            'MaterialIcons/work'              => 'MaterialIcons › work',
            'MaterialIcons/star'              => 'MaterialIcons › star',
            'MaterialIcons/favorite'          => 'MaterialIcons › favorite',
            'MaterialIcons/person'            => 'MaterialIcons › person',
            'MaterialIcons/people'            => 'MaterialIcons › people',
            'MaterialIcons/group'             => 'MaterialIcons › group',
            'MaterialIcons/child-care'        => 'MaterialIcons › child-care',
            'MaterialIcons/family-restroom'   => 'MaterialIcons › family-restroom',
            'MaterialIcons/smoking-rooms'     => 'MaterialIcons › smoking-rooms',
            'MaterialIcons/smoke-free'        => 'MaterialIcons › smoke-free',
            'MaterialIcons/pets'              => 'MaterialIcons › pets',

            // ── MaterialCommunityIcons ─────────────────────────────────────
            'MaterialCommunityIcons/clock-outline'          => 'MaterialCommunityIcons › clock-outline',
            'MaterialCommunityIcons/clock-time-four-outline'=> 'MaterialCommunityIcons › clock-time-four-outline',
            'MaterialCommunityIcons/calendar-clock'         => 'MaterialCommunityIcons › calendar-clock',
            'MaterialCommunityIcons/calendar-month'         => 'MaterialCommunityIcons › calendar-month',
            'MaterialCommunityIcons/calendar-week'          => 'MaterialCommunityIcons › calendar-week',
            'MaterialCommunityIcons/home-outline'           => 'MaterialCommunityIcons › home-outline',
            'MaterialCommunityIcons/home-city-outline'      => 'MaterialCommunityIcons › home-city-outline',
            'MaterialCommunityIcons/bed-outline'            => 'MaterialCommunityIcons › bed-outline',
            'MaterialCommunityIcons/bed-double-outline'     => 'MaterialCommunityIcons › bed-double-outline',
            'MaterialCommunityIcons/bed-single-outline'     => 'MaterialCommunityIcons › bed-single-outline',
            'MaterialCommunityIcons/door-open'              => 'MaterialCommunityIcons › door-open',
            'MaterialCommunityIcons/office-building-outline'=> 'MaterialCommunityIcons › office-building-outline',
            'MaterialCommunityIcons/sofa-outline'           => 'MaterialCommunityIcons › sofa-outline',
            'MaterialCommunityIcons/television-outline'     => 'MaterialCommunityIcons › television-outline',
            'MaterialCommunityIcons/wifi'                   => 'MaterialCommunityIcons › wifi',
            'MaterialCommunityIcons/pool'                   => 'MaterialCommunityIcons › pool',
            'MaterialCommunityIcons/car-outline'            => 'MaterialCommunityIcons › car-outline',
            'MaterialCommunityIcons/star-outline'           => 'MaterialCommunityIcons › star-outline',
            'MaterialCommunityIcons/tag-outline'            => 'MaterialCommunityIcons › tag-outline',

            // ── Ionicons ───────────────────────────────────────────────────
            'Ionicons/time-outline'          => 'Ionicons › time-outline',
            'Ionicons/alarm-outline'         => 'Ionicons › alarm-outline',
            'Ionicons/calendar-outline'      => 'Ionicons › calendar-outline',
            'Ionicons/calendar-number-outline' => 'Ionicons › calendar-number-outline',
            'Ionicons/home-outline'          => 'Ionicons › home-outline',
            'Ionicons/bed-outline'           => 'Ionicons › bed-outline',
            'Ionicons/business-outline'      => 'Ionicons › business-outline',
            'Ionicons/restaurant-outline'    => 'Ionicons › restaurant-outline',
            'Ionicons/cafe-outline'          => 'Ionicons › cafe-outline',
            'Ionicons/wifi-outline'          => 'Ionicons › wifi-outline',
            'Ionicons/car-outline'           => 'Ionicons › car-outline',
            'Ionicons/star-outline'          => 'Ionicons › star-outline',
            'Ionicons/person-outline'        => 'Ionicons › person-outline',
            'Ionicons/people-outline'        => 'Ionicons › people-outline',
            'Ionicons/paw-outline'           => 'Ionicons › paw-outline',
            'Ionicons/leaf-outline'          => 'Ionicons › leaf-outline',
            'Ionicons/sunny-outline'         => 'Ionicons › sunny-outline',
            'Ionicons/moon-outline'          => 'Ionicons › moon-outline',

            // ── FontAwesome5 ───────────────────────────────────────────────
            'FontAwesome5/clock'             => 'FontAwesome5 › clock',
            'FontAwesome5/calendar-alt'      => 'FontAwesome5 › calendar-alt',
            'FontAwesome5/calendar-day'      => 'FontAwesome5 › calendar-day',
            'FontAwesome5/calendar-week'     => 'FontAwesome5 › calendar-week',
            'FontAwesome5/home'              => 'FontAwesome5 › home',
            'FontAwesome5/hotel'             => 'FontAwesome5 › hotel',
            'FontAwesome5/bed'               => 'FontAwesome5 › bed',
            'FontAwesome5/building'          => 'FontAwesome5 › building',
            'FontAwesome5/wifi'              => 'FontAwesome5 › wifi',
            'FontAwesome5/tv'                => 'FontAwesome5 › tv',
            'FontAwesome5/swimming-pool'     => 'FontAwesome5 › swimming-pool',
            'FontAwesome5/parking'           => 'FontAwesome5 › parking',
            'FontAwesome5/dumbbell'          => 'FontAwesome5 › dumbbell',
            'FontAwesome5/concierge-bell'    => 'FontAwesome5 › concierge-bell',
            'FontAwesome5/star'              => 'FontAwesome5 › star',
            'FontAwesome5/user'              => 'FontAwesome5 › user',
            'FontAwesome5/users'             => 'FontAwesome5 › users',
            'FontAwesome5/dog'               => 'FontAwesome5 › dog',
            'FontAwesome5/smoking'           => 'FontAwesome5 › smoking',
            'FontAwesome5/smoking-ban'       => 'FontAwesome5 › smoking-ban',

            // ── Feather ────────────────────────────────────────────────────
            'Feather/clock'                  => 'Feather › clock',
            'Feather/calendar'               => 'Feather › calendar',
            'Feather/home'                   => 'Feather › home',
            'Feather/star'                   => 'Feather › star',
            'Feather/user'                   => 'Feather › user',
            'Feather/users'                  => 'Feather › users',
            'Feather/wifi'                   => 'Feather › wifi',
            'Feather/tv'                     => 'Feather › tv',
            'Feather/sun'                    => 'Feather › sun',
            'Feather/moon'                   => 'Feather › moon',
            'Feather/tag'                    => 'Feather › tag',
            'Feather/coffee'                 => 'Feather › coffee',
        ];

        return $icons;
    }

    /**
     * Trả về danh sách nhóm theo IconSet — dùng cho Select grouped.
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::all() as $value => $label) {
            [$set] = explode('/', $value, 2);
            $groups[$set][$value] = $label;
        }
        return $groups;
    }
}
