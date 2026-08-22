import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './app/Http/Controllers/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ink: '#111111',
                paper: '#FFFDF5',
                brand: '#FFD400',
                // Setiap chip di kalender publik adalah slot yang sudah dipesan —
                // tidak ada chip "kosong". Jadi warnanya membedakan kepastian,
                // bukan ketersediaan, dan hijau di sini berarti "terkunci", bukan
                // "masih bebas". Oranye yang lama terbaca sebagai peringatan.
                booked: '#00D26A',
                tentative: '#7FB3FF',
                // Dipakai hanya untuk penghitung jadwal. Sengaja bukan taken/tentative,
                // karena kedua warna itu sudah berarti status di keterangan kalender.
                pop: '#B9FF39',
                // Hari Minggu. Dua nuansa karena latarnya berbeda: angka tanggal
                // duduk di atas kertas terang, nama harinya di atas bilah hitam.
                // Nuansa yang sama di kedua tempat pasti gagal di salah satunya.
                sunday: {
                    DEFAULT: '#D40000',
                    ink: '#FF6B6B',
                },
            },
            boxShadow: {
                brut: '6px 6px 0 0 #111111',
                'brut-sm': '3px 3px 0 0 #111111',
            },
        },
    },

    plugins: [],
};
