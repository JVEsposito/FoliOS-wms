@props([
    // materia-prima | frigorifico | materiales | consultas | gerencia | administracion | operacion
    'area' => 'operacion',
])

@php
    $areas = ['materia-prima', 'frigorifico', 'materiales', 'consultas', 'gerencia', 'administracion', 'operacion'];
    $area = in_array($area, $areas, true) ? $area : 'operacion';
@endphp

<span {{ $attributes->class('folios-area-tag') }} data-area="{{ $area }}"><x-folios.gajo :area="$area" :size="16" class="folios-gajo--light" /><span>{{ $slot }}</span></span>
