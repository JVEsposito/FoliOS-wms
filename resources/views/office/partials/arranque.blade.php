{{--
    Final de <head> de cada oficina, antes del primer pintado:
    - script en línea con el tema y la sesión (resources/js/office-arranque.js);
    - estilos y script del menú de Oficina, después de los de la página para conservar
      la cascada. Antes iban dentro del componente de navegación, en <body>, y el menú
      alcanzaba a pintarse sin estilo al cambiar de pestaña.
    En /FoliOS ($soloTema) solo se aplica el tema.
--}}
<script{!! ($soloTema ?? false) ? ' data-solo-tema' : '' !!}>{!! file_get_contents(resource_path('js/office-arranque.js')) !!}</script>
@unless ($soloTema ?? false)
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/office-corporate.css', 'resources/css/office-shell.css', 'resources/css/office-folios.css', 'resources/js/office-navigation.js'])
    @endif
@endunless
