<?php

namespace App\Services\Temporadas;

use App\Enums\TipoTemporada;
use App\Models\Temporada;
use App\Models\TemporadaMaterial;
use App\Services\Clientes\ServicioCliente;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioTemporadaGlobal
{
    public function __construct(
        private readonly ServicioCliente $clientes,
        private readonly ServicioTemporadaActiva $temporadaActiva,
    ) {}

    /** @param array<string, mixed> $datos */
    public function guardar(
        array $datos,
        ?Temporada $temporada = null,
        ?int $usuarioId = null,
    ): Temporada {
        $codigo = mb_strtoupper(Str::of($datos['codigo'])->squish()->toString());
        $duplicada = Temporada::query()
            ->where('codigo', $codigo)
            ->when($temporada, fn ($consulta) => $consulta->whereKeyNot($temporada->id))
            ->exists();

        if ($duplicada) {
            throw new DomainException('Ya existe una temporada con ese código.');
        }

        return DB::transaction(function () use ($datos, $codigo, $temporada, $usuarioId): Temporada {
            $nueva = $temporada === null;
            $temporada ??= new Temporada;
            $temporada->fill([
                'codigo' => $codigo,
                'nombre' => Str::of($datos['nombre'])->squish()->toString(),
                'fecha_inicio' => $datos['fecha_inicio'] ?? null,
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'prefijo_documental' => $this->prefijo($datos, $temporada),
                'activa' => (bool) ($datos['activa'] ?? false),
                'intervalo_embarques_minutos' => (int) ($datos['intervalo_embarques_minutos']
                    ?? $temporada->intervalo_embarques_minutos
                    ?? 60),
            ]);

            // El tipo solo se elige al crear. Después cambia únicamente con
            // ServicioClasificacionTemporada, que exige motivo y lo audita.
            if ($nueva && isset($datos['tipo'])) {
                $temporada->tipo = TipoTemporada::from((string) $datos['tipo']);
            }

            if ($temporada->tipo === TipoTemporada::Productiva) {
                $this->asegurarVigenciaProductiva($temporada);
            }

            if ($temporada->activa) {
                $this->asegurarActivable($temporada);
            }

            $activar = $temporada->activa;
            if ($activar) {
                // El índice único impide activar la nueva fila antes de
                // desactivar la temporada vigente, incluso dentro de la transacción.
                $temporada->activa = false;
            }
            $temporada->save();

            if ($activar) {
                $this->activarDentroDeTransaccion($temporada, $usuarioId);
            } else {
                $this->asegurarConfiguracionMaterial($temporada, $usuarioId);
            }

            return $temporada->refresh();
        }, attempts: 3);
    }

    public function activar(Temporada $temporada, ?int $usuarioId = null): Temporada
    {
        return DB::transaction(function () use ($temporada, $usuarioId): Temporada {
            $temporada = Temporada::query()->lockForUpdate()->findOrFail($temporada->id);
            $this->asegurarActivable($temporada);
            $this->asegurarVigenciaProductiva($temporada);
            $this->activarDentroDeTransaccion($temporada, $usuarioId);

            return $temporada->refresh();
        }, attempts: 3);
    }

    /**
     * Una temporada productiva tiene inicio y término, y sus fechas no se
     * cruzan con las de otra temporada productiva.
     */
    public function asegurarVigenciaProductiva(Temporada $temporada): void
    {
        if ($temporada->fecha_inicio === null || $temporada->fecha_fin === null) {
            throw new DomainException('Una temporada productiva requiere fecha de inicio y de término.');
        }

        if ($temporada->fecha_fin->lt($temporada->fecha_inicio)) {
            throw new DomainException('La fecha de término no puede ser anterior a la de inicio.');
        }

        $traslape = Temporada::query()
            ->productivas()
            ->when($temporada->exists, fn ($consulta) => $consulta->whereKeyNot($temporada->id))
            ->whereNotNull('fecha_inicio')
            ->whereNotNull('fecha_fin')
            ->whereDate('fecha_inicio', '<=', $temporada->fecha_fin->toDateString())
            ->whereDate('fecha_fin', '>=', $temporada->fecha_inicio->toDateString())
            ->orderBy('fecha_inicio')
            ->first(['codigo', 'fecha_inicio', 'fecha_fin']);

        if ($traslape) {
            throw new DomainException(sprintf(
                'Las fechas se cruzan con la temporada productiva %s (%s a %s). Ajusta las fechas o, si %s fue de ensayo, declárala temporada de prueba.',
                $traslape->codigo,
                $traslape->fecha_inicio->format('d-m-Y'),
                $traslape->fecha_fin->format('d-m-Y'),
                $traslape->codigo,
            ));
        }
    }

    public function asegurarActivable(Temporada $temporada): void
    {
        if ($temporada->esPrueba()) {
            throw new DomainException(
                "La temporada {$temporada->codigo} es de prueba y no puede activarse. Declárala productiva antes de activarla.",
            );
        }

        if ($temporada->prefijo_documental === null) {
            throw new DomainException(
                "Registra el prefijo documental de la temporada {$temporada->codigo} antes de activarla.",
            );
        }
    }

    /** @param array<string, mixed> $datos */
    private function prefijo(array $datos, Temporada $temporada): ?string
    {
        if (! array_key_exists('prefijo_documental', $datos)) {
            return $temporada->prefijo_documental;
        }

        $prefijo = mb_strtoupper(trim((string) $datos['prefijo_documental']));
        if ($prefijo === '') {
            return null;
        }

        $duplicado = Temporada::query()
            ->where('prefijo_documental', $prefijo)
            ->when($temporada->exists, fn ($consulta) => $consulta->whereKeyNot($temporada->id))
            ->value('codigo');

        if ($duplicado !== null) {
            throw new DomainException("El prefijo documental {$prefijo} ya pertenece a la temporada {$duplicado}.");
        }

        return $prefijo;
    }

    private function activarDentroDeTransaccion(Temporada $temporada, ?int $usuarioId): void
    {
        $this->temporadaActiva->buscar(bloquear: true);
        Temporada::query()->whereKeyNot($temporada->id)->update(['activa' => false]);
        $temporada->update(['activa' => true]);
        $this->temporadaActiva->olvidar();
        DB::table('temporadas_materiales')->update(['activa' => false]);
        $this->asegurarConfiguracionMaterial($temporada, $usuarioId);
    }

    public function asegurarConfiguracionMaterial(
        Temporada $temporada,
        ?int $usuarioId = null,
    ): TemporadaMaterial {
        $configuracion = TemporadaMaterial::query()->firstOrNew([
            'temporada_id' => $temporada->id,
        ]);
        $configuracion->fill([
            'codigo' => $temporada->codigo,
            'nombre' => $temporada->nombre,
            'fecha_inicio' => $temporada->fecha_inicio,
            'fecha_fin' => $temporada->fecha_fin,
            'activa' => $temporada->activa,
            'creado_por_user_id' => $configuracion->creado_por_user_id ?? $usuarioId,
            'actualizado_por_user_id' => $usuarioId,
        ]);
        $configuracion->save();
        $this->clientes->asegurarClientesEnTemporada(
            $temporada,
            $configuracion,
            $usuarioId,
        );

        return $configuracion;
    }
}
