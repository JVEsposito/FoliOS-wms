@props([
    // materia-prima | frigorifico | materiales | consultas | gerencia | administracion | operacion | null
    'area' => null,
    'size' => 24,
    'label' => null,
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
@endphp

<svg viewBox="0 0 32 32" width="{{ $size }}" height="{{ $size }}"
    {{ $attributes->class(['folios-gajo', 'folios-gajo--center' => $center]) }}
    data-area="{{ $resolvedArea }}"
    @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif
>@foreach ($leaves as $key => $path)<path d="{{ $path }}" @class(['folios-gajo__leaf', 'is-area' => $key === $area]) />@endforeach<circle cx="16" cy="16" r="{{ $center ? '4.4' : '2.6' }}" class="folios-gajo__hub" /></svg>
