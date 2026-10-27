<?php

namespace App\Services\Estiba;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoSesionEstiba;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\BloqueoCamara;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\OperacionSincronizacion;
use App\Models\Posicion;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Folios\ServicioHabilitacionAlmacenamiento;
use DomainException;

class ValidadorMovimiento
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ServicioHabilitacionAlmacenamiento $habilitacion,
        private readonly ServicioReservasTareasMovimiento $reservasTareas,
    ) {}

    public function validar(Movimiento $movimiento): void
    {
        $tipo = $movimiento->tipo_movimiento;

        if (! $tipo instanceof TipoMovimiento) {
            throw new DomainException('El tipo de movimiento no es válido.');
        }

        $this->validarActorYOperacion($movimiento, $tipo);
        $this->validarFolio($movimiento, $tipo);
        $this->validarEstructura($movimiento, $tipo);

        if ($movimiento->camara_origen_id !== null) {
            $this->validarExtremo($movimiento, 'origen', false);
        }

        if ($movimiento->camara_destino_id !== null) {
            $this->validarExtremo($movimiento, 'destino', true);
        }

        if ($tipo === TipoMovimiento::Reubicacion) {
            if ($movimiento->camara_origen_id !== $movimiento->camara_destino_id) {
                throw new DomainException('Una reubicación debe permanecer en la misma cámara.');
            }

            if ($movimiento->sesion_origen_id !== $movimiento->sesion_destino_id) {
                throw new DomainException('Una reubicación debe utilizar la misma sesión de estiba.');
            }
        }

        if ($tipo === TipoMovimiento::TrasladoEntreCamaras
            && $movimiento->camara_origen_id === $movimiento->camara_destino_id) {
            throw new DomainException('Un traslado debe utilizar cámaras diferentes.');
        }

        if ($movimiento->posicion_origen_id !== null
            && $movimiento->posicion_origen_id === $movimiento->posicion_destino_id) {
            throw new DomainException('El origen y el destino deben ser posiciones diferentes.');
        }

        $this->reservasTareas->validarIntegridadMovimiento($movimiento);
    }

    private function validarActorYOperacion(
        Movimiento $movimiento,
        TipoMovimiento $tipo,
    ): void {
        $usuario = User::query()
            ->whereKey($movimiento->user_id)
            ->where('activo', true)
            ->first();
        $dispositivoActivo = Dispositivo::query()
            ->whereKey($movimiento->dispositivo_id)
            ->where('activo', true)
            ->exists();
        $operacion = OperacionSincronizacion::query()->find($movimiento->operacion_id);
        $tipoCompatible = $operacion
            && ($operacion->tipo === $tipo->value
                || $this->esRefinamientoUbicacionMaterial($operacion, $movimiento, $tipo));

        if (! $usuario
            || ! $this->alcance->puedeOperarAlgunaCamara($usuario)
            || ! $dispositivoActivo) {
            throw new OperacionNoAutorizada(
                'El usuario o el dispositivo no se encuentra autorizado.',
            );
        }

        if (! $operacion
            || $operacion->user_id !== $movimiento->user_id
            || $operacion->dispositivo_id !== $movimiento->dispositivo_id
            || ! $tipoCompatible) {
            throw new DomainException(
                'La operación de sincronización no corresponde al movimiento.',
            );
        }
    }

    private function esRefinamientoUbicacionMaterial(
        OperacionSincronizacion $operacion,
        Movimiento $movimiento,
        TipoMovimiento $tipo,
    ): bool {
        if ($operacion->tipo !== TipoMovimiento::UbicacionInicial->value
            || $tipo !== TipoMovimiento::Reubicacion
            || $movimiento->camara_origen_id === null
            || $movimiento->camara_origen_id !== $movimiento->camara_destino_id
            || $movimiento->posicion_origen_id !== null
            || $movimiento->posicion_destino_id === null
            || $movimiento->sesion_origen_id !== $movimiento->sesion_destino_id) {
            return false;
        }

        return Folio::query()
            ->whereKey($movimiento->folio_id)
            ->where('tipo_bulto', TipoBulto::Material)
            ->exists();
    }

    private function validarFolio(Movimiento $movimiento, TipoMovimiento $tipo): void
    {
        $folio = Folio::query()->find($movimiento->folio_id);

        if (! $folio) {
            throw new DomainException('El folio no se encuentra disponible para movimientos.');
        }

        if ($this->esMovimientoRetenidoAutorizado($movimiento, $folio)) {
            return;
        }

        if ($tipo === TipoMovimiento::UbicacionInicial) {
            $this->habilitacion->validarUbicacionInicial($folio);

            return;
        }

        if (! $folio->activo
            || $folio->estado_operacional !== EstadoOperacionalFolio::Disponible) {
            throw new DomainException('El folio no se encuentra disponible para movimientos.');
        }
    }

    private function esMovimientoRetenidoAutorizado(
        Movimiento $movimiento,
        Folio $folio,
    ): bool {
        if (! $folio->activo
            || $folio->estado_operacional !== EstadoOperacionalFolio::Bloqueado
            || $folio->habilitacion_almacenamiento
                !== HabilitacionAlmacenamientoFolio::Retenido
            || ! $movimiento->tarea_movimiento_id) {
            return false;
        }

        $tarea = TareaMovimiento::query()
            ->with('planOperacional')
            ->find($movimiento->tarea_movimiento_id);
        if (! $tarea
            || $tarea->folio_id !== $folio->id
            || $tarea->estado !== EstadoTareaMovimiento::EnProceso) {
            return false;
        }

        // Una labor que ya cruzó el punto de no retorno puede terminar antes de
        // que la retención la replanifique. Las nuevas labores solo se admiten
        // cuando pertenecen al objetivo crítico de segregación.
        return $tarea->planOperacional?->tipo === TipoPlanOperacional::SegregacionRetenido
            || $tarea->iniciada_at?->lte($folio->updated_at) === true;
    }

    private function validarEstructura(Movimiento $movimiento, TipoMovimiento $tipo): void
    {
        $esMaterial = Folio::query()
            ->whereKey($movimiento->folio_id)
            ->where('tipo_bulto', TipoBulto::Material)
            ->exists();
        $origenCompleto = $this->extremoCompleto($movimiento, 'origen');
        $destinoCompleto = $this->extremoCompleto($movimiento, 'destino');
        $origenSoloCamara = $esMaterial && $this->extremoSoloCamara($movimiento, 'origen');
        $destinoSoloCamara = $esMaterial && $this->extremoSoloCamara($movimiento, 'destino');
        $origenValido = $origenCompleto || $origenSoloCamara;
        $destinoValido = $destinoCompleto || $destinoSoloCamara;
        $origenVacio = $this->extremoVacio($movimiento, 'origen');
        $destinoVacio = $this->extremoVacio($movimiento, 'destino');

        $valido = match ($tipo) {
            TipoMovimiento::UbicacionInicial => $origenVacio && $destinoValido,
            TipoMovimiento::Reubicacion,
            TipoMovimiento::TrasladoEntreCamaras => $origenValido && $destinoValido,
            TipoMovimiento::Retiro => $origenValido && $destinoVacio,
            TipoMovimiento::Reversion => ($origenValido || $origenVacio)
                && ($destinoValido || $destinoVacio)
                && ! ($origenVacio && $destinoVacio)
                && filled($movimiento->motivo),
        };

        if (! $valido) {
            throw new DomainException('La combinación de origen y destino no corresponde al tipo de movimiento.');
        }
    }

    private function extremoCompleto(Movimiento $movimiento, string $extremo): bool
    {
        return $movimiento->{"camara_{$extremo}_id"} !== null
            && $movimiento->{"posicion_{$extremo}_id"} !== null
            && $movimiento->{"sesion_{$extremo}_id"} !== null;
    }

    private function extremoSoloCamara(Movimiento $movimiento, string $extremo): bool
    {
        return $movimiento->{"camara_{$extremo}_id"} !== null
            && $movimiento->{"posicion_{$extremo}_id"} === null
            && $movimiento->{"sesion_{$extremo}_id"} !== null;
    }

    private function extremoVacio(Movimiento $movimiento, string $extremo): bool
    {
        return $movimiento->{"camara_{$extremo}_id"} === null
            && $movimiento->{"posicion_{$extremo}_id"} === null
            && $movimiento->{"sesion_{$extremo}_id"} === null;
    }

    private function validarExtremo(
        Movimiento $movimiento,
        string $extremo,
        bool $esDestino,
    ): void {
        $camaraId = $movimiento->{"camara_{$extremo}_id"};
        $posicionId = $movimiento->{"posicion_{$extremo}_id"};
        $sesionId = $movimiento->{"sesion_{$extremo}_id"};

        $camara = Camara::query()->find($camaraId);
        $sesion = SesionEstiba::query()->find($sesionId);

        if (! $camara || $camara->estado !== EstadoCamara::Activa) {
            throw new DomainException("La cámara de {$extremo} no se encuentra activa.");
        }

        $folio = Folio::query()->find($movimiento->folio_id);
        $contenidoEsperado = ContenidoCamara::Productos;

        if ($folio && $folio->tipo_bulto === TipoBulto::Material) {
            $contenidoEsperado = ContenidoCamara::Materiales;
        }

        if ($camara->contenido !== $contenidoEsperado) {
            throw new DomainException('El contenido del folio no corresponde al tipo de cámara.');
        }

        $usuario = User::query()->find($movimiento->user_id);

        if (! $usuario || ! $this->alcance->puedeOperarCamara($usuario, $camara)) {
            throw new OperacionNoAutorizada(
                "El usuario no puede operar la cámara de {$extremo}.",
            );
        }

        if ($posicionId !== null) {
            $posicion = Posicion::query()->find($posicionId);

            if (! $posicion || $posicion->camara_id !== $camara->id) {
                throw new DomainException("La posición de {$extremo} no pertenece a la cámara indicada.");
            }

            if ($esDestino && $posicion->estado !== EstadoPosicion::Activa) {
                throw new DomainException('La posición de destino no se encuentra activa.');
            }
        } elseif (! $folio || $folio->tipo_bulto !== TipoBulto::Material) {
            throw new DomainException("El extremo de {$extremo} requiere una posición exacta.");
        }

        if (! $sesion
            || $sesion->camara_id !== $camara->id
            || $sesion->estado !== EstadoSesionEstiba::Abierta
            || $sesion->user_id !== $movimiento->user_id
            || $sesion->dispositivo_id !== $movimiento->dispositivo_id) {
            throw new DomainException("La sesión de {$extremo} no autoriza este movimiento.");
        }

        $poseeBloqueo = BloqueoCamara::query()
            ->where('camara_id', $camara->id)
            ->where('sesion_estiba_id', $sesion->id)
            ->exists();

        if ($sesion->tarea_movimiento_id) {
            $poseeBloqueo = $sesion->tarea_movimiento_id === $movimiento->tarea_movimiento_id
                && $sesion->clave_tarea_activa === $sesion->tarea_movimiento_id.':'.$camara->id;
        }
        if (! $poseeBloqueo) {
            throw new DomainException("La sesión de {$extremo} no posee el bloqueo de la cámara.");
        }

        $versionAnterior = $movimiento->{"version_{$extremo}_anterior"};
        $versionResultante = $movimiento->{"version_{$extremo}_resultante"};

        if ($versionAnterior === null
            || $versionResultante === null
            || $versionResultante !== $versionAnterior + 1) {
            throw new DomainException(
                "Las versiones de {$extremo} deben representar un incremento unitario.",
            );
        }
    }
}
