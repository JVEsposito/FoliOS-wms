<?php

namespace App\Services\Planificador;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class ServicioSaludProcesos
{
    private const WORKER = 'salud:worker:latido';

    private const SCHEDULER = 'salud:scheduler:latido';

    public function marcarWorker(): void
    {
        $ultimo = Cache::get(self::WORKER);
        $ahora = CarbonImmutable::now();
        if ($ultimo && CarbonImmutable::parse($ultimo)->diffInSeconds($ahora) < (int) config('procesos.worker_intervalo_segundos', 30)) {
            return;
        }

        Cache::put(self::WORKER, $ahora->toIso8601String(), now()->addHour());
    }

    public function marcarScheduler(): void
    {
        Cache::put(self::SCHEDULER, CarbonImmutable::now()->toIso8601String(), now()->addHour());
    }

    /** @return array{worker:array<string,mixed>,scheduler:array<string,mixed>} */
    public function consultar(): array
    {
        return [
            'worker' => $this->estado(self::WORKER, (int) config('procesos.worker_umbral_segundos', 120)),
            'scheduler' => $this->estado(self::SCHEDULER, (int) config('procesos.scheduler_umbral_segundos', 150)),
        ];
    }

    /** @return array{estado:string,ultimo_latido_at:?string,antiguedad_segundos:?int,umbral_segundos:int} */
    private function estado(string $clave, int $umbral): array
    {
        $latido = Cache::get($clave);
        $ultimo = $latido ? CarbonImmutable::parse($latido) : null;
        $edad = $ultimo ? max(0, (int) $ultimo->diffInSeconds(CarbonImmutable::now())) : null;

        return [
            'estado' => $edad !== null && $edad <= $umbral ? 'activo' : 'detenido',
            'ultimo_latido_at' => $ultimo?->toIso8601String(),
            'antiguedad_segundos' => $edad,
            'umbral_segundos' => $umbral,
        ];
    }
}
