<?php

namespace Tests\Concerns;

use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Anden;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\User;
use App\Services\Cargas\ServicioCarga;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioSesionEstiba;
use Illuminate\Support\Str;

/**
 * Cámara de productos con pallets ubicados, una tablet con sesión abierta y un
 * andén: el escenario mínimo para recorrer el despacho PT de punta a punta.
 */
trait PreparaDespachoFrigorifico
{
    /** @return array<string, mixed> */
    protected function crearContextoFrio(int $cantidadFolios, string $sufijo = 'DESP'): array
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $despachador = User::factory()->create(['rol' => RolUsuario::Despachador, 'activo' => true]);
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => "TABLET-{$sufijo}", 'nombre' => "Tablet {$sufijo}"]);
        $token = $operador->crearTokenParaDispositivo($dispositivo, "tablet-{$sufijo}")->plainTextToken;
        $tokenOficina = $despachador->createToken('oficina-despachos', ['oficina'])->plainTextToken;
        $camara = Camara::create([
            'codigo' => "CAM-{$sufijo}",
            'nombre' => "Cámara {$sufijo}",
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => $cantidadFolios,
            'cantidad_niveles' => 1,
        ]);
        $sesion = app(ServicioSesionEstiba::class)->abrir($camara, $operador, $dispositivo);
        $folios = [];

        for ($indice = 1; $indice <= $cantidadFolios; $indice++) {
            $posicion = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $indice,
                'nivel' => 1,
                'etiqueta' => sprintf('B01-P%02d-N1', $indice),
            ]);
            $folios[] = app(ServicioMovimientoEstiba::class)->ubicar(
                operacionId: (string) Str::uuid(),
                numeroFolio: sprintf('FOLIO-%s-%02d', $sufijo, $indice),
                tipoBulto: TipoBulto::Pallet,
                posicionDestino: $posicion,
                sesionDestino: $sesion,
                usuario: $operador,
                dispositivo: $dispositivo,
                versionDestinoConocida: $indice - 1,
                generadoDispositivoAt: now(),
                datosFolio: [
                    'variedad' => 'Santina',
                    'calibre' => '2J',
                    'marca' => 'Marca prueba',
                    'exportadora' => 'Exportadora prueba',
                ],
            )->folio;
        }

        $anden = Anden::create([
            'codigo' => "AND-{$sufijo}",
            'nombre' => "Andén {$sufijo}",
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);

        return compact('administrador', 'despachador', 'operador', 'dispositivo', 'token', 'tokenOficina', 'camara', 'sesion', 'anden', 'folios');
    }

    /** @param  array<int, Folio>  $folios */
    protected function crearCargaPublicada(User $despachador, Anden $anden, array $folios): Carga
    {
        $servicio = app(ServicioCarga::class);
        $carga = $servicio->crear(['anden_previsto_id' => $anden->id], $despachador);
        $carga = $servicio->agregarFolios($carga, collect($folios)->pluck('numero_folio')->all(), $despachador, 1);

        return $servicio->publicar($carga, $despachador, $carga->version);
    }

    /** @param  array<string, mixed>  $contexto */
    protected function enviarAlAnden(array $contexto, Carga $carga, Folio $folio): void
    {
        $asignacion = CargaFolio::query()->where('carga_id', $carga->id)->where('folio_id', $folio->id)->firstOrFail();
        $version = Camara::query()->whereKey($contexto['camara']->id)->value('version_plano');
        $this->conToken($contexto['token'])
            ->postJson("/api/cargas/asignaciones/{$asignacion->id}/enviar-anden", [
                'operacion_id' => (string) Str::uuid(),
                'anden_id' => $contexto['anden']->id,
                'sesion_estiba_id' => $contexto['sesion']->id,
                'version_camara_conocida' => $version,
                'generado_dispositivo_at' => now()->toAtomString(),
            ])
            ->assertOk();
    }

    protected function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
