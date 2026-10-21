<?php

namespace App\Rrhh\Nomina;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\CargoLaboral;
use App\Rrhh\Models\ConceptoNomina;
use App\Rrhh\Models\CondicionLaboral;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\PactoConcepto;
use App\Rrhh\Models\ParametroLegal;
use App\Rrhh\Models\Persona;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConfiguracionNomina
{
    public function __construct(private EsquemaRrhh $esquema, private Auditoria $auditoria) {}

    public function cargo(array $datos, ?CargoLaboral $cargo, User $actor): CargoLaboral
    {
        return $this->esquema->transaccion(function () use ($datos, $cargo, $actor) {
            if ($cargo) {
                $cargo = CargoLaboral::query()->lockForUpdate()->findOrFail($cargo->id);
                if ($cargo->version !== (int) $datos['version']) {
                    throw ValidationException::withMessages(['version' => 'El cargo cambió. Recargue antes de guardar.']);
                }
                unset($datos['codigo']);
                $datos['version'] = $cargo->version + 1;
                $cargo->update($datos);
            } else {
                if (CargoLaboral::query()->where('codigo', $datos['codigo'])->exists()) {
                    throw ValidationException::withMessages(['codigo' => 'El código de cargo ya existe.']);
                }
                $cargo = CargoLaboral::query()->create(['id' => (string) Str::uuid(), ...$datos]);
            }
            $this->auditoria->registrar('cargo', $cargo->id, 'guardado', $cargo->toArray(), $actor);

            return $cargo;
        });
    }

    public function concepto(array $d, User $actor): ConceptoNomina
    {
        if ($d['tipo'] !== 'haber' && ($d['remuneracion'] || $d['imponible'] || $d['tributable'] || $d['base_he'])) {
            $this->error('tipo', 'Los atributos de haber solo corresponden a haberes.');
        }
        if ($d['base_he'] && (! $d['remuneracion'] || ! $d['imponible'] || ! $d['tributable'] || $d['calculo'] !== 'monto')) {
            $this->error('base_he', 'La base de horas extra requiere un haber fijo mensual con carácter de sueldo, respaldado.');
        }
        if ($d['calculo'] === 'horas_extra' && ($d['tipo'] !== 'haber' || ! $d['remuneracion'] || ! $d['imponible'] || ! $d['tributable'] || $d['base_he'])) {
            $this->error('calculo', 'El sobresueldo debe ser haber remuneracional, imponible y tributable, sin formar su propia base.');
        }
        if (($d['tipo'] === 'descuento') !== ! empty($d['grupo_descuento'])) {
            $this->error('grupo_descuento', 'Todo descuento debe identificar su régimen; los otros conceptos no llevan grupo de descuento.');
        }

        return $this->esquema->transaccion(function () use ($d, $actor) {
            if (ConceptoNomina::query()->where('codigo', $d['codigo'])->exists()) {
                $this->error('codigo', 'El código ya existe. Cree otro código para cambiar el tratamiento histórico.');
            }
            $c = ConceptoNomina::query()->create(['id' => (string) Str::uuid(), ...$d]);
            $this->auditoria->registrar('concepto_nomina', $c->id, 'creado', $c->toArray(), $actor);

            return $c;
        });
    }

    public function condicion(array $d, User $actor): CondicionLaboral
    {
        return $this->esquema->transaccion(function () use ($d, $actor) {
            Persona::query()->lockForUpdate()->findOrFail($d['persona_id']);
            if (CondicionLaboral::query()->where('persona_id', $d['persona_id'])->where('desde', '<=', $d['hasta'])->where('hasta', '>=', $d['desde'])->exists()) {
                $this->error('desde', 'Las condiciones de esta persona se superponen. Use vigencias sucesivas.');
            }
            if ($d['regimen'] === 'general' && ReglasChile::decimal($d['horas_semanales'])->isGreaterThan(ReglasChile::jornadaMaxima($d['hasta']))) {
                $this->error('horas_semanales', 'La jornada supera el máximo general aplicable a la vigencia. Separe las etapas de reducción.');
            }
            $cargo = CargoLaboral::query()->findOrFail($d['cargo_id']);
            if (! $cargo->activo) {
                $this->error('cargo_id', 'El cargo está inactivo.');
            }
            $c = CondicionLaboral::query()->create(['id' => (string) Str::uuid(), 'cargo_nombre' => $cargo->nombre, ...$d]);
            $this->auditoria->registrar('condicion', $c->id, 'creada', $c->toArray(), $actor);

            return $c;
        });
    }

    public function pacto(array $d, User $actor): PactoConcepto
    {
        return $this->esquema->transaccion(function () use ($d, $actor) {
            Persona::query()->lockForUpdate()->findOrFail($d['persona_id']);
            $c = ConceptoNomina::query()->findOrFail($d['concepto_id']);
            if (! $c->activo || $c->calculo === 'horas_extra') {
                $this->error('concepto_id', 'Seleccione un concepto activo de monto o porcentaje; las horas extra se registran por fecha.');
            }
            if (PactoConcepto::query()->where('persona_id', $d['persona_id'])->where('concepto_id', $c->id)->where('desde', '<=', $d['hasta'])->where('hasta', '>=', $d['desde'])->exists()) {
                $this->error('desde', 'Ya existe una asignación vigente del mismo concepto.');
            }
            if ($c->calculo === 'porcentaje' && ReglasChile::decimal($d['cantidad'])->isGreaterThan(100)) {
                $this->error('cantidad', 'El porcentaje no puede superar 100.');
            }
            if ($c->base_he && $d['cantidad'] !== '1') {
                $this->error('cantidad', 'Para sueldo fijo adicional, indique una unidad y el monto mensual completo.');
            }
            $p = PactoConcepto::query()->create(['id' => (string) Str::uuid(), 'concepto' => $c->toArray(), ...$d]);
            $this->auditoria->registrar('pacto_concepto', $p->id, 'creado', $p->toArray(), $actor);

            return $p;
        });
    }

    public function parametro(array $d, User $actor): ParametroLegal
    {
        $candado = 'parametros-legales';
        if (! $this->esquema->tomarCandado($candado)) {
            $this->error('desde', 'Hay otra actualización de parámetros. Reintente.');
        }
        try {
            return $this->esquema->transaccion(function () use ($d, $actor) {
                if (ParametroLegal::query()->where('desde', '<=', $d['hasta'])->where('hasta', '>=', $d['desde'])->exists()) {
                    $this->error('desde', 'Ya existen parámetros para estas fechas. Las versiones históricas no se sobrescriben.');
                }
                $p = ParametroLegal::query()->create(['id' => (string) Str::uuid(), ...$d]);
                $this->auditoria->registrar('parametro_legal', $p->id, 'creado', $p->toArray(), $actor);

                return $p;
            });
        } finally {
            $this->esquema->liberarCandado($candado);
        }
    }

    public function terminar(string $tipo, string $id, array $d, User $actor): object
    {
        $clase = $tipo === 'condicion' ? CondicionLaboral::class : PactoConcepto::class;
        $fila = $clase::query()->findOrFail($id);

        return $this->esquema->transaccion(function () use ($tipo, $clase, $fila, $d, $actor) {
            Persona::query()->lockForUpdate()->findOrFail($fila->persona_id);
            $fila = $clase::query()->lockForUpdate()->findOrFail($fila->id);
            if ($fila->hasta !== $d['hasta_actual'] || $d['hasta'] < $fila->desde || $d['hasta'] >= $fila->hasta) {
                $this->error('hasta', 'Solo se puede acortar la vigencia actual, desde su inicio inclusive. Recargue si cambió.');
            }
            $usados = MovimientoNomina::query()->where('persona_id', $fila->persona_id)->where('estado', 'aprobado')->where('fecha', '>', $d['hasta'])->get();
            foreach ($usados as $m) {
                $calculo = $m->calculo;
                if (($tipo === 'condicion' && ($calculo['condicion']['id'] ?? null) === $fila->id) ||
                    ($tipo === 'pacto_concepto' && ($m->pacto_id === $fila->id || in_array($fila->id, array_column($calculo['adicionales_sueldo'] ?? [], 'id'), true)))) {
                    $this->error('hasta', 'Hay novedades aprobadas que usan esta vigencia. Anúlelas y rectifique antes de acortarla.');
                }
            }
            $anterior = $fila->hasta;
            $fila->update(['hasta' => $d['hasta']]);
            $this->auditoria->registrar($tipo, $fila->id, 'terminado', ['hasta_anterior' => $anterior, ...$d], $actor);

            return $fila;
        });
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
