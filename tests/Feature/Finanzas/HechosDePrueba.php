<?php

namespace Tests\Feature\Finanzas;

use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Tarifa;
use App\Finanzas\Tarifario\GestorTarifas;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Hechos y precios de Finanzas escritos directamente, como los deja la captura: así una prueba
 * de prefactura o de cierre controla exactamente qué se cobra. Las horas van en hora de planta.
 */
trait HechosDePrueba
{
    private User $administrador;

    private function precio(?string $cliente, string $servicio, string $unidad, string $precio, string $desde, bool $cerrarAnterior = false, string $moneda = 'CLP'): Tarifa
    {
        return app(GestorTarifas::class)->crear([
            'cliente_id' => $cliente, 'servicio' => $servicio, 'unidad' => $unidad, 'moneda' => $moneda,
            'precio' => $precio, 'vigente_desde' => $desde, 'cerrar_anterior' => $cerrarAnterior,
        ], $this->administrador);
    }

    private function enUtc(?string $horaPlanta): ?CarbonImmutable
    {
        return $horaPlanta === null ? null : CarbonImmutable::parse($horaPlanta, 'America/Santiago')->utc();
    }

    /**
     * @param  array<string, int|float>  $medidas
     * @param  array<string, mixed>  $datos
     */
    private function hecho(string $fuente, ?string $cliente, string $ocurrido, array $medidas, ?string $termina = null, array $datos = [], ?string $origen = null, string $estado = Hecho::ESTADO_VIGENTE): Hecho
    {
        $inicio = CarbonImmutable::parse($ocurrido, 'America/Santiago');

        return Hecho::query()->create([
            'fuente' => $fuente,
            'origen_id' => $origen ?? (string) Str::uuid(),
            'version' => 1,
            'vigente' => true,
            'estado' => $estado,
            'motivo_version' => Hecho::MOTIVO_CAPTURA,
            'cliente_id' => $cliente,
            'cliente_nombre' => $cliente === null ? 'EXPORTADORA SIN VINCULAR' : null,
            'referencia' => 'REF-'.Str::upper(Str::random(5)),
            'ocurrido_at' => $inicio->utc(),
            'termina_at' => $this->enUtc($termina),
            'fecha_operacional' => $inicio->toDateString(),
            'medidas' => $medidas,
            'datos' => $datos,
            'huella' => str_repeat('0', 64),
            'capturado_at' => now(),
        ]);
    }

    /**
     * @param  array<string, int>  $medidas
     * @param  array<string, mixed>  $datos
     * @param  list<array{0: array<string, int>, 1: string, 2: ?string}>  $tramos  [medidas, desde, hasta]
     */
    private function estadia(string $cliente, string $origen, string $ingreso, ?string $salida, array $medidas, array $datos = [], array $tramos = []): Hecho
    {
        return $this->hecho('estadia', $cliente, $ingreso, $medidas, $salida, $this->datosEstadia($medidas, $ingreso, $salida, $datos, $tramos), $origen);
    }

    /**
     * @param  array<string, int>  $medidas
     * @param  array<string, mixed>  $datos
     * @param  list<array{0: array<string, int>, 1: string, 2: ?string}>  $tramos
     * @return array<string, mixed>
     */
    private function datosEstadia(array $medidas, string $ingreso, ?string $salida, array $datos = [], array $tramos = []): array
    {
        $tramos = $tramos === [] ? [[$medidas, $ingreso, $salida]] : $tramos;

        return [
            ...$datos,
            'tramos' => array_map(fn (array $tramo): array => [
                'desde_at' => $this->enUtc($tramo[1])?->toIso8601String(),
                'hasta_at' => $this->enUtc($tramo[2])?->toIso8601String(),
                'medidas' => $tramo[0],
                'repaletizaje_inicio' => null,
            ], $tramos),
        ];
    }

    /**
     * Otra versión del hecho, como la registra la captura cuando la operación lo corrige o lo
     * anula: la anterior deja de ser vigente.
     *
     * @param  array<string, mixed>  $cambios
     */
    private function corregir(Hecho $anterior, array $cambios, string $motivo = Hecho::MOTIVO_CAMBIO): Hecho
    {
        Hecho::query()->whereKey($anterior->id)->update(['vigente' => false]);
        $nueva = $anterior->replicate();
        $nueva->forceFill([...$cambios, 'version' => $anterior->version + 1, 'vigente' => true, 'motivo_version' => $motivo, 'capturado_at' => now()]);
        $nueva->save();

        return $nueva;
    }
}
