<?php

namespace Tests\Feature\Comex;

use App\Comex\Configuracion\DocumentosSugeridos;
use App\Comex\Models\TipoDocumento;
use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Registros operativos mínimos para probar lo que Comex lee: embarques del calendario con sus
 * instructivos y cargas. Se insertan como los dejan los flujos reales, sin pasar por sus
 * servicios (Comex solo lee tablas). Datos ficticios.
 */
trait PreparaOperacionComex
{
    protected array $connectionsToTransact = [null, 'comex'];

    private ?int $usuarioOperacionId = null;

    private ?string $temporadaComexId = null;

    private int $correlativoEmbarque = 0;

    protected function prepararComex(): void
    {
        // En pruebas, Comex lee por la conexión de la prueba para ver sus datos sin confirmar.
        config(['comex.conexion_lectura' => config('database.default')]);
    }

    protected function cargarSugeridos(): void
    {
        app(DocumentosSugeridos::class)->cargar($this->administradorComex());
    }

    protected function tipo(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('codigo', $codigo)->firstOrFail();
    }

    protected function administradorComex(): User
    {
        return User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
    }

    /** @param  list<string>  $modulos */
    protected function conPerfil(RolUsuario $rol, array $modulos): User
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'PERFIL_'.strtoupper(bin2hex(random_bytes(3))),
            'nombre' => 'Perfil de prueba',
            'rol_base' => $rol,
            'modulos' => $modulos,
            'modulos_tablet' => [],
            'activo' => true,
            'predeterminado' => false,
            'protegido' => false,
        ]);

        return User::factory()->create(['rol' => $rol, 'perfil_acceso_id' => $perfil->id, 'activo' => true]);
    }

    protected function usuarioOperacion(): int
    {
        return $this->usuarioOperacionId ??= User::factory()->create()->id;
    }

    protected function temporadaComex(): string
    {
        return $this->temporadaComexId ??= (string) DB::table('temporadas')->where('activa', true)->value('id');
    }

    protected function clienteOperacion(string $nombre): string
    {
        $id = (string) Str::uuid();
        DB::table('clientes')->insert([
            'id' => $id, 'codigo' => Str::upper(Str::random(6)), 'nombre' => $nombre, 'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Un embarque del calendario. $instructivos: lista de [iso2 del país destino, campos extra].
     *
     * @param  list<array{0: ?string, 1?: array<string, mixed>}>  $instructivos
     * @param  array<string, mixed>  $atributos
     */
    protected function embarque(string $clienteId, string $fecha, string $modalidad = 'maritimo', array $instructivos = [['CN']], array $atributos = []): string
    {
        $id = (string) Str::uuid();
        $this->correlativoEmbarque++;
        $this->sinLlaves(function () use ($id, $clienteId, $fecha, $modalidad, $instructivos, $atributos): void {
            DB::table('embarques')->insert([
                'id' => $id, 'temporada_id' => $this->temporadaComex(), 'cliente_id' => $clienteId,
                'codigo' => 'CX-'.str_pad((string) $this->correlativoEmbarque, 4, '0', STR_PAD_LEFT),
                'numero_correlativo' => $this->correlativoEmbarque, 'fecha_programada' => $fecha, 'hora_programada' => '09:00:00',
                'intervalo_minutos' => 60, 'modalidad' => $modalidad, 'estado' => 'confirmado', 'nave_vuelo' => 'NAVE DE PRUEBA',
                'contenedor' => 'TEST1234567', 'sello' => 'SELLO-01', 'version' => 1, 'creado_por_user_id' => $this->usuarioOperacion(),
                'actualizado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now(),
                ...$atributos,
            ]);
            foreach ($instructivos as $indice => $instructivo) {
                $pais = $instructivo[0] === null ? null : DB::table('paises')->where('iso_alpha2', $instructivo[0])->first(['id', 'nombre_es']);
                DB::table('instructivos_embarque')->insert([
                    'id' => (string) Str::uuid(), 'embarque_id' => $id, 'orden' => $indice + 1, 'numero_externo' => 'INS-'.($indice + 1),
                    'recibidor' => 'RECIBIDOR DE PRUEBA', 'destino_pais' => $pais->nombre_es ?? null, 'pais_destino_id' => $pais->id ?? null,
                    'cantidad_pallets' => 20, 'cantidad_cajas' => 2400, 'created_at' => now(), 'updated_at' => now(),
                    ...($instructivo[1] ?? []),
                ]);
            }
        });

        return $id;
    }

    /** Asigna una carga al embarque, con sus folios. Devuelve el id de la carga. */
    protected function cargaParaEmbarque(string $embarqueId, string $estado = 'en_preparacion', ?string $cerradaAt = null): string
    {
        $cargaId = (string) Str::uuid();
        $this->sinLlaves(function () use ($cargaId, $embarqueId, $estado, $cerradaAt): void {
            DB::table('cargas')->insert([
                'id' => $cargaId, 'temporada_id' => $this->temporadaComex(), 'codigo' => 'CG-'.Str::upper(Str::random(5)), 'estado' => $estado,
                'modalidad_salida' => 'anden', 'prioridad' => 'normal', 'creada_por_user_id' => $this->usuarioOperacion(),
                'actualizada_por_user_id' => $this->usuarioOperacion(), 'cerrada_at' => $cerradaAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('embarques')->where('id', $embarqueId)->update(['carga_id' => $cargaId]);
        });

        return $cargaId;
    }

    /**
     * Un folio en la carga. $composicion: líneas del pallet (csg, cajas, envase...).
     *
     * @param  list<array<string, mixed>>  $composicion
     */
    protected function folioEnCarga(string $cargaId, string $numero, array $composicion, string $estado = 'en_anden'): string
    {
        $folioId = (string) Str::uuid();
        $this->sinLlaves(function () use ($folioId, $cargaId, $numero, $composicion, $estado): void {
            DB::table('folios')->insert([
                'id' => $folioId, 'temporada_id' => $this->temporadaComex(), 'numero_folio' => $numero, 'tipo_bulto' => 'pallet',
                'estado_operacional' => 'disponible', 'fecha_ingreso' => now(), 'activo' => true, 'variedad' => 'Santina',
                'calibre' => '2J', 'marca' => 'MARCA PRUEBA', 'exportadora' => 'EXPORTADORA DEMO', 'origen_sistema' => 'validacion',
                'estado_integracion' => 'local',
                'datos_externos' => json_encode([
                    'especie' => 'Cereza', 'envase' => 'Caja 5 kg', 'categoria' => 'Extra', 'predio' => 'PREDIO DEMO',
                    'cantidad_cajas' => array_sum(array_column($composicion, 'cantidad_cajas')), 'composicion' => $composicion,
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('carga_folios')->insert([
                'id' => (string) Str::uuid(), 'carga_id' => $cargaId, 'folio_id' => $folioId, 'estado' => $estado,
                'asignado_por_user_id' => $this->usuarioOperacion(), 'asignado_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $folioId;
    }

    protected function envaseConKilos(string $nombre, string $kilos): string
    {
        $id = (string) Str::uuid();
        $this->sinLlaves(fn () => DB::table('envases_validacion')->insert([
            'id' => $id, 'especie_validacion_id' => (string) Str::uuid(), 'cliente_validacion_id' => (string) Str::uuid(),
            'nombre' => $nombre, 'activo' => true, 'kilos_netos_por_caja' => $kilos, 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    protected function hoy(int $dias = 0): string
    {
        return CarbonImmutable::now(config('app.operational_timezone'))->addDays($dias)->toDateString();
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
}
