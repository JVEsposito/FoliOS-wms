<?php

namespace App\Services\Folios;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\FuenteHabilitacionAlmacenamiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Http\Requests\AnularValidacionPalletRequest;
use App\Models\CambioAtributosPt;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\RegistroHabilitacionAlmacenamiento;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Retenciones\ServicioRetencionesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ServicioConciliacionValidacion;
use App\Services\Validacion\ServicioCorreccionValidacionPallet;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cambio de atributos de un folio PT por administración: producto (artículo, cajas, CSG,
 * categoría, tipo de bulto) y estado de prefrío, mientras el folio no salga de la planta.
 *
 * No reescribe la historia: el producto se corrige con la misma corrección auditada de
 * Validación, el estado térmico con la habilitación de almacenamiento y las retenciones
 * operacionales, y cada cambio queda además en cambios_atributos_pt con antes, después y
 * motivo. Lo que acepta administración se da por cuadrado frente a planta.
 */
class ServicioCambioAtributosPt
{
    /** Estados en que el folio ya no está en la planta o dejó de existir como bulto. */
    public const ESTADOS_CERRADOS = [
        EstadoOperacionalFolio::Despachado,
        EstadoOperacionalFolio::Anulado,
        EstadoOperacionalFolio::RetiradoDefinitivo,
        EstadoOperacionalFolio::Agotado,
    ];

    /** Condiciones que administración puede fijar; «en proceso» solo la da un túnel. */
    public const CONDICIONES = [
        CondicionTermicaFolio::PrefrioAprobado,
        CondicionTermicaFolio::CondicionHeredada,
        CondicionTermicaFolio::PendientePrefrio,
        CondicionTermicaFolio::RequiereReproceso,
        CondicionTermicaFolio::Retenido,
    ];

    public function __construct(
        private readonly ServicioCorreccionValidacionPallet $correccion,
        private readonly ServicioConciliacionValidacion $conciliacion,
        private readonly ServicioHabilitacionAlmacenamiento $habilitacion,
        private readonly ServicioRetencionesOperacionales $retenciones,
    ) {}

    public function buscar(string $numero): Folio
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        $folio = $temporada ? Folio::query()
            ->where('temporada_id', $temporada->id)
            ->where('numero_folio', trim($numero))
            ->whereIn('tipo_bulto', [TipoBulto::Pallet->value, TipoBulto::Saldo->value])
            ->latest('created_at')
            ->first() : null;
        if (! $folio) {
            throw new NotFoundHttpException('No hay un folio PT con ese número en la temporada activa.');
        }

        return $folio;
    }

    /** @return array<string, mixed> */
    public function ficha(Folio $folio): array
    {
        $folio->load(['ubicacionActual.camara:id,codigo,nombre', 'ubicacionActual.posicion:id,etiqueta']);
        $datos = $folio->datos_externos ?? [];
        $carga = $this->cargaActual($folio);
        $proceso = $this->procesoPrefrioActivo($folio);
        $validacion = $this->validacionAprobada($folio);
        $bloqueo = $this->motivoBloqueo($folio);
        $noAnulable = app(ServicioAnulacionAdministrativaValidacion::class)->motivoNoAnulable($folio, $validacion);

        return [
            'folio' => [
                'id' => $folio->id,
                'numero_folio' => $folio->numero_folio,
                'tipo_bulto' => $folio->tipo_bulto->value,
                'estado_operacional' => $folio->estado_operacional->value,
                'condicion_termica' => $folio->condicion_termica?->value,
                'habilitacion_almacenamiento' => $folio->habilitacion_almacenamiento?->value,
                'retencion_motivo' => $folio->retencion_termica_motivo,
                'activo' => $folio->activo,
                'exportadora' => $folio->exportadora,
                'marca' => $folio->marca,
                'especie' => $datos['especie'] ?? null,
                'variedad' => $folio->variedad,
                'calibre' => $folio->calibre,
                'envase' => $datos['envase'] ?? null,
                'categoria' => $datos['categoria'] ?? null,
                'csg' => $datos['csg'] ?? null,
                'cantidad_cajas' => isset($datos['cantidad_cajas']) ? (int) $datos['cantidad_cajas'] : null,
                'fecha_ingreso' => $folio->fecha_ingreso?->toIso8601String(),
                'ubicacion' => $folio->ubicacionActual ? [
                    'camara' => $folio->ubicacionActual->camara?->nombre ?: $folio->ubicacionActual->camara?->codigo,
                    'posicion' => $folio->ubicacionActual->posicion?->etiqueta,
                ] : null,
            ],
            'carga' => $carga ? [
                'id' => $carga->carga_id,
                'codigo' => $carga->carga?->codigo,
                'estado' => $carga->carga?->estado instanceof \BackedEnum ? $carga->carga->estado->value : $carga->carga?->estado,
            ] : null,
            'proceso_prefrio' => $proceso ? ['codigo' => $proceso->proceso?->codigo] : null,
            'validacion_id' => $validacion?->id,
            'editable' => $bloqueo === null,
            'motivo_bloqueo' => $bloqueo,
            'puede_cambiar_producto' => $bloqueo === null && $validacion !== null,
            'puede_cambiar_condicion' => $bloqueo === null && $proceso === null,
            'puede_anular' => $noAnulable === null,
            'motivo_no_anulable' => $noAnulable,
            'categorias_anulacion' => AnularValidacionPalletRequest::CATEGORIAS,
            'condiciones' => collect(self::CONDICIONES)->map(fn (CondicionTermicaFolio $condicion): string => $condicion->value)->all(),
            'historial' => CambioAtributosPt::query()
                ->where('folio_id', $folio->id)
                ->with('usuario:id,name')
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn (CambioAtributosPt $cambio): array => [
                    'id' => $cambio->id,
                    'tipo' => $cambio->tipo,
                    'antes' => $cambio->antes,
                    'despues' => $cambio->despues,
                    'motivo' => $cambio->motivo,
                    'carga_codigo' => $cambio->carga_codigo,
                    'usuario' => $cambio->usuario?->name,
                    'created_at' => $cambio->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * Producto, cajas y CSG: la corrección auditada de Validación, más el registro del módulo y
     * la aceptación frente a planta. Idempotente por operacion_id.
     *
     * @param  array<string, mixed>  $datos
     */
    public function cambiarProducto(Folio $folio, array $datos, User $usuario): CambioAtributosPt
    {
        $hash = $this->hash(['tipo' => CambioAtributosPt::PRODUCTO, ...$datos]);
        if ($existente = $this->repetido($datos['operacion_id'], $folio, $usuario, $hash)) {
            return $existente;
        }
        $folio = Folio::query()->findOrFail($folio->id);
        $this->asegurarEditable($folio);
        $validacion = $this->validacionAprobada($folio)
            ?? throw new ConflictoOperacion('El folio no tiene una validación aprobada que corregir.');
        $antes = $this->atributos($folio);
        $carga = $this->cargaActual($folio);

        $this->correccion->corregir($validacion, [...$datos, 'motivo_correccion' => $datos['motivo']], $usuario);
        $this->conciliacion->aceptarCambioAdministrativo($validacion->refresh(), $usuario, $datos['motivo']);

        return $this->registrar($folio->refresh(), CambioAtributosPt::PRODUCTO, $datos, $hash, $antes, $carga, $usuario);
    }

    /**
     * Estado de prefrío. Aprobado o heredado habilitan el almacenamiento; pendiente o
     * reproceso lo devuelven a prefrío; retenido usa la retención operacional (suspende su
     * carga y planifica la segregación). Salir de retenido libera la retención.
     *
     * @param  array{operacion_id: string, condicion: string, motivo: string}  $datos
     */
    public function cambiarCondicionTermica(Folio $folio, array $datos, User $usuario): CambioAtributosPt
    {
        $hash = $this->hash(['tipo' => CambioAtributosPt::CONDICION_TERMICA, ...$datos]);
        if ($existente = $this->repetido($datos['operacion_id'], $folio, $usuario, $hash)) {
            return $existente;
        }
        $condicion = CondicionTermicaFolio::from($datos['condicion']);
        if (! in_array($condicion, self::CONDICIONES, true)) {
            throw new DomainException('Esa condición solo la asigna un proceso de túnel.');
        }
        $motivo = trim($datos['motivo']);

        return DB::transaction(function () use ($folio, $datos, $condicion, $motivo, $hash, $usuario): CambioAtributosPt {
            $folio = Folio::query()->lockForUpdate()->findOrFail($folio->id);
            $this->asegurarEditable($folio);
            if ($proceso = $this->procesoPrefrioActivo($folio)) {
                throw new ConflictoOperacion(sprintf(
                    'El folio está en el proceso de prefrío %s. Termínalo o retira el folio del túnel antes de cambiar su condición.',
                    $proceso->proceso?->codigo ?? 'activo',
                ));
            }
            if ($folio->condicion_termica === $condicion) {
                throw new DomainException('El folio ya tiene esa condición de prefrío.');
            }
            $antes = $this->atributos($folio);
            $carga = $this->cargaActual($folio);
            $estabaRetenido = $folio->habilitacion_almacenamiento === HabilitacionAlmacenamientoFolio::Retenido;
            $referencia = (string) $datos['operacion_id'];

            match ($condicion) {
                CondicionTermicaFolio::PrefrioAprobado, CondicionTermicaFolio::CondicionHeredada => $this->habilitar($folio, $condicion, $estabaRetenido, $usuario, $referencia, $motivo),
                CondicionTermicaFolio::Retenido => $this->habilitacion->retener($folio, $condicion, $motivo, $usuario, null, 'cambio_atributos_pt', $referencia),
                default => $this->devolverAPrefrio($folio, $condicion, $usuario, $referencia, $motivo),
            };

            return $this->registrar($folio->refresh(), CambioAtributosPt::CONDICION_TERMICA, $datos, $hash, $antes, $carga, $usuario);
        }, 3);
    }

    private function habilitar(Folio $folio, CondicionTermicaFolio $condicion, bool $estabaRetenido, User $usuario, string $referencia, string $motivo): void
    {
        $estadoAnterior = $folio->estado_operacional;
        $folio = $this->habilitacion->habilitar(
            $folio, $condicion, FuenteHabilitacionAlmacenamiento::CorreccionAdministrativa,
            $usuario, null, 'cambio_atributos_pt', $referencia, $motivo,
        );
        // La habilitación solo mueve el estado al aprobar prefrío. Un folio heredado que
        // esperaba prefrío o estaba retenido también queda disponible o por ubicar.
        if ($condicion === CondicionTermicaFolio::CondicionHeredada
            && ($estadoAnterior === EstadoOperacionalFolio::PendientePrefrio
                || ($estabaRetenido && $estadoAnterior === EstadoOperacionalFolio::Bloqueado))) {
            $folio->update(['estado_operacional' => $folio->ubicacionActual()->exists()
                ? EstadoOperacionalFolio::Disponible
                : EstadoOperacionalFolio::PendienteUbicacion]);
        }
    }

    /** Vuelve a esperar prefrío. Conserva su ubicación y su carga: un pallet pendiente puede cargarse. */
    private function devolverAPrefrio(Folio $folio, CondicionTermicaFolio $condicion, User $usuario, string $referencia, string $motivo): void
    {
        if ($folio->habilitacion_almacenamiento === HabilitacionAlmacenamientoFolio::Retenido
            || $folio->retencionOperacionalActiva()->exists()) {
            $this->retenciones->liberar($folio, $usuario);
        }
        $folio->update([
            'condicion_termica' => $condicion,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
            'fuente_habilitacion_almacenamiento' => null,
            'habilitado_almacenamiento_at' => null,
            'habilitado_almacenamiento_por_user_id' => null,
            'retencion_termica_motivo' => null,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
        ]);
        RegistroHabilitacionAlmacenamiento::create([
            'folio_id' => $folio->id,
            'estado_resultante' => HabilitacionAlmacenamientoFolio::NoHabilitado,
            'condicion_termica' => $condicion,
            'fuente' => FuenteHabilitacionAlmacenamiento::CorreccionAdministrativa,
            'proceso_origen' => 'cambio_atributos_pt',
            'referencia_origen' => $referencia,
            'user_id' => $usuario->id,
            'ocurrido_at' => now(),
            'motivo' => 'Administración devolvió el folio a prefrío.',
            'observacion' => $motivo,
        ]);
    }

    private function asegurarEditable(Folio $folio): void
    {
        if ($motivo = $this->motivoBloqueo($folio)) {
            throw new ConflictoOperacion($motivo);
        }
    }

    private function motivoBloqueo(Folio $folio): ?string
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();

        return match (true) {
            ! in_array($folio->tipo_bulto, [TipoBulto::Pallet, TipoBulto::Saldo], true) => 'Solo se cambian atributos de pallets y saldos de producto terminado.',
            $folio->temporada_id !== $temporada?->id => 'El folio no pertenece a la temporada activa.',
            ! $folio->activo || in_array($folio->estado_operacional, self::ESTADOS_CERRADOS, true) => sprintf(
                'El folio está %s y ya no admite cambios de atributos.',
                mb_strtolower(str_replace('_', ' ', $folio->estado_operacional->value)),
            ),
            default => null,
        };
    }

    private function validacionAprobada(Folio $folio): ?ValidacionPallet
    {
        return ValidacionPallet::query()
            ->where('folio_id', $folio->id)
            ->where('estado', EstadoValidacionPallet::Aceptada->value)
            ->where('resultado', ResultadoValidacionPallet::Aprobado->value)
            ->latest('recibido_servidor_at')
            ->first();
    }

    private function cargaActual(Folio $folio): ?CargaFolio
    {
        return CargaFolio::query()->where('folio_id', $folio->id)->whereHas('reservaActiva')
            ->with('carga:id,codigo,estado')->first();
    }

    private function procesoPrefrioActivo(Folio $folio): ?ProcesoPrefrioFolio
    {
        return ProcesoPrefrioFolio::query()->where('folio_id', $folio->id)
            ->where(fn ($asignacion) => $asignacion->where('estado', EstadoFolioProcesoPrefrio::EnProceso)
                ->orWhere(fn ($pendiente) => $pendiente->pendienteRetiroParcial()))
            ->with('proceso:id,codigo')->first();
    }

    /** @return array<string, mixed> */
    private function atributos(Folio $folio): array
    {
        $datos = $folio->datos_externos ?? [];

        return [
            'tipo_bulto' => $folio->tipo_bulto->value,
            'estado_operacional' => $folio->estado_operacional->value,
            'condicion_termica' => $folio->condicion_termica?->value,
            'habilitacion_almacenamiento' => $folio->habilitacion_almacenamiento?->value,
            'exportadora' => $folio->exportadora,
            'marca' => $folio->marca,
            'especie' => $datos['especie'] ?? null,
            'variedad' => $folio->variedad,
            'calibre' => $folio->calibre,
            'envase' => $datos['envase'] ?? null,
            'categoria' => $datos['categoria'] ?? null,
            'csg' => $datos['csg'] ?? null,
            'cantidad_cajas' => isset($datos['cantidad_cajas']) ? (int) $datos['cantidad_cajas'] : null,
        ];
    }

    /** @param  array<string, mixed>  $datos */
    private function registrar(Folio $folio, string $tipo, array $datos, string $hash, array $antes, ?CargaFolio $carga, User $usuario): CambioAtributosPt
    {
        try {
            return CambioAtributosPt::create([
                'temporada_id' => $folio->temporada_id,
                'folio_id' => $folio->id,
                'numero_folio' => $folio->numero_folio,
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'tipo' => $tipo,
                'antes' => $antes,
                'despues' => $this->atributos($folio),
                'motivo' => trim((string) $datos['motivo']),
                'carga_id' => $carga?->carga_id,
                'carga_codigo' => $carga?->carga?->codigo,
                'user_id' => $usuario->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->repetido($datos['operacion_id'], $folio, $usuario, $hash)
                ?? throw new ConflictoOperacion('La operación ya fue registrada.');
        }
    }

    private function repetido(string $operacionId, Folio $folio, User $usuario, string $hash): ?CambioAtributosPt
    {
        $cambio = CambioAtributosPt::query()->where('operacion_id', $operacionId)->first();
        if ($cambio && ($cambio->folio_id !== $folio->id || $cambio->user_id !== $usuario->id || ! hash_equals($cambio->payload_hash, $hash))) {
            throw new ConflictoOperacion('El identificador de la operación ya fue usado con otros datos.');
        }

        return $cambio;
    }

    /** @param  array<string, mixed>  $datos */
    private function hash(array $datos): string
    {
        ksort($datos);

        return hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
    }
}
