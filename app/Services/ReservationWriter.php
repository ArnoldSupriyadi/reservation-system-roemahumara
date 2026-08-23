<?php

namespace App\Services;

use App\Exceptions\DuplicateReservationException;
use App\Exceptions\InvalidReservationException;
use App\Exceptions\StaleReservationException;
use App\Models\Reservation;
use App\Models\User;
use App\Support\TimeInput;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReservationWriter
{
    private const DUPLICATE_ERROR = 1062;

    private const DEDUPE_INDEX = 'uniq_reservations_dedupe';

    private const IDEMPOTENCY_INDEX = 'reservations_idempotency_key_unique';

    public function __construct(private readonly NumberSequence $sequence) {}

    public function create(array $data, string $idempotencyKey, User $actor): Reservation
    {
        $existing = Reservation::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        $this->guardRules($data, null);

        $menus = $this->pullMenus($data);

        try {
            return DB::transaction(function () use ($data, $menus, $idempotencyKey, $actor) {
                $reservation = new Reservation;
                $reservation->fill($data);
                $reservation->reservation_number = Reservation::NUMBER_PREFIX
                    .$this->sequence->next('reservation');
                $reservation->idempotency_key = $idempotencyKey;
                $reservation->created_by = $actor->id;
                $reservation->version = 1;
                $reservation->save();

                $reservation->menus()->sync($menus ?? []);

                return $reservation;
            });
        } catch (QueryException $e) {
            // Submit kedua yang tiba bersamaan dengan yang pertama.
            if ($this->violates($e, self::IDEMPOTENCY_INDEX)) {
                return Reservation::where('idempotency_key', $idempotencyKey)->firstOrFail();
            }

            if ($this->violates($e, self::DEDUPE_INDEX)) {
                throw new DuplicateReservationException($this->findDuplicate($data));
            }

            throw $e;
        }
    }

    public function update(
        Reservation $reservation,
        array $data,
        int $expectedVersion,
        User $actor
    ): Reservation {
        $menus = $this->pullMenus($data);

        return DB::transaction(function () use ($reservation, $data, $menus, $expectedVersion, $actor) {
            $fresh = Reservation::whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->version !== $expectedVersion) {
                throw new StaleReservationException;
            }

            $this->guardRules($data, $fresh);

            $fresh->fill($data);
            $fresh->version = $fresh->version + 1;
            $fresh->updated_by = $actor->id;

            try {
                $fresh->save();

                // null berarti kunci menu_items memang tidak dikirim — update
                // parsial yang tidak menyentuh menu. Larik kosong berarti
                // dikirim tapi kosong: pengguna menghapus semua menunya.
                if ($menus !== null) {
                    $fresh->menus()->sync($menus);
                }
            } catch (QueryException $e) {
                if ($this->violates($e, self::DEDUPE_INDEX)) {
                    throw new DuplicateReservationException($this->findDuplicate($data));
                }

                throw $e;
            }

            return $fresh;
        });
    }

    /**
     * Mengeluarkan pilihan menu dari data dan mengubahnya jadi bentuk sync().
     *
     * Dikeluarkan, bukan sekadar dibaca: kalau menu_items ikut masuk ke fill(),
     * Eloquent akan mencoba menyimpannya sebagai kolom dan gagal dengan galat
     * SQL yang tidak menunjuk penyebabnya.
     *
     * @return array<int, array{pax: int}>|null null bila kuncinya tidak dikirim
     */
    private function pullMenus(array &$data): ?array
    {
        if (! array_key_exists('menu_items', $data)) {
            return null;
        }

        $items = $data['menu_items'];
        unset($data['menu_items']);

        return collect($items ?? [])
            ->filter(fn ($baris) => filled($baris['menu_id'] ?? null))
            ->mapWithKeys(fn ($baris) => [
                (int) $baris['menu_id'] => [
                    'pax' => max(1, (int) ($baris['pax'] ?? 1)),
                    // Spasi doang bukan catatan; disimpan null supaya tampilan
                    // tidak memunculkan baris catatan kosong.
                    'remark' => filled($baris['remark'] ?? null) ? trim($baris['remark']) : null,
                ],
            ])
            ->all();
    }

    /**
     * Aturan isi yang berlaku untuk setiap penulisan, termasuk yang tidak lewat
     * form: area wajib, dan bentrok area wajib punya penjelasan di Remark.
     *
     * Halaman Filament memeriksa hal yang sama lebih dulu dengan pesan yang
     * ramah. Penjagaan di sini bukan pengulangan yang sia-sia — tanpa ia,
     * aturannya hanya berlaku selama datanya kebetulan masuk lewat form.
     */
    private function guardRules(array $data, ?Reservation $current): void
    {
        $state = $this->effectiveState($data, $current);

        if (blank($state['area_id'])) {
            throw InvalidReservationException::missingArea();
        }

        if (filled($state['remark'])) {
            return;
        }

        // Duplikat persis juga tampak sebagai bentrok area karena menempati area
        // yang sama. Dibiarkan lewat supaya DuplicateReservationException yang
        // muncul — pesannya menyebut nama dan tanggal, jauh lebih menolong
        // daripada "Remark kosong".
        if ($this->findDuplicate($state, $current?->getKey()) !== null) {
            return;
        }

        $conflicts = app(ConflictChecker::class)->check(
            $state['area_id'],
            $state['reservation_date'],
            $state['start_time'],
            $state['end_time'],
            $current?->getKey(),
        );

        if ($conflicts->isNotEmpty()) {
            throw InvalidReservationException::unexplainedConflict($conflicts);
        }
    }

    /**
     * Keadaan reservasi SESUDAH perubahan diterapkan.
     *
     * update() menerima array parsial — `fill()` hanya menimpa kunci yang ada.
     * Memeriksa $data mentah akan menolak perubahan pax yang tidak menyentuh
     * area sama sekali, hanya karena area tidak ikut dikirim.
     *
     * @return array{area_id: mixed, reservation_date: string, start_time: string, end_time: ?string, remark: ?string, guest_name: string}
     */
    private function effectiveState(array $data, ?Reservation $current): array
    {
        $pick = fn (string $key, $fallback) => array_key_exists($key, $data) ? $data[$key] : $fallback;

        $date = $pick('reservation_date', $current?->reservation_date);

        return [
            'area_id' => $pick('area_id', $current?->area_id),
            'reservation_date' => $date instanceof CarbonInterface ? $date->toDateString() : (string) $date,
            'start_time' => (string) $pick('start_time', $current?->start_time),
            'end_time' => $pick('end_time', $current?->end_time),
            'remark' => $pick('remark', $current?->remark),
            'guest_name' => (string) $pick('guest_name', $current?->guest_name),
        ];
    }

    private function violates(QueryException $e, string $index): bool
    {
        return ($e->errorInfo[1] ?? null) === self::DUPLICATE_ERROR
            && str_contains($e->getMessage(), $index);
    }

    public function findDuplicate(array $data, ?int $ignoreId = null): ?Reservation
    {
        // Ketiga klausa sengaja mencerminkan definisi generated column dedupe_key,
        // supaya baris yang ditemukan di sini persis baris yang ditabrak constraint.
        //
        // whereTime() tidak dipakai karena menghasilkan `time(start_time) = ?`, dan
        // TIME() MySQL mengembalikan string '12:00:00'. Nilai yang masuk ke sini
        // berformat H:i dari ReservationInput::normalize(), sehingga perbandingannya
        // menjadi '12:00:00' = '12:00' dan tidak pernah cocok.
        return Reservation::query()
            ->whereDate('reservation_date', $data['reservation_date'])
            ->whereRaw('LOWER(TRIM(guest_name)) = ?', [mb_strtolower(trim($data['guest_name']))])
            ->whereRaw("TIME_FORMAT(start_time, '%H:%i') = ?", [TimeInput::normalize($data['start_time'])])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();
    }
}
