<?php

namespace App\Rrhh\Nomina;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Integracion\IntercambioNomina;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GestorNovedades
{
    public function __construct(private EsquemaRrhh $esquema, private CalculadorNovedad $calculador, private ReglasChile $reglas, private Auditoria $auditoria) {}

    public function crear(array $d, User $actor): MovimientoNomina
    {
        ksort($d);
        $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));

        $candado = 'novedad-'.$d['operacion_id'];
        if (! $this->esquema->tomarCandado($candado)) {
            $this->error('operacion_id', 'La novedad se está registrando. Reintente.');
        }
        try {
            return $this->esquema->transaccion(function () use ($d, $hash, $actor) {
                Persona::query()->lockForUpdate()->findOrFail($d['persona_id']);
                $existente = MovimientoNomina::query()->where('operacion_id', $d['operacion_id'])->first();
                if ($existente) {
                    if ($existente->payload_hash !== $hash) {
                        $this->error('operacion_id', 'El identificador ya fue utilizado con otros datos.');
                    }

                    return $existente;
                }
                $calculo = $this->calculador->calcular($d);
                if (ReglasChile::decimal($calculo['total'])->isGreaterThan('999999999999999999')) {
                    $this->error('total', 'El monto excede la capacidad del registro. Revise cantidad y valor.');
                }
                $m = MovimientoNomina::query()->create(['id' => (string) Str::uuid(), 'operacion_id' => $d['operacion_id'], 'payload_hash' => $hash,
                    'persona_id' => $d['persona_id'], 'concepto_id' => $d['concepto_id'], 'pacto_id' => $d['pacto_id'] ?? null, 'fecha' => $d['fecha'],
                    'entrada' => $d, 'calculo' => $calculo, 'total' => $calculo['total'], 'estado' => 'borrador']);
                $this->auditoria->registrar('movimiento_nomina', $m->id, 'creado', $m->toArray(), $actor);

                return $m;
            });
        } finally {
            $this->esquema->liberarCandado($candado);
        }
    }

    public function estado(MovimientoNomina $m, array $d, User $actor): MovimientoNomina
    {
        return $this->esquema->transaccion(function () use ($m, $d, $actor) {
            Persona::query()->lockForUpdate()->findOrFail($m->persona_id);
            $m = MovimientoNomina::query()->lockForUpdate()->findOrFail($m->id);
            if ($m->estado === $d['estado']) {
                return $m;
            }
            if ($m->estado === 'anulado' || ($d['estado'] === 'aprobado' && $m->estado !== 'borrador')) {
                $this->error('estado', 'El movimiento no admite esta transición.');
            }
            if ($d['estado'] === 'aprobado') {
                // Nuevos pactos o parámetros pueden cambiar un borrador. No se reemplaza silenciosamente su cálculo.
                if ($this->calculador->calcular($m->entrada) != $m->calculo) {
                    $this->error('estado', 'Los antecedentes cambiaron. Anule el borrador y registre otro para revisar su cálculo.');
                }
                if ($m->pacto_id && MovimientoNomina::query()->where('pacto_id', $m->pacto_id)->where('estado', 'aprobado')->whereBetween('fecha', $this->mes($m->fecha))->exists()) {
                    $this->error('pacto_id', 'Esta asignación mensual ya tiene un movimiento aprobado en el mes.');
                }
                $incidencias = $m->calculo['incidencias'];
                if (($m->calculo['concepto']['calculo'] ?? '') === 'horas_extra') {
                    $otros = MovimientoNomina::query()->where('persona_id', $m->persona_id)->where('fecha', $m->fecha)->where('estado', 'aprobado')->get();
                    $minutos = $m->calculo['minutos'];
                    foreach ($otros as $otro) {
                        $minutos += $otro->calculo['minutos'] ?? 0;
                    }
                    if ($minutos > 120) {
                        $incidencias[] = 'El total de horas extra aprobadas del día supera dos horas.';
                    }
                }
                if ($incidencias !== [] && ! ($d['acepta_incidencias'] ?? false)) {
                    $this->error('acepta_incidencias', 'Revise y reconozca las incidencias antes de aprobar. El tiempo permanece registrado.');
                }
                $this->descuentos($m);
                $m->update(['estado' => 'aprobado', 'aprobado_at' => now(), 'calculo' => [...$m->calculo, 'incidencias_aprobacion' => $incidencias]]);
            } else {
                $m->update(['estado' => 'anulado', 'anulado_at' => now()]);
            }
            app(IntercambioNomina::class)->preparar($m);
            $this->auditoria->registrar('movimiento_nomina', $m->id, $d['estado'], ['motivo' => $d['motivo'], 'incidencias' => $m->calculo['incidencias_aprobacion'] ?? []], $actor);

            return $m;
        });
    }

    private function descuentos(MovimientoNomina $m): void
    {
        $grupo = $m->calculo['concepto']['grupo_descuento'] ?? null;
        if ($grupo && empty($m->entrada['autorizacion'])) {
            $this->error('autorizacion', 'Todo descuento requiere respaldo de la autorización, norma u orden judicial aplicable.');
        }
        if ($grupo === 'vivienda' && ! ($m->entrada['pago_directo_institucion'] ?? false)) {
            $this->error('pago_directo_institucion', 'El régimen de crédito vivienda/educación requiere pago directo a la institución y cumplimiento del artículo 58.');
        }
        if (! in_array($grupo, ['voluntario', 'vivienda', 'cooperativa'], true)) {
            return;
        }
        $bruta = $m->entrada['remuneracion_bruta'] ?? '0';
        if (! ReglasChile::decimal($bruta)->isGreaterThan(0) || empty($m->entrada['autorizacion'])) {
            $this->error('remuneracion_bruta', 'Indique la remuneración total bruta del mes y el respaldo de la autorización escrita.');
        }
        $totales = [$grupo => $m->total];
        $otros = MovimientoNomina::query()->where('persona_id', $m->persona_id)->where('estado', 'aprobado')->whereBetween('fecha', $this->mes($m->fecha))->get();
        foreach ($otros as $otro) {
            $g = $otro->calculo['concepto']['grupo_descuento'] ?? null;
            if (! in_array($g, ['voluntario', 'vivienda', 'cooperativa'], true)) {
                continue;
            }
            if (! ReglasChile::decimal($bruta)->isEqualTo($otro->entrada['remuneracion_bruta'])) {
                $this->error('remuneracion_bruta', 'Use la misma base bruta documentada para todos los descuentos facultativos del mes. Para corregirla, anule y reemplace los anteriores.');
            }
            $totales[$g] = ReglasChile::texto(ReglasChile::decimal($totales[$g] ?? 0)->plus($otro->total));
        }
        $errores = $this->reglas->limites($totales, $bruta);
        if ($errores !== []) {
            $this->error('total', implode(' ', $errores));
        }
    }

    private function mes(string $fecha): array
    {
        $dia = CarbonImmutable::parse($fecha);

        return [$dia->startOfMonth()->toDateString(), $dia->endOfMonth()->toDateString()];
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
