<?php

namespace App\Services\Consultas;

use App\Models\Cliente;
use App\Models\ProductorCsg;
use App\Models\User;
use App\Services\Clientes\ServicioCliente;
use App\Services\Validacion\BloqueoCatalogoValidacion;
use App\Services\Validacion\ServicioProyeccionCatalogoValidacion;
use App\Services\Validacion\ServicioSincronizacionCatalogoSag;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioAsociacionProductorCsg
{
    public function __construct(
        private readonly ServicioCliente $clientes,
        private readonly ServicioSincronizacionCatalogoSag $sincronizador,
        private readonly ServicioProyeccionCatalogoValidacion $proyector,
        private readonly BloqueoCatalogoValidacion $bloqueo,
    ) {}

    /**
     * @param  array<int, string>  $clienteIds
     */
    public function sincronizar(
        ProductorCsg $productor,
        array $clienteIds,
        User $usuario,
    ): ProductorCsg {
        $clienteIds = array_values(array_unique($clienteIds));

        return DB::transaction(function () use ($productor, $clienteIds, $usuario): ProductorCsg {
            $productor = ProductorCsg::query()->lockForUpdate()->findOrFail($productor->id);
            $clientes = Cliente::query()
                ->whereIn('id', $clienteIds)
                ->where('activo', true)
                ->lockForUpdate()
                ->get();
            if ($clientes->count() !== count($clienteIds)) {
                throw new DomainException('Todos los clientes seleccionados deben estar activos.');
            }

            $existentes = DB::table('clientes_productores_csg')
                ->where('productor_csg_id', $productor->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('cliente_id');
            $ahora = now();
            $asociacionCambio = false;
            $proyeccionClienteCambio = false;

            foreach ($existentes as $clienteId => $existente) {
                $activo = in_array($clienteId, $clienteIds, true);
                if ((bool) $existente->activo === $activo) {
                    continue;
                }
                DB::table('clientes_productores_csg')->where('id', $existente->id)->update([
                    'activo' => $activo,
                    'actualizado_por_user_id' => $usuario->id,
                    'updated_at' => $ahora,
                ]);
                $asociacionCambio = true;
            }

            foreach ($clientes as $cliente) {
                $proyeccionClienteCambio = $this->clientes->asegurarProyeccionesActivas(
                    $cliente,
                    $usuario->id,
                ) || $proyeccionClienteCambio;
                if ($existentes->has($cliente->id)) {
                    continue;
                }
                DB::table('clientes_productores_csg')->insert([
                    'id' => (string) Str::uuid(),
                    'cliente_id' => $cliente->id,
                    'productor_csg_id' => $productor->id,
                    'activo' => true,
                    'asociado_por_user_id' => $usuario->id,
                    'actualizado_por_user_id' => $usuario->id,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
                $asociacionCambio = true;
            }

            $sincronizacion = $this->sincronizador->sincronizar(
                $productor,
                $productor->especies_variedades ?? [],
                proyectar: false,
            );
            // La sincronización ya obtuvo el bloqueo del catálogo en esta transacción.
            $temporada = $sincronizacion['temporada_id']
                ? $this->bloqueo->adquirir($sincronizacion['temporada_id'])
                : null;

            $catalogoCambio = $asociacionCambio
                || $proyeccionClienteCambio
                || $sincronizacion['catalogo_actualizado'];

            if ($temporada && $catalogoCambio) {
                $this->proyector->sincronizarCsg($temporada, $productor);
                $this->bloqueo->publicar($temporada);
            }

            return $productor->fresh()->load('clientes');
        }, attempts: 3);
    }
}
