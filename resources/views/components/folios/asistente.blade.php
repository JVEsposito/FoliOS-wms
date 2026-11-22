@props(['area' => 'operacion'])
{{--
    Gajo asistente de Oficina (docs/ASISTENTE_OFICINA.md). Nace oculto: office-asistente.js
    lo muestra solo si la sesión trae la capacidad puede_usar_asistente. Nunca se abre ni
    avisa por iniciativa propia.
--}}
<div class="folios-asistente" data-asistente data-esquina="abajo-derecha" hidden>
    <button class="folios-asistente__gajo" type="button" data-asistente-gajo
        aria-expanded="false" aria-controls="foliosAsistentePanel" title="Asistente (Alt+A)">
        <x-folios.gajo :area="$area" :size="30" />
        <span class="estiba-office-sr">Abrir el asistente</span>
    </button>

    <section class="folios-asistente__panel" id="foliosAsistentePanel" role="dialog" aria-modal="false"
        aria-labelledby="foliosAsistenteTitulo" data-asistente-panel hidden>
        <header class="folios-asistente__cabecera">
            <h2 id="foliosAsistenteTitulo">Asistente</h2>
            <span class="folios-asistente__modo" data-asistente-modo>Sin IA</span>
            <button type="button" class="folios-asistente__cerrar" data-asistente-cerrar>
                <span aria-hidden="true">×</span><span class="estiba-office-sr">Cerrar el asistente</span>
            </button>
        </header>
        <div class="folios-asistente__historial" data-asistente-historial aria-live="polite">
            <p class="folios-asistente__ayuda">Pregunta por tiempos, kilos, kilos por CSG, cajas, formatos, saldos, repas, consumos o «revisa las taras de hoy». También puedes escribir un código, como una recepción o un folio.</p>
        </div>
        <form class="folios-asistente__formulario" data-asistente-formulario>
            <label class="estiba-office-sr" for="foliosAsistenteTexto">Pregunta</label>
            <input id="foliosAsistenteTexto" name="texto" type="text" maxlength="500" autocomplete="off"
                placeholder="Por ejemplo: tiempos de romana hoy" data-asistente-texto>
            <button type="submit" data-asistente-enviar>Preguntar</button>
        </form>
    </section>
</div>
