<?php

namespace App\Services\Validacion;

use App\Models\Cliente;
use App\Models\Temporada;
use App\Services\Clientes\ServicioCliente;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;

class ServicioReparacionCatalogoValidacion
{
    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly ServicioCliente $clientes,
        private readonly ServicioProyeccionCatalogoValidacion $proyector,
        private readonly BloqueoCatalogoValidacion $bloqueo,
    ) {}

    /**
     * @return array{
     *     temporada: Temporada,
     *     clientes_reparados: int,
     *     antes: array{articulos: int, origenes: int, combinaciones: int},
     *     despues: array{articulos: int, origenes: int, combinaciones: int}
     * }
     */
    public function repararActiva(): array
    {
        $temporada = $this->temporadas->obtener();
        $antes = $this->proyector->conteos($temporada);
        $clientesReparados = 0;

        DB::transaction(function () use ($temporada, &$clientesReparados): void {
            $temporada = $this->bloqueo->adquirir($temporada->id);
            Cliente::query()
                ->where('activo', true)
                ->orderBy('codigo')
                ->get()
                ->each(function (Cliente $cliente) use ($temporada, &$clientesReparados): void {
                    if ($this->clientes->asegurarProyeccionValidacion($cliente, $temporada)) {
                        $clientesReparados++;
                    }
                });

            $this->proyector->reconstruir($temporada);
            $this->bloqueo->publicar($temporada);
        }, attempts: 3);

        return [
            'temporada' => $temporada->refresh(),
            'clientes_reparados' => $clientesReparados,
            'antes' => $antes,
            'despues' => $this->proyector->conteos($temporada),
        ];
    }
}
