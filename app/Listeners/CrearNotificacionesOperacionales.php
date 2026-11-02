<?php

namespace App\Listeners;

use App\Enums\AudienciaNotificacionOperacional;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoCarga;
use App\Enums\PrioridadCarga;
use App\Enums\RolUsuario;
use App\Enums\SeveridadNotificacionOperacional;
use App\Enums\TipoEventoCarga;
use App\Enums\TipoNotificacionOperacional;
use App\Events\EventoCargaRegistrado;
use App\Models\EventoCarga;
use App\Models\NotificacionOperacional;
use Illuminate\Support\Str;

class CrearNotificacionesOperacionales
{
    public function handle(EventoCargaRegistrado $eventoRegistrado): void
    {
        $evento = $eventoRegistrado->evento->loadMissing(['carga', 'folio']);

        match ($evento->tipo) {
            TipoEventoCarga::Publicada => $this->cargaPublicada($evento),
            TipoEventoCarga::Retornada => $this->cargaRetornada($evento),
            TipoEventoCarga::Actualizada => $this->prioridadCambiada($evento),
            TipoEventoCarga::IncidenciaReportada => $this->incidenciaReportada($evento),
            TipoEventoCarga::IncidenciaResuelta,
            TipoEventoCarga::FolioReemplazado => $this->incidenciaResuelta($evento),
            default => null,
        };
    }

    private function cargaPublicada(EventoCarga $evento): void
    {
        $cantidad = (int) data_get($evento->datos, 'cantidad_folios', 0);
        $this->crear(
            $evento,
            TipoNotificacionOperacional::CargaPublicada,
            SeveridadNotificacionOperacional::Informativa,
            "Carga {$evento->carga->codigo} publicada",
            "La carga quedó disponible para frío con {$cantidad} folios.",
            AudienciaNotificacionOperacional::Area,
            ContenidoCamara::Productos->value,
        );
    }

    private function cargaRetornada(EventoCarga $evento): void
    {
        $folios = array_values(array_filter((array) data_get($evento->datos, 'folios', []), 'is_string'));
        $cantidad = count($folios);
        $detalle = implode(', ', array_slice($folios, 0, 8));
        if ($cantidad > 8) {
            $detalle .= ' y '.($cantidad - 8).' más';
        }
        $this->crear(
            $evento,
            TipoNotificacionOperacional::CargaRetornada,
            SeveridadNotificacionOperacional::Advertencia,
            "Retorno a planta · {$evento->carga->codigo}",
            "{$cantidad} ".($cantidad === 1 ? 'pallet requiere' : 'pallets requieren')." reingreso a cámara. Folios: {$detalle}. Revisa la labor de reingreso.",
            AudienciaNotificacionOperacional::Area,
            ContenidoCamara::Productos->value,
            ['folios' => $folios, 'cantidad_folios' => $cantidad, 'href' => '/oficina/operacion-ahora#despacho'],
        );
    }

    private function prioridadCambiada(EventoCarga $evento): void
    {
        if (! in_array($evento->carga->estado, EstadoCarga::visiblesEnOperacion(), true)) {
            return;
        }

        $anterior = data_get($evento->datos, 'prioridad_anterior');
        $nueva = data_get($evento->datos, 'prioridad_nueva');

        if (! is_string($anterior) || ! is_string($nueva) || $anterior === $nueva) {
            return;
        }

        $this->crear(
            $evento,
            TipoNotificacionOperacional::PrioridadCargaCambiada,
            $nueva === PrioridadCarga::Urgente->value
                ? SeveridadNotificacionOperacional::Critica
                : SeveridadNotificacionOperacional::Advertencia,
            "Prioridad actualizada: {$evento->carga->codigo}",
            sprintf('La prioridad cambió de %s a %s.', Str::headline($anterior), Str::headline($nueva)),
            AudienciaNotificacionOperacional::Area,
            ContenidoCamara::Productos->value,
        );
    }

    private function incidenciaReportada(EventoCarga $evento): void
    {
        $folio = $evento->folio?->numero_folio ?? 'folio sin identificar';
        $tipo = Str::headline((string) data_get($evento->datos, 'tipo', 'incidencia'));

        foreach ([
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::Despachador,
        ] as $rol) {
            $this->crear(
                $evento,
                TipoNotificacionOperacional::IncidenciaCargaReportada,
                SeveridadNotificacionOperacional::Critica,
                "Incidencia en {$evento->carga->codigo}",
                "{$folio}: {$tipo}. Requiere resolución desde oficina.",
                AudienciaNotificacionOperacional::Rol,
                $rol->value,
            );
        }
    }

    private function incidenciaResuelta(EventoCarga $evento): void
    {
        $resolucion = (string) data_get($evento->datos, 'resolucion', '');

        if (! in_array($resolucion, ['reparado', 'reemplazo'], true)) {
            return;
        }

        $folio = $evento->folio?->numero_folio ?? 'folio';
        $detalle = $resolucion === 'reemplazo'
            ? 'Se autorizó y asignó un folio de reemplazo.'
            : 'El pallet fue reparado y volvió a la ruta de extracción.';
        $this->crear(
            $evento,
            TipoNotificacionOperacional::IncidenciaCargaResuelta,
            SeveridadNotificacionOperacional::Exito,
            "Incidencia resuelta: {$evento->carga->codigo}",
            "{$folio}: {$detalle}",
            AudienciaNotificacionOperacional::Area,
            ContenidoCamara::Productos->value,
        );
    }

    private function crear(
        EventoCarga $evento,
        TipoNotificacionOperacional $tipo,
        SeveridadNotificacionOperacional $severidad,
        string $titulo,
        string $mensaje,
        AudienciaNotificacionOperacional $audiencia,
        string $audienciaValor,
        array $datos = [],
    ): void {
        NotificacionOperacional::query()->firstOrCreate(
            ['clave' => "evento:{$evento->id}:{$audiencia->value}:{$audienciaValor}"],
            [
                'tipo' => $tipo,
                'audiencia_tipo' => $audiencia,
                'audiencia_valor' => $audienciaValor,
                'severidad' => $severidad,
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'carga_id' => $evento->carga_id,
                'folio_id' => $evento->folio_id,
                'incidencia_carga_folio_id' => data_get($evento->datos, 'incidencia_id'),
                'datos' => [
                    ...$datos,
                    'evento_carga_id' => $evento->id,
                    'version_carga' => data_get($evento->datos, 'version'),
                ],
            ],
        );
    }
}
