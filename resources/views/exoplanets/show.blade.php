@extends('layouts.exoplanets', ['title' => ($exoplanet->displayName().' - egzoplaneta')])

@php
    use Illuminate\Support\Str;

    $prediction = $exoplanet->latestPrediction;
    $payload = $exoplanet->prediction_payload ?: [];
    $statusPayload = data_get($payload, 'status', []);
    $result = data_get($statusPayload, 'result')
        ?: data_get($payload, 'result')
        ?: data_get($prediction?->response_payload ?: [], 'latest_status.result')
        ?: [];
    $specialist = data_get($payload, 'specialist_data')
        ?: data_get($result, 'specialist_data')
        ?: ($prediction?->specialist_data ?: []);
    $predictionResult = data_get($result, 'prediction', []);
    $llmReport = data_get($result, 'llm_report', []);
    $metrics = data_get($result, 'metrics', []);
    $qualityFlags = data_get($result, 'quality_flags') ?: data_get($specialist, 'quality_flags', []);
    $plots = data_get($result, 'plots', []);
    $blsDiagnostics = data_get($specialist, 'bls_diagnostics', []);
    $expertDiagnostics = data_get($specialist, 'expert_diagnostics', []);
    $lightcurveStats = data_get($specialist, 'lightcurve_stats', []);
    $provenance = data_get($specialist, 'provenance', []);
    $products = data_get($provenance, 'products', []);
    $preprocessing = data_get($provenance, 'preprocessing_config', []);
    $provenanceSummary = collect($provenance)->except(['products', 'preprocessing_config'])->all();
    $activeFlags = collect($qualityFlags)->filter()->keys();
    $inactiveFlags = collect($qualityFlags)->reject()->keys();
    $planetProbability = (float) data_get($predictionResult, 'probability_planet_like', 0);
    $falsePositiveProbability = (float) data_get($predictionResult, 'probability_false_positive', 0);
    $planetPercent = round($planetProbability * 100, 1);
    $falsePositivePercent = round($falsePositiveProbability * 100, 1);
    $formatLabel = static fn (string $key): string => Str::headline(str_replace(['_', '-'], ' ', $key));
    $formatValue = static function (mixed $value, string $suffix = ''): string {
        if ($value === null || $value === '') {
            return 'brak';
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            $formatted = abs($number) >= 1000 || (abs($number) > 0 && abs($number) < 0.001)
                ? sprintf('%.4e', $number)
                : rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');

            return $formatted.$suffix;
        }

        return (string) $value.$suffix;
    };
    $statusLabels = [
        'not_requested' => 'Brak predykcji',
        'queued' => 'W kolejce',
        'submitting' => 'Wysylanie do AI',
        'running' => 'Uruchomione w AI',
        'completed' => 'Predykcja gotowa',
        'failed' => 'Blad',
    ];
    $plotLabels = [
        'raw_png' => 'Krzywa surowa',
        'flattened_png' => 'Krzywa po wyrownaniu',
        'periodogram_png' => 'Periodogram BLS',
        'folded_global_png' => 'Faza globalna',
        'folded_local_png' => 'Faza lokalna',
    ];
@endphp

@section('content')
    <div class="mb-6">
        <a href="{{ route('home') }}" class="text-sm text-cyan-300 hover:text-cyan-200">&larr; Wroc do katalogu</a>
        <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-medium text-neutral-400">{{ $exoplanet->mission ?? 'Nieznana misja' }}</p>
                <h1 class="mt-1 text-3xl font-semibold text-white">{{ $exoplanet->displayName() }}</h1>
                <p class="mt-2 text-sm text-neutral-400">{{ $exoplanet->target ?? 'Brak targetu w katalogu' }}</p>
            </div>

            <span class="inline-flex w-fit rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-200" data-status-label>
                {{ $statusLabels[$exoplanet->prediction_status] ?? $exoplanet->prediction_status }}
            </span>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)]">
        <section class="rounded-md border border-neutral-800 bg-neutral-900/60">
            <div class="border-b border-neutral-800 px-5 py-4">
                <h2 class="text-base font-semibold text-white">Dane katalogowe</h2>
            </div>
            <dl class="grid gap-px bg-neutral-800 text-sm sm:grid-cols-2">
                @forelse (($exoplanet->catalog_payload ?: []) as $key => $value)
                    <div class="bg-neutral-950 px-5 py-4">
                        <dt class="text-xs uppercase text-neutral-500">{{ $formatLabel((string) $key) }}</dt>
                        <dd class="mt-1 break-words text-neutral-200">@include('exoplanets.partials.value', ['value' => $value])</dd>
                    </div>
                @empty
                    <div class="bg-neutral-950 px-5 py-4 text-sm text-neutral-500">Brak danych katalogowych.</div>
                @endforelse
            </dl>
        </section>

        <aside class="space-y-6">
            <section class="rounded-md border border-neutral-800 bg-neutral-900/60 p-5">
                <h2 class="text-base font-semibold text-white">Predykcja</h2>
                <p class="mt-2 text-sm text-neutral-400">
                    @if ($exoplanet->hasPredictionData())
                        Dane predykcji sa zapisane lokalnie i pokazane ponizej w sekcjach.
                    @elseif ($prediction?->isActive())
                        Zlecenie jest aktywne. Status bedzie odswiezany automatycznie.
                    @else
                        Brak zapisanej predykcji dla tego obiektu.
                    @endif
                </p>

                @if (! $exoplanet->hasPredictionData())
                    <div class="mt-5">
                        @auth
                            @if ($prediction?->isActive())
                                <button disabled class="w-full rounded-md border border-neutral-700 px-4 py-2 text-sm text-neutral-500">Zlecenie aktywne</button>
                            @else
                                <form method="POST" action="{{ route('exoplanets.predictions.store', $exoplanet) }}">
                                    @csrf
                                    <button type="submit" class="w-full rounded-md bg-cyan-400 px-4 py-2 text-sm font-semibold text-neutral-950 hover:bg-cyan-300">
                                        Pobierz dane i wykonaj predykcje
                                    </button>
                                </form>
                            @endif
                        @else
                            <a href="{{ route('login') }}" class="block rounded-md border border-cyan-400 px-4 py-2 text-center text-sm font-semibold text-cyan-200 hover:bg-cyan-400 hover:text-neutral-950">
                                Zaloguj, aby zlecic predykcje
                            </a>
                        @endauth
                    </div>
                @endif
            </section>

            @if ($prediction)
                <section
                    class="rounded-md border border-neutral-800 bg-neutral-900/60 p-5"
                    @auth
                        @if ($prediction->isActive())
                            data-queue-status
                            data-has-prediction-data="{{ $exoplanet->hasPredictionData() ? '1' : '0' }}"
                            data-url="{{ route('exoplanets.queue-status', $exoplanet) }}"
                        @endif
                    @endauth
                >
                    <h2 class="text-base font-semibold text-white">Kolejka</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-neutral-500">Status</dt>
                            <dd class="text-right text-neutral-200" data-field="status">{{ $statusLabels[$prediction->status] ?? $prediction->status }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-neutral-500">Pozycja</dt>
                            <dd class="text-right text-neutral-200" data-field="position">{{ $queuePosition ? '#'.$queuePosition : 'brak' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-neutral-500">Czas od dodania</dt>
                            <dd class="text-right text-neutral-200" data-field="waited">{{ $prediction->queued_at ? $prediction->queued_at->diffForHumans(null, true) : 'brak' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-neutral-500">Aktualnie wysylany</dt>
                            <dd class="text-right text-neutral-200" data-field="current">{{ $currentPrediction?->exoplanet?->displayName() ?? 'brak' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-neutral-500">Remote job</dt>
                            <dd class="break-all text-right font-mono text-neutral-200" data-field="remote">{{ $prediction->remote_job_id ?? 'brak' }}</dd>
                        </div>
                    </dl>

                    @if ($prediction->error_message)
                        <p class="mt-4 rounded-md border border-rose-400/40 bg-rose-400/10 p-3 text-sm text-rose-100" data-field="error">{{ $prediction->error_message }}</p>
                    @else
                        <p class="mt-4 hidden rounded-md border border-rose-400/40 bg-rose-400/10 p-3 text-sm text-rose-100" data-field="error"></p>
                    @endif
                </section>
            @endif
        </aside>
    </div>

    @if ($result)
        <section class="mt-6 rounded-md border border-neutral-800 bg-neutral-900/60 p-5">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-sm font-medium text-cyan-300">Wynik modelu</p>
                    <h2 class="mt-1 text-2xl font-semibold text-white">
                        {{ $formatLabel((string) data_get($predictionResult, 'class_label', 'brak klasy')) }}
                    </h2>
                    <p class="mt-2 text-sm text-neutral-400">
                        Pewnosc: {{ $formatLabel((string) data_get($predictionResult, 'confidence_bucket', 'brak')) }},
                        prog: {{ $formatValue(data_get($predictionResult, 'threshold')) }}.
                    </p>
                </div>

                <div class="grid min-w-full gap-3 sm:min-w-[360px]">
                    <div>
                        <div class="mb-1 flex justify-between text-xs text-neutral-400">
                            <span>Planet-like</span>
                            <span>{{ $planetPercent }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-neutral-800">
                            <div class="h-full bg-emerald-400" style="width: {{ max(0, min(100, $planetPercent)) }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 flex justify-between text-xs text-neutral-400">
                            <span>False positive</span>
                            <span>{{ $falsePositivePercent }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-neutral-800">
                            <div class="h-full bg-rose-400" style="width: {{ max(0, min(100, $falsePositivePercent)) }}%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <dl class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-md border border-neutral-800 bg-neutral-950 p-4">
                    <dt class="text-xs uppercase text-neutral-500">Model</dt>
                    <dd class="mt-1 break-words text-sm text-neutral-200">{{ data_get($predictionResult, 'model_adapter', 'brak') }}</dd>
                </div>
                <div class="rounded-md border border-neutral-800 bg-neutral-950 p-4">
                    <dt class="text-xs uppercase text-neutral-500">Mock</dt>
                    <dd class="mt-1 text-sm text-neutral-200">@include('exoplanets.partials.value', ['value' => data_get($predictionResult, 'mock')])</dd>
                </div>
                <div class="rounded-md border border-neutral-800 bg-neutral-950 p-4 sm:col-span-2">
                    <dt class="text-xs uppercase text-neutral-500">Sciezka modelu</dt>
                    <dd class="mt-1 break-words font-mono text-xs text-neutral-200">{{ data_get($predictionResult, 'model_path', 'brak') }}</dd>
                </div>
            </dl>
        </section>

        @if ($llmReport)
            <section class="mt-6 rounded-md border border-neutral-800 bg-neutral-900/60">
                <div class="border-b border-neutral-800 px-5 py-4">
                    <p class="text-sm font-medium text-cyan-300">Raport</p>
                    <h2 class="mt-1 text-xl font-semibold text-white">{{ data_get($llmReport, 'title', 'Raport vettingu') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-neutral-300">{{ data_get($llmReport, 'summary') }}</p>
                </div>

                <div class="grid gap-px bg-neutral-800 lg:grid-cols-2">
                    <div class="bg-neutral-950 p-5">
                        <h3 class="text-sm font-semibold text-emerald-200">Argumenty za planet-like</h3>
                        <ul class="mt-3 space-y-2 text-sm leading-6 text-neutral-300">
                            @foreach ((array) data_get($llmReport, 'evidence_for_planet_like', []) as $item)
                                <li class="rounded-md border border-neutral-800 bg-neutral-900 p-3">{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="bg-neutral-950 p-5">
                        <h3 class="text-sm font-semibold text-rose-200">Argumenty za false-positive</h3>
                        <ul class="mt-3 space-y-2 text-sm leading-6 text-neutral-300">
                            @foreach ((array) data_get($llmReport, 'evidence_for_false_positive', []) as $item)
                                <li class="rounded-md border border-neutral-800 bg-neutral-900 p-3">{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="grid gap-px bg-neutral-800 lg:grid-cols-3">
                    <div class="bg-neutral-950 p-5 lg:col-span-1">
                        <h3 class="text-sm font-semibold text-amber-200">Ostrzezenia jakosci</h3>
                        <ul class="mt-3 space-y-2 text-sm leading-6 text-neutral-300">
                            @foreach ((array) data_get($llmReport, 'quality_warnings', []) as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="bg-neutral-950 p-5 lg:col-span-2">
                        <h3 class="text-sm font-semibold text-white">Rekomendacja</h3>
                        <p class="mt-3 text-sm leading-6 text-neutral-300">{{ data_get($llmReport, 'recommendation', 'brak') }}</p>
                        <h3 class="mt-5 text-sm font-semibold text-white">Notatka specjalistyczna</h3>
                        <p class="mt-3 text-sm leading-6 text-neutral-300">{{ data_get($llmReport, 'specialist_note', 'brak') }}</p>
                    </div>
                </div>
            </section>
        @endif

        @if ($plots)
            <section class="mt-6 rounded-md border border-neutral-800 bg-neutral-900/60">
                <div class="border-b border-neutral-800 px-5 py-4">
                    <h2 class="text-base font-semibold text-white">Wykresy</h2>
                </div>
                <div class="grid gap-px bg-neutral-800 lg:grid-cols-2">
                    @foreach ($plots as $plotKey => $plotUrl)
                        <figure class="bg-neutral-950 p-4">
                            <figcaption class="mb-3 text-sm font-medium text-neutral-200">{{ $plotLabels[$plotKey] ?? $formatLabel((string) $plotKey) }}</figcaption>
                            <button
                                type="button"
                                class="block w-full cursor-zoom-in rounded-md border border-neutral-800 bg-white transition hover:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/50"
                                data-plot-preview-trigger
                                data-plot-src="{{ route('exoplanets.plots.show', [$exoplanet, $plotKey]) }}"
                                data-plot-title="{{ $plotLabels[$plotKey] ?? $formatLabel((string) $plotKey) }}"
                            >
                                <img
                                    src="{{ route('exoplanets.plots.show', [$exoplanet, $plotKey]) }}"
                                    alt="{{ $plotLabels[$plotKey] ?? $formatLabel((string) $plotKey) }}"
                                    loading="lazy"
                                    class="aspect-video w-full rounded-md object-contain"
                                >
                            </button>
                        </figure>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="mt-6 rounded-md border border-neutral-800 bg-neutral-900/60">
            <div class="border-b border-neutral-800 px-5 py-4">
                <h2 class="text-base font-semibold text-white">Metryki tranzytu</h2>
            </div>
            <dl class="grid gap-px bg-neutral-800 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($metrics as $key => $value)
                    <div class="bg-neutral-950 p-4">
                        <dt class="text-xs uppercase text-neutral-500">{{ $formatLabel((string) $key) }}</dt>
                        <dd class="mt-1 text-sm text-neutral-200">@include('exoplanets.partials.value', ['value' => $value])</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        @if ($qualityFlags)
            <section class="mt-6 rounded-md border border-neutral-800 bg-neutral-900/60 p-5">
                <h2 class="text-base font-semibold text-white">Flagi jakosci</h2>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <h3 class="text-sm font-medium text-amber-200">Aktywne</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @forelse ($activeFlags as $flag)
                                <span class="rounded-md border border-amber-400/40 bg-amber-400/10 px-2 py-1 text-xs text-amber-100">{{ $formatLabel((string) $flag) }}</span>
                            @empty
                                <span class="text-sm text-neutral-500">Brak aktywnych flag.</span>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-neutral-300">Nieaktywne</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($inactiveFlags as $flag)
                                <span class="rounded-md border border-neutral-800 bg-neutral-950 px-2 py-1 text-xs text-neutral-400">{{ $formatLabel((string) $flag) }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>
        @endif

        @foreach ([
            'Diagnostyka BLS' => $blsDiagnostics,
            'Diagnostyka ekspercka' => $expertDiagnostics,
            'Statystyki krzywej blasku' => $lightcurveStats,
            'Pochodzenie danych' => $provenanceSummary,
            'Konfiguracja preprocessingu' => $preprocessing,
        ] as $sectionTitle => $sectionData)
            @if ($sectionData)
                <section class="mt-6 rounded-md border border-neutral-800 bg-neutral-900/60">
                    <div class="border-b border-neutral-800 px-5 py-4">
                        <h2 class="text-base font-semibold text-white">{{ $sectionTitle }}</h2>
                    </div>
                    <dl class="grid gap-px bg-neutral-800 sm:grid-cols-2">
                        @foreach ($sectionData as $key => $value)
                            <div class="bg-neutral-950 p-4">
                                <dt class="text-xs uppercase text-neutral-500">{{ $formatLabel((string) $key) }}</dt>
                                <dd class="mt-1 break-words text-sm text-neutral-200">@include('exoplanets.partials.value', ['value' => $value])</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif
        @endforeach

        @if ($products)
            <section class="mt-6 overflow-hidden rounded-md border border-neutral-800 bg-neutral-900/60">
                <div class="border-b border-neutral-800 px-5 py-4">
                    <h2 class="text-base font-semibold text-white">Produkty danych</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-800 text-sm">
                        <thead class="bg-neutral-900 text-left text-xs uppercase text-neutral-500">
                            <tr>
                                <th class="px-4 py-3 font-medium">Misja</th>
                                <th class="px-4 py-3 font-medium">Autor</th>
                                <th class="px-4 py-3 font-medium">Ekspozycja</th>
                                <th class="px-4 py-3 font-medium">Plik</th>
                                <th class="px-4 py-3 font-medium">Obs ID</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-900">
                            @foreach ($products as $product)
                                <tr>
                                    <td class="px-4 py-3 text-neutral-200">{{ data_get($product, 'mission_label', 'brak') }}</td>
                                    <td class="px-4 py-3 text-neutral-300">{{ data_get($product, 'author', 'brak') }}</td>
                                    <td class="px-4 py-3 text-neutral-300">{{ $formatValue(data_get($product, 'exptime_seconds'), ' s') }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-neutral-300">{{ data_get($product, 'productFilename', 'brak') }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-neutral-300">{{ data_get($product, 'obs_id', 'brak') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @elseif ($prediction?->status === 'failed')
        <section class="mt-6 rounded-md border border-rose-400/40 bg-rose-400/10 p-5">
            <h2 class="text-base font-semibold text-rose-100">Predykcja zakonczyla sie bledem</h2>
            <p class="mt-2 text-sm leading-6 text-rose-100">{{ $prediction->error_message ?: 'Drugi system nie zwrocil szczegolow bledu.' }}</p>
        </section>
    @else
        <section class="mt-6 rounded-md border border-neutral-800 bg-neutral-900/60 p-5">
            <h2 class="text-base font-semibold text-white">Dane predykcji</h2>
            <p class="mt-2 text-sm text-neutral-500">Brak gotowych danych predykcji.</p>
        </section>
    @endif

    <div
        class="fixed inset-0 z-50 hidden items-center justify-center bg-black/85 p-4"
        data-plot-preview
        aria-hidden="true"
    >
        <button
            type="button"
            class="absolute inset-0 cursor-default"
            data-plot-preview-backdrop
            aria-label="Zamknij podglad"
        ></button>
        <div class="relative z-10 flex max-h-full w-full max-w-6xl flex-col gap-3">
            <div class="flex items-center justify-between gap-3 rounded-md border border-neutral-700 bg-neutral-950 px-4 py-3">
                <h2 class="truncate text-sm font-semibold text-white" data-plot-preview-title>Podglad wykresu</h2>
                <button
                    type="button"
                    class="flex size-9 items-center justify-center rounded-md border border-neutral-700 text-lg leading-none text-neutral-200 hover:border-cyan-300 hover:text-white focus:outline-none focus:ring-2 focus:ring-cyan-300/50"
                    data-plot-preview-close
                    aria-label="Zamknij podglad"
                >X</button>
            </div>
            <div class="overflow-auto rounded-md border border-neutral-700 bg-white p-2">
                <img
                    src=""
                    alt=""
                    class="mx-auto max-h-[78vh] w-auto max-w-full object-contain"
                    data-plot-preview-image
                >
            </div>
        </div>
    </div>

    @auth
        <script>
            (() => {
                const panel = document.querySelector('[data-queue-status]');

                if (!panel) {
                    return;
                }

                const labels = {
                    queued: 'W kolejce',
                    submitting: 'Wysylanie do AI',
                    running: 'Uruchomione w AI',
                    completed: 'Predykcja gotowa',
                    failed: 'Blad',
                };

                const field = (name) => panel.querySelector(`[data-field="${name}"]`);
                const formatWait = (seconds) => {
                    if (seconds === null || seconds === undefined) {
                        return 'brak';
                    }

                    const minutes = Math.floor(seconds / 60);
                    const rest = seconds % 60;

                    return minutes > 0 ? `${minutes} min ${rest} s` : `${rest} s`;
                };

                const update = async () => {
                    const response = await fetch(panel.dataset.url, {
                        headers: {
                            Accept: 'application/json',
                        },
                    });

                    if (!response.ok) {
                        return;
                    }

                    const data = await response.json();
                    const prediction = data.prediction;

                    if (!prediction) {
                        return;
                    }

                    field('status').textContent = labels[prediction.status] ?? prediction.status;
                    field('position').textContent = prediction.queue_position ? `#${prediction.queue_position}` : 'brak';
                    field('waited').textContent = formatWait(prediction.waited_seconds);
                    field('current').textContent = data.current_prediction?.label ?? 'brak';
                    field('remote').textContent = prediction.remote_job_id ?? 'brak';

                    const pageStatus = document.querySelector('[data-status-label]');

                    if (pageStatus) {
                        pageStatus.textContent = labels[data.prediction_status] ?? data.prediction_status;
                    }

                    const error = field('error');

                    if (error) {
                        error.textContent = prediction.error_message ?? '';
                        error.classList.toggle('hidden', !prediction.error_message);
                    }

                    if (data.has_prediction_data && panel.dataset.hasPredictionData !== '1') {
                        window.clearInterval(timer);
                        window.location.reload();

                        return;
                    }

                    if (['completed', 'failed'].includes(prediction.status)) {
                        window.clearInterval(timer);
                    }
                };

                update();
                const timer = window.setInterval(update, 3000);
            })();
        </script>
    @endauth

    <script>
        (() => {
            const modal = document.querySelector('[data-plot-preview]');
            const image = document.querySelector('[data-plot-preview-image]');
            const title = document.querySelector('[data-plot-preview-title]');
            const closeButtons = document.querySelectorAll('[data-plot-preview-close], [data-plot-preview-backdrop]');
            const triggers = document.querySelectorAll('[data-plot-preview-trigger]');

            if (!modal || !image || !title || triggers.length === 0) {
                return;
            }

            const open = (trigger) => {
                image.src = trigger.dataset.plotSrc;
                image.alt = trigger.dataset.plotTitle;
                title.textContent = trigger.dataset.plotTitle;
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            };

            const close = () => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
                image.src = '';
                image.alt = '';
            };

            triggers.forEach((trigger) => {
                trigger.addEventListener('click', () => open(trigger));
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', close);
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') {
                    close();
                }
            });
        })();
    </script>
@endsection
