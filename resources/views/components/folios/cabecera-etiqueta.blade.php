@props(['area', 'seccion', 'titulo', 'codigo', 'descripcion' => null])

<header {{ $attributes->class('folios-etiqueta folios-etiqueta--cabecera') }} data-folios-masthead>
    <div>
        <p class="folios-etiqueta__area"><x-folios.gajo :area="$area" :size="18" class="folios-gajo--hoja" />{{ $seccion }}</p>
        <h1>{{ $titulo }}</h1>
        <p class="folios-etiqueta__codigo">{{ $codigo }}</p>
        @if (filled($descripcion))
            <p class="folios-etiqueta__descripcion">{{ $descripcion }}</p>
        @endif
    </div>
    {{ $slot }}
    <div class="folios-perforacion folios-etiqueta__corte" aria-hidden="true"></div>
</header>
