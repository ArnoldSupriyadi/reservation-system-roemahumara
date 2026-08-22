<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Jadwal Roemah Umara')</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen">
    <header class="border-b-[3px] border-ink bg-brand">
        <div class="mx-auto max-w-5xl px-4 py-6">
            <h1 class="text-3xl font-black uppercase tracking-tight sm:text-4xl">Roemah Umara</h1>
            <p class="mt-1 text-sm font-bold uppercase tracking-wide">Jadwal ketersediaan tempat</p>
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
