@props(['area', 'titulo', 'id'])

<section {{ $attributes->class('folios-ticket folios-bandeja') }} data-ticket-area="{{ $area }}" aria-labelledby="{{ $id }}">
    <header class="folios-bandeja__cabecera">
        <x-folios.gajo :area="$area" :size="24" class="folios-gajo--hoja" />
        <h2 id="{{ $id }}">{{ $titulo }}</h2>
        {{ $resumen ?? '' }}
    </header>
    <div class="folios-perforacion" aria-hidden="true"></div>
    <div class="folios-bandeja__contenido">{{ $slot }}</div>
</section>
