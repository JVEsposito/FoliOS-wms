<?php

namespace App\Services\DespachoNacional;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoLoteInspeccionSag;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\DespachoNacionalPt;
use App\Models\Folio;
use App\Models\Pais;
use App\Models\PartidaNacionalPt;
use App\Models\User;
use App\Services\InspeccionSag\ServicioExistenciasSag;
use App\Services\InspeccionSag\ServicioRetencionesLoteSag;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ServicioRepaletizaje;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ServicioDespachoNacionalPt
{
    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly ServicioRepaletizaje $repaletizajes,
        private readonly ServicioExistenciasSag $existenciasSag,
        private readonly ServicioRetencionesLoteSag $retenciones,
    ) {}

    public function consultar(Folio $folio): array
    {
        $this->asegurarSeparacion($folio, $this->temporadas->buscar()?->id);
        $composicion = $this->composicion($folio);

        return [
            'id' => $folio->id,
            'numero_folio' => $folio->numero_folio,
            'cliente' => $folio->exportadora,
            'ubicacion' => $this->ubicacion($folio),
            'cajas' => array_sum(array_column($composicion, 'cantidad_cajas')),
            'composicion' => $composicion,
        ];
    }

    public function separar(array $datos, User $usuario): PartidaNacionalPt
    {
        $id = $datos['operacion_id'];
        $seleccion = collect($datos['composicion'])->mapWithKeys(
            fn (array $linea): array => [$linea['clave'] => (int) $linea['cajas']],
        )->sortKeys();
        $hash = $this->huella([$datos['folio_id'], (int) $datos['cajas_esperadas'], $datos['motivo'], $seleccion->all()]);

        try {
            return DB::transaction(function () use ($datos, $usuario, $id, $seleccion, $hash): PartidaNacionalPt {
                $folio = Folio::query()->lockForUpdate()->findOrFail($datos['folio_id']);
                if ($existente = PartidaNacionalPt::query()->lockForUpdate()->find($id)) {
                    $this->validarReintento($existente, $usuario, $hash);

                    return $existente;
                }

                $this->asegurarSeparacion($folio, $this->temporadas->buscarConBloqueoCompartido()?->id);
                $composicion = collect($this->composicion($folio))->keyBy('clave');
                $cajasAntes = $composicion->sum('cantidad_cajas');
                if ($cajasAntes !== (int) $datos['cajas_esperadas']) {
                    throw new ConflictoOperacion('Las cajas del folio cambiaron. Vuelve a consultarlo antes de separar.');
                }

                $nacional = $seleccion->map(function (int $cajas, string $clave) use ($composicion): array {
                    $linea = $composicion->get($clave);
                    if (! $linea || $cajas > $linea['cantidad_cajas']) {
                        throw new ConflictoOperacion('Cambió el desglose disponible. Vuelve a consultar el folio.');
                    }

                    return [...$linea, 'cantidad_cajas' => $cajas];
                });
                $cajasNacionales = $seleccion->sum();
                $cajasRestantes = $cajasAntes - $cajasNacionales;
                $ubicacion = $this->ubicacion($folio);
                $folioNacional = $folio;
                $repa = null;
                if ($cajasRestantes > 0) {
                    $numeroNacional = 'MN-'.strtoupper(str_replace('-', '', $id));
                    $remanente = $composicion->map(fn (array $linea, string $clave): array => [
                        'clave' => $clave,
                        'cantidad_cajas' => $linea['cantidad_cajas'] - $seleccion->get($clave, 0),
                    ])->where('cantidad_cajas', '>', 0)->values()->all();

                    $repa = $this->repaletizajes->registrar([
                        'operacion_id' => $id,
                        'modalidad' => 'division',
                        'observacion' => 'Separación para mercado nacional. '.$datos['motivo'],
                        'origenes' => [['folio_id' => $folio->id, 'cantidad_aportada' => $cajasAntes]],
                        'resultados' => [
                            ['numero_folio' => $folio->numero_folio, 'tipo_resultado' => 'saldo', 'cantidad_resultante' => $cajasRestantes, 'composicion' => $remanente],
                            ['numero_folio' => $numeroNacional, 'tipo_resultado' => 'saldo', 'cantidad_resultante' => $cajasNacionales, 'composicion' => $nacional->values()->all()],
                        ],
                    ], $usuario, folioParaNacional: $numeroNacional);
                    $folioNacional = $repa->resultados->firstWhere('orden', 2)->folio;
                } else {
                    $this->retirarDeCamara($folio);
                    $folio->update([
                        'estado_operacional' => EstadoOperacionalFolio::Bloqueado,
                        'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
                        'activo' => false,
                    ]);
                    $this->existenciasSag->sincronizar([$folio->id], 'separacion_nacional', $folio->numero_folio);
                }

                return PartidaNacionalPt::create([
                    'id' => $id,
                    'temporada_id' => $folio->temporada_id,
                    'folio_origen_id' => $folio->id,
                    'folio_id' => $folioNacional->id,
                    'user_id' => $usuario->id,
                    'cajas' => $cajasNacionales,
                    'motivo' => $datos['motivo'],
                    'payload_hash' => $hash,
                    'snapshot' => [
                        'folio_origen' => $folio->numero_folio, 'folio_nacional' => $folioNacional->numero_folio,
                        'cliente' => $folio->exportadora, 'ubicacion_origen' => $ubicacion,
                        'cajas_antes' => $cajasAntes, 'cajas_restantes' => $cajasRestantes,
                        'composicion' => $nacional->values()->all(), 'repaletizaje' => $repa?->codigo,
                    ],
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $error) {
            $existente = PartidaNacionalPt::query()->find($id) ?? throw $error;
            $this->validarReintento($existente, $usuario, $hash);

            return $existente;
        }
    }

    public function despachar(array $datos, User $usuario): DespachoNacionalPt
    {
        $id = $datos['operacion_id'];
        $ids = $datos['partidas'];
        sort($ids, SORT_STRING);
        $patente = filled($datos['patente'] ?? null) ? mb_strtoupper($datos['patente']) : null;
        $hash = $this->huella([$ids, $datos['destinatario'], $datos['guia'], $patente]);

        try {
            return DB::transaction(function () use ($datos, $usuario, $id, $ids, $patente, $hash): DespachoNacionalPt {
                $partidas = PartidaNacionalPt::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($existente = DespachoNacionalPt::query()->find($id)) {
                    $this->validarReintento($existente, $usuario, $hash);

                    return $existente;
                }
                if ($partidas->count() !== count($ids) || $partidas->contains(fn ($partida): bool => $partida->despacho_id !== null)) {
                    throw new ConflictoOperacion('Una partida ya salió o cambió. Actualiza la bandeja antes de confirmar.');
                }
                if ($partidas->pluck('temporada_id')->unique()->count() !== 1) {
                    throw new DomainException('Selecciona partidas de una misma temporada.');
                }
                $temporadaId = $partidas->first()->temporada_id;
                $folioIds = $partidas->pluck('folio_id')->all();
                $this->retenciones->bloquearTemporada($temporadaId);
                $folios = Folio::query()->whereIn('id', $folioIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($partidas as $partida) {
                    $folio = $folios->get($partida->folio_id);
                    if (! $folio || $folio->activo || $folio->estado_operacional !== EstadoOperacionalFolio::Bloqueado
                        || (int) data_get($folio->datos_externos, 'cantidad_cajas') !== $partida->cajas
                        || $folio->ubicacionActual()->exists() || $folio->retencionOperacionalActiva()->exists()) {
                        throw new ConflictoOperacion('Cambió el estado o saldo de la partida '.$partida->snapshot['folio_nacional'].'. Revisa su trazabilidad.');
                    }
                }

                $this->asegurarSalidaNacional($folioIds);
                $despacho = DespachoNacionalPt::create([
                    'id' => $id, 'temporada_id' => $temporadaId, 'user_id' => $usuario->id, 'payload_hash' => $hash,
                    'destinatario' => $datos['destinatario'], 'guia' => $datos['guia'], 'patente' => $patente, 'despachado_at' => now(),
                ]);
                foreach ($partidas as $partida) {
                    $folios->get($partida->folio_id)->update(['estado_operacional' => EstadoOperacionalFolio::Despachado]);
                    $partida->update(['despacho_id' => $despacho->id]);
                }

                return $despacho;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $error) {
            $existente = DespachoNacionalPt::query()->find($id) ?? throw $error;
            $this->validarReintento($existente, $usuario, $hash);

            return $existente;
        }
    }

    private function asegurarSeparacion(Folio $folio, ?string $temporadaActiva): void
    {
        if ($temporadaActiva === null || $folio->temporada_id !== $temporadaActiva) {
            throw new DomainException('La separación requiere un folio de la temporada activa.');
        }
        if (! $folio->activo || ! in_array($folio->tipo_bulto, [TipoBulto::Pallet, TipoBulto::Saldo], true)
            || ! in_array($folio->estado_operacional, [EstadoOperacionalFolio::Disponible, EstadoOperacionalFolio::PendienteUbicacion, EstadoOperacionalFolio::PendientePrefrio], true)) {
            throw new DomainException('Solo se pueden separar cajas de un folio PT activo y disponible.');
        }
        if ($folio->retencionOperacionalActiva()->exists()
            || ! in_array($folio->condicion_termica, [CondicionTermicaFolio::PendientePrefrio, CondicionTermicaFolio::PrefrioAprobado, CondicionTermicaFolio::CondicionHeredada], true)) {
            throw new ConflictoOperacion('El folio tiene una retención operacional o una condición térmica que impide separarlo.');
        }
        if ($folio->reservaCargaActual()->exists() || $folio->asignacionesCarga()->whereIn('estado', [
            EstadoCargaFolio::Pendiente, EstadoCargaFolio::ConIncidencia, EstadoCargaFolio::EnAnden, EstadoCargaFolio::Cargado,
        ])->exists()) {
            throw new ConflictoOperacion('El folio pertenece a una carga activa. Libéralo desde Despacho antes de separar cajas.');
        }
        if ($folio->reingresoPendiente()->exists() || $folio->procesosPrefrio()->where(fn ($q) => $q
            ->whereIn('estado', ['cargado', 'en_proceso'])->orWhere(fn ($retiro) => $retiro->pendienteRetiroParcial()))->exists()) {
            throw new ConflictoOperacion('Termina el prefrío o el reingreso en curso antes de separar cajas.');
        }
        if ($folio->tareasMovimiento()->whereNotIn('estado', [EstadoTareaMovimiento::Completada, EstadoTareaMovimiento::Cancelada])->exists()
            || DB::table('custodias_temporales_maniobra')->where('bloqueo_folio_id', $folio->id)->exists()) {
            throw new ConflictoOperacion('Hay una maniobra vigente para el folio. Termínala o cancélala antes de separar cajas.');
        }
        if ($folio->inspeccionesSag()->whereHas('lote', fn ($q) => $q->whereIn('estado', [
            EstadoLoteInspeccionSag::Preparacion, EstadoLoteInspeccionSag::EnInspeccion, EstadoLoteInspeccionSag::ResultadoParcial,
        ]))->exists()) {
            throw new ConflictoOperacion('El folio pertenece a un lote de inspección SAG abierto.');
        }
    }

    private function asegurarSalidaNacional(array $folioIds): void
    {
        $casos = collect($this->retenciones->paraFolios($folioIds, bloquear: true))->flatten(1);
        if ($casos->isEmpty()) {
            return;
        }
        $chile = Pais::query()->where('iso_alpha2', 'CL')->value('id');
        $bloqueo = $casos->first(fn (array $caso): bool => $caso['todos_destinos']
            || ($caso['destinos'] !== [] && ($chile === null || in_array($chile, $caso['paises_restringidos'], true))));
        if ($bloqueo) {
            throw new ConflictoOperacion('Salida nacional impedida por la retención SAG del lote '.$bloqueo['lote'].'.');
        }
    }

    private function composicion(Folio $folio): array
    {
        $lineas = $this->repaletizajes->composicionFolio($folio);
        $cajas = (int) data_get($folio->datos_externos, 'cantidad_cajas');
        if ($cajas < 1 || array_sum(array_column($lineas, 'cantidad_cajas')) !== $cajas
            || $this->repaletizajes->desgloseIncompleto($lineas)) {
            throw new DomainException('El folio requiere regularizar su cantidad o composición antes de separar cajas.');
        }

        return $lineas;
    }

    private function ubicacion(Folio $folio): ?array
    {
        $ubicacion = $folio->ubicacionActual()->with(['camara:id,codigo', 'posicion:id,etiqueta'])->first();

        return $ubicacion ? ['camara' => $ubicacion->camara?->codigo, 'posicion' => $ubicacion->posicion?->etiqueta] : null;
    }

    private function retirarDeCamara(Folio $folio): void
    {
        $ubicacion = $folio->ubicacionActual()->lockForUpdate()->first();
        if ($ubicacion) {
            Camara::query()->whereKey($ubicacion->camara_id)->increment('version_plano');
            $ubicacion->delete();
        }
    }

    private function huella(array $datos): string
    {
        return hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
    }

    private function validarReintento(PartidaNacionalPt|DespachoNacionalPt $registro, User $usuario, string $hash): void
    {
        if ($registro->user_id !== $usuario->id || ! hash_equals($registro->payload_hash, $hash)) {
            throw new ConflictoOperacion('El identificador de la operación ya fue utilizado con otros datos.');
        }
    }
}
