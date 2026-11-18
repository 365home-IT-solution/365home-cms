<?php

namespace Modules\Motrak\Database\Seeders;

use Carbon\Carbon;

/**
 * Dữ liệu mẫu cho GPS Motrak: ảnh chụp THẬT của response POST refreshvehicles do chủ dự án cung cấp (13 phương tiện).
 *
 * LƯU Ý: chứa biển số / vehicleId thật của tài khoản Motrak — chỉ dùng cho môi trường phát triển, KHÔNG đưa lên kho mã công khai.
 * Chữ tiếng Việt đã được giải mã lại đúng (bản dán gốc bị lỗi mã hoá do PowerShell hiển thị UTF-8 thành cp1252).
 * Cấu trúc từng phần tử giữ nguyên như API thật; đưa qua đúng đường nhập dữ liệu của đồng bộ (MotrakSyncService::importSample).
 */
class MotrakSampleData
{
    /** Chi nhánh 365Home (89 Xuân Thủy): các xe đang đỗ quanh số 83–107 Xuân Thủy là xe của chi nhánh. */
    public const HOME_AREA = 'TP. Cần Thơ';

    /** @return list<array<string, mixed>> các phần tử của "data" trong response */
    public static function items(): array
    {
        return [
            ['vehicleId' => '622c81538aba0a34ec2910f1', 'plate' => '65A 16503', 'time' => 479378230, 'x' => 105933040, 'y' => 10232496, 'heading' => 168, 'kmh' => 18,
                'kmage' => 30862.379418757053, 'status' => 1, 'data' => ['addr' => '8-11 Ấp Tân Thuận', 'admin' => 'X. Tân Hạnh,H. Long Hồ,T. Vĩnh Long'],
                'utcTicks' => 638772538321280068, 'dayState' => ['km' => 28.105629228910402, 'startTime' => 479373670, 'idleSeconds' => 1123, 'runSeconds' => 3496],
                'lastStopped' => 479378230, 'isConnected' => false, 'sharing' => null],
            ['vehicleId' => '6385b7a4ddf2737454f16ce6', 'plate' => '65A-36023', 'time' => 532631760, 'x' => 105747770, 'y' => 10024618, 'heading' => 0, 'kmh' => 0,
                'kmage' => 9816.774269704434, 'status' => 0, 'data' => ['addr' => '91 Xuân Thủy Khu Vực 2', 'admin' => 'P. An Bình,TP. Cần Thơ'],
                'utcTicks' => 639305073609883901, 'dayState' => ['km' => 49.40222491658278, 'startTime' => 532596662, 'idleSeconds' => 8700, 'runSeconds' => 7107],
                'lastStopped' => 532631760, 'isConnected' => true, 'sharing' => null],
            ['vehicleId' => '660f7e31acf47677906908ee', 'plate' => '65A 16176', 'time' => 513446237, 'x' => 105747688, 'y' => 10024776, 'heading' => 351, 'kmh' => 0,
                'kmage' => 150232.8222549141, 'status' => 0, 'data' => ['addr' => '100 Xuân Thủy Khu Vực 2', 'admin' => 'P. An Bình,TP. Cần Thơ'],
                'utcTicks' => 639113218384183971, 'dayState' => ['km' => 0.036089782923227176, 'startTime' => 513446237, 'idleSeconds' => 0, 'runSeconds' => 0],
                'lastStopped' => 513446237, 'isConnected' => false, 'sharing' => null],
            // Thiết bị CŨ của xe 65A 34836: toạ độ 0/0 và "data" = null (không có vị trí hợp lệ) — trùng biển số với thiết bị mới bên dưới.
            ['vehicleId' => '631da4f6ddf2737454f10725', 'plate' => '65A-34836', 'time' => 489604602, 'x' => 0, 'y' => 0, 'heading' => 0, 'kmh' => 0,
                'kmage' => 173275.0376833686, 'status' => 0, 'data' => null,
                'utcTicks' => 638874802616982755, 'dayState' => ['km' => 18.122203565726522, 'startTime' => 489580252, 'idleSeconds' => 0, 'runSeconds' => 2019],
                'lastStopped' => 489604602, 'isConnected' => false, 'sharing' => null],
            ['vehicleId' => '6644849750602211b0d2ff22', 'plate' => '65A 41737', 'time' => 532625220, 'x' => 105747623, 'y' => 10024700, 'heading' => 44, 'kmh' => 0,
                'kmage' => 9411.603817260486, 'status' => 0, 'data' => ['addr' => '104 Xuân Thủy Khu Vực 2', 'admin' => 'P. An Bình,TP. Cần Thơ'],
                'utcTicks' => 639305008217245147, 'dayState' => ['km' => 40.60411830380326, 'startTime' => 532596646, 'idleSeconds' => 2216, 'runSeconds' => 5451],
                'lastStopped' => 532625220, 'isConnected' => true, 'sharing' => null],
            ['vehicleId' => '6644708250602211b0d2ff01', 'plate' => '65A 41445', 'time' => 532631453, 'x' => 106339501, 'y' => 9922783, 'heading' => 267, 'kmh' => 0,
                'kmage' => 7968.524683402119, 'status' => 0, 'data' => ['addr' => '563B Nguyễn Đáng', 'admin' => 'P. Trà Vinh,T. Vĩnh Long'],
                'utcTicks' => 639305070538845211, 'dayState' => ['km' => 247.67668133913958, 'startTime' => 532596686, 'idleSeconds' => 6389, 'runSeconds' => 26031],
                'lastStopped' => 532631453, 'isConnected' => true, 'sharing' => null],
            ['vehicleId' => '67e26175220ed834b8cc2157', 'plate' => '65A 46654', 'time' => 503175026, 'x' => 105747626, 'y' => 10024655, 'heading' => 48, 'kmh' => 18,
                'kmage' => 231047.4410998969, 'status' => 0, 'data' => ['addr' => '93 Xuân Thủy Khu Vực 2', 'admin' => 'P. An Bình,TP. Cần Thơ'],
                'utcTicks' => 639010506267253584, 'dayState' => ['km' => 38.12315118231345, 'startTime' => 503129599, 'idleSeconds' => 2947, 'runSeconds' => 5101],
                'lastStopped' => 503175026, 'isConnected' => false, 'sharing' => null],
            ['vehicleId' => '666166d850602211b0d31c67', 'plate' => '65A 34360', 'time' => 491373927, 'x' => 105776951, 'y' => 10008978, 'heading' => 0, 'kmh' => 2,
                'kmage' => 230263.72725638986, 'status' => 0, 'data' => ['addr' => 'A21 Quốc Lộ 1A', 'admin' => 'P. Cái Răng,TP. Cần Thơ'],
                'utcTicks' => 638892495276846742, 'dayState' => ['km' => 0, 'startTime' => 491373927, 'idleSeconds' => 0, 'runSeconds' => 0],
                'lastStopped' => 491373927, 'isConnected' => false, 'sharing' => null],
            ['vehicleId' => '6618e32facf4767790690eb1', 'plate' => '65C 18665', 'time' => 532630388, 'x' => 105747428, 'y' => 10024367, 'heading' => 0, 'kmh' => 0,
                'kmage' => 8506.649446288504, 'status' => 0, 'data' => ['addr' => '103 Xuân Thủy Khu Vực 2', 'admin' => 'P. An Bình,TP. Cần Thơ'],
                'utcTicks' => 639305059888272874, 'dayState' => ['km' => 50.156068280979525, 'startTime' => 532618440, 'idleSeconds' => 2350, 'runSeconds' => 5142],
                'lastStopped' => 532630388, 'isConnected' => true, 'sharing' => null],
            ['vehicleId' => '686b9409dcfc562504384f47', 'plate' => '65A 34836', 'time' => 532625088, 'x' => 105747653, 'y' => 10024667, 'heading' => 0, 'kmh' => 0,
                'kmage' => 173178.92554562265, 'status' => 0, 'data' => ['addr' => '93 Xuân Thủy Khu Vực 2', 'admin' => 'P. An Bình,TP. Cần Thơ'],
                'utcTicks' => 639305006900691696, 'dayState' => ['km' => 16.91455666028196, 'startTime' => 532602910, 'idleSeconds' => 900, 'runSeconds' => 2059],
                'lastStopped' => 532625088, 'isConnected' => true, 'sharing' => null],
            ['vehicleId' => '68665948dcfc562504384b5a', 'plate' => '65A 25431', 'time' => 532569068, 'x' => 104655306, 'y' => 10286915, 'heading' => 0, 'kmh' => 3,
                'kmage' => 25326.388281084244, 'status' => 0, 'data' => ['addr' => '174 Quốc Lộ 80 Khu Phố Ngã Ba', 'admin' => 'X. Hòa Điền, T. An Giang'],
                'utcTicks' => 639304446698039830, 'dayState' => ['km' => 0, 'startTime' => 532569068, 'idleSeconds' => 0, 'runSeconds' => 0],
                'lastStopped' => 532569068, 'isConnected' => true, 'sharing' => null],
            ['vehicleId' => '67e26aa4220ed834b8cc2169', 'plate' => '65H 01846', 'time' => 532610008, 'x' => 105747360, 'y' => 10024318, 'heading' => 0, 'kmh' => 3,
                'kmage' => 45450.956797100924, 'status' => 0, 'data' => ['addr' => '107 Xuân Thủy Khu Vực 2', 'admin' => 'P. An Bình,TP. Cần Thơ'],
                'utcTicks' => 639304856091549132, 'dayState' => ['km' => 0.3741341906134039, 'startTime' => 532596670, 'idleSeconds' => 539, 'runSeconds' => 121],
                'lastStopped' => 532610008, 'isConnected' => true, 'sharing' => null],
            ['vehicleId' => '6661335750602211b0d31c24', 'plate' => '65A 36129', 'time' => 532546417, 'x' => 105747831, 'y' => 10024792, 'heading' => 0, 'kmh' => 0,
                'kmage' => 175624.93779767808, 'status' => 0, 'data' => ['addr' => '83 Xuân Thủy Khu Vực 2', 'admin' => 'P. An Bình,TP. Cần Thơ'],
                'utcTicks' => 639304220255697994, 'dayState' => ['km' => 0.03302528025233187, 'startTime' => 532546417, 'idleSeconds' => 283, 'runSeconds' => 0],
                'lastStopped' => 532546417, 'isConnected' => true, 'sharing' => null],
        ];
    }

    /**
     * Dịch toàn bộ mốc thời gian sao cho điểm mới nhất của mẫu rơi vào "bây giờ − $offsetMinutes", GIỮ NGUYÊN khoảng cách tương đối giữa các xe
     * (xe nào cũ vẫn cũ). Dùng khi muốn xem bản đồ/bộ lọc với dữ liệu "tươi".
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function shiftToNow(array $items, int $offsetMinutes = 1): array
    {
        $ticksOf = fn (array $i) => (int) $i['utcTicks'];
        $newest = max(array_map($ticksOf, $items));
        $targetTicks = \Modules\Motrak\App\Support\MotrakTime::toUtcTicks(Carbon::now()->subMinutes($offsetMinutes));
        $deltaTicks = $targetTicks - $newest;
        $deltaSeconds = intdiv($deltaTicks, 10000000);

        return array_map(function (array $i) use ($deltaTicks, $deltaSeconds) {
            $i['utcTicks'] += $deltaTicks;
            foreach (['time', 'lastStopped'] as $k) {
                $i[$k] += $deltaSeconds;
            }
            if (isset($i['dayState']['startTime'])) {
                $i['dayState']['startTime'] += $deltaSeconds;
            }

            return $i;
        }, $items);
    }
}
