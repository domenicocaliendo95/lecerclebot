<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Le Cercle Tennis Club')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">

    {{-- Nav --}}
    <header class="bg-green-700 text-white shadow">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="{{ route('classifica') }}" class="flex items-center gap-2 font-bold text-lg tracking-tight">
                🎾 Le Cercle Tennis Club
            </a>
            <nav class="flex gap-4 text-sm font-medium">
                <a href="{{ route('classifica') }}" class="hover:underline">Classifica</a>
            </nav>
        </div>
    </header>

    {{-- Content --}}
    <main class="max-w-5xl mx-auto px-4 py-8">
        @yield('content')
    </main>

    <footer class="text-center text-xs text-gray-400 py-6">
        Le Cercle Tennis Club &mdash; San Gennaro Vesuviano (NA)
    </footer>

</body>
</html>
