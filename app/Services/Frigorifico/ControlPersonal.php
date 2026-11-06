<?php

namespace App\Services\Frigorifico;

use App\Exceptions\ConflictoOperacion;
use App\Models\JornadaFrigorifico;
use App\Models\PersonaFrigorifico;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ControlPersonal
{
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
            $this->evento($persona->id, null, $actor, 'persona_guardada', $anterior, $persona->toArray());

            return $persona;
        }, attempts: 3);
    }

    public function guardarJornada(array $datos, User $actor, ?string $id = null): JornadaFrigorifico
    {
        return DB::transaction(function () use ($datos, $actor, $id): JornadaFrigorifico {
            // Bloqueo común por persona: serializa altas, cierres, correcciones y anulaciones.
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
            $anterior = $jornada->exists ? $jornada->toArray() : null;
            $jornada->fill(['persona_id' => $persona->id, 'ingreso_at' => $ingreso, 'salida_at' => $salida,
                'jornada_base_minutos' => $base, 'colacion_minutos' => $datos['colacion_minutos'],
                'sector' => $datos['sector'] ?? ($jornada->exists ? $jornada->sector : $persona->sector),
                'observacion' => $datos['observacion'] ?? null, 'version' => $jornada->exists ? $jornada->version + 1 : 1]);
            $jornada->save();
            $this->evento($persona->id, $jornada->id, $actor, 'jornada_guardada', $anterior, $jornada->toArray(), $datos['motivo'] ?? null);

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
            $this->evento($jornada->persona_id, $jornada->id, $actor, 'jornada_anulada', $anterior, $jornada->toArray(), $datos['motivo']);
        }, attempts: 3);
    }

    private function version($modelo, array $datos): void
    {
        if ($modelo->exists && $modelo->version !== ($datos['version'] ?? null)) {
            throw new ConflictoOperacion('Otro supervisor modificó este registro. Actualiza antes de guardar; conserva tu borrador para revisarlo.');
        }
    }

    private function evento(string $persona, ?string $jornada, User $actor, string $accion, ?array $anterior, array $nuevo, ?string $motivo = null): void
    {
        DB::table('frigorifico_control_personal_eventos')->insert([
            'persona_id' => $persona, 'jornada_id' => $jornada, 'user_id' => $actor->id, 'accion' => $accion, 'motivo' => $motivo,
            'anterior' => $anterior === null ? null : json_encode($anterior, JSON_THROW_ON_ERROR),
            'nuevo' => json_encode($nuevo, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function reporte(string $mes, ?string $personaId = null, ?string $sector = null): array
    {
        $zona = config('app.operational_timezone', 'America/Santiago');
        $inicio = CarbonImmutable::createFromFormat('!Y-m', $mes, $zona)->startOfMonth();
        $jornadas = JornadaFrigorifico::query()->with('persona')->where('ingreso_at', '>=', $inicio->utc())
            ->where('ingreso_at', '<', $inicio->addMonth()->utc())
            ->when($personaId, fn ($q) => $q->where('persona_id', $personaId))
            ->when($sector, fn ($q) => $q->where('sector', $sector))->orderByDesc('ingreso_at')->orderBy('id')->get();
        $filas = [];
        $resumen = [];
        foreach ($jornadas as $j) {
            $calculo = $this->calculadora->calcular($j->ingreso_at, $j->salida_at, $j->jornada_base_minutos, $j->colacion_minutos);
            $filas[] = [...$j->toArray(), ...$calculo, 'persona' => $j->persona->only(['id', 'nombre', 'identificador']),
                'ingreso' => $j->ingreso_at->tz($zona)->format('Y-m-d\TH:i'),
                'salida' => $j->salida_at?->tz($zona)->format('Y-m-d\TH:i'),
                'estado' => $j->anulado_at ? 'anulada' : ($j->salida_at ? 'cerrada' : 'pendiente')];
            if ($j->anulado_at) {
                continue;
            }
            $resumen[$j->persona_id] ??= ['persona_id' => $j->persona_id, 'nombre' => $j->persona->nombre,
                'identificador' => $j->persona->identificador, 'jornadas_cerradas' => 0, 'pendientes' => 0,
                'minutos_trabajados' => 0, 'minutos_extra' => 0];
            $fila = &$resumen[$j->persona_id];
            $fila[$j->salida_at ? 'jornadas_cerradas' : 'pendientes']++;
            $fila['minutos_trabajados'] += $calculo['minutos_trabajados'] ?? 0;
            $fila['minutos_extra'] += $calculo['minutos_extra'] ?? 0;
            unset($fila);
        }
        uasort($resumen, fn ($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));

        return ['jornadas' => $filas, 'resumen' => array_values($resumen), 'meta' => [
            'mes' => $mes, 'zona_horaria' => $zona, 'ahora' => now()->toISOString(),
            'criterio' => 'Mes de ingreso de la jornada. Las jornadas que cruzan medianoche se imputan completas al día de ingreso. Pendientes y anuladas no suman horas.',
        ]];
    }
}
