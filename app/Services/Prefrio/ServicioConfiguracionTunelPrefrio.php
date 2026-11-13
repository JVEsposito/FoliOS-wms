<?php

namespace App\Services\Prefrio;

use App\Enums\EstadoAdministrativoTunelPrefrio;
use App\Enums\EstadoTecnicoTunelPrefrio;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\PosicionTunelPrefrio;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Secuencias\ServicioSecuenciaDocumento;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioConfiguracionTunelPrefrio
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ServicioSecuenciaDocumento $secuencias,
    ) {}

    public function siguienteCodigo(): string
    {
        return $this->codigo($this->secuencias->consultarSiguiente('tuneles_prefrio'));
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos, User $usuario): TunelPrefrio
    {
        if (! $this->alcance->puedeAdministrarTunelesPrefrio($usuario)) {
            throw new OperacionNoAutorizada('Solo el administrador puede crear túneles de prefrío.');
        }

        return DB::transaction(function () use ($datos, $usuario): TunelPrefrio {
            $capacidad = (int) $datos['capacidad_posiciones'];
            $tunel = TunelPrefrio::create([
                'codigo' => $this->codigo(
                    $this->secuencias->reservarSiguiente('tuneles_prefrio'),
                ),
                'nombre' => Str::of($datos['nombre'])->squish()->toString(),
                'capacidad_posiciones' => $capacidad,
                'setpoint_habitual' => $datos['setpoint_habitual'] ?? null,
                'estado_administrativo' => EstadoAdministrativoTunelPrefrio::Activo,
                'estado_tecnico' => EstadoTecnicoTunelPrefrio::from(
                    $datos['estado_tecnico'] ?? EstadoTecnicoTunelPrefrio::Operativo->value,
                ),
                'codigo_externo' => $this->textoOpcional($datos['codigo_externo'] ?? null),
                'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
                'creado_por_user_id' => $usuario->id,
            ]);

            $this->crearPosiciones($tunel, 1, $capacidad);

            return $this->cargar($tunel);
        }, attempts: 3);
    }

    private function codigo(int $numero): string
    {
        return sprintf('TUN-%02d', $numero);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(TunelPrefrio $tunel, array $datos, User $usuario): TunelPrefrio
    {
        if (! $this->alcance->puedeAdministrarTunelesPrefrio($usuario)) {
            throw new OperacionNoAutorizada('Solo el administrador puede modificar túneles de prefrío.');
        }

        return DB::transaction(function () use ($tunel, $datos): TunelPrefrio {
            $tunel = TunelPrefrio::query()->lockForUpdate()->findOrFail($tunel->id);
            $this->asegurarSinProcesoActivo($tunel);

            $capacidadAnterior = $tunel->capacidad_posiciones;
            $capacidadNueva = (int) $datos['capacidad_posiciones'];

            if ($capacidadNueva < $capacidadAnterior) {
                $ocupadasHistoricamente = PosicionTunelPrefrio::query()
                    ->where('tunel_prefrio_id', $tunel->id)
                    ->where('numero', '>', $capacidadNueva)
                    ->whereHas('asignaciones')
                    ->exists();

                if ($ocupadasHistoricamente) {
                    throw new DomainException(
                        'No es posible reducir la capacidad porque existen procesos históricos en las posiciones retiradas.',
                    );
                }
            }

            if ($capacidadNueva > $capacidadAnterior) {
                $this->crearPosiciones($tunel, $capacidadAnterior + 1, $capacidadNueva);
            }

            PosicionTunelPrefrio::query()
                ->where('tunel_prefrio_id', $tunel->id)
                ->update([
                    'activa' => DB::raw("CASE WHEN numero <= {$capacidadNueva} THEN 1 ELSE 0 END"),
                    'updated_at' => now(),
                ]);

            $tunel->update([
                'nombre' => Str::of($datos['nombre'])->squish()->toString(),
                'capacidad_posiciones' => $capacidadNueva,
                'setpoint_habitual' => $datos['setpoint_habitual'] ?? null,
                'estado_administrativo' => EstadoAdministrativoTunelPrefrio::from($datos['estado_administrativo']),
                'estado_tecnico' => EstadoTecnicoTunelPrefrio::from($datos['estado_tecnico']),
                'codigo_externo' => $this->textoOpcional($datos['codigo_externo'] ?? null),
                'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
                'version_configuracion' => $tunel->version_configuracion + 1,
            ]);

            return $this->cargar($tunel->refresh());
        }, attempts: 3);
    }

    private function asegurarSinProcesoActivo(TunelPrefrio $tunel): void
    {
        if ($tunel->procesos()->ocupaTunel()->exists()) {
            throw new DomainException('El túnel posee un proceso activo y no puede modificarse.');
        }
    }

    private function crearPosiciones(TunelPrefrio $tunel, int $desde, int $hasta): void
    {
        $ahora = now();
        $filas = [];

        for ($numero = $desde; $numero <= $hasta; $numero++) {
            $filas[] = [
                'id' => (string) Str::uuid(),
                'tunel_prefrio_id' => $tunel->id,
                'numero' => $numero,
                'etiqueta' => sprintf('%s-P%02d', $tunel->codigo, $numero),
                'activa' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        foreach (array_chunk($filas, 250) as $lote) {
            PosicionTunelPrefrio::query()->insertOrIgnore($lote);
        }
    }

    private function cargar(TunelPrefrio $tunel): TunelPrefrio
    {
        return $tunel->load([
            'posiciones' => fn ($consulta) => $consulta->orderBy('numero'),
            'procesoActivo.folios',
            'creadoPor:id,name',
        ]);
    }

    private function textoOpcional(mixed $valor): ?string
    {
        $texto = Str::of((string) $valor)->squish()->toString();

        return $texto !== '' ? $texto : null;
    }
}
