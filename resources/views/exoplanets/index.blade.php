@extends('layouts.exoplanets', ['title' => 'Katalog egzoplanet'])

@php
    $statusLabels = [
        'not_requested' => 'Brak predykcji',
        'queued' => 'W kolejce',
        'submitting' => 'Wysyłanie',
        'running' => 'W systemie AI',
        'completed' => 'Gotowe',
        'failed' => 'Błąd',
    ];

    $statusClasses = [
        'not_requested' => 'border-neutral-700 bg-neutral-900 text-neutral-300',
        'queued' => 'border-amber-400/50 bg-amber-400/10 text-amber-100',
        'submitting' => 'border-cyan-400/50 bg-cyan-400/10 text-cyan-100',
        'running' => 'border-sky-400/50 bg-sky-400/10 text-sky-100',
        'completed' => 'border-emerald-400/50 bg-emerald-400/10 text-emerald-100',
        'failed' => 'border-rose-400/50 bg-rose-400/10 text-rose-100',
    ];
@endphp

@section('content')
    <div class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-medium text-cyan-300">CSV → katalog → predykcje</p>
            <h1 class="mt-2 text-3xl font-semibold text-white">Katalog egzoplanetek</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-neutral-400">
                Lista synchronizuje się z pliku CSV przy wejściu na stronę. Duplikaty są pomijane po kluczu kandydata albo po kombinacji celu, misji, okresu i epoki.
            </p>
        </div>

        <form method="GET" action="{{ route(request()->routeIs('dashboard') ? 'dashboard' : 'home') }}" class="flex w-full max-w-md gap-2">
            <input
                name="q"
                value="{{ $search }}"
                placeholder="Szukaj targetu, misji albo ID"
                class="min-w-0 flex-1 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-white placeholder:text-neutral-500 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/30"
            >
            <button type="submit" class="rounded-md bg-cyan-400 px-4 py-2 text-sm font-semibold text-neutral-950 hover:bg-cyan-300">Szukaj</button>
        </form>
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-md border border-neutral-800 bg-neutral-900/60 p-4">
            <p class="text-xs uppercase text-neutral-500">W katalogu</p>
            <p class="mt-2 text-2xl font-semibold text-white">{{ $stats['total'] }}</p>
        </div>
        <div class="rounded-md border border-neutral-800 bg-neutral-900/60 p-4">
            <p class="text-xs uppercase text-neutral-500">Gotowe predykcje</p>
            <p class="mt-2 text-2xl font-semibold text-emerald-200">{{ $stats['completed'] }}</p>
        </div>
        <div class="rounded-md border border-neutral-800 bg-neutral-900/60 p-4">
            <p class="text-xs uppercase text-neutral-500">W kolejce</p>
            <p class="mt-2 text-2xl font-semibold text-amber-200">{{ $stats['queued'] }}</p>
        </div>
        <div class="rounded-md border border-neutral-800 bg-neutral-900/60 p-4">
            <p class="text-xs uppercase text-neutral-500">W AI</p>
            <p class="mt-2 text-2xl font-semibold text-sky-200">{{ $stats['running'] }}</p>
        </div>
        <div class="rounded-md border border-neutral-800 bg-neutral-900/60 p-4">
            <p class="text-xs uppercase text-neutral-500">Błędy</p>
            <p class="mt-2 text-2xl font-semibold text-rose-200">{{ $stats['failed'] }}</p>
        </div>
    </div>

    <div class="mb-6 rounded-md border border-neutral-800 bg-neutral-900/60 p-4 text-sm text-neutral-300">
        @if ($import['missing'])
            <p class="font-medium text-amber-200">Nie znaleziono pliku CSV.</p>
            <p class="mt-1 text-neutral-400">Oczekiwana ścieżka: <span class="font-mono text-neutral-200">{{ $import['path'] }}</span></p>
            <p class="mt-3 text-neutral-400">Nagłówki przykładowe: <span class="font-mono text-neutral-200">target,mission,candidate_id,period_days,epoch_bjd,duration_hours,use_llm,threshold</span></p>
        @else
            <p>
                Synchronizacja CSV:
                <span class="text-neutral-100">{{ $import['seen'] }}</span> odczytanych,
                <span class="text-emerald-200">{{ $import['imported'] }}</span> nowych,
                <span class="text-cyan-200">{{ $import['updated'] }}</span> zaktualizowanych,
                <span class="text-amber-200">Pominięte duplikaty: {{ $import['skipped_duplicates'] }}</span>,
                <span class="text-rose-200">niepoprawne: {{ $import['skipped_invalid'] }}</span>.
            </p>
            <p class="mt-1 text-xs text-neutral-500">{{ $import['path'] }}</p>
        @endif
    </div>

    <div class="overflow-hidden rounded-md border border-neutral-800 bg-neutral-950">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-800 text-sm">
                <thead class="bg-neutral-900 text-left text-xs uppercase text-neutral-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Target</th>
                        <th class="px-4 py-3 font-medium">Misja</th>
                        <th class="px-4 py-3 font-medium">Candidate ID</th>
                        <th class="px-4 py-3 font-medium">Okres</th>
                        <th class="px-4 py-3 font-medium">Epoka BJD</th>
                        <th class="px-4 py-3 font-medium">Czas trwania</th>
                        <th class="px-4 py-3 font-medium">Predykcja</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-900">
                    @forelse ($exoplanets as $exoplanet)
                        <tr class="hover:bg-neutral-900/70">
                            <td class="px-4 py-3 font-medium text-white">{{ $exoplanet->target ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-300">{{ $exoplanet->mission ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-neutral-300">{{ $exoplanet->candidate_id ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-300">{{ $exoplanet->period_days !== null ? number_format($exoplanet->period_days, 6, '.', '') . ' d' : '—' }}</td>
                            <td class="px-4 py-3 text-neutral-300">{{ $exoplanet->epoch_bjd !== null ? number_format($exoplanet->epoch_bjd, 6, '.', '') : '—' }}</td>
                            <td class="px-4 py-3 text-neutral-300">{{ $exoplanet->duration_hours !== null ? number_format($exoplanet->duration_hours, 3, '.', '') . ' h' : '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-md border px-2 py-1 text-xs {{ $statusClasses[$exoplanet->prediction_status] ?? $statusClasses['not_requested'] }}">
                                    {{ $statusLabels[$exoplanet->prediction_status] ?? $exoplanet->prediction_status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('exoplanets.show', $exoplanet) }}" class="rounded-md border border-neutral-700 px-3 py-2 text-xs font-medium text-neutral-200 hover:border-cyan-300 hover:text-white">Szczegóły</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-neutral-500">
                                Brak obiektów do wyświetlenia.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">
        {{ $exoplanets->links() }}
    </div>
@endsection
