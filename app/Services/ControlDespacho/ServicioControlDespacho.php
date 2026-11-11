<?php

namespace App\Services\ControlDespacho;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\ControlDespacho;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PosicionControlDespacho;
use App\Models\ReservaCargaFolio;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Cargas\ServicioCargaCamion;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\ContextoEjecucionTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Str;

/**
 * Planilla R-24 que el equipo de consolidación llena en el andén.
 *
 * Al leer un pallet que espera en el andén, la planilla lo sube al camión con la misma
 * transición que usa el camarero; un pallet que el camarero ya cargó solo recibe su
 * posición. Así las cargas sin consolidación siguen igual que siempre.
 */
class ServicioControlDespacho
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly MotorTransicionesOperacionales $motor,
        private readonly ContextoEjecucionTransicionOperacional $contexto,
        private readonly ServicioCargaCamion $carguio,
        private readonly PlanillaControlDespacho $planilla,
    ) {}

    public function iniciar(string $operacionId, Carga $carga, User $usuario, ?Dispositivo $dispositivo): ControlDespacho
    {
        $this->asegurarRegistro($usuario);

        return $this->transicionar('control_despacho.iniciar', $operacionId, $usuario, ['carga_id' => $carga->id], function () use ($operacionId, $carga, $usuario, $dispositivo): ControlDespacho {
            if ($this->contexto->actual() === null) {
                return ControlDespacho::query()->where('carga_id', $carga->id)->firstOrFail();
            }

            $bloqueada = Carga::query()->lockForUpdate()->findOrFail($carga->id);
            $existente = ControlDespacho::query()->where('carga_id', $bloqueada->id)->first();
            if ($existente) {
                return $existente;
            }
            $this->asegurarCargaEnDespacho($bloqueada);
            $enAnden = $bloqueada->presenciaAndenActiva()->exists()
                || $bloqueada->asignacionesActuales()->whereIn('estado', EstadoCargaFolio::valoresFueraDeCamara())->exists();
            if (! $enAnden) {
                throw new DomainException("La carga {$bloqueada->codigo} todavía no está en el andén: la planilla se abre cuando llega el camión o el primer pallet.");
            }

            return ControlDespacho::create([
                'temporada_id' => $bloqueada->temporada_id,
                'carga_id' => $bloqueada->id,
                'estado' => ControlDespacho::BORRADOR,
                'version' => 1,
                'operacion_inicio_id' => $operacionId,
                'iniciado_por_user_id' => $usuario->id,
                'iniciado_desde_dispositivo_id' => $dispositivo?->id,
                'iniciado_at' => now(),
                'actualizado_por_user_id' => $usuario->id,
            ]);
        }, Carga::class, $carga->id, $carga->codigo, $dispositivo);
    }

    /**
     * Guarda una o más secciones. Solo cambian los campos enviados; un campo vacío vuelve a la
     * sugerencia de FoliOS.
     *
     * @param  array{encabezado?: array<string, mixed>, inspeccion?: array<string, mixed>, cierre?: array<string, mixed>}  $datos
     */
    public function guardar(string $operacionId, ControlDespacho $control, int $version, array $datos, User $usuario): ControlDespacho
    {
        $this->asegurarRegistro($usuario);

        return $this->transicionar('control_despacho.guardar', $operacionId, $usuario, ['control_despacho_id' => $control->id, 'version' => $version, ...$datos], function () use ($control, $version, $datos, $usuario): ControlDespacho {
            if ($this->contexto->actual() === null) {
                return $control->refresh();
            }

            $bloqueado = $this->bloquear($control);
            $this->asegurarBorrador($bloqueado);
            $this->asegurarVersion($bloqueado, $version);
            $cambios = ['version' => $bloqueado->version + 1, 'actualizado_por_user_id' => $usuario->id];
            foreach (['encabezado', 'inspeccion', 'cierre'] as $seccion) {
                if (! array_key_exists($seccion, $datos)) {
                    continue;
                }
                $actual = $bloqueado->{$seccion} ?? [];
                foreach ($datos[$seccion] as $clave => $valor) {
                    $normalizado = $this->normalizar($clave, $valor);
                    if ($normalizado === null || $normalizado === '') {
                        unset($actual[$clave]);
                    } else {
                        $actual[$clave] = $normalizado;
                    }
                }
                $cambios[$seccion] = $actual === [] ? null : $actual;
            }
            $bloqueado->update($cambios);

            return $bloqueado->refresh();
        }, ControlDespacho::class, $control->id);
    }

    /**
     * El pallet subió al camión y quedó en una posición. Si esperaba en el andén se registra
     * como cargado; si ya estaba cargado, se completa o corrige su posición.
     *
     * @param  array{numero_folio: string, posicion: int, codigo_pe?: ?string, cajas?: ?int, temperatura?: float|int|string|null, compartir_posicion?: bool}  $datos
     */
    public function registrarPosicion(
        string $operacionId,
        ControlDespacho $control,
        array $datos,
        User $usuario,
        Dispositivo $dispositivo,
        DateTimeInterface $generadoDispositivoAt,
    ): ControlDespacho {
        $this->asegurarRegistro($usuario);
        $numero = Str::upper(trim($datos['numero_folio']));
        if ($numero === '') {
            throw new DomainException('Lee o digita el folio que subió.');
        }
        $posicion = (int) $datos['posicion'];
        if ($posicion < 1 || $posicion > FormularioControlDespacho::POSICION_MAXIMA) {
            throw new DomainException('La posición va de 1 a '.FormularioControlDespacho::POSICION_MAXIMA.'.');
        }
        $leidoAt = CarbonImmutable::instance($generadoDispositivoAt);
        if ($leidoAt->isAfter(CarbonImmutable::now()->addMinutes(5))) {
            throw new DomainException('La hora del equipo está adelantada; revisa el reloj de la PDA.');
        }
        $codigoPe = Str::upper(trim((string) ($datos['codigo_pe'] ?? ''))) ?: null;
        $temperatura = $datos['temperatura'] ?? null;
        $temperatura = $temperatura === null || $temperatura === '' ? null : round((float) $temperatura, 1);
        $cajas = isset($datos['cajas']) && $datos['cajas'] !== '' ? (int) $datos['cajas'] : null;
        $compartir = (bool) ($datos['compartir_posicion'] ?? false);

        return $this->transicionar('control_despacho.registrar_posicion', $operacionId, $usuario, [
            'control_despacho_id' => $control->id,
            'numero_folio' => $numero,
            'posicion' => $posicion,
            'codigo_pe' => $codigoPe,
            'cajas' => $cajas,
            'temperatura' => $temperatura,
            'compartir_posicion' => $compartir,
            'generado_dispositivo_at' => $leidoAt->toAtomString(),
        ], function () use ($control, $numero, $posicion, $codigoPe, $cajas, $temperatura, $compartir, $usuario, $dispositivo, $leidoAt): ControlDespacho {
            if ($this->contexto->actual() === null) {
                return $control->refresh();
            }

            // Mismo orden de bloqueo que la lectura del camarero: primero la carga.
            $carga = Carga::query()->lockForUpdate()->findOrFail($control->carga_id);
            $bloqueado = $this->bloquear($control);
            $this->asegurarBorrador($bloqueado);
            $folio = Folio::query()->where('numero_folio', $numero)->first();
            if (! $folio) {
                throw new DomainException("El folio {$numero} no existe en el sistema.");
            }
            $asignacion = CargaFolio::query()
                ->where('carga_id', $carga->id)
                ->where('folio_id', $folio->id)
                ->whereIn('estado', [
                    EstadoCargaFolio::Pendiente->value,
                    EstadoCargaFolio::ConIncidencia->value,
                    EstadoCargaFolio::EnAnden->value,
                    EstadoCargaFolio::Cargado->value,
                    EstadoCargaFolio::Despachado->value,
                ])
                ->latest('asignado_at')
                ->lockForUpdate()
                ->first();
            if (! $asignacion) {
                $reservaAjena = ReservaCargaFolio::query()->where('folio_id', $folio->id)->with('asignacion.carga:id,codigo')->first();
                throw new DomainException($reservaAjena
                    ? "El folio {$numero} pertenece a la carga {$reservaAjena->asignacion->carga->codigo}, no a {$carga->codigo}. No lo subas a este camión."
                    : "El folio {$numero} no pertenece a la carga {$carga->codigo}. No lo subas a este camión.");
            }
            if (in_array($asignacion->estado, [EstadoCargaFolio::Pendiente, EstadoCargaFolio::ConIncidencia], true)) {
                throw new DomainException("El folio {$numero} todavía no sale de la cámara: el camarero debe enviarlo al andén.");
            }

            $ocupante = PosicionControlDespacho::query()
                ->where('control_despacho_id', $bloqueado->id)
                ->where('posicion', $posicion)
                ->where('carga_folio_id', '!=', $asignacion->id)
                ->with('folio:id,numero_folio')
                ->first();
            if ($ocupante && ! $compartir) {
                throw new DomainException("La posición {$posicion} ya la ocupa el folio {$ocupante->folio?->numero_folio}. Confirma si ambos comparten la posición.");
            }

            if ($asignacion->estado === EstadoCargaFolio::EnAnden) {
                $this->asegurarCargaEnDespacho($carga);
                $this->carguio->aplicarCargado($carga, $asignacion, $folio, $usuario, $dispositivo, $leidoAt, [
                    'registrado_desde' => 'consolidacion',
                    'control_despacho_id' => $bloqueado->id,
                    'posicion' => $posicion,
                ]);
            }

            PosicionControlDespacho::query()->updateOrCreate(
                ['control_despacho_id' => $bloqueado->id, 'carga_folio_id' => $asignacion->id],
                [
                    'folio_id' => $folio->id,
                    'posicion' => $posicion,
                    'codigo_pe' => $codigoPe,
                    'cajas' => $cajas ?? max(0, (int) (($folio->datos_externos ?? [])['cantidad_cajas'] ?? 0)),
                    'temperatura' => $temperatura,
                    'registrado_por_user_id' => $usuario->id,
                    'registrado_desde_dispositivo_id' => $dispositivo->id,
                    'registrado_at' => $leidoAt->isFuture() ? now() : $leidoAt,
                ],
            );
            $bloqueado->update(['actualizado_por_user_id' => $usuario->id]);

            return $bloqueado->refresh();
        }, ControlDespacho::class, $control->id, $numero, $dispositivo, $leidoAt);
    }

    /** Quita el pallet de la planilla. No lo baja del camión: eso lo hace el camarero con motivo. */
    public function quitarPosicion(string $operacionId, ControlDespacho $control, PosicionControlDespacho $posicion, User $usuario): ControlDespacho
    {
        $this->asegurarRegistro($usuario);

        return $this->transicionar('control_despacho.quitar_posicion', $operacionId, $usuario, [
            'control_despacho_id' => $control->id,
            'posicion_id' => $posicion->id,
        ], function () use ($control, $posicion, $usuario): ControlDespacho {
            if ($this->contexto->actual() === null) {
                return $control->refresh();
            }

            $bloqueado = $this->bloquear($control);
            $this->asegurarBorrador($bloqueado);
            $fila = PosicionControlDespacho::query()->where('control_despacho_id', $bloqueado->id)->whereKey($posicion->id)->lockForUpdate()->first();
            if (! $fila) {
                throw new DomainException('Ese pallet ya no está en la planilla.');
            }
            $fila->delete();
            $bloqueado->update(['actualizado_por_user_id' => $usuario->id]);

            return $bloqueado->refresh();
        }, ControlDespacho::class, $control->id);
    }

    public function cerrar(string $operacionId, ControlDespacho $control, int $version, User $usuario): ControlDespacho
    {
        $this->asegurarRegistro($usuario);

        return $this->transicionar('control_despacho.cerrar', $operacionId, $usuario, [
            'control_despacho_id' => $control->id,
            'version' => $version,
        ], function () use ($control, $version, $usuario): ControlDespacho {
            if ($this->contexto->actual() === null) {
                return $control->refresh();
            }

            Carga::query()->lockForUpdate()->findOrFail($control->carga_id);
            $bloqueado = $this->bloquear($control);
            $this->asegurarBorrador($bloqueado);
            $this->asegurarVersion($bloqueado, $version);
            $detalle = $this->planilla->detalle($bloqueado);
            if ($detalle['faltantes_cierre'] !== []) {
                throw new DomainException('Antes de cerrar la planilla: '.implode(' ', $detalle['faltantes_cierre']));
            }

            // Se confirman las sugerencias: la planilla cerrada ya no cambia con la carga.
            $cierre = $detalle['cierre'];
            $cierre['responsable'] = filled($cierre['responsable'] ?? null) ? $cierre['responsable'] : $usuario->name;
            $bloqueado->update([
                'estado' => ControlDespacho::CERRADO,
                'version' => $bloqueado->version + 1,
                'encabezado' => array_filter($detalle['encabezado'], fn ($valor): bool => $valor !== null),
                'cierre' => array_filter($cierre, fn ($valor): bool => $valor !== null),
                'cerrado_por_user_id' => $usuario->id,
                'cerrado_at' => now(),
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $bloqueado->update(['planilla_cerrada' => collect($this->planilla->detalle($bloqueado->refresh()))->except(['fotos', 'sugerido'])->all()]);

            return $bloqueado->refresh();
        }, ControlDespacho::class, $control->id);
    }

    public function reabrir(string $operacionId, ControlDespacho $control, int $version, string $motivo, User $usuario): ControlDespacho
    {
        if (! $this->alcance->puedeReabrirControlDespacho($usuario)) {
            throw new OperacionNoAutorizada('Solo supervisión de frío o administración reabre una planilla cerrada.');
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10) {
            throw new DomainException('Explica por qué se reabre la planilla (mínimo 10 caracteres).');
        }

        return $this->transicionar('control_despacho.reabrir', $operacionId, $usuario, [
            'control_despacho_id' => $control->id,
            'version' => $version,
            'motivo' => $motivo,
        ], function () use ($control, $version, $motivo, $usuario): ControlDespacho {
            if ($this->contexto->actual() === null) {
                return $control->refresh();
            }

            $bloqueado = $this->bloquear($control);
            if (! $bloqueado->estaCerrado()) {
                throw new DomainException('La planilla ya está abierta.');
            }
            $this->asegurarVersion($bloqueado, $version);
            $bloqueado->update([
                'estado' => ControlDespacho::BORRADOR,
                'version' => $bloqueado->version + 1,
                'reaperturas' => [...($bloqueado->reaperturas ?? []), [
                    'motivo' => $motivo,
                    'user_id' => $usuario->id,
                    'nombre' => $usuario->name,
                    'cerrado_at' => $bloqueado->cerrado_at?->toAtomString(),
                    'reabierto_at' => now()->toAtomString(),
                ]],
                'planilla_cerrada' => null,
                'cerrado_por_user_id' => null,
                'cerrado_at' => null,
                'actualizado_por_user_id' => $usuario->id,
            ]);

            return $bloqueado->refresh();
        }, ControlDespacho::class, $control->id);
    }

    private function asegurarRegistro(User $usuario): void
    {
        if (! $this->alcance->puedeRegistrarControlDespacho($usuario)) {
            throw new OperacionNoAutorizada('El usuario no está autorizado para registrar la planilla de consolidación.');
        }
    }

    private function asegurarCargaEnDespacho(Carga $carga): void
    {
        if (! in_array($carga->estado, [...EstadoCarga::visiblesEnOperacion(), EstadoCarga::Despachada], true)) {
            throw new DomainException("La carga {$carga->codigo} ya no admite movimientos de carguío.");
        }
    }

    private function asegurarBorrador(ControlDespacho $control): void
    {
        if ($control->estaCerrado()) {
            throw new DomainException('La planilla está cerrada. Supervisión puede reabrirla con un motivo.');
        }
    }

    private function asegurarVersion(ControlDespacho $control, int $version): void
    {
        if ($control->version !== $version) {
            throw new ConflictoOperacion('La planilla cambió en otro equipo. Actualiza y vuelve a guardar.');
        }
    }

    private function bloquear(ControlDespacho $control): ControlDespacho
    {
        return ControlDespacho::query()->lockForUpdate()->findOrFail($control->id);
    }

    private function normalizar(string $clave, mixed $valor): mixed
    {
        if ($valor === null || is_int($valor)) {
            return $valor;
        }
        $texto = trim((string) $valor);

        return in_array($clave, ['patente_camion', 'patente_carro', 'contenedor', 'sello_planta', 'sello_naviera'], true)
            ? Str::upper($texto)
            : $texto;
    }

    /** @param  array<string, mixed>  $payload */
    private function transicionar(
        string $tipo,
        string $operacionId,
        User $usuario,
        array $payload,
        Closure $accion,
        string $sujetoTipo,
        string $sujetoId,
        ?string $referencia = null,
        ?Dispositivo $dispositivo = null,
        ?DateTimeInterface $ocurridoAt = null,
    ): ControlDespacho {
        if (! Str::isUuid($operacionId)) {
            throw new DomainException('El identificador de la operación debe ser un UUID válido.');
        }

        return $this->motor->ejecutar(new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Despacho,
            tipo: $tipo,
            usuario: $usuario,
            payload: $payload,
            operacionId: $operacionId,
            dispositivo: $dispositivo,
            sujetoTipo: $sujetoTipo,
            sujetoId: $sujetoId,
            referencia: $referencia,
            ocurridoAt: $ocurridoAt,
        ), $accion);
    }
}
