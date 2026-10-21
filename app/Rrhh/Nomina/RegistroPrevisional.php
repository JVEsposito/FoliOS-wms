<?php

namespace App\Rrhh\Nomina;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\Afiliacion;
use App\Rrhh\Models\ContratoLaboral;
use App\Rrhh\Models\Empleador;
use App\Rrhh\Models\Institucion;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RegistroPrevisional
{
    public const INSTITUCIONES = ['afp', 'fonasa', 'isapre', 'ccaf', 'mutualidad', 'isl', 'afc', 'fapp', 'fisco', 'apv', 'sindicato', 'cooperativa', 'aseguradora', 'otro'];

    public const EMPLEADORES = ['propio', 'contratista', 'subcontratista', 'est'];

    public const REGIMENES = ['afp' => ['afp'], 'salud' => ['fonasa', 'isapre'], 'ccaf' => ['ccaf'], 'accidentes' => ['mutualidad', 'isl'], 'apv' => ['afp', 'apv']];

    public function __construct(private EsquemaRrhh $esquema, private Auditoria $auditoria) {}

    public function directorio(string $tipo, array $d, ?string $id, User $actor): object
    {
        $clase = $tipo === 'institucion' ? Institucion::class : Empleador::class;
        $rut = strtoupper(preg_replace('/[.\s-]/', '', $d['rut']));
        $d['rut'] = ltrim(substr($rut, 0, -1), '0').'-'.substr($rut, -1);
        if ((int) $d['rut'] < 1) {
            $this->error('rut', 'Ingrese un RUT válido de la entidad.');
        }

        return $this->exclusivo('directorio-'.$tipo, function () use ($clase, $tipo, $d, $id, $actor) {
            $actual = $id ? $clase::query()->lockForUpdate()->findOrFail($id) : null;
            if ($actual && ($actual->version !== (int) $d['version'] || $actual->rut !== $d['rut'] || $actual->codigo !== $d['codigo'] || $actual->tipo !== $d['tipo'])) {
                $this->error('version', 'Recargue la entidad. Su RUT, código y tipo son permanentes.');
            }
            if ($clase::query()->when($id, fn ($q) => $q->whereKeyNot($id))->where(fn ($q) => $q->where('rut', $d['rut'])->orWhere('codigo', $d['codigo']))->exists()) {
                $this->error('rut', 'Ya existe una entidad con ese RUT o código.');
            }
            if ($actual) {
                // Las vigencias que ya respaldan un contrato o afiliación no se recortan desde el catálogo.
                if ($actual->desde !== $d['desde'] || $actual->hasta !== $d['hasta']) {
                    $this->error('desde', 'La vigencia del catálogo es permanente. Use desactivación para impedir nuevos registros.');
                }
                $actual->update([...$d, 'version' => $actual->version + 1]);
            } else {
                unset($d['version']);
                $actual = $clase::query()->create(['id' => (string) Str::uuid(), ...$d]);
            }
            $this->auditoria->registrar($tipo, $actual->id, 'guardado', $actual->toArray(), $actor);

            return $actual;
        });
    }

    public function contrato(array $d, User $actor): ContratoLaboral
    {
        return $this->esquema->transaccion(function () use ($d, $actor) {
            Persona::query()->lockForUpdate()->findOrFail($d['persona_id']);
            $empleador = Empleador::query()->lockForUpdate()->findOrFail($d['empleador_id']);
            $this->vigente($empleador, $d);
            $q = ContratoLaboral::query()->where('persona_id', $d['persona_id'])->where('empleador_id', $empleador->id);
            if ((clone $q)->where('desde', '<=', $d['hasta'])->where('hasta', '>=', $d['desde'])->exists()) {
                $this->error('desde', 'Ya existe un contrato de esta persona con el empleador para esas fechas.');
            }
            if (ContratoLaboral::query()->where('empleador_id', $empleador->id)->where('referencia', $d['referencia'])->exists()) {
                $this->error('referencia', 'La referencia de contrato ya está registrada para este empleador.');
            }
            if (! empty($d['prorroga_de'])) {
                $anterior = ContratoLaboral::query()->findOrFail($d['prorroga_de']);
                if ($anterior->persona_id !== $d['persona_id'] || $anterior->empleador_id !== $empleador->id || $anterior->hasta !== date('Y-m-d', strtotime($d['desde'].' -1 day')) || $anterior->tipo === 'honorarios' || $d['tipo'] === 'honorarios') {
                    $this->error('prorroga_de', 'La prórroga debe continuar al contrato laboral de la misma persona y empleador sin interrupción.');
                }
            }
            $fila = ContratoLaboral::query()->create(['id' => (string) Str::uuid(), 'empleador' => $empleador->toArray(), ...$d]);
            $this->auditoria->registrar('contrato', $fila->id, 'creado', $fila->toArray(), $actor);

            return $fila;
        });
    }

    public function afiliacion(array $d, User $actor): Afiliacion
    {
        return $this->esquema->transaccion(function () use ($d, $actor) {
            $persona = ! empty($d['persona_id']);
            if ($persona === ! empty($d['empleador_id']) || ($persona && in_array($d['regimen'], ['ccaf', 'accidentes'], true)) || (! $persona && ! in_array($d['regimen'], ['ccaf', 'accidentes'], true))) {
                $this->error('persona_id', 'AFP, salud y APV corresponden a una persona; CCAF y accidentes a un empleador. Indique solo un titular.');
            }
            $campo = $persona ? 'persona_id' : 'empleador_id';
            $clase = $persona ? Persona::class : Empleador::class;
            $titular = $clase::query()->lockForUpdate()->findOrFail($d[$campo]);
            if (! $persona) {
                $this->vigente($titular, $d);
            }
            $i = Institucion::query()->lockForUpdate()->findOrFail($d['institucion_id']);
            if (($d['regimen'] === 'apv' && empty($d['regimen_apv'])) || ($d['regimen'] !== 'apv' && ! empty($d['regimen_apv']))) {
                $this->error('regimen_apv', 'La afiliación APV requiere identificar régimen tributario A o B; los otros regímenes no llevan esa selección.');
            }
            $this->vigente($i, $d);
            if (! in_array($i->tipo, self::REGIMENES[$d['regimen']], true)) {
                $this->error('institucion_id', 'El tipo de institución no corresponde al régimen de afiliación.');
            }
            $q = Afiliacion::query()->where($campo, $d[$campo])->where('regimen', $d['regimen'])->where('desde', '<=', $d['hasta'])->where('hasta', '>=', $d['desde']);
            if ($d['regimen'] === 'apv') {
                $q->where('institucion_id', $i->id)->where('regimen_apv', $d['regimen_apv']);
            }
            if ($q->exists()) {
                $this->error('desde', 'Ya existe una afiliación del mismo régimen para esas fechas. Cierre la anterior antes de registrar el cambio.');
            }
            if ($i->tipo === 'isapre' && (empty($d['moneda_plan']) || ! ReglasChile::decimal($d['precio_plan'] ?? '0')->isGreaterThan(0))) {
                $this->error('precio_plan', 'El plan Isapre requiere moneda y precio positivo según FUN o contrato.');
            }
            if ($i->tipo !== 'isapre' && (isset($d['moneda_plan']) || isset($d['precio_plan']))) {
                $this->error('precio_plan', 'El precio del plan de salud solo corresponde a Isapre.');
            }
            $fila = Afiliacion::query()->create(['id' => (string) Str::uuid(), 'institucion' => $i->toArray(), ...$d]);
            $this->auditoria->registrar('afiliacion', $fila->id, 'creada', $fila->toArray(), $actor);

            return $fila;
        });
    }

    public function terminar(string $tipo, string $id, array $d, User $actor): object
    {
        $clase = $tipo === 'contrato' ? ContratoLaboral::class : Afiliacion::class;

        return $this->esquema->transaccion(function () use ($tipo, $clase, $id, $d, $actor) {
            $previa = $clase::query()->findOrFail($id);
            $titular = $previa->persona_id ? Persona::class : Empleador::class;
            $titular::query()->lockForUpdate()->findOrFail($previa->persona_id ?: $previa->empleador_id);
            $fila = $clase::query()->lockForUpdate()->findOrFail($id);
            if ($fila->hasta !== $d['hasta_actual'] || $d['hasta'] < $fila->desde || $d['hasta'] >= $fila->hasta) {
                $this->error('hasta', 'Solo puede acortar la vigencia actual desde su inicio inclusive. Recargue si cambió.');
            }
            if ($tipo === 'contrato' && ContratoLaboral::query()->where('prorroga_de', $id)->exists()) {
                $this->error('hasta', 'El contrato ya tiene una prórroga. Conserve la continuidad de sus antecedentes.');
            }
            if ($tipo === 'contrato' && MovimientoNomina::query()->where('contrato_id', $id)->where('estado', 'aprobado')->where('fecha', '>', $d['hasta'])->exists()) {
                $this->error('hasta', 'Hay novedades aprobadas posteriores que usan este contrato. Anúlelas y rectifique antes de acortarlo.');
            }
            $fila->update(['hasta' => $d['hasta']]);
            $this->auditoria->registrar($tipo, $fila->id, 'terminado', $d, $actor);

            return $fila;
        });
    }

    private function vigente(object $fila, array $d): void
    {
        if (! $fila->activo || $fila->desde > $d['desde'] || $fila->hasta < $d['hasta']) {
            $this->error('desde', 'La entidad debe estar activa y cubrir toda la vigencia que registra.');
        }
    }

    private function exclusivo(string $clave, callable $fn): mixed
    {
        if (! $this->esquema->tomarCandado($clave)) {
            $this->error('version', 'Hay otra actualización del directorio. Reintente.');
        }
        try {
            return $this->esquema->transaccion($fn);
        } finally {
            $this->esquema->liberarCandado($clave);
        }
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
