<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Jadwal Roemah Umara')</title>

    {{-- Favicon. Dibuat dari ornamen logo gold lewat scripts/buat-favicon.php.
         .ico ditaruh lebih dulu untuk peramban lama yang mengabaikan type PNG;
         yang modern memilih ukuran paling cocok dari daftar di bawahnya. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('img/favicon-16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('img/favicon-180.png') }}">

    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen">
    <header class="border-b-[3px] border-ink bg-brand">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-6">
            {{-- Nama tetap ada sebagai teks lewat alt, supaya pembaca layar dan mesin
                 pencari tidak kehilangan identitas halaman saat gambarnya gagal dimuat.
                 width/height ditulis agar tata letak tidak melompat saat logo masuk. --}}
            <h1>
                <img
                    src="{{ asset('img/logo-roemah-umara.png') }}"
                    alt="Roemah Umara"
                    width="900"
                    height="422"
                    class="h-16 w-auto sm:h-20"
                >
            </h1>
            <p class="ms-auto text-right text-3xl font-black uppercase leading-none tracking-tight sm:text-5xl">
                Kalender Booking
            </p>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-6">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-5xl px-4 pb-10">
        <p class="brut-box p-4 text-sm font-bold">
            Halaman ini hanya menampilkan ketersediaan. Untuk memesan, hubungi kami langsung.
        </p>
    </footer>
</body>
</html>
