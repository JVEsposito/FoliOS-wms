<?php

namespace Tests\Feature\Architecture;

use App\Http\Middleware\AsegurarTemporadaActivaDelRegistro;
use App\Models;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RutaLaravel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Toda escritura por ID sobre un registro operacional pasa por el control de
 * temporada activa. Un modelo nuevo en una ruta de escritura obliga a decidir:
 * implementa PerteneceATemporada o se agrega aquí con el motivo.
 */
class TemporadaActivaRutasTest extends TestCase
{
    /** @var array<class-string<Model>, string> */
    private const EXENTOS = [
        // Configuración física y maestros: no pertenecen a una temporada.
        Models\Anden::class => 'infraestructura física',
        Models\BandaOperacional::class => 'infraestructura física',
        Models\Camara::class => 'infraestructura física',
        Models\TunelPrefrio::class => 'infraestructura física',
        Models\Cliente::class => 'maestro global',
        Models\ProductorCsg::class => 'maestro global',
        Models\FormatoRegistro::class => 'maestro documental global; la recepción conserva su versión por temporada',
        Models\PerfilAcceso::class => 'accesos',
        Models\User::class => 'accesos',
        Models\ConexionExistencia::class => 'accesos a existencias Excel',
        Models\Temporada::class => 'administración de temporadas',
        Models\RegistroControlAmbiental::class => 'se registra por cámara y hora, sin temporada',
        Models\NotificacionOperacional::class => 'leer o confirmar no modifica la operación; ya filtra por temporada',
        // Cerrar sesiones de estiba debe seguir siendo posible después de un cambio de temporada.
        Models\SesionEstiba::class => 'sesión de cámara, sin temporada',
        // El catálogo de la próxima temporada se prepara antes de activarla.
        Models\ArticuloValidacion::class => 'catálogo de validación',
        Models\CalibreValidacion::class => 'catálogo de validación',
        Models\CategoriaValidacion::class => 'catálogo de validación',
        Models\CombinacionValidacion::class => 'catálogo de validación',
        Models\CsgValidacion::class => 'catálogo de validación',
        Models\EnvaseValidacion::class => 'catálogo de validación',
        Models\EspecieValidacion::class => 'catálogo de validación',
        Models\ImportacionValidacion::class => 'catálogo de validación',
        Models\MarcaValidacion::class => 'catálogo de validación',
        Models\OrigenValidacion::class => 'catálogo de validación',
        Models\VariedadValidacion::class => 'catálogo de validación',
        // Materiales traspasa su inventario entre temporadas con su propia migración.
        Models\DespachoMaterial::class => 'Materiales',
        Models\DestinoMaterial::class => 'Materiales',
        Models\FolioMaterial::class => 'Materiales',
        Models\ImportacionCatalogoMaterial::class => 'Materiales',
        Models\ItemMaterial::class => 'Materiales',
        Models\LoteTransformacionMaterial::class => 'Materiales',
        Models\OrdenTransformacionMaterial::class => 'Materiales',
        Models\PerfilImpresionEtiqueta::class => 'Materiales',
        Models\ProveedorMaterial::class => 'Materiales',
        Models\RecepcionMaterial::class => 'Materiales',
        Models\RecetaMaterial::class => 'Materiales',
        Models\TrabajoImpresionMaterial::class => 'Materiales',
    ];

    public function test_cada_modelo_en_una_ruta_de_escritura_decide_su_temporada(): void
    {
        $sinDecision = [];
        $controlados = [];

        foreach ($this->rutasDeEscritura() as $ruta) {
            foreach ($ruta->signatureParameters(['subClass' => Model::class]) as $parametro) {
                $clase = $parametro->getType()?->getName();

                if (is_subclass_of($clase, PerteneceATemporada::class)) {
                    $controlados[$clase] = true;
                } elseif (! array_key_exists($clase, self::EXENTOS)) {
                    $sinDecision[] = implode('|', $ruta->methods()).' '.$ruta->uri()." → {$clase}";
                }
            }
        }

        $this->assertSame([], $sinDecision, 'Implementa PerteneceATemporada o declara el modelo como exento con su motivo.');
        $this->assertArrayHasKey(Models\ValidacionPallet::class, $controlados);
        $this->assertArrayHasKey(Models\RecepcionRomana::class, $controlados);
        $this->assertArrayHasKey(Models\Carga::class, $controlados);
    }

    public function test_los_exentos_no_implementan_el_contrato(): void
    {
        foreach (array_keys(self::EXENTOS) as $clase) {
            $this->assertFalse(
                is_subclass_of($clase, PerteneceATemporada::class),
                "{$clase} implementa PerteneceATemporada: sácalo de la lista de exentos.",
            );
        }
    }

    public function test_el_control_corre_despues_de_resolver_los_modelos_de_la_ruta(): void
    {
        $ruta = collect(Route::getRoutes()->getRoutes())
            ->first(fn (RutaLaravel $ruta): bool => $ruta->uri() === 'api/validacion/pallets/{validacionPallet}/anular');
        $this->assertNotNull($ruta);

        $middleware = app(Router::class)->gatherRouteMiddleware($ruta);
        $posicionBindings = array_search(SubstituteBindings::class, $middleware, true);
        $posicionControl = array_search(AsegurarTemporadaActivaDelRegistro::class, $middleware, true);

        $this->assertIsInt($posicionBindings);
        $this->assertIsInt($posicionControl);
        $this->assertGreaterThan($posicionBindings, $posicionControl);
    }

    /** @return iterable<RutaLaravel> */
    private function rutasDeEscritura(): iterable
    {
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (str_starts_with($ruta->uri(), 'api/') && array_diff($ruta->methods(), ['GET', 'HEAD']) !== []) {
                yield $ruta;
            }
        }
    }
}
