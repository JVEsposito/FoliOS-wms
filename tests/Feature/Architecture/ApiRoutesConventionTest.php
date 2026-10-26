<?php

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiRoutesConventionTest extends TestCase
{
    public function test_las_rutas_api_estan_centralizadas_y_sin_duplicados(): void
    {
        $rutas = [
            ['GET', 'api/materiales/asignacion-bodegas', 'can:consultar-kardex-materiales'],
            ['GET', 'api/materiales/asignacion-bodegas/historial', 'can:consultar-kardex-materiales'],
            ['POST', 'api/materiales/asignacion-bodegas', 'can:configurar-capacidad-materiales'],
            ['PUT', 'api/materiales/asignacion-bodegas/{id}', 'can:configurar-capacidad-materiales'],
            ['GET', 'api/materiales/bodegas', 'can:consultar-despachos-materiales'],
            ['GET', 'api/materiales/bodegas/historial', 'can:consultar-kardex-materiales'],
            ['POST', 'api/materiales/bodegas', 'can:configurar-capacidad-materiales'],
            ['PUT', 'api/materiales/bodegas/{id}', 'can:configurar-capacidad-materiales'],
            ['GET', 'api/materiales/capacidad/catalogo', 'can:consultar-kardex-materiales'],
            ['GET', 'api/materiales/capacidad/cargas', 'can:consultar-kardex-materiales'],
            ['GET', 'api/materiales/capacidad/historial', 'can:consultar-kardex-materiales'],
            ['GET', 'api/materiales/capacidad/almacenes/{id}/simular', 'can:consultar-kardex-materiales'],
            ['POST', 'api/materiales/capacidad/tipos-pallet', 'can:configurar-capacidad-materiales'],
            ['PUT', 'api/materiales/capacidad/tipos-pallet/{id}', 'can:configurar-capacidad-materiales'],
            ['PUT', 'api/materiales/capacidad/almacenes/{id}', 'can:configurar-capacidad-materiales'],
            ['PUT', 'api/materiales/capacidad/cargas/{id}', 'can:medir-cargas-materiales'],
            ['GET', 'api/materiales/supervision', 'can:consultar-kardex-materiales'],
            ['GET', 'api/materiales/supervision/catalogos', 'can:consultar-kardex-materiales'],
            ['GET', 'api/materiales/supervision/evidencia/{fuente}/{id}', 'can:consultar-kardex-materiales'],
            ['GET', 'api/materiales/recepciones/catalogos', 'can:consultar-recepciones-materiales'],
            ['GET', 'api/materiales/recepciones/folios-pendientes', 'can:consultar-recepciones-materiales'],
            ['GET', 'api/materiales/recepciones', 'can:consultar-recepciones-materiales'],
            ['GET', 'api/materiales/recepciones/registro-muestreo/en-blanco', 'can:consultar-recepciones-materiales'],
            ['GET', 'api/materiales/recepciones/{recepcionMaterial}/registro-muestreo', 'can:consultar-recepciones-materiales'],
            ['GET', 'api/materiales/recepciones/{recepcionMaterial}', 'can:consultar-recepciones-materiales'],
            ['POST', 'api/materiales/recepciones', 'can:gestionar-recepciones-materiales'],
            ['POST', 'api/materiales/recepciones/importaciones/previsualizar', 'can:gestionar-recepciones-materiales'],
            ['POST', 'api/materiales/recepciones/{recepcionMaterial}/confirmar', 'can:confirmar-recepciones-materiales'],
            ['POST', 'api/materiales/recepciones/{recepcionMaterial}/anular', 'can:anular-recepciones-materiales'],
            ['PUT', 'api/materiales/recepciones/{recepcionMaterial}/administrar', 'can:administrar-recepciones-materiales'],
            ['DELETE', 'api/materiales/recepciones/{recepcionMaterial}', 'can:eliminar-recepciones-materiales'],
            ['GET', 'api/materiales/recepciones/eliminaciones', 'can:consultar-eliminaciones-recepciones-materiales'],
            ['GET', 'api/materiales/recepciones/eliminaciones/{eliminacion}/fotos/{indice}', 'can:consultar-eliminaciones-recepciones-materiales'],
            ['POST', 'api/materiales/inventario/{folioMaterial}/bloquear', 'can:gestionar-bloqueos-materiales'],
            ['POST', 'api/materiales/inventario/{folioMaterial}/liberar-bloqueo', 'can:gestionar-bloqueos-materiales'],
            ['POST', 'api/materiales/despachos/{despachoMaterial}/entregas/{entrega}/regularizar', 'can:autorizar-despachos-tardios-materiales'],
            ['GET', 'api/materiales/almacenes', 'can:consultar-despachos-materiales'],
            ['GET', 'api/materiales/almacenes/exportar', 'can:consultar-despachos-materiales'],
            ['GET', 'api/materiales/almacenes/movimientos', 'can:consultar-kardex-materiales'],
            ['GET', 'api/materiales/transformaciones/consumos', 'can:consultar-transformaciones-materiales'],
            ['GET', 'api/materiales/transformaciones/consumos/excel', 'can:consultar-transformaciones-materiales'],
            ['GET', 'api/materiales/transformaciones/consumos/ordenes', 'can:consultar-transformaciones-materiales'],
            ['GET', 'api/materiales/transformaciones/ordenes/{ordenTransformacionMaterial}/consumos', 'can:consultar-transformaciones-materiales'],
            ['GET', 'api/materiales/transformaciones/recetas', 'can:consultar-transformaciones-materiales'],
            ['GET', 'api/materiales/transformaciones/ordenes', 'can:consultar-transformaciones-materiales'],
            ['GET', 'api/materiales/transformaciones/ordenes/{ordenTransformacionMaterial}', 'can:consultar-transformaciones-materiales'],
            ['GET', 'api/materiales/transformaciones/ordenes/{ordenTransformacionMaterial}/impresiones', 'can:consultar-transformaciones-materiales'],
            ['POST', 'api/materiales/transformaciones/recetas', 'can:administrar-recetas-materiales'],
            ['POST', 'api/materiales/transformaciones/recetas/{recetaMaterial}/versiones', 'can:administrar-recetas-materiales'],
            ['POST', 'api/materiales/transformaciones/ordenes', 'can:gestionar-transformaciones-materiales'],
            ['POST', 'api/materiales/transformaciones/ordenes/{ordenTransformacionMaterial}/planificar', 'can:gestionar-transformaciones-materiales'],
            ['POST', 'api/materiales/transformaciones/ordenes/{ordenTransformacionMaterial}/cancelar', 'can:gestionar-transformaciones-materiales'],
            ['POST', 'api/materiales/transformaciones/lotes/{loteTransformacionMaterial}/revertir', 'can:revertir-transformaciones-materiales'],
            ['POST', 'api/materiales/transformaciones/ordenes/{ordenTransformacionMaterial}/etiquetas', 'can:operar-transformaciones-materiales'],
            ['GET', 'api/romana/registro-pesaje/en-blanco', 'can:consultar-romana'],
            ['GET', 'api/materia-prima/hidrocooler/registro/en-blanco', 'can:consultar-hidrocooler-materia-prima'],
            ['GET', 'api/materia-prima/hidrocooler/registro', 'can:consultar-hidrocooler-materia-prima'],
            ['PUT', 'api/romana/recepciones/{recepcion}/corregir', 'can:corregir-recepciones-romana'],
            ['GET', 'api/validacion/registro/rrpp-01/en-blanco', 'can:consultar-validaciones-pallet'],
            ['PUT', 'api/administracion/usuarios/{usuario}', 'can:administrar-accesos'],
            ['GET', 'api/consultas/resumen', 'can:consultar-oficina-consultas'],
            ['GET', 'api/consultas/buscar', 'can:consultar-oficina-consultas'],
            ['GET', 'api/consultas/productores/{productorCsg}', 'can:consultar-oficina-consultas'],
            ['POST', 'api/consultas/sag', 'can:consultar-sag'],
            ['POST', 'api/consultas/productores/{productorCsg}/clientes', 'can:asociar-productores-csg'],
        ];

        $registradas = collect(Route::getRoutes()->getRoutes());

        foreach ($rutas as [$metodo, $uri, $permiso]) {
            $coincidencias = $registradas->filter(
                fn ($ruta): bool => $ruta->uri() === $uri
                    && in_array($metodo, $ruta->methods(), true),
            );

            $this->assertCount(1, $coincidencias, "La ruta {$metodo} {$uri} debe registrarse exactamente una vez.");
            $middleware = $coincidencias->first()->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, "La ruta {$metodo} {$uri} debe requerir autenticación.");
            $this->assertContains($permiso, $middleware, "La ruta {$metodo} {$uri} debe conservar su autorización.");
        }
    }

    public function test_bootstrap_no_registra_proveedores_dedicados_a_rutas_api(): void
    {
        $proveedores = require base_path('bootstrap/providers.php');

        $this->assertNotContains('App\\Providers\\ImportacionRecepcionesMaterialesServiceProvider', $proveedores);
        $this->assertNotContains('App\\Providers\\RecepcionMaterialServiceProvider', $proveedores);
        $this->assertNotContains('App\\Providers\\TransformacionMaterialServiceProvider', $proveedores);
        $this->assertFileDoesNotExist(app_path('Providers/ImportacionRecepcionesMaterialesServiceProvider.php'));
        $this->assertFileDoesNotExist(app_path('Providers/RecepcionMaterialServiceProvider.php'));
        $this->assertFileDoesNotExist(app_path('Providers/TransformacionMaterialServiceProvider.php'));
        $this->assertFileDoesNotExist(base_path('routes/materiales-almacenes.php'));

        $proveedorUsuarios = file_get_contents(app_path('Providers/AdministracionUsuarioServiceProvider.php'));
        $this->assertIsString($proveedorUsuarios);
        $this->assertStringNotContainsString('Route::', $proveedorUsuarios);

        $proveedorCustodia = file_get_contents(
            app_path('Providers/CustodiaDistribuidaMaterialesServiceProvider.php'),
        );
        $this->assertIsString($proveedorCustodia);
        $this->assertStringNotContainsString('loadRoutesFrom', $proveedorCustodia);
    }
}
