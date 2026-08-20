<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'menu_style_id',
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

    public function menuStyle(): BelongsTo
    {
        return $this->belongsTo(MenuStyle::class);
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
                'menu_style_id',
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
