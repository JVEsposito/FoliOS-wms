<?php

namespace App\Services\Validacion;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\Folio;
use App\Models\ImpresionEtiquetaPt;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ServicioEtiquetasPt
{
    public function __construct(private readonly GeneradorEtiquetaPtPdf $pdf) {}

    public function consulta(): Builder
    {
        return ValidacionPallet::query()
            ->where('estado', EstadoValidacionPallet::Aceptada)
            ->where('resultado', ResultadoValidacionPallet::Aprobado)
            ->whereHas('folio', fn (Builder $query) => $this->foliosVigentes($query)
                ->whereColumn('folios.temporada_id', 'validaciones_pallet.temporada_id'));
    }

    private function foliosVigentes(Builder $query): Builder
    {
        return $query->where('activo', true)
            ->whereIn('tipo_bulto', [TipoBulto::Pallet, TipoBulto::Saldo])
            ->whereNotIn('estado_operacional', [
                EstadoOperacionalFolio::Anulado, EstadoOperacionalFolio::Agotado,
                EstadoOperacionalFolio::Despachado, EstadoOperacionalFolio::RetiradoDefinitivo,
            ]);
    }

    /** Los datos actuales del inventario prevalecen tras una corrección o repaletizaje. */
    public function etiqueta(ValidacionPallet $validacion): array
    {
        $folio = $validacion->folio;
        $snapshot = $validacion->snapshot ?? [];
        $actual = $folio->datos_externos ?? [];
        $articulo = $snapshot['articulo'] ?? [];
        $origen = $snapshot['origen'] ?? [];

        return [
            'validacion_id' => $validacion->id,
            'folio_id' => $folio->id,
            'numero_folio' => (string) $folio->numero_folio,
            'temporada' => $validacion->temporada?->codigo,
            'tipo_bulto' => $folio->tipo_bulto->value,
            'cantidad_cajas' => (int) ($actual['cantidad_cajas'] ?? $validacion->cantidad_cajas),
            'especie' => $actual['especie'] ?? $articulo['especie'] ?? '',
            'variedad' => $folio->variedad ?? $articulo['variedad'] ?? '',
            'calibre' => $folio->calibre ?? $articulo['calibre'] ?? '',
            'envase' => $actual['envase'] ?? $articulo['envase'] ?? '',
            'categoria' => $actual['categoria'] ?? $snapshot['categoria']['nombre'] ?? '',
            'cliente' => $folio->exportadora ?? $origen['cliente'] ?? '',
            'marca' => $folio->marca ?? $origen['marca'] ?? '',
            'csg' => $actual['csg'] ?? $origen['csg'] ?? '',
            'predio' => $actual['predio'] ?? $origen['predio'] ?? '',
            'fecha_embalaje' => $actual['fecha_embalaje'] ?? $snapshot['fecha_embalaje'] ?? null,
            'composicion' => $actual['composicion'] ?? $snapshot['composicion'] ?? [],
            'linea_proceso' => $validacion->linea_proceso,
            'turno' => $validacion->turno,
            'validador' => $validacion->usuario?->name,
            'validado_at' => $validacion->generado_dispositivo_at?->toAtomString(),
            'estado_operacional' => $folio->estado_operacional->value,
        ];
    }

    public function version(ValidacionPallet $validacion): string
    {
        return $this->hash($this->etiqueta($validacion));
    }

    public function generar(array $datos, User $usuario): ImpresionEtiquetaPt
    {
        $payload = $datos;
        unset($payload['operacion_id']);
        usort($payload['validaciones'], fn ($a, $b) => strcmp($a['id'], $b['id']));
        $hash = $this->hash($payload);
        try {
            return DB::transaction(function () use ($datos, $payload, $hash, $usuario): ImpresionEtiquetaPt {
                $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true);
                if (! $temporada || $temporada->id !== $datos['temporada_id']) {
                    throw new DomainException('Solo se generan etiquetas de la temporada activa.');
                }
                $existente = ImpresionEtiquetaPt::query()->where('operacion_id', $datos['operacion_id'])->first();
                if ($existente) {
                    return $this->repetir($existente, $hash, $usuario);
                }

                $validaciones = $this->consulta()->where('temporada_id', $temporada->id)
                    ->whereIn('id', array_column($payload['validaciones'], 'id'))
                    ->orderBy('id')->lockForUpdate()->get();
                if ($validaciones->count() !== count($payload['validaciones'])) {
                    throw new DomainException('Selecciona únicamente aprobaciones vigentes de esta temporada. Actualiza el listado.');
                }
                // Serializa con correcciones y movimientos del folio; vuelve a verificar su vigencia bajo bloqueo.
                $folios = $this->foliosVigentes(Folio::query())
                    ->where('temporada_id', $temporada->id)
                    ->whereIn('id', $validaciones->pluck('folio_id'))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($folios->count() !== $validaciones->count()) {
                    throw new DomainException('Uno de los folios ya no está disponible para etiquetar.');
                }
                $validaciones->load(['temporada', 'usuario']);
                $versiones = collect($payload['validaciones'])->keyBy('id');
                foreach ($validaciones as $validacion) {
                    $validacion->setRelation('folio', $folios->get($validacion->folio_id));
                    if (! hash_equals($this->version($validacion), $versiones[$validacion->id]['version'])) {
                        throw new ConflictoOperacion('Los datos del pallet cambiaron. Actualiza y revisa la etiqueta antes de imprimir.');
                    }
                }
                $yaGenerados = DB::table('impresion_etiqueta_pt_folios as folios')
                    ->join('impresiones_etiquetas_pt as impresiones', 'impresiones.id', '=', 'folios.impresion_id')
                    ->where('impresiones.tipo', $datos['tipo'])
                    ->whereIn('folios.folio_id', $folios->keys())->exists();
                if ($yaGenerados && blank($datos['motivo_reimpresion'] ?? null)) {
                    throw new DomainException('Indica un motivo para volver a generar este formato de etiqueta.');
                }
                $etiquetas = $validaciones->map(fn ($validacion) => $this->etiqueta($validacion))->all();
                // Un fallo de composición o tamaño no debe registrar una generación que no entregó PDF.
                $this->pdf->generarPt($etiquetas, $datos['tipo'], $datos['copias']);
                $impresion = ImpresionEtiquetaPt::create([
                    'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash,
                    'temporada_id' => $temporada->id, 'user_id' => $usuario->id,
                    'tipo' => $datos['tipo'], 'copias' => $datos['copias'],
                    'motivo_reimpresion' => $datos['motivo_reimpresion'] ?? null,
                    'etiquetas_snapshot' => $etiquetas,
                ]);
                $impresion->folios()->attach($folios->keys()->all());

                return $impresion;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $impresion = ImpresionEtiquetaPt::query()->where('operacion_id', $datos['operacion_id'])->first();
            if (! $impresion) {
                throw $exception;
            }

            return $this->repetir($impresion, $hash, $usuario);
        }
    }

    private function repetir(ImpresionEtiquetaPt $impresion, string $hash, User $usuario): ImpresionEtiquetaPt
    {
        if ($impresion->user_id !== $usuario->id || ! hash_equals($impresion->payload_hash, $hash)) {
            throw new ConflictoOperacion('Esta operación de etiquetas ya se utilizó con otros datos.');
        }

        return $impresion;
    }

    private function hash(array $datos): string
    {
        return hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
