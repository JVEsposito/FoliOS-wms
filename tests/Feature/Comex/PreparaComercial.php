<?php

namespace Tests\Feature\Comex;

use App\Comex\Comercial\NotasVenta;
use App\Comex\Models\ParteComercial;
use Illuminate\Support\Str;

trait PreparaComercial
{
    protected function datosNota(): array
    {
        $parte = ParteComercial::query()->create(['id' => (string) Str::uuid(), 'codigo' => 'TEST_'.Str::upper(Str::random(8)), 'pais_iso2' => 'US', 'tax_id' => Str::uuid(), 'identidad_fiscal' => Str::uuid(), 'razon_social' => 'Comercial ficticia 商业', 'direccion' => 'Domicilio de prueba 123', 'ciudad' => 'Ciudad de prueba', 'roles' => [...NotasVenta::ROLES, 'notify', 'naviera', 'forwarder'], 'activo' => true, 'version' => 1, 'respaldo' => 'Antecedentes de prueba verificados']);

        return ['operacion_id' => (string) Str::uuid(), 'version' => 0, 'folio' => 'NV-'.Str::upper(Str::random(8)), 'cliente_operacion_id' => $this->clienteOperacion('Cliente comercial ficticio'), 'gestion' => 'servicio', 'fecha' => '2026-10-01', 'vigente_hasta' => '2027-03-31', 'temporada_referencia' => '2026-2027', 'referencia_pedido' => 'Pedido comercial de prueba', 'negocio' => 'firme', 'moneda' => 'USD', 'incoterm' => 'FOB', 'lugar_incoterm' => 'Puerto de salida de prueba', 'modalidad' => 'maritimo', 'condicion_pago' => 'Transferencia a 30 días del embarque', 'contrata_flete_id' => $parte->id, 'partes' => [...array_fill_keys(NotasVenta::ROLES, $parte->id), 'notify' => [$parte->id]], 'lineas' => [['sku' => 'CEREZA-5', 'descripcion' => 'Cereza de prueba', 'especie' => 'Cereza', 'variedad' => 'Santina', 'calibre' => '2J', 'categoria' => 'Extra', 'embalaje' => 'Caja 5 kg', 'unidad' => 'caja', 'cantidad' => '120', 'kilos' => '600', 'precio' => '10.125']], 'respaldo' => 'Acuerdo comercial documentado de prueba'];
    }

    protected function pasoNota(array $data, string $accion, ?string $operacion = null): array
    {
        return $this->postJson('/api/comex/comercial/notas/'.$data['nota']['id'].'/transiciones', ['operacion_id' => $operacion ?? (string) Str::uuid(), 'version' => $data['nota']['version'], 'accion' => $accion, 'motivo' => 'Decisión comercial documentada de prueba'])->assertOk()->json('data');
    }
}
