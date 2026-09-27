<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ModoBandaOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\UbicacionActual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecomendacionUbicacionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_camara_preferente_para_despacho_solo_desempata_afinidad(): void
    {
        $token = $this->crearIdentidad();
        [$preferida] = $this->crearCamara('CAM-DESPACHO', 1, 2);
        [$otra, $posiciones] = $this->crearCamara('CAM-OTRA', 1, 2);
        config(['planificador.camara_preferente_despacho' => $preferida->codigo]);

        $folioExistente = $this->crearFolio('PAL-MISMO-CLIENTE', 'Cliente Norte', 'Marca Norte', 'Caja 5');
        $this->ocupar($otra, $posiciones[1][1], $folioExistente);
        $compatible = $this->crearFolio(
            'PAL-POR-AFINIDAD', 'Cliente Norte', 'Marca Norte', 'Caja 5', pendienteUbicacion: true,
        );

        $this->withToken($token)
            ->getJson('/api/movimientos/consultar-folio?numero_folio='.$compatible->numero_folio)
            ->assertOk()
            ->assertJsonPath('data.recomendacion_ubicacion.mejor.camara.id', $otra->id);

        $otroCliente = $this->crearFolio(
            'PAL-POR-DESPACHO', 'Cliente Sur', 'Marca Sur', 'Caja 5', pendienteUbicacion: true,
        );
        $this->withToken($token)
            ->getJson('/api/movimientos/consultar-folio?numero_folio='.$otroCliente->numero_folio)
            ->assertOk()
            ->assertJsonPath('data.recomendacion_ubicacion.mejor.camara.id', $preferida->id);
    }

    public function test_prioriza_cliente_marca_y_formato_antes_de_abrir_otra_afinidad(): void
    {
        $token = $this->crearIdentidad();
        [$camara, $posiciones] = $this->crearCamara('CAM-AFINIDAD', 5, 3);
        $this->ocupar($camara, $posiciones[1][1], $this->crearFolio(
            'PAL-EXACTO',
            cliente: 'Exportadora Norte',
            marca: 'Cordillera',
            formato: 'Caja 5 kg',
        ));
        $this->ocupar($camara, $posiciones[2][1], $this->crearFolio(
            'PAL-MARCA',
            cliente: 'Exportadora Norte',
            marca: 'Cordillera',
            formato: 'Caja 2,5 kg',
        ));
        $this->ocupar($camara, $posiciones[3][1], $this->crearFolio(
            'PAL-CLIENTE',
            cliente: 'Exportadora Norte',
            marca: 'Valle',
            formato: 'Caja 5 kg',
        ));
        $this->ocupar($camara, $posiciones[5][1], $this->crearFolio(
            'PAL-OTRO',
            cliente: 'Exportadora Sur',
            marca: 'Pacífico',
            formato: 'Caja 5 kg',
        ));
        $objetivo = $this->crearFolio(
            'PAL-OBJETIVO',
            cliente: 'Exportadora Norte',
            marca: 'Cordillera',
            formato: 'Caja 5 kg',
            pendienteUbicacion: true,
        );

        $this->withToken($token)
            ->getJson('/api/movimientos/consultar-folio?'.http_build_query([
                'numero_folio' => $objetivo->numero_folio,
                'camara_id' => $camara->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.recomendacion_ubicacion.aplica', true)
            ->assertJsonPath('data.recomendacion_ubicacion.disponible', true)
            ->assertJsonPath('data.recomendacion_ubicacion.uso', 'transito_pt')
            ->assertJsonPath('data.recomendacion_ubicacion.mejor.camara.id', $camara->id)
            ->assertJsonPath('data.recomendacion_ubicacion.mejor.banda.numero', 1)
            ->assertJsonPath('data.recomendacion_ubicacion.mejor.posicion.id', $posiciones[1][2]->id)
            ->assertJsonPath('data.recomendacion_ubicacion.mejor.afinidad.nivel', 'cliente_marca_formato')
            ->assertJsonPath('data.recomendacion_ubicacion.mejor.en_camara_consultada', true)
            ->assertJsonPath('data.recomendacion_ubicacion.alternativas.0.banda.numero', 2)
            ->assertJsonPath('data.recomendacion_ubicacion.alternativas.0.afinidad.nivel', 'cliente_marca')
            ->assertJsonPath('data.recomendacion_ubicacion.alternativas.1.banda.numero', 3)
            ->assertJsonPath('data.recomendacion_ubicacion.alternativas.1.afinidad.nivel', 'cliente')
            ->assertJsonPath('data.recomendacion_ubicacion.alternativas.2.banda.numero', 4)
            ->assertJsonPath('data.recomendacion_ubicacion.alternativas.2.afinidad.nivel', 'banda_libre')
            ->assertJsonPath('data.recomendacion_ubicacion.alternativas.3.banda.numero', 5)
            ->assertJsonPath('data.recomendacion_ubicacion.alternativas.3.afinidad.nivel', 'sin_afinidad')
            ->assertJsonPath('data.recomendacion_ubicacion.criterio.solo_pallets_completos', true)
            ->assertJsonPath('data.recomendacion_ubicacion.criterio.genera_movimiento', false)
            ->assertJsonPath('data.recomendacion_ubicacion.criterio.reserva_destino', false);

        $this->assertDatabaseCount('planes_operacionales', 0);
        $this->assertDatabaseCount('tareas_movimiento', 0);
    }

    public function test_el_plano_expone_afinidad_dinamica_y_la_libera_al_vaciar_la_banda(): void
    {
        $token = $this->crearIdentidad();
        [$camara, $posiciones] = $this->crearCamara('CAM-PLANO-AFINIDAD', 1, 2);
        $folio = $this->crearFolio(
            'PAL-PLANO',
            cliente: 'Exportadora Central',
            marca: 'Andes',
            formato: 'Clamshell 2 kg',
        );
        $ubicacion = $this->ocupar($camara, $posiciones[1][1], $folio);

        $this->withToken($token)
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.bandas_operacionales.0.afinidad.activa', true)
            ->assertJsonPath(
                'data.bandas_operacionales.0.afinidad.cliente.valor',
                'Exportadora Central',
            )
            ->assertJsonPath('data.bandas_operacionales.0.afinidad.marca.valor', 'Andes')
            ->assertJsonPath(
                'data.bandas_operacionales.0.afinidad.formato.valor',
                'Clamshell 2 kg',
            )
            ->assertJsonPath('data.bandas_operacionales.0.afinidad.pallets_completos', 1)
            ->assertJsonPath('data.bandas_operacionales.0.afinidad.perfiles.0.cliente', 'Exportadora Central')
            ->assertJsonPath('data.bandas_operacionales.0.afinidad.perfiles.0.marca', 'Andes')
            ->assertJsonPath('data.bandas_operacionales.0.afinidad.fuera_alcance', 0);

        $ubicacion->delete();

        $this->withToken($token)
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.bandas_operacionales.0.afinidad.activa', false)
            ->assertJsonPath('data.bandas_operacionales.0.afinidad.cliente', null)
            ->assertJsonPath('data.bandas_operacionales.0.afinidad.pallets_completos', 0);
    }

    public function test_respeta_modo_uso_y_excluye_bandas_intervenidas_por_saldos(): void
    {
        $token = $this->crearIdentidad();
        [$camara, $posiciones, $bandas] = $this->crearCamara('CAM-RESTRICCIONES', 4, 2);
        $bandas[1]->update([
            'modo' => ModoBandaOperacional::Bloqueada,
            'motivo_estado' => 'Mantención',
        ]);
        $bandas[2]->update(['usos_permitidos' => ['inspeccion']]);
        $this->ocupar($camara, $posiciones[1][1], $this->crearFolio(
            'PAL-BLOQUEADO',
            cliente: 'Cliente A',
            marca: 'Marca A',
            formato: 'Formato A',
        ));
        $this->ocupar($camara, $posiciones[2][1], $this->crearFolio(
            'PAL-INSPECCION',
            cliente: 'Cliente A',
            marca: 'Marca A',
            formato: 'Formato A',
        ));
        $this->ocupar($camara, $posiciones[3][1], $this->crearFolio(
            'SALDO-FUERA-ALCANCE',
            cliente: 'Cliente A',
            marca: 'Marca A',
            formato: 'Formato A',
            tipo: TipoBulto::Saldo,
        ));
        $objetivo = $this->crearFolio(
            'PAL-RESTRICCIONES',
            cliente: 'Cliente A',
            marca: 'Marca A',
            formato: 'Formato A',
            pendienteUbicacion: true,
        );

        $this->withToken($token)
            ->getJson('/api/movimientos/consultar-folio?numero_folio='.$objetivo->numero_folio)
            ->assertOk()
            ->assertJsonPath('data.recomendacion_ubicacion.mejor.banda.numero', 4)
            ->assertJsonCount(0, 'data.recomendacion_ubicacion.alternativas');
    }

    public function test_no_recomienda_destino_para_saldos(): void
    {
        $token = $this->crearIdentidad();
        $this->crearCamara('CAM-SOLO-COMPLETOS', 1, 2);
        $saldo = $this->crearFolio(
            'SALDO-OBJETIVO',
            cliente: 'Cliente A',
            marca: 'Marca A',
            formato: 'Formato A',
            tipo: TipoBulto::Saldo,
            pendienteUbicacion: true,
        );

        $this->withToken($token)
            ->getJson('/api/movimientos/consultar-folio?numero_folio='.$saldo->numero_folio)
            ->assertOk()
            ->assertJsonPath('data.disponible_ubicacion', true)
            ->assertJsonPath('data.recomendacion_ubicacion.aplica', false)
            ->assertJsonPath('data.recomendacion_ubicacion.disponible', false)
            ->assertJsonPath('data.recomendacion_ubicacion.mejor', null);
    }

    private function crearIdentidad(): string
    {
        $usuario = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-AFINIDAD',
            'nombre' => 'Tablet afinidad',
        ]);

        return $usuario
            ->crearTokenParaDispositivo($dispositivo, 'tablet-afinidad')
            ->plainTextToken;
    }

    /**
     * @return array{
     *     Camara,
     *     array<int, array<int, Posicion>>,
     *     array<int, BandaOperacional>
     * }
     */
    private function crearCamara(string $codigo, int $cantidadBandas, int $posicionesPorBanda): array
    {
        $camara = Camara::create([
            'codigo' => $codigo,
            'nombre' => "Cámara {$codigo}",
            'contenido' => 'productos',
            'estado' => 'activa',
            'cantidad_bandas' => $cantidadBandas,
            'posiciones_por_banda' => $posicionesPorBanda,
            'cantidad_niveles' => 1,
        ]);
        $posiciones = [];
        $bandas = [];

        for ($banda = 1; $banda <= $cantidadBandas; $banda++) {
            $bandas[$banda] = BandaOperacional::create([
                'camara_id' => $camara->id,
                'numero' => $banda,
                'usos_permitidos' => ['transito_pt', 'inspeccion', 'retenidos'],
                'modo' => ModoBandaOperacional::Operativa,
                'version' => 1,
            ]);

            for ($posicion = 1; $posicion <= $posicionesPorBanda; $posicion++) {
                $posiciones[$banda][$posicion] = Posicion::create([
                    'camara_id' => $camara->id,
                    'banda' => $banda,
                    'posicion' => $posicion,
                    'nivel' => 1,
                    'etiqueta' => sprintf('B%02d-P%02d-N1', $banda, $posicion),
                ]);
            }
        }

        return [$camara, $posiciones, $bandas];
    }

    private function crearFolio(
        string $numero,
        string $cliente,
        string $marca,
        string $formato,
        TipoBulto $tipo = TipoBulto::Pallet,
        bool $pendienteUbicacion = false,
    ): Folio {
        return Folio::create([
            'numero_folio' => $numero,
            'tipo_bulto' => $tipo,
            'estado_operacional' => $pendienteUbicacion
                ? EstadoOperacionalFolio::PendienteUbicacion
                : EstadoOperacionalFolio::Disponible,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
            'marca' => $marca,
            'exportadora' => $cliente,
            'origen_sistema' => 'validacion',
            'datos_externos' => ['envase' => $formato],
        ]);
    }

    private function ocupar(Camara $camara, Posicion $posicion, Folio $folio): UbicacionActual
    {
        return UbicacionActual::create([
            'folio_id' => $folio->id,
            'camara_id' => $camara->id,
            'posicion_id' => $posicion->id,
            'ubicado_at' => now(),
        ]);
    }
}
