<?php

namespace Modules\Metering\App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Metering\App\Exceptions\CannotDeleteUsedMeteringReadingException;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingViaRoom;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;

// Log chỉ số điện/nước 1 phòng trong 1 tháng — xem migration create_metering_readings_table để biết
// lý do tách riêng module này khỏi Minihouse (chỉ quản lý CHỈ SỐ, KHÔNG quản lý đơn giá).
//
// electric_start/water_start KHÔNG cho nhân viên nhập tay — luôn TỰ TÍNH lúc tạo log (xem creating()
// bên dưới), nên CỐ Ý không có trong $fillable (chặn cả trường hợp form gửi lên field này) và không
// xuất hiện trên form tạo/sửa log (xem MeteringReadingForm) — tránh nhân viên gõ sai số đầu kỳ làm
// lệch dây chuyền nối tiếp với tháng trước.
class MeteringReading extends Model
{
    use ScopedToActiveBuildingViaRoom;
    use LogsMinihouseActivity;

    protected $table = 'metering_readings';

    protected $fillable = ['room_id', 'month', 'electric_end', 'water_end', 'note'];

    protected $casts = [
        'month'          => 'date',
        'electric_start' => 'float',
        'electric_end'   => 'float',
        'water_start'    => 'float',
        'water_end'      => 'float',
    ];

    // ÉP về ngày 1 đầu tháng bất kể ngày nào được truyền vào — DatePicker trên form (dù hiển thị
    // dạng m/Y) vẫn cho chọn/gõ bất kỳ ngày nào trong tháng, nếu lưu nguyên ngày đó thì
    // forRoomAndMonth()/latestBefore() (so khớp CHÍNH XÁC theo startOfMonth()) sẽ không bao giờ khớp
    // được với hoá đơn — lỗi thật đã gặp: log lưu "2026-10-09" thay vì "2026-10-01" khiến hoá đơn
    // tháng 10 không lấy được log dù đã tạo, vẫn để trống electric_end/water_end như chưa hề có log.
    public function setMonthAttribute($value): void
    {
        $this->attributes['month'] = $value ? Carbon::parse($value)->startOfMonth()->toDateString() : $value;
    }

    // Sửa lại electric_end/water_end của 1 log (VD nhân viên đọc nhầm số, sửa lại sau) — tự đẩy giá
    // trị mới xuống làm electric_start/water_start của log THÁNG KẾ TIẾP (nếu có), CHỈ KHI start của
    // log kế tiếp đó vẫn đang khớp với end CŨ (tức là đang nối tiếp tự động, chưa bị nhân viên sửa tay
    // lệch đi) — tránh ghi đè 1 giá trị nhân viên đã cố tình sửa khác đi. Tự lan tiếp xuống các tháng
    // sau nữa nếu chúng cũng đang nối tiếp dây chuyền (đệ quy qua chính event 'updated' này), dừng lại
    // ngay khi gặp 1 log đã lệch dây chuyền hoặc hết log.
    protected static function booted(): void
    {
        // Tự tính electric_start/water_start NGAY LÚC TẠO log — không nhận số nhân viên gõ tay (đã bỏ
        // khỏi $fillable): ưu tiên lấy electric_end/water_end của log THÁNG TRƯỚC của đúng phòng
        // (latestBefore()); nếu đây là log ĐẦU TIÊN của phòng trong module Metering (phòng vừa mới bắt
        // đầu ghi log, trước đó vẫn nhập tay trên hoá đơn) thì lấy tiếp từ electric_end/water_end của
        // hoá đơn GẦN NHẤT của phòng đó (mirror đúng nguồn InvoiceGenerationService đang dùng) để
        // không mất nối tiếp chỉ số thật; phòng hoàn toàn chưa có dữ liệu nào (mới tinh) thì mặc định 0.
        static::creating(function (self $model) {
            $values = static::computeStartValues($model->room_id, Carbon::parse($model->month));

            $model->electric_start = $values['electric_start'];
            $model->water_start    = $values['water_start'];
        });

        // Chặn xoá 1 log ĐÃ ĐƯỢC DÙNG để lập hoá đơn (cùng phòng, cùng tháng đã có hoá đơn) — hoá đơn
        // đã copy số liệu vào bảng riêng nên xoá log không phá hoá đơn đó, NHƯNG xoá xong sẽ không còn
        // gì chứng minh/đối chiếu lại số đã dùng để lập hoá đơn nữa, dễ gây nhầm lẫn nếu sau này cần
        // tra lại. Mirror đúng quy ước CannotDeletePaidInvoiceException của Invoice — ném exception ở
        // 'deleting' để chặn được MỌI đường gọi delete(), Table tự kiểm tra lại điều kiện này để hiện
        // thông báo thân thiện trước khi gọi tới đây (xem MeteringReadingTable).
        static::deleting(function (self $model) {
            if ($model->isUsedInInvoice()) {
                throw new CannotDeleteUsedMeteringReadingException(
                    'Log này đã được dùng để lập hoá đơn tháng ' . $model->month->format('m/Y')
                    . ' — không thể xoá để tránh mất căn cứ đối chiếu. Muốn xoá, hãy xoá hoá đơn đó trước.'
                );
            }
        });

        // Xoá xong 1 log — log THÁNG KẾ TIẾP (nếu có) đang lấy start từ đúng log vừa xoá sẽ bị "mồ
        // côi" (vẫn giữ nguyên số cũ dù log gốc không còn) — tự nối lại đúng dây chuyền bằng cách tính
        // lại start của nó theo latestBefore() MỚI (tự động bỏ qua log vừa xoá vì đã không còn trong
        // DB), y hệt logic lúc tạo mới ở creating() bên trên.
        static::deleted(function (self $model) {
            $next = static::nextAfter($model->room_id, $model->month);

            if (! $next) {
                return;
            }

            $values = static::computeStartValues($model->room_id, $next->month);

            if ((float) $next->electric_start === (float) $values['electric_start']
                && (float) $next->water_start === (float) $values['water_start']) {
                return;
            }

            $next->electric_start = $values['electric_start'];
            $next->water_start    = $values['water_start'];
            $next->save();
        });

        static::updated(function (self $model) {
            if (! $model->wasChanged('electric_end') && ! $model->wasChanged('water_end')) {
                return;
            }

            $next = static::nextAfter($model->room_id, $model->month);

            if (! $next) {
                return;
            }

            $dirty = false;

            if ($model->wasChanged('electric_end')) {
                $oldEnd = $model->getOriginal('electric_end');

                if ($next->electric_start === null || (float) $next->electric_start === (float) $oldEnd) {
                    $next->electric_start = $model->electric_end;
                    $dirty                = true;
                }
            }

            if ($model->wasChanged('water_end')) {
                $oldEnd = $model->getOriginal('water_end');

                if ($next->water_start === null || (float) $next->water_start === (float) $oldEnd) {
                    $next->water_start = $model->water_end;
                    $dirty             = true;
                }
            }

            if ($dirty) {
                $next->save();
            }
        });
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    // Log đã được dùng để lập hoá đơn khi có hoá đơn của đúng phòng này, đúng tháng này — dùng để
    // chặn xoá (xem booted()). Tra qua contract->room_id (Invoice không có room_id trực tiếp), cùng
    // cách join InvoiceGenerationService đang dùng.
    public function isUsedInInvoice(): bool
    {
        return Invoice::withoutGlobalScopes()
            ->whereHas('contract', fn ($q) => $q->where('room_id', $this->room_id))
            ->whereYear('month', $this->month->year)
            ->whereMonth('month', $this->month->month)
            ->exists();
    }

    // Số đầu kỳ điện/nước cho 1 phòng + tháng: ưu tiên lấy số cuối kỳ của log THÁNG TRƯỚC (đúng
    // phòng); nếu chưa có log nào trước đó (log đầu tiên của phòng trong module Metering) thì lấy
    // tiếp từ hoá đơn GẦN NHẤT của phòng (dữ liệu cũ trước khi có module Metering); không có gì cả
    // thì mặc định 0. Dùng chung bởi creating() (tạo mới) và deleted() (nối lại dây chuyền sau khi
    // xoá 1 log ở giữa).
    private static function computeStartValues(string $roomId, Carbon $month): array
    {
        $previous = static::latestBefore($roomId, $month);

        if ($previous) {
            return ['electric_start' => $previous->electric_end ?? 0, 'water_start' => $previous->water_end ?? 0];
        }

        $lastInvoice = Invoice::query()
            ->whereHas('contract', fn ($q) => $q->where('room_id', $roomId))
            ->orderByDesc('period_end')
            ->first();

        return ['electric_start' => $lastInvoice?->electric_end ?? 0, 'water_start' => $lastInvoice?->water_end ?? 0];
    }

    // Lấy log ghi số GẦN NHẤT trước 1 tháng cho trước, của đúng phòng đó — dùng để tự điền
    // electric_start/water_start của log tháng mới bằng electric_end/water_end của tháng trước, mirror
    // đúng cơ chế "start kỳ mới = end kỳ trước" hiện có trong InvoiceGenerationService.
    public static function latestBefore(string $roomId, Carbon $month): ?self
    {
        return static::query()
            ->where('room_id', $roomId)
            ->where('month', '<', $month->copy()->startOfMonth())
            ->orderByDesc('month')
            ->first();
    }

    // Log GẦN NHẤT ngay SAU 1 tháng cho trước, của đúng phòng đó — dùng để đẩy chỉ số cuối kỳ vừa sửa
    // xuống làm chỉ số đầu kỳ của log kế tiếp (xem booted() ở trên).
    public static function nextAfter(string $roomId, Carbon $month): ?self
    {
        return static::query()
            ->where('room_id', $roomId)
            ->where('month', '>', $month->copy()->startOfMonth())
            ->orderBy('month')
            ->first();
    }

    // Log của đúng phòng + đúng tháng (khớp period_start của hoá đơn) — dùng bởi
    // InvoiceGenerationService để lấy sẵn electric_start/end, water_start/end khi sinh hoá đơn.
    public static function forRoomAndMonth(string $roomId, Carbon $month): ?self
    {
        return static::query()
            ->where('room_id', $roomId)
            ->where('month', $month->copy()->startOfMonth()->toDateString())
            ->first();
    }
}
