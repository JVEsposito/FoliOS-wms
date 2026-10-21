<?php

namespace App\Rrhh\Contratistas;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\AntecedenteContratista;
use App\Rrhh\Models\ContratoLaboral;
use App\Rrhh\Models\Empleador;
use App\Rrhh\Models\ExpedienteContratista;
use App\Rrhh\Models\Persona;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ControlContratistas
{
    public const TIPOS = ['f30', 'f301', 'remuneraciones', 'pago_remuneraciones', 'cotizaciones', 'contratos', 'registro_est', 'otro'];

    public function __construct(private EsquemaRrhh $esquema, private Auditoria $auditoria) {}

    public function crear(array $d, User $actor): ExpedienteContratista
    {
        return $this->esquema->transaccion(function () use ($d, $actor) {
            $e = Empleador::query()->lockForUpdate()->findOrFail($d['empleador_id']);
            if (! in_array($e->tipo, ['contratista', 'subcontratista', 'est'], true) || ! $e->activo) {
                $this->error('empleador_id', 'Seleccione el empleador contratista, subcontratista o EST activo.');
            }
            if (ExpedienteContratista::query()->where('empleador_id', $e->id)->where('faena', $d['faena'])->where('mes', $d['mes'])->exists()) {
                $this->error('faena', 'Ya existe expediente para esa empresa, faena y mes. Consulte el existente.');
            }
            $personal = $this->personal($e->id, $d['mes'], $d['personal']);
            $exp = ExpedienteContratista::query()->create(['id' => (string) Str::uuid(), ...$d, 'empleador' => $e->toArray(), 'personal' => $personal, 'personas_count' => count($personal)]);
            $this->auditoria->registrar('expediente_contratista', $exp->id, 'creado', $exp->toArray(), $actor);

            return $exp;
        });
    }

    public function cambiarPersonal(ExpedienteContratista $exp, array $d, User $actor): ExpedienteContratista
    {
        return $this->esquema->transaccion(function () use ($exp, $d, $actor) {
            $exp = $this->bloquear($exp, $d['version']);
            $anterior = $exp->personal;
            $personal = $this->personal($exp->empleador_id, $exp->mes, $d['personal']);
            $exp->update(['personal' => $personal, 'personas_count' => count($personal)]);
            $this->pendiente($exp);
            $this->auditoria->registrar('expediente_contratista', $exp->id, 'personal', ['anterior' => $anterior, 'personal' => $personal, 'motivo' => $d['motivo']], $actor);

            return $exp;
        });
    }

    public function antecedente(ExpedienteContratista $exp, array $d, User $actor): AntecedenteContratista
    {
        return $this->esquema->transaccion(function () use ($exp, $d, $actor) {
            $exp = $this->bloquear($exp, $d['version_expediente']);
            $desde = CarbonImmutable::parse($d['desde']);
            $hasta = CarbonImmutable::parse($d['hasta']);
            if ($d['tipo'] === 'f301' && ($desde->day !== 1 || $hasta->toDateString() !== $hasta->endOfMonth()->toDateString() || $desde->diffInMonths($hasta->startOfMonth()) > 2 || $d['hasta'] > $d['fecha_documento'])) {
                $this->error('hasta', 'F30-1 cubre de uno a tres meses consecutivos completos y concluidos a la fecha del certificado.');
            }
            $personas = $d['cubre_todos'] ? array_column($exp->personal, 'id') : ($d['personas'] ?? []);
            if (array_diff($personas, array_column($exp->personal, 'id')) !== []) {
                $this->error('personas', 'La cobertura debe identificar personas de este expediente.');
            }
            if (! in_array($d['tipo'], ['f30', 'registro_est', 'otro'], true) && $personas === []) {
                $this->error('personas', 'Identifique a quiénes cubre el antecedente.');
            }
            if (AntecedenteContratista::query()->where('expediente_id', $exp->id)->where('tipo', $d['tipo'])->where('referencia', $d['referencia'])->exists()) {
                $this->error('referencia', 'El antecedente ya está registrado. Revise su estado; no lo duplique.');
            }
            unset($d['version_expediente'], $d['cubre_todos']);
            $fila = AntecedenteContratista::query()->create(['id' => (string) Str::uuid(), 'expediente_id' => $exp->id, ...$d, 'personas' => array_values($personas)]);
            $this->pendiente($exp);
            $this->auditoria->registrar('antecedente_contratista', $fila->id, 'registrado', $fila->toArray(), $actor);

            return $fila;
        });
    }

    public function revisarAntecedente(AntecedenteContratista $a, array $d, User $actor): AntecedenteContratista
    {
        return $this->esquema->transaccion(function () use ($a, $d, $actor) {
            $exp = ExpedienteContratista::query()->lockForUpdate()->findOrFail($a->expediente_id);
            $a = AntecedenteContratista::query()->lockForUpdate()->findOrFail($a->id);
            if ($a->version !== (int) $d['version']) {
                $this->error('version', 'El antecedente cambió. Recargue antes de revisar.');
            }
            $anterior = $a->toArray();
            $a->update([...$d, 'version' => $a->version + 1, 'revisado_por' => $actor->name, 'revisado_at' => now()]);
            $this->pendiente($exp);
            $this->auditoria->registrar('antecedente_contratista', $a->id, 'revisado', ['anterior' => $anterior, 'actual' => $a->toArray()], $actor);

            return $a;
        });
    }

    public function revisarExpediente(ExpedienteContratista $exp, array $d, User $actor): ExpedienteContratista
    {
        return $this->esquema->transaccion(function () use ($exp, $d, $actor) {
            $exp = $this->bloquear($exp, $d['version']);
            if ($d['estado'] === 'conforme') {
                $this->personal($exp->empleador_id, $exp->mes, array_column($exp->personal, 'id'));
                $faltantes = $this->faltantes($exp);
                if ($faltantes !== [] || $exp->mes >= now()->timezone(config('app.operational_timezone'))->format('Y-m')) {
                    $this->error('estado', 'La revisión conforme requiere mes concluido y cobertura revisada de todo el personal: '.implode(', ', $faltantes));
                }
            }
            $exp->update([...$d, 'version' => $exp->version + 1, 'revisado_por' => $actor->name, 'revisado_at' => now()]);
            $this->auditoria->registrar('expediente_contratista', $exp->id, 'revision', $exp->toArray(), $actor);

            return $exp;
        });
    }

    public function faltantes(ExpedienteContratista $exp): array
    {
        $desde = $exp->mes.'-01';
        $hasta = CarbonImmutable::parse($desde)->endOfMonth()->toDateString();
        $documentos = AntecedenteContratista::query()->where('expediente_id', $exp->id)->where('estado', 'conforme')->where('desde', '<=', $desde)->where('hasta', '>=', $hasta)->get();
        $requeridos = ['f301', 'remuneraciones', 'pago_remuneraciones', 'cotizaciones'];
        if ($exp->empleador['tipo'] === 'est') {
            $requeridos[] = 'registro_est';
        }
        $faltantes = [];
        $sinResolver = AntecedenteContratista::query()->where('expediente_id', $exp->id)->whereIn('estado', ['pendiente', 'observado'])->count();
        if ($sinResolver > 0) {
            $faltantes[] = $sinResolver.' antecedente(s) pendiente(s) u observado(s)';
        }
        foreach ($requeridos as $tipo) {
            $docs = $documentos->where('tipo', $tipo);
            $cubiertos = $tipo === 'registro_est' && $docs->isNotEmpty() ? array_column($exp->personal, 'id') : $docs->flatMap(fn ($a) => $a->personas)->unique()->all();
            $pendientes = count(array_diff(array_column($exp->personal, 'id'), $cubiertos));
            if ($pendientes > 0) {
                $faltantes[] = $tipo.': '.$pendientes.' persona(s) sin cobertura conforme';
            }
        }

        return $faltantes;
    }

    private function personal(string $empleador, string $mes, array $ids): array
    {
        $desde = $mes.'-01';
        $hasta = CarbonImmutable::parse($desde)->endOfMonth()->toDateString();
        $validos = ContratoLaboral::query()->where('empleador_id', $empleador)->where('tipo', '!=', 'honorarios')->whereIn('persona_id', $ids)->where('desde', '<=', $hasta)->where('hasta', '>=', $desde)->distinct()->pluck('persona_id')->all();
        if (count($validos) !== count($ids)) {
            $this->error('personal', 'Todas las personas deben tener contrato laboral con ese empleador durante el mes del expediente.');
        }

        return Persona::query()->whereIn('id', $validos)->orderBy('codigo')->get(['id', 'codigo', 'nombre'])->toArray();
    }

    private function bloquear(ExpedienteContratista $exp, int $version): ExpedienteContratista
    {
        $exp = ExpedienteContratista::query()->lockForUpdate()->findOrFail($exp->id);
        if ($exp->version !== $version) {
            $this->error('version', 'El expediente cambió. Recargue antes de continuar.');
        }

        return $exp;
    }

    private function pendiente(ExpedienteContratista $exp): void
    {
        $exp->update(['estado' => 'pendiente', 'version' => $exp->version + 1, 'revisado_por' => null, 'revisado_at' => null]);
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
