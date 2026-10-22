<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FuenteHechos;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Models\ConceptoServicio;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Valorizacion\ConsultaRegla;
use App\Finanzas\Valorizacion\ReglaValorizacion;
use Carbon\CarbonImmutable;

/** Se ejecuta después de las fuentes operativas y conserva todos los aportes del cálculo. */
final class CalculosConcepto implements FuenteHechos
{
    public function clave(): string
    {
        return ReglaValorizacion::FUENTE;
    }

    public function nombre(): string
    {
        return 'Cálculo entre medidas operativas';
    }

    public function descripcion(): string
    {
        return 'Combina medidas de igual unidad, con filtros y referencias comunes. El primer término determina la fecha del servicio.';
    }

    public function intervalo(): bool
    {
        return false;
    }

    public function medidas(): array
    {
        return ['cantidad' => ['nombre' => 'Cantidad calculada', 'unidad' => 'unidad']];
    }

    public function cambios(ContextoCaptura $contexto, ?CarbonImmutable $desde): iterable
    {
        foreach (ConceptoServicio::query()->where('fuente', $this->clave())->orderBy('clave')->get() as $concepto) {
            foreach (app(ConsultaRegla::class)->grupos($concepto->regla) as $grupo) {
                $ancla = $grupo['ancla'];
                if ($ancla === null) {
                    continue;
                }
                $vigente = $grupo['completo'] && $ancla['fecha_operacional'] >= $concepto->vigente_desde->toDateString()
                    && ($concepto->vigente_hasta === null || $ancla['fecha_operacional'] <= $concepto->vigente_hasta->toDateString());
                yield new HechoCapturado(
                    origenId: $concepto->clave.'#'.$grupo['clave'], estado: $vigente ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
                    ocurridoAt: CarbonImmutable::parse($ancla['ocurrido_at']), clienteId: $ancla['cliente_id'], clienteNombre: $ancla['cliente_nombre'],
                    temporadaId: $ancla['temporada_id'], temporadaCodigo: $ancla['temporada_codigo'], referencia: mb_substr($concepto->nombre.' · '.$ancla['referencia'], 0, 100),
                    medidas: ['cantidad' => $grupo['cantidad']],
                    datos: ['concepto_declarado' => $concepto->clave, 'regla' => $concepto->regla, 'dimensiones' => $grupo['dimensiones'], 'aportes' => $grupo['aportes']],
                );
            }
        }
    }

    public function porIds(ContextoCaptura $contexto, array $origenIds): iterable
    {
        foreach ($this->cambios($contexto, null) as $hecho) {
            if (in_array($hecho->origenId, $origenIds, true)) {
                yield $hecho;
            }
        }
    }
}
