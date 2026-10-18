<?php

namespace Tests\Feature\Finanzas;

use App\Finanzas\Captura\ServicioCapturaHechos;
use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Lectura;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Registros operativos mínimos para probar lo que Finanzas lee. Se insertan tal como los
 * dejan los flujos reales, sin pasar por sus servicios (Finanzas solo lee tablas).
 */
trait PreparaOperacionFinanzas
{
    protected array $connectionsToTransact = [null, 'finanzas'];

    private ?int $usuarioOperacionId = null;

    private ?string $temporadaFinanzasId = null;

    protected function prepararFinanzas(): void
    {
        // En pruebas, Finanzas lee por la conexión de la prueba para ver sus datos sin confirmar.
        config(['finanzas.conexion_lectura' => config('database.default')]);
    }

    protected function capturar(?array $fuentes = null, bool $conciliar = false): Lectura
    {
        return app(ServicioCapturaHechos::class)->capturar(Lectura::ORIGEN_MANUAL, null, $fuentes, $conciliar);
    }

    /** Ejecuta $mientras con la captura tomada por otra sesión (otro proceso). */
    protected function conCapturaEnOtraSesion(callable $mientras): void
    {
        config(['database.connections.finanzas_otra_sesion' => config('database.connections.finanzas')]);
        $otra = DB::connection('finanzas_otra_sesion');
        $this->assertSame(1, (int) $otra->scalar('SELECT GET_LOCK(?, 0)', [app(EsquemaFinanzas::class)->nombreCandado('captura-hechos')]));

        try {
            $mientras();
        } finally {
            $otra->disconnect();
            DB::purge('finanzas_otra_sesion');
        }
    }

    /** @return Collection<int, Hecho> */
    protected function hechos(string $fuente, bool $soloVigentes = true): Collection
    {
        return Hecho::query()
            ->where('fuente', $fuente)
            ->when($soloVigentes, fn ($consulta) => $consulta->where('vigente', true))
            ->orderBy('origen_id')
            ->orderBy('version')
            ->get();
    }

    protected function usuarioOperacion(): int
    {
        return $this->usuarioOperacionId ??= User::factory()->create()->id;
    }

    protected function temporadaFinanzas(): string
    {
        return $this->temporadaFinanzasId ??= (string) DB::table('temporadas')->where('activa', true)->value('id');
    }

    protected function clienteGlobal(string $nombre, ?string $codigo = null): string
    {
        $id = (string) Str::uuid();
        DB::table('clientes')->insert([
            'id' => $id, 'codigo' => $codigo ?? Str::upper(Str::random(6)), 'nombre' => $nombre, 'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    protected function camara(string $codigo, string $contenido = 'productos', string $tipo = 'almacenaje'): string
    {
        $id = (string) Str::uuid();
        DB::table('camaras')->insert([
            'id' => $id, 'codigo' => $codigo, 'nombre' => "Cámara {$codigo}", 'tipo' => $tipo, 'contenido' => $contenido,
            'estado' => 'activa', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    protected function folio(string $numero, ?string $clienteId, string $exportadora = 'EXPORTADORA DEMO', int $cajas = 120, string $tipoBulto = 'pallet', string $estado = 'disponible'): string
    {
        $id = (string) Str::uuid();
        $this->sinLlaves(function () use ($id, $numero, $clienteId, $exportadora, $cajas, $tipoBulto, $estado): void {
            DB::table('folios')->insert([
                'id' => $id, 'temporada_id' => $this->temporadaFinanzas(), 'numero_folio' => $numero, 'tipo_bulto' => $tipoBulto,
                'estado_operacional' => $estado, 'fecha_ingreso' => now(), 'activo' => true, 'variedad' => 'Santina',
                'calibre' => '2J', 'marca' => 'MARCA PRUEBA', 'exportadora' => $exportadora, 'origen_sistema' => 'validacion',
                'estado_integracion' => 'local',
                'datos_externos' => json_encode(['especie' => 'Cereza', 'envase' => 'Caja 5 kg', 'composicion' => [
                    ['csg' => '100001', 'cantidad_cajas' => $cajas],
                ]]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('trazabilidad_folio_origenes')->insert([
                'id' => (string) Str::uuid(), 'folio_id' => $id, 'temporada_id' => $this->temporadaFinanzas(), 'csg' => '100001',
                'cliente_id' => $clienteId, 'cantidad_cajas' => $cajas, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $id;
    }

    protected function actualizarFolio(string $folioId, array $cambios): void
    {
        DB::table('folios')->where('id', $folioId)->update([...$cambios, 'updated_at' => now()]);
    }

    protected function movimiento(string $folioId, ?string $origen, ?string $destino, string $hora, ?string $tipo = null): void
    {
        $tipo ??= match (true) {
            $origen === null => 'ubicacion_inicial',
            $destino === null => 'retiro',
            $origen === $destino => 'reubicacion',
            default => 'traslado_entre_camaras',
        };
        $this->sinLlaves(fn () => DB::table('movimientos')->insert([
            'id' => (string) Str::uuid(), 'operacion_id' => (string) Str::uuid(), 'folio_id' => $folioId, 'tipo_movimiento' => $tipo,
            'camara_origen_id' => $origen, 'camara_destino_id' => $destino, 'user_id' => $this->usuarioOperacion(),
            'dispositivo_id' => (string) Str::uuid(), 'generado_dispositivo_at' => $this->utc($hora),
            'recibido_servidor_at' => $this->utc($hora), 'created_at' => $this->utc($hora),
        ]));
    }

    protected function ubicar(string $folioId, string $camaraId, string $hora): void
    {
        DB::table('ubicaciones_actuales')->where('folio_id', $folioId)->delete();
        DB::table('ubicaciones_actuales')->insert([
            'id' => (string) Str::uuid(), 'folio_id' => $folioId, 'camara_id' => $camaraId, 'ubicado_at' => $this->utc($hora),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function quitarUbicacion(string $folioId): void
    {
        DB::table('ubicaciones_actuales')->where('folio_id', $folioId)->delete();
    }

    /** Despacha el folio en una carga nueva y lo deja como lo deja el cierre de despacho. */
    protected function despachar(string $folioId, string $hora, string $codigoCarga = 'CG-0001'): string
    {
        $cargaId = (string) Str::uuid();
        $id = (string) Str::uuid();
        $this->sinLlaves(function () use ($cargaId, $id, $folioId, $hora, $codigoCarga): void {
            DB::table('cargas')->insert([
                'id' => $cargaId, 'temporada_id' => $this->temporadaFinanzas(), 'codigo' => $codigoCarga, 'estado' => 'cerrada',
                'modalidad_salida' => 'anden', 'prioridad' => 'normal', 'creada_por_user_id' => $this->usuarioOperacion(),
                'actualizada_por_user_id' => $this->usuarioOperacion(), 'cerrada_at' => $this->utc($hora),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('carga_folios')->insert([
                'id' => $id, 'carga_id' => $cargaId, 'folio_id' => $folioId, 'estado' => 'despachado',
                'asignado_por_user_id' => $this->usuarioOperacion(), 'asignado_at' => $this->utc($hora),
                'finalizado_at' => $this->utc($hora), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->quitarUbicacion($folioId);
        $this->actualizarFolio($folioId, ['estado_operacional' => 'despachado']);

        return $id;
    }

    /** @param  array<string, mixed>  $atributos */
    protected function recepcionRomana(string $clienteId, string $numero, float $kilos, string $hora, array $atributos = []): string
    {
        $id = (string) Str::uuid();
        $this->sinLlaves(fn () => DB::table('recepciones_romana')->insert([
            'id' => $id, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'numero_recepcion' => $numero,
            'temporada_id' => $this->temporadaFinanzas(), 'temporada_codigo_snapshot' => 'GENERAL', 'temporada_nombre_snapshot' => 'General',
            'cliente_id' => $clienteId, 'cliente_nombre_snapshot' => 'PRODUCTOR DE PRUEBA SPA', 'tipo_recepcion' => 'fruta_con_envases',
            'tipo_servicio' => 'almacenaje', 'cantidad_envases_declarados' => 24, 'tipo_envase_declarado' => 'bins',
            'numero_guia_despacho' => "G-{$numero}", 'patente_camion' => 'AB1234', 'rut_conductor' => '11111111-1',
            'nombre_conductor' => 'Conductor de prueba', 'peso_neto' => $kilos, 'estado' => 'cerrado', 'estado_validacion_mp' => 'validada',
            'ingreso_at' => $this->utc($hora), 'salida_at' => $this->utc($hora), 'version' => 1, 'salida_sin_envases' => false,
            'cantidad_envases_pesados' => 0, 'creado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now(),
            ...$atributos,
        ]));

        return $id;
    }

    /**
     * Validación PT aprobada con su folio. El cliente de Validación puede venir sin vincular
     * al cliente global ($clienteGlobal null), como pasa con catálogos recién importados.
     */
    protected function validacion(string $numero, ?string $clienteGlobal, string $clienteValidacion = 'EXPORTADORA DEMO', int $cajas = 120, string $hora = '2026-11-01 08:00'): string
    {
        $temporada = $this->temporadaFinanzas();
        $clienteValidacionId = (string) Str::uuid();
        $origen = (string) Str::uuid();
        $articulo = (string) Str::uuid();
        $validacion = (string) Str::uuid();
        $folio = $this->folio($numero, $clienteGlobal, $clienteValidacion, $cajas);

        $this->sinLlaves(function () use ($temporada, $clienteValidacionId, $clienteGlobal, $clienteValidacion, $origen, $articulo, $validacion, $numero, $cajas, $folio, $hora): void {
            DB::table('clientes_validacion')->insert([
                'id' => $clienteValidacionId, 'cliente_id' => $clienteGlobal, 'temporada_id' => $temporada, 'nombre' => $clienteValidacion,
                'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('origenes_validacion')->insert([
                'id' => $origen, 'temporada_id' => $temporada, 'cliente_validacion_id' => $clienteValidacionId, 'cliente' => $clienteValidacion,
                'marca' => 'MARCA PRUEBA', 'csg' => '100001', 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('articulos_validacion')->insert([
                'id' => $articulo, 'temporada_id' => $temporada, 'especie' => 'Cereza', 'variedad' => 'Santina', 'calibre' => '2J',
                'envase' => 'Caja 5 kg', 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('validaciones_pallet')->insert([
                'id' => $validacion, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('d', 64), 'numero_folio' => $numero,
                'numero_intento' => 1, 'tipo_bulto' => 'pallet', 'cantidad_cajas' => $cajas, 'linea_proceso' => 1, 'turno' => 'A',
                'temporada_id' => $temporada, 'articulo_validacion_id' => $articulo, 'origen_validacion_id' => $origen,
                'resultado' => 'aprobado', 'estado' => 'aceptada', 'catalogo_version_dispositivo' => 1, 'catalogo_version_servidor' => 1,
                'snapshot' => '{}', 'user_id' => $this->usuarioOperacion(), 'dispositivo_id' => (string) Str::uuid(), 'folio_id' => $folio,
                'generado_dispositivo_at' => $this->utc($hora), 'recibido_servidor_at' => $this->utc($hora),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $validacion;
    }

    protected function sinLlaves(callable $insertar): mixed
    {
        Schema::disableForeignKeyConstraints();
        try {
            return $insertar();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** Hora de planta (America/Santiago) a UTC, como la guarda la operación. */
    protected function utc(string $hora): string
    {
        return CarbonImmutable::parse($hora, config('app.operational_timezone'))->utc()->format('Y-m-d H:i:s');
    }
}
