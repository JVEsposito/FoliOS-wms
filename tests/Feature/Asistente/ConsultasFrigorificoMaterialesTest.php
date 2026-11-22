<?php

namespace Tests\Feature\Asistente;

use App\Enums\RolUsuario;
use App\Models\User;
use App\Providers\AsistenteServiceProvider;
use App\Services\Asistente\CatalogoConsultasAsistente;
use App\Services\Asistente\Consultas\CajasPt;
use App\Services\Asistente\Consultas\FormatosPt;
use App\Services\Asistente\Consultas\SaldosPt;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use App\Services\Existencias\ServicioExistencias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

class ConsultasFrigorificoMaterialesTest extends TestCase
{
    use PreparaAsistente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistente();
    }

    public function test_cajas_pt_suma_por_cliente_y_especie(): void
    {
        $this->existencias([
            ['folio' => 'F1', 'cliente' => 'ASI', 'especie' => 'Cereza', 'envase' => '5 kg', 'cantidad_cajas' => 100, 'tipo_bulto' => 'Pallet'],
            ['folio' => 'F1', 'cliente' => 'ASI', 'especie' => 'Cereza', 'envase' => '2,5 kg', 'cantidad_cajas' => 20, 'tipo_bulto' => 'Pallet'],
            ['folio' => 'F2', 'cliente' => 'OTRO', 'especie' => 'Cereza', 'envase' => '5 kg', 'cantidad_cajas' => 50, 'tipo_bulto' => 'Saldo'],
        ]);

        $resultado = app(CajasPt::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(ResultadoConsulta::RESPONDIDA, $resultado->estado);
        $this->assertSame(['cliente' => 'ASI', 'especie' => 'Cereza', 'folios' => 1, 'cajas' => 120], $resultado->filas[0]);
        $this->assertSame(170, $resultado->cifras[0]['valor']);

        $soloCliente = app(CajasPt::class)->ejecutar($this->administrador, new ParametrosConsulta(clienteId: $this->cliente->id));
        $this->assertSame(120, $soloCliente->cifras[0]['valor']);
    }

    public function test_formatos_pt_agrupa_por_embalaje(): void
    {
        $this->existencias([
            ['folio' => 'F1', 'cliente' => 'ASI', 'envase' => '5 kg', 'cantidad_cajas' => 100],
            ['folio' => 'F2', 'cliente' => 'ASI', 'envase' => '5 kg', 'cantidad_cajas' => 30],
            ['folio' => 'F3', 'cliente' => 'ASI', 'envase' => '2,5 kg', 'cantidad_cajas' => 10],
        ]);

        $resultado = app(FormatosPt::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(['formato' => '5 kg', 'folios' => 2, 'cajas' => 130], $resultado->filas[0]);
        $this->assertSame(2, $resultado->cifras[0]['valor']);
    }

    public function test_saldos_pt_solo_lista_saldos(): void
    {
        $this->existencias([
            ['folio' => 'F1', 'cliente' => 'ASI', 'cantidad_cajas' => 100, 'tipo_bulto' => 'Pallet', 'camara' => 'C1', 'posicion' => 'A-1'],
            ['folio' => 'F2', 'cliente' => 'ASI', 'cantidad_cajas' => 12, 'tipo_bulto' => 'Saldo', 'camara' => 'C2', 'posicion' => 'B-3'],
        ]);

        $resultado = app(SaldosPt::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertCount(1, $resultado->filas);
        $this->assertSame('F2', $resultado->filas[0]['folio']);
        $this->assertSame(12, $resultado->filas[0]['cajas']);
    }

    public function test_existencia_vacia_devuelve_sin_datos(): void
    {
        $this->existencias([]);

        $this->assertSame(ResultadoConsulta::SIN_DATOS, app(CajasPt::class)->ejecutar($this->administrador, new ParametrosConsulta)->estado);
    }

    /** @return array<string, array{0: string}> */
    public static function claves(): array
    {
        return collect(AsistenteServiceProvider::CONSULTAS)
            ->mapWithKeys(fn (string $clase): array => [class_basename($clase) => [$clase]])
            ->all();
    }

    #[DataProvider('claves')]
    public function test_cada_consulta_rechaza_usuario_sin_su_permiso(string $clase): void
    {
        $sinPermisos = $this->usuarioAsistente(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);

        $this->assertFalse(app($clase)->puedeEjecutar($sinPermisos), "{$clase} no debería estar disponible sin el permiso de su oficina.");
        $this->assertTrue(app($clase)->puedeEjecutar($this->administrador));
    }

    public function test_el_catalogo_trae_todas_las_consultas_con_claves_unicas(): void
    {
        $claves = array_map(fn ($consulta): string => $consulta->clave(), app(CatalogoConsultasAsistente::class)->todas());

        $this->assertCount(count(AsistenteServiceProvider::CONSULTAS), $claves);
        $this->assertSame($claves, array_values(array_unique($claves)));
        $this->assertSame([], app(CatalogoConsultasAsistente::class)->disponiblesPara(
            $this->usuarioAsistente(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_ASISTENTE]),
        ));
    }

    /** @param  list<array<string, mixed>>  $filas */
    private function existencias(array $filas): void
    {
        $this->mock(ServicioExistencias::class, function ($mock) use ($filas): void {
            $mock->shouldReceive('puedeConsultar')->andReturnUsing(fn (User $u): bool => $u->rol === RolUsuario::Administrador);
            $mock->shouldReceive('filas')->andReturn(LazyCollection::make($filas));
        });
    }
}
