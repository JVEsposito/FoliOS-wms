<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\CategoriaValidacion;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\ClienteProveedorMaterial;
use App\Models\ClienteValidacion;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\PerfilAcceso;
use App\Models\ProveedorMaterial;
use App\Models\Temporada;
use App\Models\TemporadaMaterial;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministracionAccesoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_accesos_es_el_unico_dueno_del_cliente_y_propaga_sus_cambios(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $clienteId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/clientes', [
                'codigo' => 'AG-001',
                'nombre' => 'LA AGUADA',
                'codigo_externo' => 'ERP-AGUADA',
                'codigo_folio_materiales' => 'AG',
                'activo' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.codigo', 'AG-001')
            ->assertJsonPath('data.codigo_folio_materiales', 'AG')
            ->json('data.id');

        $this->assertDatabaseHas('clientes_materiales', [
            'cliente_id' => $clienteId,
            'codigo' => 'AG-001',
            'nombre' => 'LA AGUADA',
        ]);
        $this->assertDatabaseHas('clientes_validacion', [
            'cliente_id' => $clienteId,
            'codigo_externo' => 'AG-001',
            'nombre' => 'LA AGUADA',
        ]);

        $this->putJson("/api/administracion/clientes/{$clienteId}", [
            'codigo' => 'AG-002',
            'nombre' => 'LA AGUADA ACTUALIZADA',
            'codigo_externo' => 'ERP-AGUADA',
            'codigo_folio_materiales' => 'AG',
            'activo' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.codigo', 'AG-002');

        $this->assertDatabaseHas('aliases_clientes', [
            'cliente_id' => $clienteId,
            'codigo' => 'AG-001',
            'nombre' => 'LA AGUADA',
        ]);
        $this->assertSame(
            'AG-002',
            ClienteMaterial::query()->where('cliente_id', $clienteId)->firstOrFail()->codigo,
        );
        $this->assertSame(
            'LA AGUADA ACTUALIZADA',
            ClienteValidacion::query()->where('cliente_id', $clienteId)->firstOrFail()->nombre,
        );
        $this->getJson('/api/administracion/clientes')
            ->assertOk()
            ->assertJsonFragment(['codigo' => 'AG-001', 'origen' => 'edicion_accesos']);

        $this->postJson('/api/administracion/materiales/clientes', [])->assertStatus(405);
        $this->postJson('/api/administracion/validacion/clientes', [])->assertNotFound();

        $this->postJson('/api/administracion/clientes', [
            'codigo' => 'CLIENTE-NUMERICO',
            'nombre' => 'CLIENTE CON CÓDIGO CORTO INVÁLIDO',
            'codigo_folio_materiales' => 'A1',
            'activo' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigo_folio_materiales');
    }

    public function test_bodega_asocia_proveedores_a_clientes_globales_sin_duplicarlos(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $clienteIds = collect([
            ['AG-001', 'LA AGUADA'],
            ['DS-001', 'DISFRUTA'],
        ])->map(fn (array $cliente): string => $this
            ->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/clientes', [
                'codigo' => $cliente[0],
                'nombre' => $cliente[1],
                'activo' => true,
            ])
            ->assertCreated()
            ->json('data.id'));
        $categorias = $clienteIds->map(function (string $clienteId) use ($administrador): array {
            $catalogo = ClienteMaterial::query()
                ->where('cliente_id', $clienteId)
                ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))
                ->firstOrFail();
            ItemMaterial::create([
                'cliente_material_id' => $catalogo->id,
                'codigo' => $catalogo->codigo.'-EMBALAJE-PRV',
                'nombre' => 'Material de embalaje para proveedor',
                'categoria' => 'Embalaje',
                'categoria_operacional' => 'insumo',
                'unidad_medida' => 'unidades',
                'origen_sistema' => 'manual',
                'activo' => true,
                'creado_por_user_id' => $administrador->id,
                'actualizado_por_user_id' => $administrador->id,
            ]);

            return ['cliente_id' => $clienteId, 'categoria' => 'Embalaje'];
        });

        $proveedorId = $this->postJson('/api/administracion/materiales/proveedores', [
            'codigo' => 'PRV-001',
            'nombre' => 'Envases del Sur',
            'codigo_externo' => null,
            'activo' => true,
            'cliente_ids' => $clienteIds->all(),
            'categorias' => $categorias->all(),
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.clientes')
            ->assertJsonCount(2, 'data.categorias')
            ->json('data.id');

        $this->assertSame(
            2,
            ClienteProveedorMaterial::query()
                ->where('proveedor_material_id', $proveedorId)
                ->where('activo', true)
                ->count(),
        );
        $this->assertSame(2, Cliente::query()->whereIn('id', $clienteIds)->count());
        $this->getJson('/api/administracion/materiales/proveedores')
            ->assertOk()
            ->assertJsonPath('data.0.codigo', 'PRV-001');
    }

    public function test_proveedor_admite_categoria_comercial_pendiente_de_tipificacion_y_rechaza_entradas_malformadas(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $catalogo = ClienteMaterial::query()
            ->with('cliente')
            ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))
            ->firstOrFail();
        ItemMaterial::create([
            'cliente_material_id' => $catalogo->id,
            'codigo' => 'SIN-OPERACION-PROVEEDOR',
            'nombre' => 'Material sin categoría operacional',
            'categoria' => 'Solo no operacional',
            'categoria_operacional' => null,
            'unidad_medida' => 'unidades',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        $payload = [
            'codigo' => 'PROV-SIN-OPERACION',
            'nombre' => 'Proveedor sin material recepcionable',
            'codigo_externo' => null,
            'activo' => true,
            'cliente_ids' => [$catalogo->cliente_id],
            'categorias' => [[
                'cliente_id' => $catalogo->cliente_id,
                'categoria' => 'Solo no operacional',
            ]],
        ];

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/materiales/proveedores', $payload)
            ->assertCreated()
            ->assertJsonPath('data.categorias.0.categoria', 'SOLO NO OPERACIONAL');

        $payload['codigo'] = 'PROV-MALFORMADO';
        $payload['categorias'] = ['entrada-malformada'];
        $this->postJson('/api/administracion/materiales/proveedores', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'categorias.0.cliente_id',
                'categorias.0.categoria',
            ]);
    }

    public function test_el_administrador_crea_usuarios_y_tablets_autorizadas(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/usuarios', [
                'nombre' => '  Camila Operadora  ',
                'email' => '  CAMILA@EMPRESA.CL  ',
                'rol' => RolUsuario::CamareroFrio->value,
                'password' => 'Temporal2026',
                'password_confirmation' => 'Temporal2026',
            ])
            ->assertCreated()
            ->assertJsonPath('usuario.nombre', 'Camila Operadora')
            ->assertJsonPath('usuario.email', 'camila@empresa.cl')
            ->assertJsonPath('usuario.rol', RolUsuario::CamareroFrio->value)
            ->assertJsonPath('usuario.activo', true)
            ->assertJsonPath('usuario.debe_cambiar_password', true);

        $usuario = User::query()->where('email', 'camila@empresa.cl')->firstOrFail();
        $this->assertTrue(Hash::check('Temporal2026', $usuario->password));

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/dispositivos', [
                'codigo' => '  tablet-02  ',
                'nombre' => '  Tablet cámara norte  ',
            ])
            ->assertCreated()
            ->assertJsonPath('dispositivo.codigo', 'TABLET-02')
            ->assertJsonPath('dispositivo.nombre', 'Tablet cámara norte')
            ->assertJsonPath('dispositivo.plataforma', 'android')
            ->assertJsonPath('dispositivo.activo', true);

        $this->assertDatabaseHas('dispositivos', [
            'codigo' => 'TABLET-02',
            'nombre' => 'Tablet cámara norte',
            'activo' => true,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/accesos')
            ->assertOk()
            ->assertJsonCount(2, 'usuarios')
            ->assertJsonCount(1, 'dispositivos');
    }

    public function test_accesos_es_el_unico_dueno_de_la_temporada_transversal(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $temporada = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/temporadas', [
                ...$this->vigenciaProductiva(),
                'codigo' => ' 2026-2027 ',
                'nombre' => ' Temporada cerezas 2026-2027 ',
                'fecha_inicio' => '2026-10-01',
                'fecha_fin' => '2027-02-28',
                'activa' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.codigo', '2026-2027')
            ->assertJsonPath('data.activa', true)
            ->json('data');

        $this->assertNotNull($temporada['configuracion_material_id']);
        $this->assertDatabaseHas('temporadas_materiales', [
            'id' => $temporada['configuracion_material_id'],
            'temporada_id' => $temporada['id'],
            'activa' => true,
        ]);

        $nuevaId = $this->postJson('/api/administracion/temporadas', [
            ...$this->vigenciaProductiva(),
            'codigo' => '2027-2028',
            'nombre' => 'Temporada cerezas 2027-2028',
            'activa' => false,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/administracion/temporadas/{$nuevaId}/activar")
            ->assertOk()
            ->assertJsonPath('data.activa', true);

        $this->assertFalse(Temporada::query()->findOrFail($temporada['id'])->activa);
        $this->assertTrue(TemporadaMaterial::query()->where('temporada_id', $nuevaId)->firstOrFail()->activa);
        $this->getJson('/api/administracion/temporadas')
            ->assertOk()
            ->assertJsonPath('data.0.id', $nuevaId);

        $this->postJson('/api/administracion/validacion/temporadas', [])->assertNotFound();
        $this->postJson('/api/administracion/materiales/temporadas', [])->assertStatus(405);
    }

    public function test_un_usuario_no_administrador_no_puede_gestionar_accesos(): void
    {
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/administracion/accesos')
            ->assertForbidden();

        $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/administracion/usuarios', [
                'nombre' => 'Usuario no autorizado',
                'email' => 'sin-permiso@empresa.cl',
                'rol' => RolUsuario::CamareroFrio->value,
                'password' => 'Temporal2026',
                'password_confirmation' => 'Temporal2026',
            ])
            ->assertForbidden();

        $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/administracion/dispositivos', [
                'codigo' => 'TABLET-99',
                'nombre' => 'Tablet no autorizada',
            ])
            ->assertForbidden();

        $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/administracion/temporadas', [
                ...$this->vigenciaProductiva(),
                'codigo' => '2026-2027',
                'nombre' => 'Temporada no autorizada',
            ])
            ->assertForbidden();

        $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/administracion/clientes', [
                'codigo' => 'NO-AUTORIZADO',
                'nombre' => 'Cliente no autorizado',
                'activo' => true,
            ])
            ->assertForbidden();

        $temporada = Temporada::query()->firstOrFail();
        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$temporada->id}/migrar", [])
            ->assertForbidden();
    }

    public function test_administrador_migra_catalogos_e_inventario_y_activa_el_destino_global(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $origen = Temporada::query()->where('activa', true)->firstOrFail();
        $configuracionOrigen = $origen->configuracionMaterial()->firstOrFail();
        $clienteOrigen = $configuracionOrigen->clientes()->firstOrFail();
        $itemOrigen = ItemMaterial::create([
            'cliente_material_id' => $clienteOrigen->id,
            'codigo' => 'FILM-MIGRABLE',
            'nombre' => 'Film migrable',
            'categoria' => 'Embalaje',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'rollos',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        $proveedor = ProveedorMaterial::create([
            'codigo' => 'PROV-MIGRABLE',
            'nombre' => 'Proveedor migrable',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        ClienteProveedorMaterial::create([
            'cliente_id' => $clienteOrigen->cliente_id,
            'proveedor_material_id' => $proveedor->id,
            'activo' => true,
            'categorias' => ['Embalaje'],
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        CategoriaValidacion::create([
            'temporada_id' => $origen->id,
            'nombre' => 'Exportación',
            'activo' => true,
        ]);
        $folio = Folio::create([
            'temporada_id' => $origen->id,
            'numero_folio' => 'MAT-MIG-0001',
            'tipo_bulto' => TipoBulto::Material,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        FolioMaterial::create([
            'folio_id' => $folio->id,
            'item_material_id' => $itemOrigen->id,
            'cantidad_inicial' => 12.5,
            'cantidad_actual' => 10.5,
            'cantidad_reservada' => 0,
            'unidad_medida' => 'rollos',
        ]);

        $destino = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/temporadas', [
                ...$this->vigenciaProductiva(),
                'codigo' => '2027-2028',
                'nombre' => 'Temporada 2027-2028',
                'activa' => false,
            ])
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/administracion/temporadas/{$destino['id']}/migrar", [
            'temporada_origen_id' => $origen->id,
            'copiar_catalogo_validacion' => true,
            'copiar_catalogo_materiales' => true,
            'migrar_inventario_materiales' => true,
            'activar_destino' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.destino.activa', true)
            ->assertJsonPath('data.resumen.validacion.categorias', 1)
            ->assertJsonPath('data.resumen.materiales.items', 1)
            ->assertJsonPath('data.resumen.inventario.folios', 1)
            ->assertJsonPath('data.resumen.inventario.cantidad_total', 10.5);

        $configuracionDestino = TemporadaMaterial::query()
            ->where('temporada_id', $destino['id'])
            ->firstOrFail();
        $itemDestino = ItemMaterial::query()
            ->where('codigo', 'FILM-MIGRABLE')
            ->whereHas('cliente', fn ($consulta) => $consulta
                ->where('temporada_material_id', $configuracionDestino->id))
            ->firstOrFail();

        $this->assertSame(CategoriaOperacionalMaterial::Insumo, $itemDestino->categoria_operacional);
        $this->assertSame($itemDestino->id, $folio->material()->firstOrFail()->item_material_id);
        $this->assertSame($destino['id'], $folio->refresh()->temporada_id);
        $this->assertDatabaseHas('categorias_validacion', [
            'temporada_id' => $destino['id'],
            'nombre' => 'Exportación',
        ]);
        $this->assertDatabaseHas('migraciones_temporadas_folios', [
            'folio_id' => $folio->id,
            'item_material_origen_id' => $itemOrigen->id,
            'item_material_destino_id' => $itemDestino->id,
        ]);
        $this->assertFalse($origen->refresh()->activa);
        $this->assertTrue(Temporada::query()->findOrFail($destino['id'])->activa);

        $this->getJson('/api/materiales/recepciones/catalogos')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $proveedor->id,
                'codigo' => 'PROV-MIGRABLE',
            ])
            ->assertJsonFragment([
                'id' => $itemDestino->id,
                'categoria_operacional' => CategoriaOperacionalMaterial::Insumo->value,
            ]);
    }

    public function test_migracion_repara_configuraciones_de_materiales_ausentes_sin_bloquear_validacion(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $origen = Temporada::create([
            'codigo' => 'LEGACY-ORIGEN',
            'nombre' => 'Temporada heredada de origen',
            'activa' => false,
        ]);
        $destino = Temporada::create([
            'codigo' => 'LEGACY-DESTINO',
            'nombre' => 'Temporada heredada de destino',
            'activa' => false,
        ]);
        CategoriaValidacion::create([
            'temporada_id' => $origen->id,
            'nombre' => 'Catálogo heredado',
            'activo' => true,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$destino->id}/migrar", [
                'temporada_origen_id' => $origen->id,
                'copiar_catalogo_validacion' => true,
                'copiar_catalogo_materiales' => false,
                'migrar_inventario_materiales' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.resumen.validacion.categorias', 1);

        $this->assertDatabaseMissing('temporadas_materiales', [
            'temporada_id' => $origen->id,
        ]);
        $this->assertDatabaseMissing('temporadas_materiales', [
            'temporada_id' => $destino->id,
        ]);

        $clientesGlobalesActivos = Cliente::query()->where('activo', true)->count();
        $this->postJson("/api/administracion/temporadas/{$destino->id}/migrar", [
            'temporada_origen_id' => $origen->id,
            'copiar_catalogo_validacion' => false,
            'copiar_catalogo_materiales' => true,
            'migrar_inventario_materiales' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.resumen.materiales.clientes', $clientesGlobalesActivos)
            ->assertJsonPath('data.resumen.materiales.items', 0);

        $this->assertDatabaseHas('temporadas_materiales', [
            'temporada_id' => $origen->id,
            'codigo' => 'LEGACY-ORIGEN',
        ]);
        $this->assertDatabaseHas('temporadas_materiales', [
            'temporada_id' => $destino->id,
            'codigo' => 'LEGACY-DESTINO',
        ]);
    }

    public function test_valida_duplicados_formato_y_contrasena(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
            'email' => 'existente@empresa.cl',
        ]);
        Dispositivo::create([
            'codigo' => 'TABLET-01',
            'nombre' => 'Tablet existente',
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/usuarios', [
                'nombre' => 'Duplicado',
                'email' => 'EXISTENTE@EMPRESA.CL',
                'rol' => RolUsuario::CamareroFrio->value,
                'password' => 'solo-letras',
                'password_confirmation' => 'no-coincide',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/dispositivos', [
                'codigo' => 'tablet-01',
                'nombre' => 'Duplicada',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo']);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/dispositivos', [
                'codigo' => 'tablet con espacios',
                'nombre' => 'Formato inválido',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_informa_claramente_una_contrasena_demasiado_corta(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/usuarios', [
                'nombre' => 'Camarero de prueba',
                'email' => 'camarero@empresa.cl',
                'rol' => RolUsuario::CamareroFrio->value,
                'password' => 'Abc12',
                'password_confirmation' => 'Abc12',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.password.0',
                'La contraseña debe tener al menos 10 caracteres.',
            );
    }

    public function test_el_acceso_de_oficina_informa_el_permiso_administrativo(): void
    {
        User::factory()->create([
            'email' => 'admin@empresa.cl',
            'password' => 'password',
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $this->postJson('/api/acceso-oficina', [
            'email' => 'admin@empresa.cl',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.puede_administrar_accesos', true)
            ->assertJsonPath('usuario.puede_gestionar_andenes', true)
            ->assertJsonPath('usuario.capacidades.puede_gestionar_andenes', true);
    }

    public function test_administrador_crea_perfil_por_modulos_y_lo_asigna_a_un_usuario(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $respuestaCatalogo = $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/perfiles-acceso')
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'Gerencia y Administración'])
            ->assertJsonFragment(['clave' => 'materiales.ordenes'])
            ->assertJsonFragment([
                'clave' => CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
                'nombre' => 'Recepción de materiales',
            ])
            ->assertJsonFragment([
                'clave' => CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
                'nombre' => 'Cámara y operación de materiales',
            ])
            ->assertJsonFragment([
                'clave' => RolUsuario::Administrador->value,
                'nombre' => 'Administrador',
            ]);

        $this->assertCount(count(RolUsuario::cases()) + 1, $respuestaCatalogo->json('data'));
        $respuestaCatalogo->assertJsonFragment(['codigo' => 'CONTRAPARTE_SAG']);
        $this->assertCount(count(RolUsuario::cases()), $respuestaCatalogo->json('roles_base'));
        foreach ($respuestaCatalogo->json('roles_base') as $rolBase) {
            $this->assertContains('administracion.accesos', $rolBase['modulos_disponibles']);
            $this->assertContains('materiales.inventario', $rolBase['modulos_disponibles']);
        }

        $perfil = $this->postJson('/api/administracion/perfiles-acceso', [
            'codigo' => ' consulta_recepcion ',
            'nombre' => ' Consulta de recepción ',
            'descripcion' => 'Observación acotada de recepción',
            'rol_base' => RolUsuario::Consulta->value,
            'modulos' => [
                'gerencia.panel',
                'materia-prima.romana',
            ],
            'modulos_tablet' => [],
            'activo' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.codigo', 'CONSULTA_RECEPCION')
            ->assertJsonPath('data.modulos.0', 'gerencia.panel')
            ->assertJsonPath('data.modulos_tablet', [])
            ->json('data');

        $this->postJson('/api/administracion/usuarios', [
            'nombre' => 'Gerencia Recepción',
            'email' => 'gerencia.recepcion@empresa.cl',
            'perfil_acceso_id' => $perfil['id'],
            'password' => 'Temporal2026',
            'password_confirmation' => 'Temporal2026',
        ])
            ->assertCreated()
            ->assertJsonPath('usuario.rol', RolUsuario::Consulta->value)
            ->assertJsonPath('usuario.perfil.id', $perfil['id']);

        $this->postJson('/api/acceso-oficina', [
            'email' => 'gerencia.recepcion@empresa.cl',
            'password' => 'Temporal2026',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.modulos_acceso.0', 'gerencia.panel')
            ->assertJsonPath('usuario.modulos_acceso.1', 'materia-prima.romana')
            ->assertJsonPath('usuario.puede_consultar_panel_gerencial', true)
            ->assertJsonPath('usuario.puede_consultar_romana', true)
            ->assertJsonPath('usuario.puede_consultar_cargas', false)
            ->assertJsonPath('usuario.puede_consultar_despachos_materiales', false);
    }

    public function test_editar_un_perfil_revoca_sesiones_y_el_administrador_esta_protegido(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $perfil = PerfilAcceso::create([
            'codigo' => 'CONSULTA_OPERACIONAL',
            'nombre' => 'Consulta operacional',
            'rol_base' => RolUsuario::Consulta,
            'modulos' => ['gerencia.panel', 'materia-prima.romana'],
            'modulos_tablet' => [],
            'activo' => true,
            'protegido' => false,
        ]);
        $usuario = User::factory()->create([
            'rol' => RolUsuario::Consulta,
            'perfil_acceso_id' => $perfil->id,
            'activo' => true,
        ]);
        $usuario->createToken('sesion-anterior');

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/perfiles-acceso/{$perfil->id}", [
                'codigo' => 'CONSULTA_OPERACIONAL',
                'nombre' => 'Consulta operacional actualizada',
                'descripcion' => null,
                'rol_base' => RolUsuario::Consulta->value,
                'modulos' => ['gerencia.panel'],
                'modulos_tablet' => [],
                'activo' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.modulos.0', 'gerencia.panel')
            ->assertJsonPath('sesiones_revocadas', true);

        $this->assertCount(0, $usuario->tokens()->get());

        $administradorPerfil = PerfilAcceso::query()
            ->where('protegido', true)
            ->firstOrFail();
        $this->putJson("/api/administracion/perfiles-acceso/{$administradorPerfil->id}", [
            'codigo' => $administradorPerfil->codigo,
            'nombre' => 'Administrador modificado',
            'descripcion' => null,
            'rol_base' => RolUsuario::Consulta->value,
            'modulos' => ['gerencia.panel'],
            'modulos_tablet' => [],
            'activo' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'El perfil Administrador está protegido. Puedes modificar todos los demás perfiles.',
            );
    }

    public function test_administrador_puede_combinar_cualquier_modulo_en_un_perfil(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $perfil = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/perfiles-acceso', [
                'codigo' => 'VALIDADOR_MULTIAREA',
                'nombre' => 'Validador multiarea',
                'rol_base' => RolUsuario::Validador->value,
                'modulos' => ['frigorifico.validacion', 'materiales.inventario'],
                'modulos_tablet' => [
                    CatalogoModulosAcceso::TABLET_VALIDACION_PT,
                ],
                'activo' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.modulos.0', 'frigorifico.validacion')
            ->assertJsonPath('data.modulos.1', 'materiales.inventario')
            ->assertJsonPath(
                'data.modulos_tablet.0',
                CatalogoModulosAcceso::TABLET_VALIDACION_PT,
            )
            ->json('data');

        $usuario = User::factory()->create([
            'rol' => RolUsuario::Validador,
            'perfil_acceso_id' => $perfil['id'],
            'activo' => true,
        ]);

        $this->assertSame(
            ['frigorifico.validacion', 'materiales.inventario'],
            app(CatalogoModulosAcceso::class)->modulosUsuario($usuario),
        );
    }

    public function test_no_permite_habilitar_un_modulo_tablet_sin_su_oficina_relacionada(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/perfiles-acceso', [
                'codigo' => 'PDA_INCOMPATIBLE',
                'nombre' => 'PDA incompatible',
                'rol_base' => RolUsuario::CamareroMateriales->value,
                'modulos' => ['materiales.inventario'],
                'modulos_tablet' => [
                    CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
                ],
                'activo' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.modulos_tablet.0',
                'Cada módulo PDA/tablet necesita al menos una de sus oficinas relacionadas habilitada.',
            );
    }

    public function test_perfil_administrador_personalizado_puede_gestionar_usuarios(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $perfil = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/perfiles-acceso', [
                'codigo' => 'ADMIN_ACCESOS',
                'nombre' => 'Administrador de accesos',
                'rol_base' => RolUsuario::Administrador->value,
                'modulos' => ['administracion.accesos'],
                'modulos_tablet' => [],
                'activo' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.rol_base', RolUsuario::Administrador->value)
            ->assertJsonPath('data.modulos.0', 'administracion.accesos')
            ->json('data');

        $administradorDelegado = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'perfil_acceso_id' => $perfil['id'],
            'activo' => true,
        ]);

        $this->actingAs($administradorDelegado, 'sanctum')
            ->getJson('/api/administracion/accesos')
            ->assertOk();

        $this->postJson('/api/administracion/usuarios', [
            'nombre' => 'Usuario administrado',
            'email' => 'usuario.administrado@empresa.cl',
            'rol' => RolUsuario::Consulta->value,
            'password' => 'Temporal2026',
            'password_confirmation' => 'Temporal2026',
        ])
            ->assertCreated()
            ->assertJsonPath('usuario.email', 'usuario.administrado@empresa.cl');
    }

    public function test_no_crea_usuario_legacy_si_su_perfil_inicial_esta_inactivo(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        PerfilAcceso::query()
            ->where('rol_base', RolUsuario::Consulta->value)
            ->where('predeterminado', true)
            ->update(['activo' => false]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/usuarios', [
                'nombre' => 'Consulta sin perfil',
                'email' => 'consulta.sin.perfil@empresa.cl',
                'rol' => RolUsuario::Consulta->value,
                'password' => 'Temporal2026',
                'password_confirmation' => 'Temporal2026',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'El perfil inicial correspondiente al rol seleccionado no está disponible.',
            );

        $this->assertDatabaseMissing('users', [
            'email' => 'consulta.sin.perfil@empresa.cl',
        ]);
    }

    public function test_perfil_solo_consulta_navega_areas_asignadas_y_bloquea_operaciones(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $perfil = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/perfiles-acceso', [
                'codigo' => 'AUDITOR_TRANSVERSAL',
                'nombre' => 'Auditor transversal',
                'solo_consulta' => true,
                'rol_base' => RolUsuario::Administrador->value,
                'modulos' => [
                    'materia-prima.romana',
                    'frigorifico.validacion',
                    'frigorifico.catalogos',
                    'frigorifico.prefrio',
                    'materiales.catalogos',
                    'administracion.accesos',
                ],
                'modulos_tablet' => [
                    CatalogoModulosAcceso::TABLET_VALIDACION_PT,
                ],
                'activo' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.solo_consulta', true)
            ->assertJsonPath('data.rol_base', RolUsuario::Consulta->value)
            ->assertJsonPath('data.modulos_tablet', [])
            ->json('data');

        $this->postJson('/api/administracion/usuarios', [
            'nombre' => 'Auditor de prueba',
            'email' => 'auditor@empresa.cl',
            'perfil_acceso_id' => $perfil['id'],
            'password' => 'Temporal2026',
            'password_confirmation' => 'Temporal2026',
        ])
            ->assertCreated()
            ->assertJsonPath('usuario.rol', RolUsuario::Consulta->value);

        $auditor = User::query()->where('email', 'auditor@empresa.cl')->firstOrFail();

        $this->assertSame(
            [],
            app(CatalogoModulosAcceso::class)->modulosTabletUsuario($auditor),
        );

        $this->postJson('/api/acceso-oficina', [
            'email' => 'auditor@empresa.cl',
            'password' => 'Temporal2026',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.solo_consulta', true)
            ->assertJsonPath('usuario.puede_consultar_accesos', true)
            ->assertJsonPath('usuario.puede_consultar_catalogos_validacion', true)
            ->assertJsonPath('usuario.puede_consultar_validaciones_pallet', true)
            ->assertJsonPath('usuario.puede_administrar_accesos', false)
            ->assertJsonPath('usuario.puede_administrar_catalogos_validacion', false)
            ->assertJsonPath('usuario.puede_operar_prefrio', false)
            ->assertJsonPath('usuario.puede_operar_romana', false);

        $this->actingAs($auditor, 'sanctum')
            ->getJson('/api/administracion/accesos')
            ->assertOk();

        $this->getJson('/api/administracion/validacion')
            ->assertOk();

        $this->getJson('/api/validacion/pallets')
            ->assertOk();

        $this->postJson('/api/administracion/perfiles-acceso', [])
            ->assertForbidden();

        $this->postJson('/api/prefrio/procesos', [])
            ->assertForbidden();

        $this->postJson('/api/romana/recepciones', [])
            ->assertForbidden();
    }
}
