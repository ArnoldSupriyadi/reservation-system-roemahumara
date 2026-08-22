<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Jadwal Roemah Umara')</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <header>
        <h1>Roemah Umara</h1>
        <p>Jadwal ketersediaan tempat</p>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>
        <p>Halaman ini hanya menampilkan ketersediaan. Untuk memesan, hubungi kami langsung.</p>
    </footer>
</body>
</html>
