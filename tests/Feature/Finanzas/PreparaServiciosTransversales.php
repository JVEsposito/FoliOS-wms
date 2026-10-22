<?php

namespace Tests\Feature\Finanzas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Evidencias operativas mínimas; Finanzas nunca invoca servicios que modifiquen la operación. */
trait PreparaServiciosTransversales
{
    private function materialServicio(string $cliente, string $actual = '0'): array
    {
        $folio = $this->folio('MAT-BODEGA', $cliente, tipoBulto: 'material');
        $cm = (string) Str::uuid();
        $item = (string) Str::uuid();
        $this->sinLlaves(function () use ($cliente, $actual, $folio, $cm, $item): void {
            DB::table('folios')->where('id', $folio)->update(['created_at' => $this->utc('2026-11-01 09:00')]);
            DB::table('clientes_materiales')->insert(['id' => $cm, 'temporada_material_id' => (string) Str::uuid(), 'cliente_id' => $cliente, 'codigo' => 'MAT-SERVICIO', 'nombre' => 'Cliente materiales', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('items_materiales')->insert(['id' => $item, 'cliente_material_id' => $cm, 'codigo' => 'CAJA-SERVICIO', 'nombre' => 'Caja', 'unidad_medida' => 'caja', 'creado_por_user_id' => $this->usuarioOperacion(), 'actualizado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('folios_materiales')->insert(['folio_id' => $folio, 'item_material_id' => $item, 'cantidad_inicial' => 120, 'cantidad_actual' => $actual, 'unidad_medida' => 'caja', 'created_at' => now(), 'updated_at' => now()]);
        });

        return compact('folio', 'item');
    }

    private function kardexServicio(array $material, string $at, string $antes, string $despues): string
    {
        $id = (string) Str::uuid();
        DB::table('movimientos_inventario_materiales')->insert(['id' => $id, 'folio_id' => $material['folio'], 'item_material_id' => $material['item'], 'tipo' => 'retiro', 'cantidad' => abs((float) $despues - (float) $antes), 'cantidad_anterior' => $antes, 'cantidad_resultante' => $despues, 'user_id' => $this->usuarioOperacion(), 'ocurrido_at' => $this->utc($at), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function loteServicio(string $cliente, string $numero): string
    {
        $id = (string) Str::uuid();
        $this->sinLlaves(fn () => DB::table('lotes_materia_prima')->insert([
            'id' => $id, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'segmento_validacion_mp_id' => (string) Str::uuid(), 'recepcion_romana_id' => (string) Str::uuid(),
            'temporada_id' => $this->temporadaFinanzas(), 'cliente_id' => $cliente, 'numero_lote' => $numero, 'estado' => 'confirmado',
            'csg_validacion_id' => (string) Str::uuid(), 'csg_snapshot' => '100001', 'sdp' => 'SDP', 'ggn' => '1234567890123', 'fecha_cosecha' => '2026-11-01', 'predio' => 'Predio',
            'especie_validacion_id' => (string) Str::uuid(), 'especie_snapshot' => 'Cereza', 'variedad_validacion_id' => (string) Str::uuid(), 'variedad_snapshot' => 'Lapins',
            'calibre_validacion_id' => (string) Str::uuid(), 'calibre_snapshot' => '2J', 'cuartel' => '1', 'tipo_producto' => 'comercial', 'envase_primario' => 'bins',
            'cantidad_envases_primarios' => 50, 'kilos_brutos' => 10000, 'kilos_netos_calculados' => 10000, 'kilos_netos_confirmados' => 10000,
            'creado_por_user_id' => $this->usuarioOperacion(), 'actualizado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function entregaServicio(string $lote, string $orden, string $kilos): string
    {
        $id = (string) Str::uuid();
        $this->sinLlaves(fn () => DB::table('entregas_fruta_proceso')->insert([
            'id' => $id, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'lote_materia_prima_id' => $lote,
            'asignacion_camara_lote_id' => (string) Str::uuid(), 'camara_id' => (string) Str::uuid(), 'cantidad_envases' => 50, 'kilos_enviados' => $kilos,
            'saldo_anterior' => 50, 'saldo_posterior' => 0, 'linea_proceso' => '600', 'turno' => 'A', 'numero_orden' => $orden,
            'entregado_por_user_id' => $this->usuarioOperacion(), 'entregado_at' => $this->utc('2026-11-01 10:00'), 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function binServicio(array $origenes): string
    {
        $id = (string) Str::uuid();
        $this->sinLlaves(function () use ($id, $origenes): void {
            $tipo = DB::table('tipos_resultado_packing')->value('id');
            DB::table('bins_retorno_packing')->insert([
                'id' => $id, 'temporada_id' => $this->temporadaFinanzas(), 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
                'folio_provisional' => 'PROV-'.$id, 'folio_definitivo' => 'DEF-'.$id, 'kilos_totales' => array_sum(array_column($origenes, 'kilos')),
                'kilos_totales_definitivos' => array_sum(array_column($origenes, 'kilos')), 'tipo_resultado_packing_id' => $tipo, 'estado' => 'regularizado',
                'registrado_por_user_id' => $this->usuarioOperacion(), 'registrado_at' => $this->utc('2026-12-02 10:00'), 'regularizado_at' => $this->utc('2026-12-02 12:00'), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($origenes as $origen) {
                DB::table('bin_retorno_packing_origenes')->insert([
                    'id' => (string) Str::uuid(), 'bin_retorno_packing_id' => $id, 'lote_materia_prima_id' => $origen['lote'], 'numero_lote' => 'Lote',
                    'numero_orden' => $origen['orden'], 'linea_proceso' => '600', 'turno' => 'A', 'clave_proceso' => hash('sha256', $origen['lote'].'|'.$origen['orden'].'|600|A'),
                    'kilos_aportados' => $origen['kilos'], 'kilos_aportados_definitivos' => $origen['kilos'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return $id;
    }

    private function recetaServicio(string $cliente, string $cantidad = '120'): array
    {
        $receta = (string) Str::uuid();
        $version = (string) Str::uuid();
        $orden = (string) Str::uuid();
        $lote = (string) Str::uuid();
        $this->sinLlaves(function () use ($cliente, $cantidad, $receta, $version, $orden, $lote): void {
            DB::table('recetas_materiales')->insert(['id' => $receta, 'temporada_id' => $this->temporadaFinanzas(), 'cliente_id' => $cliente, 'item_salida_id' => (string) Str::uuid(), 'nombre' => 'Caja armada', 'activa' => true, 'creado_por_user_id' => $this->usuarioOperacion(), 'actualizado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('versiones_recetas_materiales')->insert(['id' => $version, 'receta_material_id' => $receta, 'numero_version' => 1, 'estado' => 'activa', 'cantidad_base_salida' => 1, 'unidad_medida_salida' => 'caja', 'snapshot' => '{}', 'creado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ordenes_transformacion_materiales')->insert(['id' => $orden, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'temporada_id' => $this->temporadaFinanzas(), 'cliente_id' => $cliente, 'version_receta_material_id' => $version, 'estado' => 'en_proceso', 'cantidad_planificada_salida' => $cantidad, 'cantidad_real_salida' => $cantidad, 'linea' => 'Armado', 'turno' => 'A', 'fecha_operacional' => '2026-11-01', 'snapshot_receta' => '{}', 'creado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lotes_transformacion_materiales')->insert(['id' => $lote, 'orden_transformacion_material_id' => $orden, 'numero_lote' => 1, 'estado' => 'cerrado', 'cantidad_planificada_salida' => $cantidad, 'cantidad_real_salida' => $cantidad, 'iniciado_at' => $this->utc('2026-11-01 10:00'), 'cerrado_at' => $this->utc('2026-11-01 12:00'), 'iniciado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return compact('receta', 'version', 'orden', 'lote');
    }
}
