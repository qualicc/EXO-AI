@php
    use Illuminate\Support\Str;

    $labelFor = static fn (string $key): string => Str::headline(str_replace(['_', '-'], ' ', $key));
@endphp

@if (is_bool($value))
    <span class="{{ $value ? 'text-emerald-200' : 'text-neutral-400' }}">{{ $value ? 'tak' : 'nie' }}</span>
@elseif (is_int($value) || is_float($value))
    <span class="font-mono">{{ abs((float) $value) >= 1000 || (abs((float) $value) > 0 && abs((float) $value) < 0.001) ? sprintf('%.4e', $value) : rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') }}</span>
@elseif (is_array($value))
    @if ($value === [])
        <span class="text-neutral-500">brak</span>
    @elseif (array_is_list($value))
        <div class="flex flex-wrap gap-2">
            @foreach ($value as $item)
                @if (is_array($item))
                    <div class="w-full rounded-md border border-neutral-800 bg-neutral-950 p-3">
                        @foreach ($item as $itemKey => $itemValue)
                            <div class="grid gap-1 border-b border-neutral-900 py-2 last:border-0 sm:grid-cols-[180px_minmax(0,1fr)]">
                                <span class="text-xs uppercase text-neutral-500">{{ $labelFor((string) $itemKey) }}</span>
                                <span class="break-words text-neutral-200">@include('exoplanets.partials.value', ['value' => $itemValue])</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <span class="rounded-md border border-neutral-800 bg-neutral-950 px-2 py-1 text-xs text-neutral-200">@include('exoplanets.partials.value', ['value' => $item])</span>
                @endif
            @endforeach
        </div>
    @else
        <dl class="space-y-2">
            @foreach ($value as $nestedKey => $nestedValue)
                <div class="grid gap-1 sm:grid-cols-[180px_minmax(0,1fr)]">
                    <dt class="text-xs uppercase text-neutral-500">{{ $labelFor((string) $nestedKey) }}</dt>
                    <dd class="break-words text-neutral-200">@include('exoplanets.partials.value', ['value' => $nestedValue])</dd>
                </div>
            @endforeach
        </dl>
    @endif
@elseif (filled($value))
    <span>{{ $value }}</span>
@else
    <span class="text-neutral-500">brak</span>
@endif
