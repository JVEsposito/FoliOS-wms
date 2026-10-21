<?php

namespace App\Rrhh\Contratistas;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\ExpedienteContratista;
use App\Rrhh\Models\Institucion;
use App\Rrhh\Models\RetencionContratista;
use App\Rrhh\Nomina\ReglasChile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Auxiliar de retenciones comerciales respaldadas. No ejecuta pagos ni contabiliza nuevamente el gasto. */
final class RetencionesContratista
{
    public function saldo(string $expediente): string
    {
        $totales = RetencionContratista::query()->where('expediente_id', $expediente)->whereNull('anulado_at')->selectRaw('tipo, SUM(monto) as monto')->groupBy('tipo')->pluck('monto', 'tipo');

        return ReglasChile::pesos(ReglasChile::decimal((string) ($totales['retencion'] ?? 0))->minus((string) ($totales['liberacion'] ?? 0))->minus((string) ($totales['pago_subrogacion'] ?? 0)));
    }

    public function registrar(ExpedienteContratista $exp, array $d, User $actor): RetencionContratista
    {
        ksort($d);
        $hash = hash('sha256', json_encode([$exp->id, $d], JSON_THROW_ON_ERROR));

        $esquema = app(EsquemaRrhh::class);
        $clave = 'retencion-'.$d['operacion_id'];
        if (! $esquema->tomarCandado($clave)) {
            $this->error('operacion_id', 'La anotación se está guardando. Reintente.');
        }
        try {
            return $esquema->transaccion(function () use ($exp, $d, $hash, $actor) {
                $exp = ExpedienteContratista::query()->lockForUpdate()->findOrFail($exp->id);
                $previo = RetencionContratista::query()->where('operacion_id', $d['operacion_id'])->first();
                if ($previo) {
                    if ($previo->payload_hash !== $hash) {
                        $this->error('operacion_id', 'El identificador ya corresponde a otra anotación.');
                    }

                    return $previo;
                }
                $monto = ReglasChile::decimal($d['monto']);
                if (! $monto->isGreaterThan(0) || ($d['tipo'] !== 'retencion' && $monto->isGreaterThan($this->saldo($exp->id)))) {
                    $this->error('monto', 'El monto debe ser positivo y no puede liberar ni aplicar más de lo retenido.');
                }
                $institucion = ! empty($d['institucion_id']);
                $persona = ! empty($d['persona_destino_id']);
                if (($d['tipo'] === 'pago_subrogacion' && $institucion === $persona) || ($d['tipo'] !== 'pago_subrogacion' && ($institucion || $persona)) || ($persona && ! in_array($d['persona_destino_id'], array_column($exp->personal, 'id'), true))) {
                    $this->error('destinatario', 'Una subrogación requiere institución o trabajador del expediente; las otras anotaciones no llevan destinatario.');
                }
                if (RetencionContratista::query()->where('expediente_id', $exp->id)->where('tipo', $d['tipo'])->where('referencia', $d['referencia'])->exists()) {
                    $this->error('referencia', 'Esa referencia ya está anotada para el mismo tipo.');
                }
                $destino = $institucion ? Institucion::query()->findOrFail($d['institucion_id'])->only(['id', 'codigo', 'rut', 'razon_social', 'tipo', 'codigo_oficial', 'codigo_externo']) : ($persona ? collect($exp->personal)->firstWhere('id', $d['persona_destino_id']) : null);
                $fila = RetencionContratista::query()->create(['id' => (string) Str::uuid(), 'expediente_id' => $exp->id, 'payload_hash' => $hash, ...$d, 'destinatario' => $destino]);
                app(Auditoria::class)->registrar('retencion_contratista', $fila->id, 'anotada', $fila->toArray(), $actor);

                return $fila;
            });
        } finally {
            $esquema->liberarCandado($clave);
        }
    }

    public function anular(RetencionContratista $fila, string $motivo, User $actor): RetencionContratista
    {
        return app(EsquemaRrhh::class)->transaccion(function () use ($fila, $motivo, $actor) {
            ExpedienteContratista::query()->lockForUpdate()->findOrFail($fila->expediente_id);
            $fila = RetencionContratista::query()->lockForUpdate()->findOrFail($fila->id);
            if ($fila->anulado_at) {
                return $fila;
            }
            if ($fila->tipo === 'retencion' && ReglasChile::decimal($fila->monto)->isGreaterThan($this->saldo($fila->expediente_id))) {
                $this->error('monto', 'La retención ya tiene liberaciones o subrogaciones; rectifíquelas antes de anularla.');
            }
            $fila->update(['anulado_at' => now()]);
            app(Auditoria::class)->registrar('retencion_contratista', $fila->id, 'anulada', ['motivo' => $motivo], $actor);

            return $fila;
        });
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
