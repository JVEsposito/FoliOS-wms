@props([
    // materia-prima | frigorifico | materiales | consultas | gerencia | administracion | operacion | null
    'area' => null,
    'size' => 24,
    'label' => null,
    'attention' => false,
])

@php
    // Geometría oficial del símbolo FoliOS (viewBox 32). El orden de las hojas es el de las
    // áreas en el menú lateral, en sentido horario desde las 12.
    $leaves = [
        'materia-prima' => 'M16,16 L16,2 L26.97,7.3 Z',
        'frigorifico' => 'M16,16 L28.12,9 L29.02,21.15 Z',
        'materiales' => 'M16,16 L28.12,23 L18.05,29.85 Z',
        'consultas' => 'M16,16 L16,30 L5.03,24.7 Z',
        'gerencia' => 'M16,16 L3.88,23 L2.98,10.85 Z',
        'administracion' => 'M16,16 L3.88,9 L13.95,2.15 Z',
    ];
    $center = $area === 'operacion';
    $resolvedArea = $center || array_key_exists((string) $area, $leaves) ? $area : 'ninguna';
    $textPositions = [
        'materia-prima' => [19.6, 8.6],
        'frigorifico' => [24.1, 15.3],
        'materiales' => [20.7, 22.9],
        'consultas' => [12.3, 23.5],
        'gerencia' => [7.9, 16.7],
        'administracion' => [11.4, 9.1],
    ];
@endphp

<svg viewBox="0 0 32 32" width="{{ $size }}" height="{{ $size }}"
    {{ $attributes->class(['folios-gajo', 'folios-gajo--center' => $center, 'folios-gajo--attention' => $attention]) }}
    data-area="{{ $resolvedArea }}"
    @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif
>
    @foreach ($leaves as $key => $path)
        @if ($attention)<g class="folios-gajo" data-area="{{ $key }}">@endif
        <path d="{{ $path }}" @class(['folios-gajo__leaf', 'is-area' => $key === $area]) @if ($attention) data-gajo-leaf="{{ $key }}" @endif />
        @if ($attention)
            <text x="{{ $textPositions[$key][0] }}" y="{{ $textPositions[$key][1] }}" data-gajo-count="{{ $key }}"></text>
            </g>
        @endif
    @endforeach
    <circle cx="16" cy="16" r="{{ $center ? '4.4' : '2.6' }}" class="folios-gajo__hub" />
    @if ($attention)
        <text x="16" y="16" data-gajo-total>—</text>
    @endif
</svg>
