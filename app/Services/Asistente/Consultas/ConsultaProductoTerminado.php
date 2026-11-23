<?php

namespace App\Services\Asistente\Consultas;

use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Existencias\ServicioExistencias;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Collection;

/**
 * Base de las consultas sobre la existencia viva de producto terminado: reutiliza las
 * filas de ServicioExistencias y su mismo permiso.
 */
abstract class ConsultaProductoTerminado extends ConsultaBase
{
    public function __construct(
        AlcanceOperacionalUsuario $alcance,
        ServicioTemporadaActiva $temporadas,
        protected readonly ServicioExistencias $existencias,
    ) {
        parent::__construct($alcance, $temporadas);
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    /** La existencia es la de ahora: no filtra por fecha ni por temporada anterior. */
    public function filtros(): array
    {
        return ['cliente', 'especie'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->existencias->puedeConsultar($usuario, ServicioExistencias::PRODUCTO_TERMINADO);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function filas(ParametrosConsulta $parametros): Collection
    {
        $filas = collect($this->existencias->filas(ServicioExistencias::PRODUCTO_TERMINADO)->all());
        // La existencia identifica al cliente por la exportadora del folio (código o nombre) y la especie por su texto.
        $aceptados = $this->exportadoras($parametros);
        $especie = $this->especieTexto($parametros);

        return $filas
            ->when($aceptados !== null, fn (Collection $c) => $c->filter(fn (array $fila): bool => in_array(mb_strtoupper(trim((string) ($fila['cliente'] ?? ''))), $aceptados, true)))
            ->when($especie !== null, fn (Collection $c) => $c->filter(fn (array $fila): bool => mb_strtoupper(trim((string) ($fila['especie'] ?? ''))) === $especie))
            ->values();
    }

    protected static function cajas(mixed $valor): int
    {
        return (int) ($valor ?? 0);
    }
}
