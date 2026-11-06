<?php

namespace App\Services\Envases;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\GuiaDespachoEnvase;
use App\Models\RecepcionRomana;
use Illuminate\Support\Facades\DB;

class DocumentoSalidaEnvases
{
    /** Debe ser la primera lectura/escritura de la transacción, antes de bloquear los documentos. */
    public function bloquear(string $temporada, string $cliente, ?string $numero, string $campo = 'numero_guia_despacho'): ?string
    {
        if ($numero === null || trim($numero) === '') {
            return null;
        }
        $normalizado = app(ServicioGuiaEmitidaEnvases::class)->exigir($numero, $campo);
        $clave = hash('sha256', $temporada.'|'.$cliente.'|'.$normalizado);
        DB::table('documentos_salida_envases')->insertOrIgnore([
            'clave' => $clave, 'temporada_id' => $temporada, 'cliente_id' => $cliente,
            'numero' => $normalizado, 'created_at' => now(),
        ]);
        DB::table('documentos_salida_envases')->where('clave', $clave)->lockForUpdate()->first();

        return $normalizado;
    }

    public function asegurarSinRomana(string $temporada, string $cliente, string $numero): void
    {
        $normalizador = app(ServicioGuiaEmitidaEnvases::class);
        $numero = $normalizador->exigir($numero);
        $otra = RecepcionRomana::query()->where('temporada_id', $temporada)->where('cliente_id', $cliente)
            ->where('estado', EstadoRecepcionRomana::Cerrado->value)->where('documento_salida_normalizado', $numero)
            ->whereHas('salidasEnvases', fn ($q) => $q->where('cantidad', '>', 0))
            ->lockForUpdate()->first(['id', 'numero_recepcion', 'numero_guia_salida']);
        if ($otra) {
            throw new ConflictoOperacion("La guía {$numero} ya registra una salida de envases en Romana ({$otra->numero_recepcion}). No confirmes un segundo despacho: duplicaría cuenta y existencia. Para un duplicado histórico, abre Cuenta Envases → Conciliar duplicados. Si los números difieren por digitación, usa Comparación manual sin renumerar primero.");
        }
    }

    public function asegurarSinDespacho(string $temporada, string $cliente, string $numero, ?string $excluirRecepcion = null): void
    {
        $normalizador = app(ServicioGuiaEmitidaEnvases::class);
        $numero = $normalizador->exigir($numero, 'numero_guia_salida');
        $otra = GuiaDespachoEnvase::query()->where('temporada_id', $temporada)->where('cliente_id', $cliente)
            ->where('estado', EstadoGuiaDespachoEnvase::Confirmada->value)->where('documento_salida_normalizado', $numero)
            ->lockForUpdate()->first(['id', 'numero', 'numero_guia_despacho']);
        if ($otra) {
            throw new ConflictoOperacion("La guía {$numero} ya descontó envases en Despacho ({$otra->numero}). Revisa y anula el despacho duplicado antes de registrar la salida en Romana; no vuelvas a descontarlo en el destare.");
        }
        $otraRecepcion = RecepcionRomana::query()->where('temporada_id', $temporada)->where('cliente_id', $cliente)
            ->where('estado', EstadoRecepcionRomana::Cerrado->value)->where('documento_salida_normalizado', $numero)
            ->when($excluirRecepcion, fn ($q) => $q->whereKeyNot($excluirRecepcion))
            ->whereHas('salidasEnvases', fn ($q) => $q->where('cantidad', '>', 0))
            ->lockForUpdate()->first(['id', 'numero_recepcion', 'numero_guia_salida']);
        if ($otraRecepcion) {
            throw new ConflictoOperacion("La guía {$numero} ya registra una salida en {$otraRecepcion->numero_recepcion}. Una misma guía no puede descontarse en dos recepciones.");
        }
    }
}
