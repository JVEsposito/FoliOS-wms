<?php

namespace Tests\Concerns;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Models\CalibreValidacion;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Support\Str;

/**
 * Lote de materia prima en bins, confirmado y asignado a cámara, construido por el mismo
 * camino de Romana, Validación MP y Digitación que usa la planta.
 *
 * Requiere el trait PreparaInspeccionesEnvases en la clase de prueba.
 */
trait PreparaLotesMpEnCamara
{
    /**
     * @param  array<string, mixed>  $lote  Reemplazos del payload del lote.
     * @return array{lote: array<string, mixed>, cliente: Cliente, camara: Camara, csg: string}
     */
    protected function loteMpEnCamara(string $sufijo, array $lote = [], ?Cliente $cliente = null, ?Camara $camara = null, bool $prepararSinLote = false): array
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente ??= Cliente::create(['codigo' => "EXP-{$sufijo}", 'nombre' => "Exportadora {$sufijo}", 'activo' => true]);
        $bins = (int) ($lote['cantidad_envases_primarios'] ?? 48);
        $totes = (int) ($lote['cantidad_envases_secundarios'] ?? 10);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);

        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', [
                'operacion_id' => (string) Str::uuid(),
                'temporada_id' => $temporada->id,
                'cliente_id' => $cliente->id,
                'tipo_recepcion' => 'fruta_con_envases',
                'especie_validacion_id' => $especie->id,
                'tipo_servicio' => 'proceso',
                'envases' => [
                    ['tipo_envase' => 'bins', 'cantidad' => $bins],
                    ['tipo_envase' => 'totes', 'cantidad' => $totes],
                ],
                'numero_guia_despacho' => "GD-{$sufijo}",
                'patente_camion' => 'ABCD12',
                'tipo_camion' => 'plano',
                'rut_conductor' => '12.345.678-5',
                'nombre_conductor' => 'Transportista MP',
                'peso_bruto' => 28000,
            ])
            ->assertCreated()
            ->json('data');
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/confirmar-ingreso", [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk();

        $variedad = VariedadValidacion::firstOrCreate(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina'], ['activo' => true]);
        $calibre = CalibreValidacion::firstOrCreate(['especie_validacion_id' => $especie->id, 'nombre' => '28 mm'], ['activo' => true]);
        $csg = CsgValidacion::create([
            'temporada_id' => $temporada->id,
            'codigo' => "CSG{$sufijo}",
            'predio' => "Fundo {$sufijo}",
            'activo' => true,
        ]);

        $validacion = $this->actingAs($validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()
            ->json('data');
        $segmentoId = $this->postJson(
            "/api/validacion-mp/validaciones/{$validacion['id']}/confirmar",
            $this->payloadConInspeccionRc02("/api/validacion-mp/validaciones/{$validacion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'envases' => [
                    ['tipo_envase' => 'bins', 'cantidad_validada' => $bins],
                    ['tipo_envase' => 'totes', 'cantidad_validada' => $totes],
                ],
                'tarjas_verificadas' => true,
                'requiere_segregacion' => false,
                'csg_validacion_id' => $csg->id,
                'variedad_validacion_id' => $variedad->id,
            ]),
        )->assertOk()->json('data.segmentos.0.id');

        $this->actingAs($operador, 'sanctum')->postJson(
            "/api/romana/recepciones/{$recepcion['id']}/cerrar",
            $this->payloadConInspeccionRc02("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'modo_salida_envases' => 'mismos',
                'numero_guia_salida' => "GS-{$sufijo}",
                'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 2]],
                'peso_tara' => 10000,
                'tipo_envase_calculo_neto' => 'bins',
            ]),
        )->assertOk();

        $camara ??= Camara::create([
            'codigo' => "MP-{$sufijo}",
            'nombre' => "Cámara MP {$sufijo}",
            'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $payloadLote = [
            'operacion_id' => (string) Str::uuid(),
            'segmento_validacion_mp_id' => $segmentoId,
            'numero_lote' => "L-{$sufijo}",
            'csg_validacion_id' => $csg->id,
            'sdp' => '987654321',
            'ggn' => '1234567890123',
            'fecha_cosecha' => '2026-10-26',
            'predio' => "Fundo {$sufijo}",
            'especie_validacion_id' => $especie->id,
            'variedad_validacion_id' => $variedad->id,
            'calibre_validacion_id' => $calibre->id,
            'cuartel' => 'C-1',
            'tipo_producto' => 'materia_prima',
            'envase_primario' => 'bins',
            'envase_secundario' => 'totes',
            'cantidad_envases_primarios' => $bins,
            'cantidad_envases_secundarios' => $totes,
            'kilos_brutos' => 19000,
            'kilos_netos_confirmados' => 18000,
            'requiere_hidrocooler' => false,
            ...$lote,
        ];
        if ($prepararSinLote) {
            return ['lote' => [], 'cliente' => $cliente, 'camara' => $camara, 'csg' => $csg->codigo, 'payload_lote' => $payloadLote];
        }
        $creado = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $payloadLote)
            ->assertCreated()
            ->json('data');
        $confirmado = $this->postJson("/api/materia-prima/lotes/{$creado['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $creado['version'],
        ])->assertOk()->json('data');
        $asignado = $this->postJson("/api/materia-prima/lotes/{$confirmado['id']}/asignar-camara", [
            'operacion_id' => (string) Str::uuid(),
            'camara_id' => $camara->id,
        ])->assertOk()->json('data');

        return ['lote' => $asignado, 'cliente' => $cliente, 'camara' => $camara, 'csg' => $csg->codigo];
    }
}
