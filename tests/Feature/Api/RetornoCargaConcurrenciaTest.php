<?php

namespace Tests\Feature\Api;

use App\Models\Carga;
use App\Models\ReingresoFolioCamara;
use App\Models\TareaMovimiento;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Concerns\EjecutaSolicitudesConcurrentes;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class RetornoCargaConcurrenciaTest extends TestCase
{
    use DatabaseMigrations;
    use EjecutaSolicitudesConcurrentes;
    use PreparaDespachoFrigorifico;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->refreshTestDatabase();
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_dos_oficinas_que_registran_el_mismo_retorno_no_duplican_reingresos(): void
    {
        $contexto = $this->crearContextoFrio(2, 'RT1');
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        foreach (array_reverse($contexto['folios']) as $folio) {
            $this->enviarAlAnden($contexto, $carga, $folio);
        }
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/cerrar-despacho", [
            'operacion_id' => (string) Str::uuid(),
            'patente' => 'AB-CD-12',
            'conductor' => 'María Pérez',
        ])->assertOk();
        $version = $carga->refresh()->version;
        $tokenOtro = $contexto['administrador']->createToken('oficina-otro', ['oficina'])->plainTextToken;

        $solicitudes = array_map(fn (string $token): array => [
            'POST', "/api/cargas/{$carga->id}/retorno", $token, ['version_esperada' => $version, 'motivo' => 'Contenedor rechazado'],
        ], [$contexto['tokenOficina'], $tokenOtro]);
        $resultados = $this->reintentarConcurrenciaTemporal($solicitudes, $this->solicitudesConcurrentes($solicitudes));
        $estados = array_column($resultados, 'status');
        sort($estados);

        $this->assertSame([200, 409], $estados, json_encode(array_column($resultados, 'body')));
        $this->assertSame('retornada', Carga::query()->whereKey($carga->id)->toBase()->value('estado'));
        $this->assertSame(2, ReingresoFolioCamara::query()->where('carga_id', $carga->id)->count());
    }

    public function test_dos_oficinas_pueden_recuperar_el_mismo_reingreso_sin_duplicar_la_labor(): void
    {
        config(['planificador.mode' => 'off', 'planificador.generacion_automatica' => false]);
        $contexto = $this->crearContextoFrio(1, 'RECUP');
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $this->enviarAlAnden($contexto, $carga, $folio);
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/cancelar", [
            'version_esperada' => $carga->refresh()->version, 'motivo' => 'Carga de prueba cancelada',
        ])->assertOk();
        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $folio->id)->sole();
        $this->assertNull($reingreso->plan_operacional_id);
        $tokenOtro = $contexto['administrador']->createToken('recuperacion-otra-oficina', ['oficina'])->plainTextToken;
        $variables = ['WMS_PLANNER_MODE' => 'guided', 'WMS_PLANNER_COMPUTE' => 'server', 'WMS_PLANNER_HORIZON' => 'rolling', 'WMS_PLANIFICADOR_AUTOMATICO' => 'true'];
        $anteriores = [];
        try {
            foreach ($variables as $clave => $valor) {
                $anteriores[$clave] = ['proceso' => getenv($clave), 'env' => $_ENV[$clave] ?? null, 'servidor' => $_SERVER[$clave] ?? null];
                putenv("{$clave}={$valor}");
                $_ENV[$clave] = $_SERVER[$clave] = $valor;
            }
            $solicitudes = array_map(fn (string $token): array => ['POST', "/api/cargas/reingresos/{$reingreso->id}/programar", $token, []], [$contexto['tokenOficina'], $tokenOtro]);
            $resultados = $this->reintentarConcurrenciaTemporal($solicitudes, $this->solicitudesConcurrentes($solicitudes));
            $this->assertSame([200, 200], array_column($resultados, 'status'), json_encode(array_column($resultados, 'body')));
            $this->assertSame(['programada', 'programada'], array_map(fn ($r) => $r['body']['data']['programacion']['estado'], $resultados));
        } finally {
            foreach ($anteriores as $clave => $valor) {
                putenv($valor['proceso'] === false ? $clave : "{$clave}={$valor['proceso']}");
                if ($valor['env'] === null) {
                    unset($_ENV[$clave]);
                } else {
                    $_ENV[$clave] = $valor['env'];
                }
                if ($valor['servidor'] === null) {
                    unset($_SERVER[$clave]);
                } else {
                    $_SERVER[$clave] = $valor['servidor'];
                }
            }
        }
        $this->assertNotNull($reingreso->refresh()->plan_operacional_id);
        $this->assertSame(1, TareaMovimiento::query()->where('folio_id', $folio->id)->whereIn('estado', ['pendiente', 'asumida', 'en_proceso'])->count());
        $this->assertSame(1, ReingresoFolioCamara::query()->where('folio_id', $folio->id)->count());
    }
}
