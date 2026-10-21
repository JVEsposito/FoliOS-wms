<?php

namespace App\Services\InspeccionSag;

use App\Models\CargaFolio;
use App\Models\Embarque;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Facades\DB;

class DatosDespachoSag
{
    public function fuente(string $referencia): array
    {
        $embarque = Embarque::with(['cliente', 'instructivos.paisDestino', 'instructivos.puertoDestino', 'puertoEmbarque'])
            ->where(fn ($q) => $q->where('id', $referencia)->orWhere('codigo', $referencia))->firstOrFail();
        if ($embarque->temporada_id !== app(ServicioTemporadaActiva::class)->obtener()->id || $embarque->estado->value === 'cancelado') {
            throw new DomainException('Selecciona un embarque vigente de la temporada activa.');
        }
        $folios = CargaFolio::with('folio')->where('carga_id', $embarque->carga_id)
            ->whereNotIn('estado', ['descartado', 'reemplazado'])->get();
        $lineas = [];
        $pesos = app(KilosDocumentoSag::class);
        $configuracion = DB::table('configuracion_contraparte_sag')->where('id', 'planta')->value('datos');
        $cspPlanta = $configuracion ? (json_decode($configuracion, true)['csp'] ?? '') : '';
        foreach ($folios as $asignacion) {
            $folio = $asignacion->folio;
            $datos = $folio->datos_externos ?? [];
            $composicion = array_values(array_filter($datos['composicion'] ?? [], 'is_array')) ?: [$datos];
            foreach ($composicion as $indice => $parte) {
                $cajas = (int) ($parte['cantidad_cajas'] ?? 0);
                $parcial = clone $folio;
                $parcial->datos_externos = [...$datos, ...$parte, 'composicion' => [], 'cantidad_cajas' => $cajas];
                $lineas[] = ['linea_id' => $folio->id.':'.($indice + 1), 'folio_id' => $folio->id, 'folio' => $folio->numero_folio,
                    'especie' => $parte['especie'] ?? $datos['especie'] ?? '', 'variedad' => $parte['variedad'] ?? $folio->variedad ?? '',
                    'lote' => $parte['lote'] ?? $datos['lote'] ?? '', 'cantidad' => $cajas, 'kilos' => $pesos->calcular($parcial),
                    'csg' => (string) ($parte['csg'] ?? $datos['csg'] ?? ''), 'csp' => (string) ($parte['csp'] ?? $datos['csp'] ?? ($folio->origen_sistema === 'recepcion_externa' ? '' : $cspPlanta)),
                    'provincia' => $parte['provincia'] ?? $datos['provincia'] ?? '', 'comuna' => $parte['comuna'] ?? $datos['comuna'] ?? ''];
            }
        }

        return ['embarque_id' => $embarque->id, 'solicitud' => $embarque->codigo, 'fecha_actividad' => $embarque->fecha_programada->toDateString(),
            'transporte' => ['exportador' => $embarque->cliente->nombre, 'nave_vuelo' => $embarque->nave_vuelo,
                'puerto_embarque' => $embarque->puertoEmbarque?->nombre ?? $embarque->puerto_embarque,
                'contenedor' => $embarque->contenedor, 'sello' => $embarque->sello, 'patente' => $embarque->patente_camion,
                'patente_trasera' => $embarque->patente_trasera, 'modalidad' => $embarque->modalidad->value],
            'instructivos' => $embarque->instructivos->map(fn ($i) => ['id' => $i->id, 'numero' => $i->numero_externo ?: (string) $i->orden,
                'destino_pais' => $i->paisDestino?->nombre_es ?? $i->destino_pais, 'destino_ciudad' => $i->puertoDestino?->nombre ?? $i->destino_ciudad,
                'recibidor' => $i->recibidor, 'sello_sag' => $i->sello_sag, 'planilla_sag' => $i->planilla_sag])->values()->all(), 'lineas' => $lineas];
    }

    public function preparar(array $entrada): array
    {
        $fuente = $this->fuente($entrada['embarque_id']);
        $instructivos = collect($fuente['instructivos']);
        $id = $entrada['instructivo_id'] ?? ($instructivos->count() === 1 ? $instructivos->first()['id'] : null);
        $instructivo = $instructivos->firstWhere('id', $id);
        if (! $instructivo) {
            throw new DomainException('Selecciona el instructivo del embarque para documentar su destino.');
        }
        $seleccion = $entrada['seleccion_folios'] ?? [];
        if ($instructivos->count() > 1 && $seleccion === []) {
            throw new DomainException('Este embarque tiene varios instructivos. Selecciona expresamente los pallets del documento.');
        }
        $disponibles = collect($fuente['lineas'])->pluck('folio_id')->unique()->all();
        if (array_diff($seleccion, $disponibles) !== []) {
            throw new DomainException('Un folio seleccionado ya no pertenece a la carga efectiva del embarque.');
        }
        $lineas = collect($fuente['lineas'])->filter(fn ($l) => $seleccion === [] || in_array($l['folio_id'], $seleccion, true))->values()->all();
        if ($lineas === []) {
            throw new DomainException('El embarque no tiene pallets efectivos para este documento.');
        }
        foreach ($lineas as &$linea) {
            $clave = $linea['linea_id'];
            $linea['kilos'] = isset($entrada['kilos'][$clave]) ? (float) $entrada['kilos'][$clave] : $linea['kilos'];
            foreach (['provincia', 'comuna', 'csg', 'csp'] as $campo) {
                $linea[$campo] = $entrada['origenes'][$clave][$campo] ?? $linea[$campo];
            }
        }
        unset($linea);
        $transporte = [...$fuente['transporte'], 'destino_pais' => $instructivo['destino_pais'], 'destino_ciudad' => $instructivo['destino_ciudad'],
            'consignatario' => $instructivo['recibidor'], 'sello' => $instructivo['sello_sag'] ?: $fuente['transporte']['sello'],
            'referencia_multipuerto' => $instructivo['planilla_sag'], ...($entrada['encabezado']['despacho'] ?? [])];

        return [...$fuente, 'instructivo_id' => $instructivo['id'], 'lineas' => $lineas, 'transporte' => $transporte,
            'pallets' => collect($lineas)->pluck('folio_id')->unique()->count()];
    }

    public function validarEmision(array $detalle): void
    {
        $d = $detalle['transporte'];
        foreach (['exportador', 'rut_exportador', 'agente', 'rut_agente', 'destino_pais', 'puerto_embarque', 'oficina_sag_puerto', 'patente', 'guias', 'tipo_produccion', 'transgenico', 'tratamiento', 'modo'] as $campo) {
            if (blank($d[$campo] ?? null)) {
                throw new DomainException('Completa los datos de despacho antes de emitir: '.$campo.'.');
            }
        }
        if (($d['modo'] === 'contingencia' && blank($d['motivo_contingencia'] ?? null)) || ! isset($d['nimf15'], $d['inspeccionado'])) {
            throw new DomainException('Registra la verificación física y el motivo si corresponde a contingencia.');
        }
        if ($d['tratamiento'] === 'tratado' && blank($d['detalle_tratamiento'] ?? null)) {
            throw new DomainException('Indica los antecedentes del tratamiento declarado.');
        }
        foreach ($detalle['lineas'] as $linea) {
            if ($linea['cantidad'] <= 0 || $linea['kilos'] === null || blank($linea['especie']) || blank($linea['variedad']) || blank($linea['csg']) || blank($linea['csp']) || blank($linea['provincia']) || blank($linea['comuna'])) {
                throw new DomainException('Completa kilos, producto y origen de cada línea del anexo por pallet.');
            }
        }
    }
}
