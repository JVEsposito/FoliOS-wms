<?php

namespace App\Rrhh\Integracion;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\EntregaNomina;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class IntercambioNomina
{
    /** Se ejecuta dentro de la MISMA transacción de aprobación/anulación. Una entrega por destino remuneracional. */
    public function preparar(MovimientoNomina $m): void
    {
        if (! $m->aprobado_at) {
            return;
        } // Anular un borrador no ordena revertir un pago inexistente.
        $concepto = $m->calculo['concepto'];
        $contenido = ['contrato' => 'folios.rrhh.novedad.v1', 'evento_id' => (string) Str::uuid(), 'movimiento_id' => $m->id, 'accion' => $m->estado,
            'persona_codigo' => Persona::query()->findOrFail($m->persona_id)->codigo, 'fecha' => $m->fecha, 'moneda' => 'CLP', 'total' => $m->total,
            'concepto' => array_intersect_key($concepto, array_flip(['codigo', 'codigo_externo', 'codigo_lre', 'tipo', 'calculo', 'remuneracion', 'imponible', 'tributable', 'grupo_descuento'])),
            'cantidad' => $m->calculo['cantidad'] ?? null, 'valor' => $m->calculo['valor'] ?? null, 'minutos' => $m->calculo['minutos'] ?? null,
            'base_he' => $m->calculo['base_he'] ?? null, 'recargo' => $m->calculo['recargo'] ?? null, 'regla' => $m->calculo['regla']];
        $contenido = $this->ordenar($contenido);
        if (isset($m->calculo['institucion'])) {
            $contenido['contrato'] = 'folios.rrhh.novedad.v2';
            $contenido['institucion'] = array_intersect_key($m->calculo['institucion'], array_flip(['id', 'codigo', 'rut', 'razon_social', 'tipo', 'codigo_oficial', 'codigo_externo']));
            $contenido['mandato_id'] = $m->mandato_id;
            $contenido['empleador'] = array_intersect_key($m->calculo['contrato']['empleador'], array_flip(['id', 'codigo', 'rut', 'razon_social', 'codigo_externo']));
            $contenido = $this->ordenar($contenido);
        }
        $json = json_encode($contenido, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        EntregaNomina::query()->create(['id' => $contenido['evento_id'], 'movimiento_id' => $m->id, 'secuencia' => $m->estado === 'aprobado' ? 1 : 2, 'contenido' => $contenido, 'huella' => hash('sha256', $json)]);
    }

    public function pendientes()
    {
        // No hay cursor por ID autoincremental: commits concurrentes no dejan eventos atrás.
        // La anulación se entrega solo después del acuse de su aprobación.
        return EntregaNomina::query()->whereNull('acusado_at')->whereNotExists(function ($q): void {
            $q->selectRaw('1')->from('rh_entregas_nomina as anterior')->whereColumn('anterior.movimiento_id', 'rh_entregas_nomina.movimiento_id')->whereColumn('anterior.secuencia', '<', 'rh_entregas_nomina.secuencia')->whereNull('anterior.acusado_at');
        })->orderBy('created_at')->orderBy('id')->paginate(50);
    }

    public function acusar(EntregaNomina $entrega, array $d, User $actor): EntregaNomina
    {
        return app(EsquemaRrhh::class)->transaccion(function () use ($entrega, $d, $actor) {
            $entrega = EntregaNomina::query()->lockForUpdate()->findOrFail($entrega->id);
            if (! hash_equals($entrega->huella, $d['huella'])) {
                $this->error('La huella no corresponde al contenido entregado.');
            }
            if ($entrega->acusado_at) {
                if ($entrega->recibo_externo !== $d['recibo_externo']) {
                    $this->error('La entrega ya tiene otro comprobante externo.');
                }

                return $entrega;
            }
            if (EntregaNomina::query()->where('movimiento_id', $entrega->movimiento_id)->where('secuencia', '<', $entrega->secuencia)->whereNull('acusado_at')->exists()) {
                $this->error('Primero debe acusar la aprobación anterior del movimiento.');
            }
            $entrega->update(['acusado_at' => now(), 'recibo_externo' => $d['recibo_externo']]);
            app(Auditoria::class)->registrar('entrega_nomina', $entrega->id, 'acuse', ['recibo_externo' => $d['recibo_externo']], $actor);

            return $entrega;
        });
    }

    private function ordenar(array $valor): array
    {
        if (! array_is_list($valor)) {
            ksort($valor, SORT_STRING);
        }
        foreach ($valor as $k => $v) {
            if (is_array($v)) {
                $valor[$k] = $this->ordenar($v);
            }
        }

        return $valor;
    }

    private function error(string $mensaje): never
    {
        throw ValidationException::withMessages(['entrega' => $mensaje]);
    }
}
