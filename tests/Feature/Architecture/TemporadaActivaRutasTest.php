<?php

namespace Tests\Feature\Architecture;

use App\Comex\Models\Booking;
use App\Comex\Models\LugarComercial;
use App\Comex\Models\NotaVenta;
use App\Comex\Models\ParteComercial;
use App\Comex\Models\TipoDocumento;
use App\Finanzas\Models\AplicacionProveedor;
use App\Finanzas\Models\CargoAcordado;
use App\Finanzas\Models\ConceptoServicio;
use App\Finanzas\Models\CostoComex;
use App\Finanzas\Models\Diferencia;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Models\EvaluacionProveedor;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Models\PagoProveedor;
use App\Finanzas\Models\ProveedorFinanciero;
use App\Finanzas\Models\Tarifa;
use App\Http\Middleware\AsegurarTemporadaActivaDelRegistro;
use App\Models;
use App\Models\Contracts\PerteneceATemporada;
use App\Rrhh\Models\AntecedenteContratista;
use App\Rrhh\Models\AsignacionPersonal;
use App\Rrhh\Models\CargoLaboral;
use App\Rrhh\Models\ConceptoNomina;
use App\Rrhh\Models\EntregaNomina;
use App\Rrhh\Models\ExpedienteContratista;
use App\Rrhh\Models\FuenteMarcaje;
use App\Rrhh\Models\LoteIntegracion;
use App\Rrhh\Models\MandatoDescuento;
use App\Rrhh\Models\Marcaje;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use App\Rrhh\Models\RetencionContratista;
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
        Booking::class => 'reserva comercial con viaje y fechas propios; solo lee el despacho de la temporada referenciada',
        NotaVenta::class => 'acuerdo comercial con vigencia y revisiones propias; continúa entre temporadas sin modificar stock',
        ParteComercial::class => 'identidad comercial global; documentos vigentes entre temporadas',
        LugarComercial::class => 'catálogo logístico global en base Comex, sin inventario físico',
        ExpedienteContratista::class => 'control de empresa, faena y mes; las obligaciones laborales continúan después de cerrar una temporada',
        AntecedenteContratista::class => 'antecedente laboral/previsional de RRHH por período, con revisión documental independiente de temporada',
        RetencionContratista::class => 'anotación de retención comercial respaldada por fecha; no modifica existencias ni ejecución operacional',
        MandatoDescuento::class => 'autorización de descuento de RRHH por empleador, persona y vigencia; no modifica operación ni pertenece a una temporada',
        PagoProveedor::class => 'registro de desembolso por fecha en base financiera propia',
        AplicacionProveedor::class => 'aplicación de créditos por fecha contable, sin temporada',
        EvaluacionProveedor::class => 'evaluación de proveedor por período en base financiera propia',
        ProveedorFinanciero::class => 'ficha financiera global en base propia',
        DocumentoCompra::class => 'documento recibido por fecha contable, independiente de temporada',
        LoteIntegracion::class => 'recepción laboral persistida en base propia, reintento independiente de temporada',
        EntregaNomina::class => 'acuse de novedad laboral en base propia, sin operación ni temporada',
        CargoLaboral::class => 'catálogo laboral global, independiente de temporada',
        ConceptoNomina::class => 'configuración laboral global con tratamiento histórico conservado',
        MovimientoNomina::class => 'novedad laboral por fecha en base propia',
        Persona::class => 'maestro laboral global, independiente de temporada',
        AsignacionPersonal::class => 'dotación por fechas y turnos en base propia',
        Marcaje::class => 'asistencia por instante en base propia, sin modificar operación',
        FuenteMarcaje::class => 'configuración global de integración de asistencia',
        // Configuración física y maestros: no pertenecen a una temporada.
        Models\Anden::class => 'infraestructura física',
        Models\BandaOperacional::class => 'infraestructura física',
        Models\Camara::class => 'infraestructura física',
        Models\TunelPrefrio::class => 'infraestructura física',
        Models\PlantaOrigen::class => 'catálogo global de plantas de origen',
        Models\UmbralPrefrioEspecie::class => 'parámetro térmico global por especie',
        Models\Cliente::class => 'maestro global',
        Models\ProductorCsg::class => 'maestro global',
        Models\ProductoHidrocooler::class => 'catálogo global; cada ciclo conserva los datos del producto aplicado por temporada',
        Models\ResponsableContraparteSag::class => 'responsable autorizado del establecimiento; maestro global con snapshot documental por temporada',
        Models\FormatoRegistro::class => 'maestro documental global; la recepción conserva su versión por temporada',
        Models\PerfilAcceso::class => 'accesos',
        Models\User::class => 'accesos',
        Models\ConexionExistencia::class => 'accesos a existencias Excel',
        Models\ConexionExterna::class => 'configuración global de acceso a sistemas externos, independiente de la temporada',
        Models\Temporada::class => 'administración de temporadas',
        Models\IngresoPorteria::class => 'bitácora de accesos por fecha de planta, disponible entre temporadas; no modifica inventario',
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
        Models\FotoItemMaterial::class => 'metadatos del catálogo de ítems; edición independiente por temporada, sin modificar inventario',
        Models\LoteTransformacionMaterial::class => 'Materiales',
        Models\OrdenTransformacionMaterial::class => 'Materiales',
        Models\PerfilImpresionEtiqueta::class => 'Materiales',
        Models\ProveedorMaterial::class => 'Materiales',
        Models\RecepcionMaterial::class => 'Materiales',
        Models\RecetaMaterial::class => 'Materiales',
        Models\TrabajoImpresionMaterial::class => 'Materiales',
        // Finanzas no escribe registros operacionales: un precio rige por sus propias fechas.
        Tarifa::class => 'precio con vigencia por fechas; un contrato de temporada se carga con sus fechas',
        CargoAcordado::class => 'cargo económico por fecha y acuerdo, independiente de la ejecución y temporada',
        Moneda::class => 'configuración global de monedas de Finanzas',
        CostoComex::class => 'control económico por booking, con fechas y cierre propios; solo lee otras bases',
        ConceptoServicio::class => 'catálogo comercial de Finanzas con vigencia por fechas; no modifica la operación ni pertenece a una temporada',
        Diferencia::class => 'decisión sobre un mes cerrado de la prefactura: se rige por el mes calendario, no por la temporada',
        // Comex escribe solo en su base; los documentos de un embarque llegan semanas después de la salida.
        TipoDocumento::class => 'configuración de Comex: los documentos de un embarque se completan aunque la temporada haya cambiado',
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
