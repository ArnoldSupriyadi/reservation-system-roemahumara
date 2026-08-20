<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\Ability;
use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\EventType;
use App\Models\MenuStyle;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Tamu')
                ->columns(2)
                ->schema([
                    DatePicker::make('reservation_date')
                        ->label('Tanggal')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    TextInput::make('guest_name')
                        ->label('Nama tamu')
                        ->required()
                        ->maxLength(150),

                    TextInput::make('company')
                        ->label('Company')
                        ->maxLength(150)
                        ->helperText('Opsional'),

                    TextInput::make('phone')
                        ->label('HP')
                        ->required()
                        ->tel()
                        ->maxLength(30)
                        ->placeholder('0812 3456 7890'),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(150)
                        ->helperText('Opsional'),

                    Select::make('pic_id')
                        ->label('PIC / Sales')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id')),
                ]),

            Section::make('Detail acara')
                ->columns(2)
                ->schema([
                    Select::make('event_type_id')
                        ->label('Event')
                        ->options(fn () => EventType::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                        ->helperText('Opsional'),

                    Select::make('menu_style_id')
                        ->label('Menu style')
                        ->options(fn () => MenuStyle::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                        ->helperText('Opsional'),

                    Select::make('area_id')
                        ->label('Area')
                        ->options(fn () => Area::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                        ->helperText('Opsional'),

                    TextInput::make('pax')
                        ->label('Pax')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Kalau jumlahnya berupa perkiraan, isi angka terendah lalu tulis rentangnya di Remark.'),

                    TextInput::make('start_time')
                        ->label('Jam mulai')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('11.00')
                        ->helperText('Boleh 11, 11.00, atau 11:00. Menulis 12.00-15.00 di sini akan otomatis terpecah.'),

                    Toggle::make('has_end_time')
                        ->label('Sampai jam tertentu')
                        ->dehydrated(false)
                        ->live()
                        ->afterStateHydrated(fn (Toggle $component, $state, $record) => $component->state(filled($record?->end_time)))
                        ->afterStateUpdated(function (bool $state, callable $set) {
                            if (! $state) {
                                $set('end_time', null);
                            }
                        }),

                    TextInput::make('end_time')
                        ->label('Jam selesai')
                        ->maxLength(20)
                        ->placeholder('15.00')
                        ->visible(fn (callable $get) => (bool) $get('has_end_time')),

                    Select::make('status')
                        ->label('Status')
                        ->options(self::statusOptions())
                        ->placeholder('Belum ditentukan')
                        ->helperText(fn () => self::canConfirm()
                            ? null
                            : 'Anda tidak punya hak untuk menetapkan CONFIRMED.'),
                ]),

            Section::make('Catatan')
                ->schema([
                    Textarea::make('remark')
                        ->label('Remark')
                        ->rows(4)
                        ->columnSpanFull()
                        ->helperText('Ditampilkan penuh di daftar reservasi.'),
                ]),

            Hidden::make('idempotency_key')
                ->default(fn () => (string) Str::uuid())
                ->dehydrated(),
        ]);
    }

    private static function canConfirm(): bool
    {
        return auth()->user()?->can(Ability::ConfirmReservation->value) ?? false;
    }

    /**
     * CONFIRMED hanya ditawarkan kepada yang punya kemampuannya. Ini
     * kenyamanan UI — penegakan sebenarnya ada di CreateReservation dan
     * EditReservation.
     *
     * Perhatikan bahwa yang dicek adalah kemampuan, bukan nama role.
     * Ketika role "manajer" dibuat lewat UI dan diberi kemampuan
     * reservation.confirm, opsi ini langsung muncul untuknya tanpa
     * perubahan kode apa pun.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [ReservationStatus::Tentative->value => 'TENTATIVE'];

        if (self::canConfirm()) {
            $options[ReservationStatus::Confirmed->value] = 'CONFIRMED';
        }

        return $options;
    }
}
