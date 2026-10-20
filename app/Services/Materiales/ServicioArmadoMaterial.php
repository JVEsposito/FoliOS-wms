<?php

namespace App\Services\Materiales;

use App\Enums\EstadoReservaMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\FolioSalidaReservadoTransformacion;
use App\Models\OrdenTransformacionMaterial;
use App\Models\RecepcionMaterial;
use App\Models\ReservaTransformacionMaterial;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tarja de armado de cajas. Cuando la bodega de armado tiene código, cada pallet que sale
 * de una orden de transformación lleva:
 *
 * - folio único de armado: AR + código del ítem (codificación del cliente) + código de
 *   bodega + correlativo de 5 dígitos que nunca se repite;
 * - lote: código de bodega + guía con que llegaron las cajas + fecha del armado (DDMMAA)
 *   + turno (01 día, 02 tarde) + máquina (2 dígitos).
 *
 * La guía es la que aporta más cajas reservadas a la orden en el componente principal, y
 * el proveedor de la caja es el de esa guía. Ambos se fijan al planificar. Un folio que
 * llegó por transferencia entre clientes conserva la guía de su folio de origen.
 */
class ServicioArmadoMaterial
{
    /** @var array<string, string> */
    public const TURNOS = ['01' => 'Día', '02' => 'Tarde'];

    private const MAXIMO_CORRELATIVO = 99_999;

    private const TABLA_CORRELATIVOS = 'correlativos_folio_armado_materiales';

    /** Largo de `folios.numero_folio`. */
    private const LARGO_MAXIMO_FOLIO = 50;

    /** Transferencias encadenadas que se siguen hasta encontrar la recepción. */
    private const MAXIMO_SALTOS_ORIGEN = 5;

    public function activo(): bool
    {
        return $this->codigoBodega() !== null;
    }

    public function codigoBodega(): ?string
    {
        $codigo = trim((string) config('materiales.armado.codigo_bodega'));

        return preg_match('/^[0-9]{1,6}$/', $codigo) === 1 ? $codigo : null;
    }

    public function nombreBodega(): string
    {
        return trim((string) config('materiales.armado.nombre_bodega')) ?: 'BODEGA DE ARMADO';
    }

    /**
     * @return array{activo: bool, codigo_bodega: ?string, nombre_bodega: ?string, turnos: array<int, array{codigo: string, nombre: string}>}
     */
    public function configuracion(): array
    {
        return [
            'activo' => $this->activo(),
            'codigo_bodega' => $this->codigoBodega(),
            'nombre_bodega' => $this->activo() ? $this->nombreBodega() : null,
            'turnos' => collect(self::TURNOS)
                ->map(fn (string $nombre, string $codigo): array => ['codigo' => $codigo, 'nombre' => $nombre])
                ->values()
                ->all(),
        ];
    }

    /** Número de máquina de 1 o 2 dígitos llevado a 2: «3» y «03» dan «03». */
    public function maquina(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        if (preg_match('/^[0-9]{1,2}$/', $valor) !== 1 || (int) $valor < 1) {
            return null;
        }

        return sprintf('%02d', (int) $valor);
    }

    public function codigoItem(string $codigo): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', $codigo));
    }

    /**
     * Siguiente folio único de armado del ítem. El correlativo se bloquea por fila y salta
     * cualquier número ya usado como folio o como reserva, para no chocar nunca.
     */
    public function siguienteFolio(string $codigoItem): string
    {
        $codigoBodega = $this->codigoBodega();
        if ($codigoBodega === null) {
            throw new DomainException('La bodega de armado no tiene código configurado.');
        }
        $codigo = $this->codigoItem($codigoItem);
        if ($codigo === '') {
            throw new DomainException('El ítem de salida no tiene código para el folio de armado.');
        }
        if (mb_strlen('AR'.$codigo.$codigoBodega) + 5 > self::LARGO_MAXIMO_FOLIO) {
            throw new DomainException(sprintf(
                'El código del ítem %s es demasiado largo para el folio de armado (máximo %d caracteres en total).',
                $codigo,
                self::LARGO_MAXIMO_FOLIO,
            ));
        }

        $ahora = now();
        DB::table(self::TABLA_CORRELATIVOS)->insertOrIgnore([
            'codigo_item' => $codigo,
            'codigo_bodega' => $codigoBodega,
            'ultimo_numero' => 0,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        $fila = DB::table(self::TABLA_CORRELATIVOS)
            ->where('codigo_item', $codigo)
            ->where('codigo_bodega', $codigoBodega)
            ->lockForUpdate()
            ->first();
        $numero = (int) $fila->ultimo_numero;

        do {
            $numero++;
            if ($numero > self::MAXIMO_CORRELATIVO) {
                throw new DomainException(sprintf(
                    'El ítem %s agotó el correlativo de folios de armado.',
                    $codigo,
                ));
            }
            $folio = sprintf('AR%s%s%05d', $codigo, $codigoBodega, $numero);
        } while ($this->folioOcupado($folio));

        DB::table(self::TABLA_CORRELATIVOS)
            ->where('codigo_item', $codigo)
            ->where('codigo_bodega', $codigoBodega)
            ->update(['ultimo_numero' => $numero, 'updated_at' => $ahora]);

        return $folio;
    }

    /**
     * Datos de la tarja que quedan fijos en la orden al planificar: bodega, guía y
     * proveedor de las cajas, turno, máquina y lote.
     *
     * @return array<string, mixed>
     */
    public function datosOrden(OrdenTransformacionMaterial $orden): array
    {
        $codigoBodega = $this->codigoBodega();
        if ($codigoBodega === null) {
            throw new DomainException('La bodega de armado no tiene código configurado.');
        }

        $turno = trim((string) $orden->turno);
        if (! array_key_exists($turno, self::TURNOS)) {
            throw new DomainException(
                'La orden necesita turno 01 (día) o 02 (tarde) para el lote de la tarja de armado.',
            );
        }
        $maquina = $this->maquina($orden->linea);
        if ($maquina === null) {
            throw new DomainException(
                'La orden necesita el número de máquina (1 a 99) para el lote de la tarja de armado.',
            );
        }

        $guia = $this->guiaPrincipal($orden);
        $fecha = $orden->fecha_operacional;
        $lote = $codigoBodega.$guia['numero'].$fecha->format('dmy').$turno.$maquina;

        return [
            'codigo_bodega' => $codigoBodega,
            'nombre_bodega' => $this->nombreBodega(),
            'guia' => $guia['numero'],
            'proveedor_material_id' => $guia['proveedor_id'],
            'proveedor_codigo' => $guia['proveedor_codigo'],
            'proveedor_nombre' => $guia['proveedor_nombre'],
            'turno' => $turno,
            'maquina' => $maquina,
            'fecha' => $fecha->toDateString(),
            'lote' => $lote,
        ];
    }

    /**
     * Guía de recepción que aporta más cajas reservadas al componente principal; ante un
     * empate, la primera por FIFO.
     *
     * @return array{numero: string, proveedor_id: ?string, proveedor_codigo: ?string, proveedor_nombre: ?string}
     */
    private function guiaPrincipal(OrdenTransformacionMaterial $orden): array
    {
        $componentes = collect((array) data_get($orden->snapshot_receta, 'componentes', []));
        $principal = $componentes->first(fn (array $componente): bool => (bool) ($componente['es_componente_principal'] ?? false))
            ?? $componentes->first();
        $itemId = (string) ($principal['item_id'] ?? '');

        $reservas = ReservaTransformacionMaterial::query()
            ->with('folioMaterial.folio', 'folioMaterial.bultoRecepcion.detalle.recepcion.proveedor')
            ->where('orden_transformacion_material_id', $orden->id)
            ->where('item_material_id', $itemId)
            ->where('estado', EstadoReservaMaterial::Activa->value)
            ->orderBy('orden_fifo')
            ->get();

        $guias = [];
        foreach ($reservas as $reserva) {
            $recepcion = $this->recepcionDe($reserva->folioMaterial);
            $numero = $recepcion ? $this->numeroGuia((string) $recepcion->numero_guia_despacho) : '';
            if ($numero === '') {
                continue;
            }
            $guias[$numero] ??= [
                'numero' => $numero,
                'cantidad' => 0.0,
                'fifo' => (int) $reserva->orden_fifo,
                'proveedor_id' => $recepcion->proveedor?->id,
                'proveedor_codigo' => $recepcion->proveedor?->codigo,
                'proveedor_nombre' => $recepcion->proveedor?->nombre,
            ];
            $guias[$numero]['cantidad'] += (float) $reserva->cantidad;
        }

        if ($guias === []) {
            throw new DomainException(
                'Las cajas reservadas del componente principal no tienen guía de recepción: no se puede armar el lote de la tarja.',
            );
        }

        $ganadora = collect($guias)
            ->sort(fn (array $a, array $b): int => [$b['cantidad'], $a['fifo']] <=> [$a['cantidad'], $b['fifo']])
            ->first();

        return [
            'numero' => $ganadora['numero'],
            'proveedor_id' => $ganadora['proveedor_id'],
            'proveedor_codigo' => $ganadora['proveedor_codigo'],
            'proveedor_nombre' => $ganadora['proveedor_nombre'],
        ];
    }

    /** Recepción del folio o, si llegó por transferencia entre clientes, la de su origen. */
    private function recepcionDe(?FolioMaterial $material): ?RecepcionMaterial
    {
        for ($salto = 0; $material !== null && $salto <= self::MAXIMO_SALTOS_ORIGEN; $salto++) {
            $recepcion = $material->bultoRecepcion?->detalle?->recepcion;
            if ($recepcion !== null) {
                return $recepcion;
            }
            $origenId = data_get($material->folio?->datos_externos, 'folio_origen_id');
            $material = $origenId === null ? null : FolioMaterial::query()
                ->with('folio', 'bultoRecepcion.detalle.recepcion.proveedor')
                ->find($origenId);
        }

        return null;
    }

    private function numeroGuia(string $numero): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', $numero));
    }

    private function folioOcupado(string $folio): bool
    {
        return Folio::query()->where('numero_folio', $folio)->exists()
            || FolioSalidaReservadoTransformacion::query()->where('numero_folio', $folio)->exists();
    }
}
