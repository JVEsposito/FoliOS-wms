<?php

namespace App\Services\Frigorifico;

use App\Exceptions\ConflictoOperacion;
use App\Models\AusenciaFrigorifico;
use App\Models\JornadaFrigorifico;
use App\Models\PersonaFrigorifico;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Control interno del supervisor de Frigorífico: jornadas, horas extra y ausencias de su personal.
 *
 * Es un registro propio para contrastar a fin de mes con lo que informa RRHH. No lee ni escribe
 * datos de RRHH y RRHH no lo lee (ver ControlPersonalFrigorificoArchitectureTest).
 */
class ControlPersonal
{
    public const MAX_DIAS_AUSENCIA = 92;

    public function __construct(private readonly CalculadoraJornada $calculadora) {}

    public function guardarPersona(array $datos, User $actor, ?string $id = null): PersonaFrigorifico
    {
        return DB::transaction(function () use ($datos, $actor, $id): PersonaFrigorifico {
            $persona = $id ? PersonaFrigorifico::query()->lockForUpdate()->findOrFail($id) : new PersonaFrigorifico;
            $this->version($persona, $datos);
            $anterior = $persona->exists ? $persona->toArray() : null;
            unset($datos['version']);
            $persona->fill($datos);
            $persona->version = $persona->exists ? $persona->version + 1 : 1;
            $persona->save();
            $this->evento($persona->id, $actor, 'persona_guardada', $anterior, $persona->toArray());

            return $persona;
        }, attempts: 3);
    }

    public function guardarJornada(array $datos, User $actor, ?string $id = null): JornadaFrigorifico
    {
        return DB::transaction(function () use ($datos, $actor, $id): JornadaFrigorifico {
            // Bloqueo común por persona: serializa jornadas y ausencias (altas, cierres, correcciones y anulaciones).
            $persona = PersonaFrigorifico::query()->lockForUpdate()->findOrFail($datos['persona_id']);
            $jornada = $id ? JornadaFrigorifico::query()->lockForUpdate()->findOrFail($id) : new JornadaFrigorifico;
            $this->version($jornada, $datos);
            abort_if($jornada->exists && $jornada->persona_id !== $persona->id, 422, 'No se puede cambiar la persona de una jornada.');
            if ($jornada->anulado_at || (! $jornada->exists && ! $persona->activo)) {
                throw ValidationException::withMessages(['persona_id' => 'La persona debe estar activa para ingresar y una jornada anulada no se puede editar.']);
            }
            $ingreso = $this->calculadora->fecha($datos['ingreso'], 'ingreso');
            $salida = empty($datos['salida']) ? null : $this->calculadora->fecha($datos['salida'], 'salida');
            $base = $datos['jornada_base_minutos'] ?? ($jornada->exists ? $jornada->jornada_base_minutos : $persona->jornada_base_minutos);
            $this->calculadora->calcular($ingreso, $salida, $base, $datos['colacion_minutos']);
            $solapada = JornadaFrigorifico::query()->where('persona_id', $persona->id)->whereNull('anulado_at')
                ->when($jornada->exists, fn ($q) => $q->whereKeyNot($jornada->id))
                ->when($salida, fn ($q) => $q->where('ingreso_at', '<', $salida))
                ->where(fn ($q) => $q->whereNull('salida_at')->orWhere('salida_at', '>', $ingreso))->exists();
            if ($solapada) {
                throw ValidationException::withMessages(['ingreso' => 'Se superpone con otra jornada de esta persona. Revisa también sus salidas pendientes.']);
            }
            $dia = $ingreso->tz($this->zona())->toDateString();
            $ausencia = AusenciaFrigorifico::query()->where('persona_id', $persona->id)->whereNull('anulado_at')
                ->whereDate('desde', '<=', $dia)->whereDate('hasta', '>=', $dia)->first();
            if ($ausencia) {
                throw ValidationException::withMessages(['ingreso' => sprintf(
                    'Ese día está marcado como %s (%s). Corrige o anula la ausencia antes de registrar la jornada.',
                    mb_strtolower(AusenciaFrigorifico::TIPOS[$ausencia->tipo]), $this->rango($ausencia->desde->format('Y-m-d'), $ausencia->hasta->format('Y-m-d')),
                )]);
            }
            $anterior = $jornada->exists ? $jornada->toArray() : null;
            $jornada->fill(['persona_id' => $persona->id, 'ingreso_at' => $ingreso, 'salida_at' => $salida,
                'jornada_base_minutos' => $base, 'colacion_minutos' => $datos['colacion_minutos'],
                'sector' => $datos['sector'] ?? ($jornada->exists ? $jornada->sector : $persona->sector),
                'observacion' => $datos['observacion'] ?? null, 'version' => $jornada->exists ? $jornada->version + 1 : 1]);
            $jornada->save();
            $this->evento($persona->id, $actor, 'jornada_guardada', $anterior, $jornada->toArray(), $datos['motivo'] ?? null, jornada: $jornada->id);

            return $jornada;
        }, attempts: 3);
    }

    public function anular(string $id, array $datos, User $actor): void
    {
        $referencia = JornadaFrigorifico::query()->findOrFail($id);
        DB::transaction(function () use ($referencia, $datos, $actor): void {
            PersonaFrigorifico::query()->lockForUpdate()->findOrFail($referencia->persona_id);
            $jornada = JornadaFrigorifico::query()->lockForUpdate()->findOrFail($referencia->id);
            $this->version($jornada, $datos);
            if ($jornada->anulado_at) {
                throw new ConflictoOperacion('Esta jornada ya fue anulada.');
            }
            $anterior = $jornada->toArray();
            $jornada->update(['anulado_at' => now(), 'version' => $jornada->version + 1]);
            $this->evento($jornada->persona_id, $actor, 'jornada_anulada', $anterior, $jornada->toArray(), $datos['motivo'], jornada: $jornada->id);
        }, attempts: 3);
    }

    /**
     * Marca uno o más días completos sin jornada. No admite días con jornada vigente ni otra ausencia vigente.
     */
    public function guardarAusencia(array $datos, User $actor, ?string $id = null): AusenciaFrigorifico
    {
        return DB::transaction(function () use ($datos, $actor, $id): AusenciaFrigorifico {
            $persona = PersonaFrigorifico::query()->lockForUpdate()->findOrFail($datos['persona_id']);
            $ausencia = $id ? AusenciaFrigorifico::query()->lockForUpdate()->findOrFail($id) : new AusenciaFrigorifico;
            $this->version($ausencia, $datos);
            abort_if($ausencia->exists && $ausencia->persona_id !== $persona->id, 422, 'No se puede cambiar la persona de una ausencia.');
            if ($ausencia->anulado_at || (! $ausencia->exists && ! $persona->activo)) {
                throw ValidationException::withMessages(['persona_id' => 'La persona debe estar activa para registrar ausencias y una ausencia anulada no se puede editar.']);
            }
            $zona = $this->zona();
            $desde = CarbonImmutable::createFromFormat('!Y-m-d', $datos['desde'], $zona);
            $hasta = CarbonImmutable::createFromFormat('!Y-m-d', $datos['hasta'], $zona);
            if ($datos['hasta'] < $datos['desde']) {
                throw ValidationException::withMessages(['hasta' => 'La fecha final no puede ser anterior a la inicial.']);
            }
            if ($this->dias($datos['desde'], $datos['hasta']) > self::MAX_DIAS_AUSENCIA) {
                throw ValidationException::withMessages(['hasta' => 'Registra como máximo '.self::MAX_DIAS_AUSENCIA.' días por ausencia; divide los periodos más largos.']);
            }
            if ($datos['tipo'] === 'falta' && $datos['hasta'] > CarbonImmutable::now($zona)->toDateString()) {
                throw ValidationException::withMessages(['hasta' => 'Una falta no puede registrarse por anticipado.']);
            }
            $otra = AusenciaFrigorifico::query()->where('persona_id', $persona->id)->whereNull('anulado_at')
                ->when($ausencia->exists, fn ($q) => $q->whereKeyNot($ausencia->id))
                ->whereDate('desde', '<=', $hasta->toDateString())->whereDate('hasta', '>=', $desde->toDateString())->first();
            if ($otra) {
                throw ValidationException::withMessages(['desde' => sprintf('Se superpone con otra ausencia de esta persona (%s, %s).',
                    mb_strtolower(AusenciaFrigorifico::TIPOS[$otra->tipo]), $this->rango($otra->desde->format('Y-m-d'), $otra->hasta->format('Y-m-d')))]);
            }
            $jornada = JornadaFrigorifico::query()->where('persona_id', $persona->id)->whereNull('anulado_at')
                ->where('ingreso_at', '>=', $desde->utc())->where('ingreso_at', '<', $hasta->addDay()->utc())
                ->orderBy('ingreso_at')->first();
            if ($jornada) {
                throw ValidationException::withMessages(['desde' => 'Esta persona tiene una jornada con ingreso el '
                    .$jornada->ingreso_at->tz($zona)->format('d-m-Y H:i').'. Anúlala o ajusta las fechas de la ausencia.']);
            }
            $anterior = $ausencia->exists ? $ausencia->toArray() : null;
            $ausencia->fill(['persona_id' => $persona->id, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
                'tipo' => $datos['tipo'], 'observacion' => $datos['observacion'] ?? null,
                'version' => $ausencia->exists ? $ausencia->version + 1 : 1]);
            $ausencia->save();
            $this->evento($persona->id, $actor, 'ausencia_guardada', $anterior, $ausencia->toArray(), $datos['motivo'] ?? null, ausencia: $ausencia->id);

            return $ausencia;
        }, attempts: 3);
    }

    public function anularAusencia(string $id, array $datos, User $actor): void
    {
        $referencia = AusenciaFrigorifico::query()->findOrFail($id);
        DB::transaction(function () use ($referencia, $datos, $actor): void {
            PersonaFrigorifico::query()->lockForUpdate()->findOrFail($referencia->persona_id);
            $ausencia = AusenciaFrigorifico::query()->lockForUpdate()->findOrFail($referencia->id);
            $this->version($ausencia, $datos);
            if ($ausencia->anulado_at) {
                throw new ConflictoOperacion('Esta ausencia ya fue anulada.');
            }
            $anterior = $ausencia->toArray();
            $ausencia->update(['anulado_at' => now(), 'version' => $ausencia->version + 1]);
            $this->evento($ausencia->persona_id, $actor, 'ausencia_anulada', $anterior, $ausencia->toArray(), $datos['motivo'], ausencia: $ausencia->id);
        }, attempts: 3);
    }

    private function version($modelo, array $datos): void
    {
        if ($modelo->exists && $modelo->version !== ($datos['version'] ?? null)) {
            throw new ConflictoOperacion('Otro supervisor modificó este registro. Actualiza antes de guardar; conserva tu borrador para revisarlo.');
        }
    }

    private function evento(string $persona, User $actor, string $accion, ?array $anterior, array $nuevo, ?string $motivo = null, ?string $jornada = null, ?string $ausencia = null): void
    {
        DB::table('frigorifico_control_personal_eventos')->insert([
            'persona_id' => $persona, 'jornada_id' => $jornada, 'ausencia_id' => $ausencia, 'user_id' => $actor->id, 'accion' => $accion, 'motivo' => $motivo,
            'anterior' => $anterior === null ? null : json_encode($anterior, JSON_THROW_ON_ERROR),
            'nuevo' => json_encode($nuevo, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    private function zona(): string
    {
        return config('app.operational_timezone', 'America/Santiago');
    }

    /** Días calendario entre dos fechas sin hora, ambos inclusive (en UTC para no depender del cambio de horario). */
    private function dias(string $desde, string $hasta): int
    {
        return (int) CarbonImmutable::createFromFormat('!Y-m-d', $desde, 'UTC')->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $hasta, 'UTC')) + 1;
    }

    private function rango(string $desde, string $hasta): string
    {
        $formato = fn (string $fecha): string => implode('-', array_reverse(explode('-', $fecha)));

        return $desde === $hasta ? $formato($desde) : $formato($desde).' al '.$formato($hasta);
    }

    public function reporte(string $mes, ?string $personaId = null, ?string $sector = null): array
    {
        $zona = $this->zona();
        $inicio = CarbonImmutable::createFromFormat('!Y-m', $mes, $zona)->startOfMonth();
        $fin = $inicio->endOfMonth()->startOfDay();
        $jornadas = JornadaFrigorifico::query()->with('persona')->where('ingreso_at', '>=', $inicio->utc())
            ->where('ingreso_at', '<', $inicio->addMonth()->utc())
            ->when($personaId, fn ($q) => $q->where('persona_id', $personaId))
            ->when($sector, fn ($q) => $q->where('sector', $sector))->orderByDesc('ingreso_at')->orderBy('id')->get();
        // Las ausencias no guardan sector: se filtran por el sector actual de la persona.
        $ausencias = AusenciaFrigorifico::query()->with('persona')
            ->whereDate('desde', '<=', $fin->toDateString())->whereDate('hasta', '>=', $inicio->toDateString())
            ->when($personaId, fn ($q) => $q->where('persona_id', $personaId))
            ->when($sector, fn ($q) => $q->whereHas('persona', fn ($p) => $p->where('sector', $sector)))
            ->orderByDesc('desde')->orderBy('id')->get();
        $filas = [];
        $resumen = [];
        $resumir = function (PersonaFrigorifico $persona) use (&$resumen): void {
            $resumen[$persona->id] ??= ['persona_id' => $persona->id, 'nombre' => $persona->nombre,
                'identificador' => $persona->identificador, 'sector' => $persona->sector, 'jornadas_cerradas' => 0, 'pendientes' => 0,
                'minutos_trabajados' => 0, 'minutos_extra' => 0, 'dias_ausencia' => 0,
                'ausencias' => array_fill_keys(array_keys(AusenciaFrigorifico::TIPOS), 0)];
        };
        foreach ($jornadas as $j) {
            $calculo = $this->calculadora->calcular($j->ingreso_at, $j->salida_at, $j->jornada_base_minutos, $j->colacion_minutos);
            $filas[] = [...$j->toArray(), ...$calculo, 'persona' => $j->persona->only(['id', 'nombre', 'identificador']),
                'ingreso' => $j->ingreso_at->tz($zona)->format('Y-m-d\TH:i'),
                'salida' => $j->salida_at?->tz($zona)->format('Y-m-d\TH:i'),
                'estado' => $j->anulado_at ? 'anulada' : ($j->salida_at ? 'cerrada' : 'pendiente')];
            if ($j->anulado_at) {
                continue;
            }
            $resumir($j->persona);
            $fila = &$resumen[$j->persona_id];
            $fila[$j->salida_at ? 'jornadas_cerradas' : 'pendientes']++;
            $fila['minutos_trabajados'] += $calculo['minutos_trabajados'] ?? 0;
            $fila['minutos_extra'] += $calculo['minutos_extra'] ?? 0;
            unset($fila);
        }
        $filasAusencia = [];
        foreach ($ausencias as $a) {
            [$desde, $hasta] = [$a->desde->format('Y-m-d'), $a->hasta->format('Y-m-d')];
            $dias = $this->dias(max($desde, $inicio->toDateString()), min($hasta, $fin->toDateString()));
            $filasAusencia[] = [...$a->toArray(), 'persona' => $a->persona->only(['id', 'nombre', 'identificador']),
                'tipo_nombre' => AusenciaFrigorifico::TIPOS[$a->tipo], 'dias_mes' => $dias,
                'dias_total' => $this->dias($desde, $hasta), 'estado' => $a->anulado_at ? 'anulada' : 'vigente'];
            if ($a->anulado_at) {
                continue;
            }
            $resumir($a->persona);
            $resumen[$a->persona_id]['ausencias'][$a->tipo] += $dias;
            $resumen[$a->persona_id]['dias_ausencia'] += $dias;
        }
        uasort($resumen, fn ($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));

        return ['jornadas' => $filas, 'ausencias' => $filasAusencia, 'resumen' => array_values($resumen), 'meta' => [
            'mes' => $mes, 'zona_horaria' => $zona, 'ahora' => now()->toISOString(), 'tipos_ausencia' => AusenciaFrigorifico::TIPOS,
            'criterio' => 'Mes de ingreso de la jornada. Las jornadas que cruzan medianoche se imputan completas al día de ingreso. Pendientes y anuladas no suman horas. Las ausencias cuentan los días que caen dentro del mes.',
        ]];
    }

    /**
     * Ficha día a día de una persona para revisar con el trabajador a fin de mes.
     *
     * @return array{persona: PersonaFrigorifico, mes: string, dias: list<array>, resumen: array}
     */
    public function ficha(string $mes, PersonaFrigorifico $persona): array
    {
        // Fechas sin hora: se recorren en UTC para que el cambio de horario no salte ni repita días.
        $inicio = CarbonImmutable::createFromFormat('!Y-m', $mes, 'UTC')->startOfMonth();
        $reporte = $this->reporte($mes, $persona->id);
        $porDia = [];
        foreach ($reporte['jornadas'] as $j) {
            if ($j['estado'] !== 'anulada') {
                $porDia[substr($j['ingreso'], 0, 10)]['jornadas'][] = $j;
            }
        }
        foreach ($reporte['ausencias'] as $a) {
            if ($a['estado'] === 'anulada') {
                continue;
            }
            for ($d = CarbonImmutable::createFromFormat('!Y-m-d', max($a['desde'], $inicio->toDateString()), 'UTC'); $d->format('Y-m-d') <= $a['hasta'] && $d->format('Y-m') === $mes; $d = $d->addDay()) {
                $porDia[$d->format('Y-m-d')]['ausencia'] = $a;
            }
        }
        $dias = [];
        for ($d = $inicio; $d->format('Y-m') === $mes; $d = $d->addDay()) {
            $clave = $d->format('Y-m-d');
            $jornadasDia = $porDia[$clave]['jornadas'] ?? [];
            usort($jornadasDia, fn ($a, $b) => strcmp($a['ingreso'], $b['ingreso']));
            $dias[] = ['fecha' => $clave, 'dia_semana' => $d->dayOfWeekIso, 'jornadas' => $jornadasDia, 'ausencia' => $porDia[$clave]['ausencia'] ?? null];
        }

        return ['persona' => $persona, 'mes' => $mes, 'dias' => $dias, 'resumen' => $reporte['resumen'][0] ?? null, 'meta' => $reporte['meta']];
    }
}
