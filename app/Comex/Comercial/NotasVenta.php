<?php

namespace App\Comex\Comercial;

use App\Comex\Expediente\EmbarquesOperacion;
use App\Comex\Models\Booking;
use App\Comex\Models\BookingAsignacion;
use App\Comex\Models\ClienteComex;
use App\Comex\Models\NotaVenta;
use App\Comex\Models\NotaVentaRevision;
use App\Comex\Models\ParteComercial;
use App\Models\User;
use Illuminate\Support\Str;

final class NotasVenta
{
    public const ROLES = ['operador', 'vendedor', 'exportador', 'propietario', 'comprador', 'recibidor', 'consignatario', 'pagador'];

    public const INCOTERMS = ['EXW', 'FCA', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP', 'FAS', 'FOB', 'CFR', 'CIF'];

    public function __construct(private RegistroComercial $registro, private EmbarquesOperacion $operacion, private CantidadesComerciales $cantidades) {}

    public function guardar(array $d, User $actor, ?NotaVenta $nota = null): array
    {
        return $this->registro->operar($d['operacion_id'], 'nota.guardar', ['id' => $nota?->id, ...$d], function () use ($d, $actor, $nota) {
            $actual = $nota ? $this->bloquear($nota, $d['version']) : null;
            if (! $actual && (int) $d['version'] !== 0) {
                $this->registro->error('version', 'Una nota nueva parte de la versión cero.');
            }
            $cliente = $this->operacion->cliente($d['cliente_operacion_id']);
            $gestion = $actual?->gestion ?? ClienteComex::query()->find($d['cliente_operacion_id'])?->gestion ?? ClienteComex::SERVICIO;
            if (! $cliente || ! $cliente['activo'] || $gestion === ClienteComex::SIN_SEGUIMIENTO || $gestion !== $d['gestion']) {
                $this->registro->error('cliente_operacion_id', 'Revise el cliente activo y su gestión configurada en COMEX. La gestión propia requiere configuración expresa.');
            }
            $vendedor = $d['partes']['vendedor'];
            if ($actual && ($actual->vendedor_id !== $vendedor || $actual->folio !== $d['folio'] || $actual->cliente_operacion_id !== $d['cliente_operacion_id'] || $actual->gestion !== $gestion)) {
                $this->registro->error('folio', 'Vendedor, folio, cliente y gestión identifican el acuerdo y son permanentes.');
            }
            if (! $actual && NotaVenta::query()->where('vendedor_id', $vendedor)->where('folio', $d['folio'])->exists()) {
                $this->registro->error('folio', 'Ese vendedor ya tiene una nota con ese folio.');
            }
            if (in_array($d['incoterm'], ['FAS', 'FOB', 'CFR', 'CIF'], true) && $d['modalidad'] !== 'maritimo') {
                $this->registro->error('incoterm', 'Este término se utiliza en transporte marítimo. Revise la modalidad contractual.');
            }
            $ultima = $actual ? $this->ultima($actual) : null;
            if ($ultima && $ultima->estado !== 'borrador') {
                $this->registro->error('estado', 'Abra una nueva revisión o retire la propuesta antes de editar.');
            }
            $idsExistentes = array_column($ultima?->datos['lineas'] ?? [], 'id');
            foreach ($d['lineas'] as $linea) {
                if (isset($linea['id']) && ! in_array($linea['id'], $idsExistentes, true)) {
                    $this->registro->error('lineas', 'No reutilice identificadores de líneas de otro acuerdo.');
                }
            }
            $datos = collect($d)->except(['operacion_id', 'version', 'folio', 'cliente_operacion_id', 'gestion', 'motivo'])->all();
            $datos['partes'] = $this->partes($d['partes']);
            $datos['contrata_flete'] = ParteComercial::query()->findOrFail($d['contrata_flete_id'])->toArray();
            if (! $datos['contrata_flete']['activo']) {
                $this->registro->error('contrata_flete_id', 'Seleccione una parte activa que contrata el flete.');
            }
            $datos['incoterm_version'] = '2020';
            $datos['valorizacion'] = $this->cantidades->calcular($d['lineas'], $d['negocio'], $d['moneda']);
            $datos['lineas'] = $datos['valorizacion']['lineas'];
            unset($datos['valorizacion']['lineas']);
            $antes = $ultima?->toArray();
            if (! $actual) {
                $actual = NotaVenta::query()->create(['id' => (string) Str::uuid(), 'vendedor_id' => $vendedor, 'folio' => $d['folio'], 'cliente_operacion_id' => $cliente['id'], 'cliente' => [...$cliente, 'source_version' => $this->registro->hash($cliente), 'captured_at' => now()->toIso8601String()], 'gestion' => $gestion, 'version' => 1]);
            } else {
                $actual->update(['version' => $actual->version + 1]);
            }
            if ($ultima) {
                $ultima->update(['datos' => $datos, 'preparado_por_user_id' => $actor->id, 'preparado_por' => $actor->name]);
            } else {
                $ultima = NotaVentaRevision::query()->create(['id' => (string) Str::uuid(), 'nota_id' => $actual->id, 'numero' => 1, 'datos' => $datos, 'estado' => 'borrador', 'preparado_por_user_id' => $actor->id, 'preparado_por' => $actor->name]);
            }
            $this->registro->evento('nota', $actual->id, 'borrador_guardado', ['antes' => $antes, 'despues' => $ultima->fresh()->toArray(), 'motivo' => $d['motivo'] ?? null], $actor);

            return $this->detalle($actual->fresh());
        });
    }

    public function cambiar(NotaVenta $nota, array $d, User $actor): array
    {
        return $this->registro->operar($d['operacion_id'], 'nota.transicion', ['id' => $nota->id, ...$d], function () use ($nota, $d, $actor) {
            $nota = $this->bloquear($nota, $d['version']);
            $rev = $this->ultima($nota);
            $antes = $rev->toArray();
            switch ($d['accion']) {
                case 'nueva_revision':
                    if (in_array($rev->estado, ['borrador', 'en_revision'], true)) {
                        $this->registro->error('estado', 'Ya existe una propuesta pendiente.');
                    }
                    $rev = NotaVentaRevision::query()->create(['id' => (string) Str::uuid(), 'nota_id' => $nota->id, 'numero' => $rev->numero + 1, 'datos' => $rev->datos, 'estado' => 'borrador', 'preparado_por_user_id' => $actor->id, 'preparado_por' => $actor->name]);
                    break;
                case 'enviar_revision':
                    $this->exigirEstado($rev, ['borrador']);
                    $this->validarCopias($rev);
                    $rev->update(['estado' => 'en_revision', 'enviado_at' => now()]);
                    break;
                case 'retirar_revision':
                    $this->exigirEstado($rev, ['en_revision']);
                    $rev->update(['estado' => 'borrador', 'enviado_at' => null]);
                    break;
                case 'aprobar':
                case 'rechazar':
                    $this->exigirEstado($rev, ['en_revision']);
                    if (config('comex.aprobador_distinto', true) && $rev->preparado_por_user_id === $actor->id) {
                        $this->registro->error('aprobador', 'La revisión requiere una persona distinta de quien preparó el borrador.');
                    }
                    if ($d['accion'] === 'aprobar') {
                        $this->validarCopias($rev);
                        $this->exigirSinReservas($nota);
                        if ($nota->revision_activa_id) {
                            NotaVentaRevision::query()->findOrFail($nota->revision_activa_id)->update(['estado' => 'sustituida']);
                        }
                        $nota->revision_activa_id = $rev->id;
                    }
                    $rev->update(['estado' => $d['accion'] === 'aprobar' ? 'aprobada' : 'rechazada', 'aprobado_por_user_id' => $actor->id, 'aprobado_por' => $actor->name, 'decidido_at' => now(), 'observacion' => $d['motivo']]);
                    break;
                case 'cerrar':
                    $this->exigirEstado($rev, ['aprobada', 'rechazada']);
                    if (! $nota->revision_activa_id) {
                        $this->registro->error('estado', 'Solo se cierra comercialmente un acuerdo aprobado.');
                    }
                    $pendientes = Booking::query()->whereIn('id', BookingAsignacion::query()->where('nota_id', $nota->id)->where('activa', true)->select('booking_id'))->whereNotIn('estado', ['cerrado', 'anulado'])->exists();
                    if ($pendientes) {
                        $this->registro->error('estado', 'Cierre los bookings pendientes antes de cerrar el acuerdo.');
                    }
                    $nota->estado = 'cerrada';
                    break;
                case 'anular':
                    $this->exigirSinReservas($nota);
                    $nota->estado = 'anulada';
                    break;
                default:
                    $this->registro->error('accion', 'La acción comercial no está habilitada.');
            }
            $nota->version++;
            $nota->save();
            $this->registro->evento('nota', $nota->id, $d['accion'], ['antes' => $antes, 'despues' => $rev->fresh()->toArray(), 'estado_nota' => $nota->estado, 'motivo' => $d['motivo']], $actor);

            return $this->detalle($nota);
        });
    }

    public function detalle(NotaVenta $nota): array
    {
        return ['nota' => $nota->toArray(), 'propuesta' => $this->ultima($nota)->toArray(), 'aprobada' => $nota->revision_activa_id ? NotaVentaRevision::query()->findOrFail($nota->revision_activa_id)->toArray() : null];
    }

    private function partes(array $ids): array
    {
        $partes = [];
        foreach (self::ROLES as $rol) {
            $parte = ParteComercial::query()->findOrFail($ids[$rol]);
            if (! $parte->activo || ! in_array($rol, $parte->roles, true)) {
                $this->registro->error('partes', 'La parte seleccionada no está activa o no tiene habilitado el rol '.$rol.'.');
            }
            $partes[$rol] = $parte->toArray();
        }
        $partes['notify'] = [];
        foreach ($ids['notify'] ?? [] as $id) {
            $p = ParteComercial::query()->findOrFail($id);
            if (! $p->activo || ! in_array('notify', $p->roles, true)) {
                $this->registro->error('partes', 'Revise las partes de aviso habilitadas.');
            }
            $partes['notify'][] = $p->toArray();
        }

        return $partes;
    }

    private function validarCopias(NotaVentaRevision $rev): void
    {
        $partes = $rev->datos['partes'];
        $todas = [...array_values(array_diff_key($partes, ['notify' => true])), ...$partes['notify'], $rev->datos['contrata_flete']];
        foreach ($todas as $copia) {
            $actual = ParteComercial::query()->findOrFail($copia['id']);
            if (! $actual->activo || $actual->version !== $copia['version']) {
                $this->registro->error('partes', 'Cambió una identidad comercial. Actualice el borrador y vuelva a revisar antes de aprobar.');
            }
        }
    }

    private function bloquear(NotaVenta $nota, int $version): NotaVenta
    {
        $nota = NotaVenta::query()->lockForUpdate()->findOrFail($nota->id);
        if ($nota->version !== $version || $nota->estado !== 'abierta') {
            $this->registro->error('version', 'El acuerdo cambió o está cerrado/anulado. Recargue antes de continuar.');
        }

        return $nota;
    }

    private function ultima(NotaVenta $nota): NotaVentaRevision
    {
        return NotaVentaRevision::query()->where('nota_id', $nota->id)->orderByDesc('numero')->firstOrFail();
    }

    private function exigirEstado(NotaVentaRevision $rev, array $estados): void
    {
        if (! in_array($rev->estado, $estados, true)) {
            $this->registro->error('estado', 'La propuesta no está en el estado requerido para esta acción.');
        }
    }

    private function exigirSinReservas(NotaVenta $nota): void
    {
        if (BookingAsignacion::query()->where('nota_id', $nota->id)->where('activa', true)->exists()) {
            $this->registro->error('estado', 'Hay cantidades comprometidas en bookings. Retire las asignaciones antes de sustituir o anular el acuerdo; los despachos cerrados requieren rectificación formal.');
        }
    }
}
