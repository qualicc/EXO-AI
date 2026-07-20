<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title ?? 'Katalog egzoplanet'])
    </head>
    <body class="min-h-screen bg-neutral-950 text-neutral-100 antialiased">
        <div class="border-b border-neutral-800 bg-neutral-950/95">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-md bg-cyan-400 text-sm font-bold text-neutral-950">EX</span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-white">EXO-AI</span>
                        <span class="block text-xs text-neutral-400">Katalog i predykcje</span>
                    </span>
                </a>

                <nav class="flex items-center gap-2 text-sm">
                    <a href="{{ route('home') }}" class="rounded-md px-3 py-2 text-neutral-300 hover:bg-neutral-900 hover:text-white">Katalog</a>

                    @auth
                        <a href="{{ route('dashboard') }}" class="hidden rounded-md px-3 py-2 text-neutral-300 hover:bg-neutral-900 hover:text-white sm:inline-flex">Dashboard</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="rounded-md border border-neutral-700 px-3 py-2 text-neutral-200 hover:border-rose-400 hover:text-white">Wyloguj</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="rounded-md border border-cyan-400 px-3 py-2 text-cyan-200 hover:bg-cyan-400 hover:text-neutral-950">Zaloguj</a>
                    @endauth
                </nav>
            </div>
        </div>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-5 rounded-md border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                    {{ session('status') }}
                </div>
            @endif

            @yield('content')
        </main>
    </body>
</html>
