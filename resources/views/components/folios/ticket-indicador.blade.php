@props(['area', 'clave', 'rotulo', 'valorId', 'valor' => '0', 'detalle' => null])

<article {{ $attributes->class('folios-ticket folios-ticket--indicador') }} data-ticket-area="{{ $area }}">
    <div class="folios-ticket__stub" aria-hidden="true">
        <x-folios.gajo :area="$area" :size="18" class="folios-gajo--hoja" />
        <span>{{ $clave }}</span>
    </div>
    <div class="folios-ticket__contenido">
        <span class="folios-ticket__rotulo">{{ $rotulo }}</span>
        <strong class="folios-ticket__valor" id="{{ $valorId }}">{{ $valor }}</strong>
        @if ($detalle)<small>{{ $detalle }}</small>@endif
    </div>
</article>
