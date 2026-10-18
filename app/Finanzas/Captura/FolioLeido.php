<?php

namespace App\Finanzas\Captura;

/**
 * Lo que Finanzas necesita de un folio de producto terminado: identificación, cajas y
 * cliente. El cliente sale de la composición por CSG del folio y, si falta, del nombre
 * de la exportadora.
 */
final class FolioLeido
{
    public function __construct(
        public readonly string $id,
        public readonly string $numero,
        public readonly string $tipoBulto,
        public readonly string $estado,
        public readonly bool $activo,
        public readonly ?string $temporadaId,
        public readonly int $cajas,
        public readonly ?string $clienteId,
        public readonly ?string $clienteNombre,
        public readonly ?string $marca,
        public readonly ?string $especie,
        public readonly ?string $variedad,
        public readonly ?string $calibre,
        public readonly ?string $envase,
        public readonly ?string $actualizadoAt,
    ) {}

    /** @return array{bultos: int, pallets: int, saldos: int, cajas: int} */
    public function medidas(): array
    {
        return [
            'bultos' => 1,
            'pallets' => $this->tipoBulto === 'pallet' ? 1 : 0,
            'saldos' => $this->tipoBulto === 'saldo' ? 1 : 0,
            'cajas' => $this->cajas,
        ];
    }

    /** @return array<string, string|null> */
    public function datos(): array
    {
        return [
            'folio_id' => $this->id,
            'folio' => $this->numero,
            'tipo_bulto' => $this->tipoBulto,
            'marca' => $this->marca,
            'especie' => $this->especie,
            'variedad' => $this->variedad,
            'calibre' => $this->calibre,
            'envase' => $this->envase,
        ];
    }

    /**
     * @param  list<string>  $folioIds
     * @return array<string, self>
     */
    public static function leer(ContextoCaptura $contexto, array $folioIds): array
    {
        $folioIds = array_values(array_unique(array_filter($folioIds)));
        if ($folioIds === []) {
            return [];
        }

        $folios = $contexto->lector->tabla('folios')
            ->whereIn('id', $folioIds)
            ->whereIn('tipo_bulto', ['pallet', 'saldo'])
            ->get([
                'id', 'numero_folio', 'tipo_bulto', 'estado_operacional', 'activo', 'temporada_id', 'exportadora',
                'marca', 'variedad', 'calibre', 'datos_externos', 'updated_at',
            ]);

        // Un bulto es de un solo cliente; ante una composición mezclada manda el de más cajas.
        $clientes = $contexto->lector->tabla('trazabilidad_folio_origenes')
            ->whereIn('folio_id', $folios->pluck('id'))
            ->whereNotNull('cliente_id')
            ->groupBy('folio_id', 'cliente_id')
            ->selectRaw('folio_id, cliente_id, SUM(cantidad_cajas) as cajas')
            ->get()
            ->groupBy('folio_id')
            ->map(fn ($lineas): ?string => $lineas->sortByDesc('cajas')->first()?->cliente_id);

        $leidos = [];
        foreach ($folios as $folio) {
            $externos = json_decode((string) $folio->datos_externos, true);
            $externos = is_array($externos) ? $externos : [];
            $composicion = array_filter((array) ($externos['composicion'] ?? []), 'is_array');
            $cajas = $composicion !== []
                ? array_sum(array_map(fn (array $linea): int => (int) ($linea['cantidad_cajas'] ?? 0), $composicion))
                : (int) ($externos['cantidad_cajas'] ?? 0);
            [$clienteId, $clienteNombre] = $contexto->cliente($clientes->get($folio->id), $folio->exportadora);

            $leidos[$folio->id] = new self(
                id: $folio->id,
                numero: (string) $folio->numero_folio,
                tipoBulto: (string) $folio->tipo_bulto,
                estado: (string) $folio->estado_operacional,
                activo: (bool) $folio->activo,
                temporadaId: $folio->temporada_id,
                cajas: $cajas,
                clienteId: $clienteId,
                clienteNombre: $clienteNombre,
                marca: $folio->marca,
                especie: isset($externos['especie']) ? (string) $externos['especie'] : null,
                variedad: $folio->variedad,
                calibre: $folio->calibre,
                envase: isset($externos['envase']) ? (string) $externos['envase'] : null,
                actualizadoAt: $folio->updated_at,
            );
        }

        return $leidos;
    }
}
