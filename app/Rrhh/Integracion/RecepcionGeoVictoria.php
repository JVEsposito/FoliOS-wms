<?php

namespace App\Rrhh\Integracion;

use App\Models\User;
use App\Rrhh\Asistencia\ImportadorMarcajes;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\FuenteMarcaje;
use App\Rrhh\Models\LoteIntegracion;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecepcionGeoVictoria
{
    public function __construct(private EsquemaRrhh $e, private ClienteGeoVictoria $cliente, private ImportadorMarcajes $importador, private Auditoria $auditoria) {}

    public function consultar(FuenteMarcaje $fuente, array $d, User $actor): LoteIntegracion
    {
        $filas = $this->cliente->consultar($fuente, $d['desde'], $d['identificadores']);
        // Se confirma la recepción persistida antes de intentar importar una sola fila.
        $lote = $this->e->transaccion(function () use ($fuente, $d, $filas, $actor) {
            $lote = LoteIntegracion::query()->create(['id' => (string) Str::uuid(), 'fuente_id' => $fuente->id, 'estado' => 'recibido', 'consulta' => [...$d, 'zona_origen' => $fuente->configuracion['zona_origen']], 'contenido' => $filas]);
            $this->auditoria->registrar('lote_integracion', $lote->id, 'recibido', ['fuente_id' => $fuente->id, 'cantidad' => count($filas)], $actor);

            return $lote;
        });

        return $this->procesar($lote, $actor);
    }

    public function procesar(LoteIntegracion $lote, User $actor): LoteIntegracion
    {
        $candado = 'lote-'.$lote->id;
        if (! $this->e->tomarCandado($candado)) {
            throw ValidationException::withMessages(['lote' => 'El lote se está procesando. Reintente.']);
        }
        try {
            $lote = $lote->fresh();
            if ($lote->estado === 'procesado') {
                return $lote;
            }
            $fuente = FuenteMarcaje::query()->findOrFail($lote->fuente_id);
            $fuente->configuracion = [...($fuente->configuracion ?? []), 'zona_origen' => $lote->consulta['zona_origen']]; // Copia en memoria, no modifica configuración.
            $resultado = $this->importador->ejecutar($fuente, $lote->contenido, $actor);

            return $this->e->transaccion(function () use ($lote, $resultado, $actor) {
                $lote->update(['estado' => $resultado->errores === [] ? 'procesado' : 'con_incidencias', 'intentos' => $lote->intentos + 1, 'resultado' => $resultado->toArray()]);
                $this->auditoria->registrar('lote_integracion', $lote->id, 'procesado', ['estado' => $lote->estado, 'intentos' => $lote->intentos, 'importacion_id' => $resultado->id], $actor);

                return $lote;
            });
        } finally {
            $this->e->liberarCandado($candado);
        }
    }
}
