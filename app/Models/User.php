<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Mencerminkan default(true) pada kolom is_active di database.
     *
     * Tanpa baris ini, instance User yang baru dibuat bernilai null pada
     * is_active sampai dimuat ulang dari database. Setiap Policy memeriksa
     * $user->is_active lebih dulu, sehingga null membuat pengguna yang baru
     * dibuat ditolak melakukan apa pun tanpa pesan kesalahan apa pun.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Gerbang masuk ke panel, dipanggil Filament\Http\Middleware\Authenticate.
     *
     * Tanpa User mengimplementasikan FilamentUser, middleware itu menolak
     * SEMUA orang dengan 403 kecuali config('app.env') bernilai 'local'.
     * Artinya panel akan terkunci total begitu dideploy ke produksi.
     *
     * is_active dipakai sebagai syaratnya karena memang itu maknanya: status
     * akun, boleh login atau tidak. Policy tetap memeriksanya lagi per aksi,
     * sehingga sesi yang sudah terlanjur terbuka pun ikut tertutup.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_active;
    }
}
