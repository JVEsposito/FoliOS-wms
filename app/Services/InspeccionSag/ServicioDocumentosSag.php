<?php

namespace App\Services\InspeccionSag;

use App\Exceptions\ConflictoOperacion;
use App\Models\DocumentoSag;
use App\Models\ResponsableContraparteSag;
use App\Models\User;
use App\Services\Documentos\ServicioFormatosRegistro;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioDocumentosSag
{
    public const FORMATOS = ['repaletizaje' => 'F-PA-CFT-DR-010', 'anulacion' => 'F-PA-CFT-DR-011', 'cambio_mercado' => 'F-CER-PPS-PA-002'];

    public function __construct(private readonly DatosDocumentoSag $datos) {}

    public function guardar(array $entrada, User $usuario, ?DocumentoSag $documento = null): DocumentoSag
    {
        return DB::transaction(function () use ($entrada, $usuario, $documento): DocumentoSag {
            if ($anterior = $this->repetida($entrada, 'guardar', $usuario)) {
                if ($documento && $anterior->id !== $documento->id) {
                    throw new ConflictoOperacion('La operación pertenece a otro documento.');
                }

                return $anterior;
            }
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            $registro = $documento ? DocumentoSag::query()->lockForUpdate()->findOrFail($documento->id) : new DocumentoSag;
            $this->version($registro, $entrada['version']);
            if ($registro->exists && ($registro->temporada_id !== $temporada->id || $registro->estado !== 'borrador')) {
                throw new DomainException('Solo se editan borradores de la temporada activa.');
            }
            if ($registro->exists && $registro->tipo !== $entrada['tipo']) {
                throw new DomainException('El tipo de documento no se puede cambiar.');
            }
            $responsable = ResponsableContraparteSag::findOrFail($entrada['responsable_id']);
            if (! $responsable->activo) {
                throw new DomainException('Selecciona un responsable activo.');
            }
            $detalle = $this->datos->preparar($entrada['tipo'], $entrada);
            $registro->fill([
                'temporada_id' => $temporada->id, 'tipo' => $entrada['tipo'], 'estado' => 'borrador',
                'version' => ($registro->version ?? 0) + 1, 'fecha_actividad' => $entrada['fecha_actividad'],
                'responsable_id' => $responsable->id, 'repaletizaje_id' => $detalle['repaletizaje_id'] ?? null,
                'lote_inspeccion_sag_id' => $detalle['lote_inspeccion_sag_id'] ?? null,
                'embarque_id' => $detalle['embarque_id'] ?? null,
                'encabezado' => $entrada['encabezado'] ?? [], 'entrada' => $entrada, 'detalle' => $detalle,
                'creado_por_user_id' => $registro->creado_por_user_id ?? $usuario->id,
            ]);
            if (! $registro->exists && $registro->repaletizaje_id && DocumentoSag::where('repaletizaje_id', $registro->repaletizaje_id)->exists()) {
                throw new ConflictoOperacion('La repa ya tiene un documento SAG. Ábrelo desde el listado.');
            }
            if ($registro->lote_inspeccion_sag_id && DocumentoSag::where('lote_inspeccion_sag_id', $registro->lote_inspeccion_sag_id)->when($registro->exists, fn ($q) => $q->whereKeyNot($registro->id))->exists()) {
                throw new ConflictoOperacion('El lote ya tiene un documento SAG. Ábrelo desde el listado.');
            }
            $registro->save();
            $this->registrar($registro, $entrada, 'guardar', $usuario);

            return $registro;
        }, 3);
    }

    public function actuar(DocumentoSag $documento, string $accion, array $entrada, User $usuario): DocumentoSag
    {
        return DB::transaction(function () use ($documento, $accion, $entrada, $usuario): DocumentoSag {
            if ($anterior = $this->repetida($entrada, $accion, $usuario)) {
                if ($anterior->id !== $documento->id) {
                    throw new ConflictoOperacion('La operación pertenece a otro documento.');
                }

                return $anterior;
            }
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            $registro = DocumentoSag::query()->lockForUpdate()->findOrFail($documento->id);
            $this->version($registro, $entrada['version']);
            if ($registro->temporada_id !== $temporada->id) {
                throw new DomainException('El documento no pertenece a la temporada activa.');
            }
            if ($accion === 'emitir') {
                if ($registro->estado !== 'borrador') {
                    throw new DomainException('Este documento ya fue emitido. Puedes descargar su misma versión.');
                }
                $planta = DB::table('configuracion_contraparte_sag')->where('id', 'planta')->first();
                if (! $planta) {
                    throw new DomainException('Completa los datos del establecimiento antes de emitir.');
                }
                $responsable = ResponsableContraparteSag::findOrFail($registro->responsable_id);
                if (! $responsable->activo) {
                    throw new DomainException('El responsable está inactivo. Corrige el borrador.');
                }
                $detalle = $this->datos->preparar($registro->tipo, $registro->entrada);
                if ($registro->tipo === 'cambio_mercado') {
                    if (blank($registro->encabezado['programa'] ?? null) || blank($registro->encabezado['motivo'] ?? null) || empty($detalle['lineas'])) {
                        throw new DomainException('Completa el programa, motivo y folios del cambio de mercado.');
                    }
                    foreach ($detalle['lineas'] as $linea) {
                        if ($linea['kilos'] === null || blank($linea['mercados_anteriores']) || blank($linea['nuevo_destino'])) {
                            throw new DomainException('Revisa los kilos y mercados de cada folio antes de emitir.');
                        }
                    }
                }
                foreach ($detalle['originales'] ?? $detalle['lineas'] ?? [] as $linea) {
                    if (blank($linea['tipo_actividad'] ?? null) || blank($linea['numero_actividad'] ?? null)) {
                        throw new DomainException('Completa la última actividad y su número para cada folio antes de emitir.');
                    }
                }
                DB::table('secuencias_documentos_sag')->insertOrIgnore(['temporada_id' => $temporada->id, 'tipo' => $registro->tipo, 'ultimo' => 0]);
                $secuencia = DB::table('secuencias_documentos_sag')->where('temporada_id', $temporada->id)->where('tipo', $registro->tipo);
                $numero = (int) (clone $secuencia)->lockForUpdate()->value('ultimo') + 1;
                $secuencia->update(['ultimo' => $numero]);
                $registro->fill([
                    'estado' => 'emitido', 'numero' => $numero, 'emitido_at' => now(), 'detalle' => $detalle,
                    'planta_snapshot' => json_decode($planta->datos, true), 'responsable_snapshot' => $responsable->toArray(),
                    'formato_snapshot' => app(ServicioFormatosRegistro::class)->vigente(self::FORMATOS[$registro->tipo], bloquear: true),
                ]);
            } else {
                $transiciones = [
                    'enviar' => ['desde' => ['emitido'], 'estado' => 'enviado'],
                    'aprobar' => ['desde' => ['emitido', 'enviado'], 'estado' => 'aprobado'],
                    'rechazar' => ['desde' => ['emitido', 'enviado'], 'estado' => 'rechazado'],
                    'cancelar' => ['desde' => ['borrador'], 'estado' => 'cancelado'],
                ];
                $regla = $transiciones[$accion] ?? throw new DomainException('Acción no disponible.');
                if (! in_array($registro->estado, $regla['desde'], true)) {
                    throw new DomainException('La acción no corresponde al estado del documento.');
                }
                if (blank($entrada['referencia'] ?? null)) {
                    throw new DomainException('Indica el comprobante, recepción o resolución que respalda esta acción.');
                }
                if (in_array($accion, ['aprobar', 'rechazar'], true) && blank($entrada['resuelto_por'] ?? null)) {
                    throw new DomainException('Indica el nombre y cargo de quien resolvió.');
                }
                $registro->estado = $regla['estado'];
            }
            $registro->version++;
            $registro->save();
            $this->registrar($registro, $entrada, $accion, $usuario);

            return $registro;
        }, 3);
    }

    private function version(DocumentoSag $documento, int $version): void
    {
        if ((int) ($documento->version ?? 0) !== $version) {
            throw new ConflictoOperacion('Otra persona cambió el documento. Recarga antes de continuar.');
        }
    }

    private function repetida(array $datos, string $accion, User $usuario): ?DocumentoSag
    {
        $op = DB::table('operaciones_documento_sag')->where('operacion_id', $datos['operacion_id'])->first();
        if (! $op) {
            return null;
        }
        if ($op->accion !== $accion || (int) $op->user_id !== $usuario->id || ! hash_equals($op->payload_hash, hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR)))) {
            throw new ConflictoOperacion('El identificador de operación ya se utilizó con otros datos.');
        }

        return DocumentoSag::findOrFail($op->documento_id);
    }

    private function registrar(DocumentoSag $documento, array $datos, string $accion, User $usuario): void
    {
        DB::table('operaciones_documento_sag')->insert([
            'operacion_id' => $datos['operacion_id'], 'documento_id' => $documento->id,
            'accion' => $accion, 'payload_hash' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR)),
            'user_id' => $usuario->id, 'created_at' => now(),
        ]);
        DB::table('eventos_contraparte_sag')->insert([
            'id' => (string) Str::uuid(), 'tipo' => 'documento.'.$accion, 'referencia' => $documento->id,
            'user_id' => $usuario->id, 'datos' => json_encode(['version' => $documento->version, 'estado' => $documento->estado,
                'referencia' => $datos['referencia'] ?? null, 'resuelto_por' => $datos['resuelto_por'] ?? null], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
