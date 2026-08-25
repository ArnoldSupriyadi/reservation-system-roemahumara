<?php

namespace App\Models;

use App\Casts\JamCast;
use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Reservation extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /**
     * Prefiks nomor reservasi. Ditulis sekali di sini, bukan disebar sebagai
     * literal di beberapa berkas.
     */
    public const NUMBER_PREFIX = 'RU-R';

    protected $fillable = [
        'reservation_date',
        'guest_name',
        'company',
        'phone',
        'email',
        'pic_id',
        'event_type_id',
        'area_id',
        'start_time',
        'end_time',
        'pax',
        'status',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            // Kolomnya TIME di MySQL dan tetap begitu; yang berubah cuma
            // bentuknya di PHP. Lihat App\Support\Jam untuk alasannya.
            'start_time' => JamCast::class,
            'end_time' => JamCast::class,
            'status' => ReservationStatus::class,
            'pax' => 'integer',
            'version' => 'integer',
        ];
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /**
     * Menu yang dipesan, masing-masing dengan jumlah porsinya sendiri.
     *
     * Jumlah porsi TIDAK selalu sama dengan pax reservasi: minuman kerap
     * dipesan lebih banyak daripada jumlah tamu, hidangan anak lebih sedikit.
     * Karena itu pax ada di tabel penghubung, bukan diturunkan dari reservasi.
     */
    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class)->withPivot('pax', 'remark');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'reservation_date',
                'guest_name',
                'company',
                'phone',
                'email',
                'pic_id',
                'event_type_id',
                'area_id',
                'start_time',
                'end_time',
                'pax',
                'status',
                'remark',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('reservation');
    }
}
