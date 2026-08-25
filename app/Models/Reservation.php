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
        'pax_max',
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
            'pax_max' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * Jumlah tamu sebagai satu kalimat: "50" atau "10–14".
     *
     * Di model, bukan diulang di tiap tampilan. Tabel, widget dashboard,
     * infolist, kalender publik, dan dokumen cetak semuanya menampilkan hal yang
     * sama; menuliskannya lima kali menghasilkan lima versi yang berangsur
     * berbeda — persis yang terjadi pada jam sebelum App\Support\Jam ada.
     *
     * En dash, sama seperti rentang jam, bukan tanda hubung biasa.
     */
    public function paxLabel(): string
    {
        if ($this->pax_max === null || $this->pax_max <= $this->pax) {
            return (string) $this->pax;
        }

        return $this->pax.'–'.$this->pax_max;
    }

    /**
     * Jumlah yang dipakai dapur saat menyiapkan hidangan.
     *
     * Batas ATAS, bukan bawah: kekurangan makanan di tengah acara lebih mahal
     * daripada kelebihan. Dipakai sebagai nilai awal kolom Porsi di form; staf
     * tetap bisa mengubahnya per item, karena minuman kerap dipesan lebih banyak
     * daripada jumlah tamu (aturan #15).
     */
    public function paxUntukDapur(): int
    {
        return max($this->pax, $this->pax_max ?? 0);
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
                'pax_max',
                'status',
                'remark',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('reservation');
    }
}
