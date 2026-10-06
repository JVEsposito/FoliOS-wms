<?php

namespace App\Services\RecepcionEmbalada;

use App\Enums\EstadoRecepcionFrutaEmbalada;
use App\Models\PersonalAccessToken;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\User;
use App\Services\Documentos\RegistroRecepcionFrutaEmbaladaPdf;
use App\Services\Documentos\ServicioFormatosRegistro;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioDocumentoRecepcionEmbalada
{
    public function __construct(private readonly RegistroRecepcionFrutaEmbaladaPdf $pdf, private readonly ServicioFormatosRegistro $formatos) {}

    public function blanco(): string
    {
        return $this->pdf->generar([], $this->formatos->vigente('RRFE-01'), blanco: true);
    }

    public function emitir(string $id, User $usuario): string
    {
        return DB::transaction(function () use ($id, $usuario): string {
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            $r = RecepcionFrutaEmbalada::query()->lockForUpdate()->findOrFail($id);
            if ($r->temporada_id !== $temporada->id || $r->estado !== EstadoRecepcionFrutaEmbalada::Aceptada) {
                throw new DomainException('Solo se emite RRFE-01 de una recepción aceptada de la temporada activa.');
            }
            $r->load(['pallets', 'cliente', 'plantaOrigen', 'validador']);
            $formato = $r->formato_rrfe_snapshot ?? $this->formatos->vigente('RRFE-01', bloquear: true);
            $folios = DB::table('recepcion_fruta_embalada_folios as rf')->join('aceptaciones_fruta_embalada as a', 'a.id', '=', 'rf.aceptacion_id')
                ->join('folios as f', 'f.id', '=', 'rf.folio_id')->where('a.recepcion_id', $id)->pluck('f.numero_folio', 'rf.recepcion_pallet_id');
            if ($folios->count() !== $r->pallets->count()) {
                throw new DomainException('La recepción no tiene todos sus folios de aceptación.');
            }
            $zona = config('app.operational_timezone');
            $datos = ['cliente' => $r->cliente->nombre, 'planta_origen' => $r->plantaOrigen->nombre,
                'servicio' => $r->servicio === 'prefrio' ? 'Prefrío' : 'Almacenaje', 'turno' => $r->turno,
                'recepcion' => $r->recepcion_at->setTimezone($zona)->format('d-m-Y H:i'),
                'salida' => $r->salida_at?->setTimezone($zona)->format('d-m-Y H:i'), 'validador' => $r->validador->name,
                'chofer' => $r->chofer, 'rut_chofer' => $r->rut_chofer, 'patente_delantera' => $r->patente_delantera,
                'patente_carro' => $r->patente_carro, 'prefrio' => $r->llega_con_prefrio ? 'Sí' : 'No', 'observacion' => $r->observacion,
                'pallets' => $r->pallets->map(fn ($p) => [...$p->toArray(), 'folio' => $folios[$p->id]])->all()];
            $documento = $this->pdf->generar($datos, $formato);
            if ($r->formato_rrfe_snapshot === null) {
                $antes = $r->toArray();
                $r->update(['formato_rrfe_snapshot' => $formato, 'version' => $r->version + 1, 'actualizado_por_user_id' => $usuario->id]);
                $token = $usuario->currentAccessToken();
                $r->eventos()->create(['operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $documento),
                    'user_id' => $usuario->id, 'dispositivo_id' => $token instanceof PersonalAccessToken ? $token->dispositivo_id : null,
                    'antes' => $antes, 'despues' => $r->toArray()]);
            }

            return $documento;
        }, attempts: 3);
    }
}
