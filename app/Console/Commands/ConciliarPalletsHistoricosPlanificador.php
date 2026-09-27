<?php

namespace App\Console\Commands;

use App\Enums\RolUsuario;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Planificador\ServicioConciliacionPalletsHistoricos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ConciliarPalletsHistoricosPlanificador extends Command
{
    protected $signature = 'planificador:conciliar-pallets
        {--aplicar : Crea los objetivos; por defecto solo muestra el diagnóstico}
        {--usuario= : ID o email del supervisor que autoriza la conciliación}
        {--limite=200 : Máximo de pallets a incorporar en esta ejecución}';

    protected $description = 'Recupera pallets aprobados en prefrío antes de habilitar la recepción rolling';

    public function handle(ServicioConciliacionPalletsHistoricos $conciliador): int
    {
        $temporada = Temporada::query()->where('activa', true)->first();
        if (! $temporada) {
            $this->components->error('No existe una temporada activa.');

            return self::FAILURE;
        }

        $limite = min(1000, max(1, (int) $this->option('limite')));
        $diagnostico = $conciliador->diagnosticar($temporada);
        $this->components->info(
            "Temporada {$temporada->codigo}: {$diagnostico['sin_objetivo']} pallets sin ubicación ni objetivo; "
            ."{$diagnostico['total']} elegibles y {$diagnostico['requieren_revision']} para revisión manual.",
        );
        foreach ($diagnostico['folios'] as $folio) {
            $this->line($folio);
        }

        if (! $this->option('aplicar')) {
            $this->components->info('Vista previa: no se crearon tareas.');

            return self::SUCCESS;
        }

        if (! config('planificador.generacion_automatica')
            || config('planificador.mode') !== 'guided'
            || config('planificador.compute') !== 'tablet') {
            $this->components->error('Se requiere generación automática, guided y cálculo en tablet.');

            return self::FAILURE;
        }

        $identificador = $this->option('usuario');
        $usuario = is_string($identificador) && trim($identificador) !== ''
            ? User::query()->where('id', $identificador)->orWhere('email', $identificador)->first()
            : null;
        if (! $usuario || ! $usuario->activo || ! in_array($usuario->rol, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
        ], true)) {
            $this->components->error('--usuario debe identificar un administrador o supervisor de frío activo.');

            return self::FAILURE;
        }

        $incorporados = $conciliador->incorporar($temporada, $usuario, $limite);
        Log::warning('Conciliación manual de pallets históricos para el planificador', [
            'temporada_id' => $temporada->id,
            'usuario_id' => $usuario->id,
            'limite' => $limite,
            'incorporados' => $incorporados,
        ]);
        $this->components->info("{$incorporados} objetivos rolling creados; repetir el diagnóstico para revisar el saldo.");

        return self::SUCCESS;
    }
}
