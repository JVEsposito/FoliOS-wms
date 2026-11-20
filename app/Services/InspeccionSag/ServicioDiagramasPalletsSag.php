<?php

namespace App\Services\InspeccionSag;

use App\Exceptions\ConflictoOperacion;
use App\Models\DiagramaPalletsSag;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Guarda los diagramas de presentación de pallets de la temporada activa. Es un apoyo para
 * presentar los pallets a la inspección: no mueve pallets, no cambia cajas ni aprueba nada.
 */
class ServicioDiagramasPalletsSag
{
    public function __construct(private readonly NumeracionDiagramaPallets $numeracion) {}

    /**
     * Normaliza el contenido y comprueba la numeración continua y las marcas.
     *
     * @return array<string, mixed>
     */
    public function contenido(array $datos): array
    {
        $texto = fn (?string $valor): ?string => filled($valor) ? trim($valor) : null;
        $pallets = array_map(fn (array $pallet): array => [
            'folio' => $texto($pallet['folio'] ?? null),
            'base' => (int) $pallet['base'],
            'corridas' => (int) $pallet['corridas'],
            'ultima' => isset($pallet['ultima']) ? (int) $pallet['ultima'] : null,
            'declaradas' => isset($pallet['declaradas']) ? (int) $pallet['declaradas'] : null,
        ], array_values($datos['pallets']));
        $numeracion = $this->numeracion->numerar($pallets);

        return [
            'tipo' => $datos['tipo'],
            'numero' => filled($datos['numero'] ?? null) ? mb_strtoupper(trim($datos['numero'])) : null,
            'csp' => $texto($datos['csp'] ?? null),
            'folio' => $texto($datos['folio'] ?? null),
            'fecha' => $datos['fecha'] ?? null,
            'lote' => $texto($datos['lote'] ?? null),
            'especie' => $texto($datos['especie'] ?? null),
            'exportadora' => $texto($datos['exportadora'] ?? null),
            'pallets' => $pallets,
            'cajas_marcadas' => $this->numeracion->marcas($datos['cajas_marcadas'] ?? [], $numeracion),
            'total_pallets' => count($pallets),
            'total_cajas' => $numeracion['total'],
        ];
    }

    public function guardar(array $datos, User $usuario, ?DiagramaPalletsSag $diagrama = null): DiagramaPalletsSag
    {
        Gate::forUser($usuario)->authorize('gestionar-inspeccion-sag');
        $contenido = $this->contenido($datos);
        $hash = hash('sha256', json_encode([$diagrama?->id, $datos['version'] ?? null, $contenido], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($datos, $usuario, $diagrama, $contenido, $hash): DiagramaPalletsSag {
            $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true)
                ?? throw new DomainException('No existe una temporada activa.');
            $previo = DB::table('eventos_diagrama_pallets_sag')->where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
            if ($previo) {
                if (! hash_equals($previo->payload_hash, $hash) || (int) $previo->user_id !== $usuario->id) {
                    throw new ConflictoOperacion('La operación ya fue utilizada con otros datos.');
                }
                $anterior = DiagramaPalletsSag::findOrFail($previo->diagrama_id);
                if ($anterior->temporada_id !== $temporada->id) {
                    throw new ConflictoOperacion('La operación pertenece a otra temporada.');
                }

                return $anterior;
            }
            $registro = $diagrama ? DiagramaPalletsSag::lockForUpdate()->findOrFail($diagrama->id) : new DiagramaPalletsSag;
            if ($diagrama && ($registro->temporada_id !== $temporada->id || $registro->version !== (int) $datos['version'])) {
                throw new ConflictoOperacion('Otra persona guardó este diagrama. Vuelve a abrirlo antes de guardar.');
            }
            $repetido = DiagramaPalletsSag::where('temporada_id', $temporada->id)->where('tipo', $contenido['tipo'])
                ->where('numero', $contenido['numero'])->when($diagrama, fn ($q) => $q->whereKeyNot($diagrama->id))->exists();
            if ($repetido) {
                throw new ConflictoOperacion('Ya existe el diagrama '.mb_strtoupper($contenido['tipo']).' N.º '.$contenido['numero']
                    .' en esta temporada. Ábrelo desde «Diagramas guardados» o usa otro número.');
            }
            if (! $diagrama) {
                $registro->fill(['temporada_id' => $temporada->id, 'creado_por_user_id' => $usuario->id]);
            }
            $registro->fill([...$contenido, 'version' => $diagrama ? $registro->version + 1 : 1,
                'actualizado_por_user_id' => $usuario->id])->save();
            DB::table('eventos_diagrama_pallets_sag')->insert([
                'id' => (string) Str::uuid(), 'diagrama_id' => $registro->id, 'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash, 'user_id' => $usuario->id, 'usuario_nombre' => $usuario->name,
                'version' => $registro->version, 'despues' => json_encode($contenido, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $registro->refresh();
        }, attempts: 3);
    }
}
