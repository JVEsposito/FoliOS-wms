<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupervisionMaterialesTest extends TestCase
{
    private string $temporada;

    private string $cliente;

    private string $item;

    private string $almacen;

    protected function setUp(): void
    {
        parent::setUp();
        // Conexión privada: no altera la base completa que prepara el CI.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $tablas = [
            'temporadas' => ['id', 'nombre', 'activa', 'fecha_inicio'],
            'temporadas_materiales' => ['id', 'temporada_id'],
            'clientes' => ['id', 'nombre'],
            'clientes_materiales' => ['id', 'temporada_material_id', 'cliente_id', 'nombre'],
            'items_materiales' => ['id', 'cliente_material_id', 'codigo', 'nombre', 'unidad_medida'],
            'users' => ['id', 'name', 'rol', 'activo'],
            'perfiles_acceso' => ['id', 'rol_base', 'activo', 'modulos'],
            'destinos_materiales' => ['id', 'nombre'],
            'folios' => ['id', 'temporada_id', 'numero_folio', 'activo', 'estado_operacional'],
            'folios_materiales' => ['folio_id', 'item_material_id', 'unidad_medida', 'motivo_bloqueo', 'fecha_vencimiento'],
            'saldos_materiales_almacenes' => ['id', 'folio_id', 'almacen_material_id', 'cantidad_actual', 'cantidad_reservada', 'version'],
            'movimientos_inventario_materiales' => ['id', 'folio_id', 'item_material_id', 'tipo', 'cantidad', 'cantidad_anterior', 'cantidad_resultante', 'user_id', 'motivo', 'ocurrido_at', 'despacho_material_id', 'retiro_material_id'],
            'movimientos_almacenes_materiales' => ['id', 'folio_id', 'item_material_id', 'tipo', 'cantidad', 'user_id', 'motivo', 'ocurrido_at', 'despacho_material_id', 'retiro_material_id', 'almacen_origen_id', 'almacen_destino_id', 'documento_relacionado', 'operacion_id', 'saldo_origen_anterior', 'saldo_origen_resultante', 'saldo_destino_anterior', 'saldo_destino_resultante'],
            'retiros_materiales' => ['id', 'operacion_retiro_material_id'],
            'operaciones_retiro_materiales' => ['id', 'fecha_despacho', 'numero_documento', 'fecha_documento', 'provisional', 'registro_retroactivo', 'fecha_limite_regularizacion', 'autorizado_por_user_id', 'autorizado_at', 'motivo_autorizacion', 'regularizado_por_user_id', 'regularizado_at', 'motivo_regularizacion'],
            'recepciones_materiales' => ['id', 'temporada_id', 'cliente_id', 'numero_guia_despacho', 'estado'],
            'eventos_recepciones_materiales' => ['id', 'recepcion_material_id', 'tipo', 'user_id', 'ocurrido_at', 'observacion', 'datos'],
            'eliminaciones_recepciones_materiales' => ['id', 'recepcion_material_id_original', 'temporada_id', 'cliente_id', 'numero_guia_despacho', 'eliminado_por_user_id', 'eliminado_at', 'motivo', 'snapshot'],
            'tomas_inventario_materiales' => ['id', 'temporada_id', 'estado', 'aprobada_por_user_id', 'aprobada_at', 'motivo_anulacion'],
            'tomas_inventario_materiales_posiciones' => ['id', 'toma_id', 'posicion_id', 'user_id', 'contada_at'],
            'tomas_inventario_materiales_resultados' => ['id', 'toma_posicion_id', 'vigente', 'numero_folio', 'item_material_id', 'unidad_medida', 'tipo', 'cantidad_esperada', 'cantidad_contada', 'diferencia', 'accion', 'motivo', 'movimiento_almacen_id'],
        ];
        foreach ($tablas as $tabla => $columnas) {
            Schema::create($tabla, function (Blueprint $t) use ($columnas) {
                foreach ($columnas as $c) {
                    if (str_starts_with($c, 'cantidad') || str_starts_with($c, 'saldo_') || $c === 'diferencia') {
                        $t->decimal($c, 14, 3)->nullable();
                    } else {
                        $t->string($c)->nullable();
                    }
                }
            });
        }
        $this->temporada = $this->insertar('temporadas', ['nombre' => '2026', 'activa' => true, 'fecha_inicio' => '2026-01-01']);
        $tm = $this->insertar('temporadas_materiales', ['temporada_id' => $this->temporada]);
        $this->cliente = $this->insertar('clientes', ['nombre' => 'Exportadora']);
        $cm = $this->insertar('clientes_materiales', ['temporada_material_id' => $tm, 'cliente_id' => $this->cliente, 'nombre' => 'Exportadora']);
        $this->item = $this->insertar('items_materiales', ['cliente_material_id' => $cm, 'codigo' => 'FILM', 'nombre' => 'Film', 'unidad_medida' => 'kg']);
        $this->almacen = $this->insertar('destinos_materiales', ['nombre' => 'Central']);
        DB::table('users')->insert(['id' => 1, 'name' => 'Ana Pérez', 'rol' => 'supervisor_materiales', 'activo' => true]);
        $this->actingAs(User::findOrFail(1));
    }

    private function insertar(string $tabla, array $datos): string
    {
        $id = (string) Str::uuid();
        DB::table($tabla)->insert(['id' => $id, ...$datos]);

        return $id;
    }

    private function consultar(string $vista, array $filtros = [])
    {
        return $this->getJson('/api/materiales/supervision?'.http_build_query([
            'vista' => $vista, 'temporada_id' => $this->temporada,
            ...($vista === 'stock' ? [] : ['desde' => '2026-10-22', 'hasta' => '2026-10-22']), ...$filtros,
        ]));
    }

    private function stock(float $cantidad = 100, float $reserva = 20, ?string $bloqueo = null, string $unidad = 'kg'): string
    {
        $folio = $this->insertar('folios', ['numero_folio' => 'F-'.Str::random(8), 'temporada_id' => $this->temporada, 'activo' => true, 'estado_operacional' => 'disponible']);
        DB::table('folios_materiales')->insert(['folio_id' => $folio, 'item_material_id' => $this->item, 'unidad_medida' => $unidad, 'motivo_bloqueo' => $bloqueo]);
        $this->insertar('saldos_materiales_almacenes', ['folio_id' => $folio, 'almacen_material_id' => $this->almacen, 'cantidad_actual' => $cantidad, 'cantidad_reservada' => $reserva, 'version' => 1]);

        return $folio;
    }

    public function test_stock_distribuido_no_duplica_folios_ni_mezcla_unidades_y_separa_bloqueo_de_reserva(): void
    {
        $folio = $this->stock();
        $otro = $this->insertar('destinos_materiales', ['nombre' => 'Línea']);
        $this->insertar('saldos_materiales_almacenes', ['folio_id' => $folio, 'almacen_material_id' => $otro, 'cantidad_actual' => 30, 'cantidad_reservada' => 0]);
        $this->stock(40, 5, 'Revisión');
        $this->stock(7, 0, null, 'unidades');
        $this->stock(0, 0);
        $r = $this->consultar('stock')->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('resumen.folios', 3);
        $kg = collect($r->json('resumen.unidades'))->firstWhere('unidad', 'kg');
        $this->assertEquals(170, $kg['actual']);
        $this->assertEquals(25, $kg['reservada']);
        $this->assertEquals(40, $kg['bloqueada']);
        $this->assertEquals(110, $kg['disponible']);
        $this->consultar('stock', ['almacen_id' => $otro])->assertOk()->assertJsonPath('meta.total', 1);
        $this->consultar('stock', ['cliente_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('meta.total', 0);
        $this->consultar('stock', ['busqueda' => '%'])->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_indicadores_agregan_todas_las_paginas_y_excluyen_inactivos_y_otras_temporadas(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->stock(1, 0);
        }
        $inactivo = $this->stock(900, 0);
        DB::table('folios')->where('id', $inactivo)->update(['activo' => false]);
        $pasado = $this->stock(800, 0);
        DB::table('folios')->where('id', $pasado)->update(['temporada_id' => (string) Str::uuid()]);
        $this->consultar('stock')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 26)->assertJsonPath('meta.paginas', 2)->assertJsonPath('resumen.folios', 26);
        $this->consultar('stock', ['page' => 2])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('resumen.unidades.0.actual', 26);
    }

    public function test_vencimientos_y_bloqueo_operativo_excluyen_disponible(): void
    {
        $vencido = $this->stock(12, 2);
        DB::table('folios_materiales')->where('folio_id', $vencido)->update(['fecha_vencimiento' => '2020-01-01']);
        $bloqueado = $this->stock(8, 1);
        DB::table('folios')->where('id', $bloqueado)->update(['estado_operacional' => 'bloqueado']);
        $r = $this->consultar('stock')->assertOk();
        $this->assertEquals(20, $r->json('resumen.unidades.0.bloqueada'));
        $this->assertEquals(0, $r->json('resumen.unidades.0.disponible'));
    }

    public function test_periodo_chileno_inclusivo_y_fuentes_independientes_con_usuario_y_tipo(): void
    {
        $folio = $this->stock();
        foreach (['2026-10-22 02:59:59', '2026-10-22 03:00:00', '2026-10-23 02:59:59', '2026-10-23 03:00:00'] as $fecha) {
            $this->insertar('movimientos_inventario_materiales', ['folio_id' => $folio, 'item_material_id' => $this->item, 'tipo' => 'despacho', 'cantidad' => -10, 'user_id' => 1, 'ocurrido_at' => $fecha]);
        }
        $otro = $this->insertar('destinos_materiales', ['nombre' => 'Producción']);
        $this->insertar('movimientos_almacenes_materiales', ['folio_id' => $folio, 'item_material_id' => $this->item, 'tipo' => 'entrega', 'cantidad' => 10, 'almacen_origen_id' => $this->almacen, 'almacen_destino_id' => $otro, 'user_id' => 1, 'ocurrido_at' => '2026-10-22 12:00:00']);
        $this->consultar('inventario')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.usuario', 'Ana Pérez')->assertJsonPath('resumen.tipos.0.registros', 2);
        $this->consultar('almacenes', ['almacen_id' => $otro])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.destino', 'Producción');
        $this->consultar('inventario', ['user_id' => 2])->assertOk()->assertJsonPath('meta.total', 0);
        $this->consultar('inventario', ['tipo' => 'ingreso'])->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_movimiento_distingue_entrega_fisica_documento_y_autorizacion_sin_duplicar_cantidades(): void
    {
        $folio = $this->stock();
        $entrega = $this->insertar('operaciones_retiro_materiales', [
            'fecha_despacho' => '2026-10-20 13:30:00', 'numero_documento' => 'VALE-901', 'fecha_documento' => '2026-10-20',
            'provisional' => true, 'registro_retroactivo' => true, 'fecha_limite_regularizacion' => '2026-10-26 02:59:59',
            'autorizado_por_user_id' => 1, 'autorizado_at' => '2026-10-22 12:00:00', 'motivo_autorizacion' => 'Conteo físico verificado',
            'regularizado_por_user_id' => 1, 'regularizado_at' => '2026-10-22 13:00:00', 'motivo_regularizacion' => 'Documento recibido',
        ]);
        $retiro = $this->insertar('retiros_materiales', ['operacion_retiro_material_id' => $entrega]);
        $this->insertar('movimientos_almacenes_materiales', ['folio_id' => $folio, 'item_material_id' => $this->item, 'tipo' => 'entrega', 'cantidad' => 4, 'user_id' => 1, 'ocurrido_at' => '2026-10-22 12:00:00', 'retiro_material_id' => $retiro]);
        $this->consultar('almacenes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.numero_documento', 'VALE-901')->assertJsonPath('data.0.fecha_documento', '2026-10-20')
            ->assertJsonPath('data.0.fecha_despacho', '2026-10-20 13:30:00')->assertJsonPath('data.0.fecha', '2026-10-22 12:00:00')
            ->assertJsonPath('data.0.autorizado_por', 'Ana Pérez')->assertJsonPath('data.0.regularizado_por', 'Ana Pérez')
            ->assertJsonPath('resumen.tipos.0.registros', 1);
        $this->assertEquals(4, DB::table('movimientos_almacenes_materiales')->sum('cantidad'));
    }

    public function test_auditoria_conserva_eliminaciones_sin_recepcion_y_solo_expone_evidencia_permitida(): void
    {
        $recepcion = $this->insertar('recepciones_materiales', ['temporada_id' => $this->temporada, 'cliente_id' => $this->cliente, 'numero_guia_despacho' => 'G-100', 'estado' => 'confirmada']);
        $evento = ['tipo' => 'corregida_administrativamente', 'user_id' => 1, 'ocurrido_at' => '2026-10-22 13:00:00', 'observacion' => 'Cantidad corregida', 'datos' => ['payload_hash' => 'SECRETO', 'estado_anterior' => 'confirmada', 'folios_resultantes' => ['F-1']]];
        $id = $this->insertar('eventos_recepciones_materiales', ['recepcion_material_id' => $recepcion, ...$evento, 'datos' => json_encode($evento['datos'])]);
        $eliminacion = $this->insertar('eliminaciones_recepciones_materiales', ['recepcion_material_id_original' => (string) Str::uuid(), 'temporada_id' => $this->temporada, 'cliente_id' => $this->cliente, 'numero_guia_despacho' => 'G-200', 'eliminado_por_user_id' => 1, 'eliminado_at' => '2026-10-22 14:00:00', 'motivo' => 'Carga duplicada', 'snapshot' => json_encode(['cabecera' => ['estado' => 'anulada', 'patente' => 'SECRETO'], 'eventos' => [$evento], 'fotos' => ['ruta' => 'SECRETO']])]);
        $this->consultar('recepciones')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.tipo', 'eliminada');
        $this->consultar('recepciones', ['tipo' => 'eliminada', 'busqueda' => 'G-200'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/materiales/supervision/evidencia/evento/$id")->assertOk()->assertJsonPath('data.evento.folios_resultantes.0', 'F-1')->assertDontSee('SECRETO');
        $this->getJson("/api/materiales/supervision/evidencia/eliminacion/$eliminacion")->assertOk()->assertJsonPath('data.eventos_total', 1)->assertJsonPath('data.eventos.0.usuario', 'Ana Pérez')->assertDontSee('SECRETO');
        $this->getJson('/api/materiales/supervision/evidencia/eliminacion/'.Str::uuid())->assertNotFound();
    }

    public function test_cambio_horario_chileno_no_extiende_el_periodo_a_la_jornada_siguiente(): void
    {
        $folio = $this->stock();
        foreach (['2026-09-06 03:59:59', '2026-09-06 04:00:00', '2026-09-07 02:59:59', '2026-09-07 03:00:00', '2026-09-07 03:30:00'] as $fecha) {
            $this->insertar('movimientos_inventario_materiales', ['folio_id' => $folio, 'item_material_id' => $this->item, 'tipo' => 'ingreso', 'cantidad' => 1, 'user_id' => 1, 'ocurrido_at' => $fecha]);
        }
        $this->consultar('inventario', ['desde' => '2026-09-06', 'hasta' => '2026-09-06'])->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_diferencias_excluyen_reconteo_anterior_y_no_netean_faltantes_con_sobrantes(): void
    {
        $toma = $this->insertar('tomas_inventario_materiales', ['temporada_id' => $this->temporada, 'estado' => 'en_revision']);
        $posicion = $this->insertar('tomas_inventario_materiales_posiciones', ['toma_id' => $toma, 'user_id' => 1, 'contada_at' => '2026-10-22 15:00:00']);
        foreach ([[-4, true, $this->item], [3, true, null], [-100, false, $this->item], [0, true, $this->item]] as [$diferencia, $vigente, $item]) {
            $this->insertar('tomas_inventario_materiales_resultados', ['toma_posicion_id' => $posicion, 'vigente' => $vigente, 'item_material_id' => $item, 'numero_folio' => 'F-INV', 'unidad_medida' => 'kg', 'cantidad_esperada' => 10, 'cantidad_contada' => 10 + $diferencia, 'diferencia' => $diferencia, 'tipo' => $diferencia === 0 ? 'coincide' : 'diferencia_cantidad']);
        }
        $r = $this->consultar('diferencias')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.usuario', 'Ana Pérez');
        $this->assertEquals(4, $r->json('resumen.unidades.0.faltante'));
        $this->assertEquals(3, $r->json('resumen.unidades.0.sobrante'));
        $this->consultar('diferencias', ['cliente_id' => $this->cliente])->assertOk()->assertJsonPath('meta.total', 1);
        $this->consultar('diferencias', ['estado' => 'aprobada'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->consultar('diferencias', ['estado' => 'en_revision'])->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_filtros_invalidos_o_incompatibles_no_se_ignoran(): void
    {
        $this->consultar('inventario', ['hasta' => '2026-10-21'])->assertUnprocessable();
        $this->consultar('inventario', ['desde' => '2024-01-01'])->assertUnprocessable();
        $this->consultar('inventario', ['almacen_id' => $this->almacen])->assertUnprocessable();
        $this->consultar('stock', ['desde' => '2026-10-22', 'hasta' => '2026-10-22'])->assertUnprocessable();
        $this->consultar('stock', ['temporada_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->consultar('stock', ['page' => 0])->assertUnprocessable();
    }

    public function test_consulta_y_perfiles_respetan_modulo_e_inactividad_en_todas_las_lecturas(): void
    {
        $usuario = User::findOrFail(1);
        $usuario->rol = RolUsuario::Consulta;
        $this->actingAs($usuario);
        $this->getJson('/api/materiales/supervision/catalogos')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('password');
        $this->consultar('stock')->assertOk();
        $perfil = new PerfilAcceso;
        $perfil->forceFill(['id' => Str::uuid(), 'rol_base' => RolUsuario::Consulta, 'modulos' => ['materiales.resumen'], 'activo' => true]);
        $usuario->perfil_acceso_id = $perfil->id;
        $usuario->setRelation('perfilAcceso', $perfil);
        foreach (['/api/materiales/supervision/catalogos', '/api/materiales/supervision', '/api/materiales/supervision/evidencia/evento/'.Str::uuid()] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $perfil->modulos = ['materiales.inventario'];
        $this->consultar('stock')->assertOk();
        $usuario->activo = false;
        $this->consultar('stock')->assertForbidden();
    }
}
