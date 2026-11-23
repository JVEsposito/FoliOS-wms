@props(['id' => 'foliosAtencion'])

<section class="folios-ticket folios-atencion" data-folios-atencion aria-labelledby="{{ $id }}Title">
    <div class="folios-atencion__graphic">
        <x-folios.gajo area="operacion" :size="160" :attention="true" />
        <p data-attention-status role="status">Consultando pendientes…</p>
    </div>
    <div>
        <h2 id="{{ $id }}Title">Dónde mirar ahora</h2>
        <ul class="folios-atencion__areas">
            @foreach (['materia-prima' => 'Materia Prima', 'frigorifico' => 'Frigorífico', 'materiales' => 'Materiales', 'consultas' => 'Consultas', 'gerencia' => 'Gerencia', 'administracion' => 'Administración'] as $area => $label)
                <li data-attention-area="{{ $area }}" hidden>
                    <x-folios.gajo :area="$area" :size="20" class="folios-gajo--hoja" />
                    <div>
                        <strong>{{ $label }}</strong>
                        <ul data-attention-sources></ul>
                    </div>
                    <span data-attention-label></span>
                </li>
            @endforeach
        </ul>
    </div>
</section>
