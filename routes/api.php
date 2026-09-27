<?php

use App\Http\Controllers\Api\AccesoOficinaController;
use App\Http\Controllers\Api\AccesoTabletController;
use App\Http\Controllers\Api\AdministracionAccesoController;
use App\Http\Controllers\Api\AdministracionTemporadaController;
use App\Http\Controllers\Api\AdministracionValidacionController;
use App\Http\Controllers\Api\AlmacenMaterialController;
use App\Http\Controllers\Api\AndenController;
use App\Http\Controllers\Api\ArchivoTemporadaController;
use App\Http\Controllers\Api\BandaOperacionalController;
use App\Http\Controllers\Api\BloqueoMaterialController;
use App\Http\Controllers\Api\CamaraController;
use App\Http\Controllers\Api\CambioPasswordUsuarioController;
use App\Http\Controllers\Api\CargaController;
use App\Http\Controllers\Api\CatalogoJerarquicoValidacionController;
use App\Http\Controllers\Api\CatalogoMaterialController;
use App\Http\Controllers\Api\CatalogoValidacionController;
use App\Http\Controllers\Api\CierreTemporadaController;
use App\Http\Controllers\Api\ClienteGlobalController;
use App\Http\Controllers\Api\ComparacionCiclosPlanificadorController;
use App\Http\Controllers\Api\CondicionSagController;
use App\Http\Controllers\Api\ConfiguracionCamaraController;
use App\Http\Controllers\Api\ConsultaOficinaController;
use App\Http\Controllers\Api\ConsultaSagController;
use App\Http\Controllers\Api\ContextoOficinaController;
use App\Http\Controllers\Api\ControlAmbientalController;
use App\Http\Controllers\Api\CorreccionItemMaterialController;
use App\Http\Controllers\Api\CuentaCorrienteEnvaseController;
use App\Http\Controllers\Api\DefectoRecepcionMpController;
use App\Http\Controllers\Api\DesocupacionCamaraController;
use App\Http\Controllers\Api\DespachoFrigorificoController;
use App\Http\Controllers\Api\DespachoMaterialController;
use App\Http\Controllers\Api\EmbarqueController;
use App\Http\Controllers\Api\EvacuacionEmergenciaController;
use App\Http\Controllers\Api\FolioPrefrioController;
use App\Http\Controllers\Api\FrutaProcesoController;
use App\Http\Controllers\Api\GuiaDespachoEnvaseController;
use App\Http\Controllers\Api\HidrocoolerMateriaPrimaController;
use App\Http\Controllers\Api\ImportacionCatalogoMaterialController;
use App\Http\Controllers\Api\ImportacionProductosRecepcionMaterialController;
use App\Http\Controllers\Api\ImpresionEtiquetaMaterialController;
use App\Http\Controllers\Api\InspeccionSagController;
use App\Http\Controllers\Api\IntegridadOperacionalController;
use App\Http\Controllers\Api\MateriaPrimaController;
use App\Http\Controllers\Api\MovimientoController;
use App\Http\Controllers\Api\NotificacionOperacionalController;
use App\Http\Controllers\Api\OperacionAhoraController;
use App\Http\Controllers\Api\PanelGerencialController;
use App\Http\Controllers\Api\PerfilAccesoController;
use App\Http\Controllers\Api\PerfilImpresionEtiquetaController;
use App\Http\Controllers\Api\PinOperacionalController;
use App\Http\Controllers\Api\PlanOperacionalController;
use App\Http\Controllers\Api\PlanoPlantaController;
use App\Http\Controllers\Api\ProcesoPrefrioController;
use App\Http\Controllers\Api\ProveedorMaterialController;
use App\Http\Controllers\Api\RecepcionMaterialController;
use App\Http\Controllers\Api\RecepcionRomanaController;
use App\Http\Controllers\Api\ReinicioOperacionalController;
use App\Http\Controllers\Api\ReplayCicloPlanificadorController;
use App\Http\Controllers\Api\RetornoPackingController;
use App\Http\Controllers\Api\SaludPlanificadorController;
use App\Http\Controllers\Api\SesionesAccesoAdministracionController;
use App\Http\Controllers\Api\SesionEstibaController;
use App\Http\Controllers\Api\TransformacionMaterialController;
use App\Http\Controllers\Api\TunelPrefrioController;
use App\Http\Controllers\Api\ValidacionMpController;
use App\Http\Controllers\Api\ValidacionPalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/acceso-tablet', [AccesoTabletController::class, 'store'])->middleware('throttle:6,1');
Route::post('/acceso-oficina', [AccesoOficinaController::class, 'store'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::put('/usuario/password', CambioPasswordUsuarioController::class)->middleware('throttle:6,1');
    Route::get('/usuario/pin', [PinOperacionalController::class, 'show']);
    Route::put('/usuario/pin', [PinOperacionalController::class, 'update'])->middleware('throttle:6,1');
    Route::get('/oficina/contexto', ContextoOficinaController::class);
    Route::get('/user', fn (Request $request) => $request->user());
    Route::get('/demo/autorizar', [AccesoOficinaController::class, 'autorizarDemo']);
    Route::get('/gerencia/resumen', PanelGerencialController::class)
        ->middleware('can:consultar-panel-gerencial');
    Route::get('/operacion-ahora', OperacionAhoraController::class)
        ->middleware('can:consultar-panel-gerencial');
    Route::get('/operacion-ahora/planificador/comparacion', ComparacionCiclosPlanificadorController::class)
        ->middleware('can:consultar-panel-gerencial');
    Route::get('/operacion-ahora/planificador/replay', ReplayCicloPlanificadorController::class)
        ->middleware('can:consultar-panel-gerencial');
    Route::get('/operacion-ahora/plano/recorrido', [PlanoPlantaController::class, 'recorrido'])
        ->middleware('can:consultar-panel-gerencial');
    Route::put('/administracion/operacion-ahora/plano', [PlanoPlantaController::class, 'update'])
        ->middleware('can:administrar-plano-planta');
    Route::get('/administracion/integridad-operacional', [IntegridadOperacionalController::class, 'index'])
        ->middleware('can:consultar-integridad-operacional');
    Route::post('/administracion/integridad-operacional/auditar', [IntegridadOperacionalController::class, 'auditar'])
        ->middleware('can:ejecutar-integridad-operacional');
    Route::get('/administracion/planificador/salud', SaludPlanificadorController::class)
        ->middleware('can:consultar-integridad-operacional');

    Route::middleware('can:consultar-oficina-consultas')->prefix('consultas')->group(function () {
        Route::get('/resumen', [ConsultaOficinaController::class, 'resumen']);
        Route::get('/buscar', [ConsultaOficinaController::class, 'buscar']);
        Route::get('/trazabilidad', [ConsultaOficinaController::class, 'trazabilidad']);
        Route::get('/trazabilidad/exportar', [ConsultaOficinaController::class, 'exportarTrazabilidad']);
        Route::get('/catalogos', [ConsultaOficinaController::class, 'catalogos']);
        Route::get('/productores', [ConsultaOficinaController::class, 'productores']);
        Route::get('/productores/{productorCsg}', [ConsultaOficinaController::class, 'productor']);
    });
    Route::post('/consultas/sag', [ConsultaSagController::class, 'store'])
        ->middleware(['can:consultar-sag', 'throttle:30,1']);
    Route::post('/consultas/productores/{productorCsg}/clientes', [ConsultaOficinaController::class, 'asociar'])
        ->middleware('can:asociar-productores-csg');

    Route::middleware('can:consultar-romana')->prefix('romana')->group(function () {
        Route::get('/catalogos', [RecepcionRomanaController::class, 'catalogos']);
        Route::get('/registro-pesaje/en-blanco', [RecepcionRomanaController::class, 'registroPesajeEnBlanco']);
        Route::get('/recepciones', [RecepcionRomanaController::class, 'index']);
        Route::get('/recepciones/{recepcion}', [RecepcionRomanaController::class, 'show']);
        Route::get('/recepciones/{recepcion}/aviso-recibo', [RecepcionRomanaController::class, 'avisoRecibo']);
    });
    Route::middleware('can:operar-romana')->prefix('romana')->group(function () {
        Route::post('/recepciones', [RecepcionRomanaController::class, 'store']);
        Route::put('/recepciones/{recepcion}', [RecepcionRomanaController::class, 'update']);
        Route::post('/recepciones/{recepcion}/confirmar-ingreso', [RecepcionRomanaController::class, 'confirmarIngreso']);
        Route::post('/recepciones/{recepcion}/pesajes-envases', [RecepcionRomanaController::class, 'registrarPesajeEnvases']);
        Route::post(
            '/recepciones/{recepcion}/pesajes-envases/{pesaje}/anular',
            [RecepcionRomanaController::class, 'anularPesajeEnvases'],
        );
        Route::post('/recepciones/{recepcion}/cerrar', [RecepcionRomanaController::class, 'cerrar']);
    });
    Route::put('/romana/recepciones/{recepcion}/corregir', [RecepcionRomanaController::class, 'corregir'])
        ->middleware('can:corregir-recepciones-romana');
    Route::middleware('can:consultar-cuenta-envases')->prefix('envases/cuenta-corriente')->group(function () {
        Route::get('/catalogos', [CuentaCorrienteEnvaseController::class, 'catalogos']);
        Route::get('/movimientos', [CuentaCorrienteEnvaseController::class, 'index']);
    });
    Route::post('/envases/cuenta-corriente/movimientos/{movimientoEnvase}/revisar', [CuentaCorrienteEnvaseController::class, 'revisar'])
        ->middleware('can:revisar-cuenta-envases');
    Route::post('/envases/cuenta-corriente/movimientos/{movimientoEnvase}/corregir-propiedad', [CuentaCorrienteEnvaseController::class, 'corregirPropiedad'])
        ->middleware('can:corregir-propiedad-envases');
    Route::post('/envases/cuenta-corriente/movimientos/{movimientoEnvase}/corregir-cantidad', [CuentaCorrienteEnvaseController::class, 'corregirCantidad'])
        ->middleware('can:corregir-cantidad-envases');
    Route::middleware('can:consultar-cuenta-envases')->prefix('envases/guias-despacho')->group(function () {
        Route::get('/catalogos', [GuiaDespachoEnvaseController::class, 'catalogos']);
        Route::get('/', [GuiaDespachoEnvaseController::class, 'index']);
        Route::get('/{guiaDespachoEnvase}', [GuiaDespachoEnvaseController::class, 'show']);
        Route::get('/{guiaDespachoEnvase}/documento', [GuiaDespachoEnvaseController::class, 'documento']);
        Route::get('/{guiaDespachoEnvase}/comprobante-anulacion', [GuiaDespachoEnvaseController::class, 'comprobanteAnulacion']);
    });
    Route::middleware('can:gestionar-despacho-envases')->prefix('envases/guias-despacho')->group(function () {
        Route::post('/', [GuiaDespachoEnvaseController::class, 'store']);
        Route::put('/{guiaDespachoEnvase}', [GuiaDespachoEnvaseController::class, 'update']);
        Route::post('/{guiaDespachoEnvase}/confirmar', [GuiaDespachoEnvaseController::class, 'confirmar']);
        Route::post('/{guiaDespachoEnvase}/cancelar', [GuiaDespachoEnvaseController::class, 'cancelar']);
    });
    Route::post('/envases/guias-despacho/{guiaDespachoEnvase}/anular', [GuiaDespachoEnvaseController::class, 'anular'])
        ->middleware('can:anular-despacho-envases');

    Route::get('/camaras', [CamaraController::class, 'index']);
    Route::get('/camaras/{camara}/plano', [CamaraController::class, 'plano']);
    Route::get('/condiciones-sag', [CondicionSagController::class, 'index']);

    Route::middleware('can:consultar-control-ambiental')
        ->prefix('control-ambiental')
        ->group(function () {
            Route::get('/estado', [ControlAmbientalController::class, 'estado']);
            Route::get('/registros', [ControlAmbientalController::class, 'index']);
        });
    Route::post(
        '/control-ambiental/camaras/{camara}/registros',
        [ControlAmbientalController::class, 'store'],
    )->middleware('can:registrar-control-ambiental');
    Route::put(
        '/control-ambiental/registros/{registro}/corregir',
        [ControlAmbientalController::class, 'corregir'],
    )->middleware('can:corregir-control-ambiental');

    Route::middleware('can:supervisar-camaras-productos')
        ->prefix('desocupaciones-camara')
        ->group(function () {
            Route::get('/candidatas', [DesocupacionCamaraController::class, 'candidatas']);
            Route::post('/{camara}', [DesocupacionCamaraController::class, 'store']);
            Route::post('/{camara}/cancelar', [DesocupacionCamaraController::class, 'cancelar']);
        });
    Route::middleware('can:supervisar-camaras-productos')
        ->prefix('evacuaciones-emergencia')
        ->group(function () {
            Route::post('/{camara}', [EvacuacionEmergenciaController::class, 'store']);
            Route::post('/{camara}/cancelar', [EvacuacionEmergenciaController::class, 'cancelar']);
        });

    Route::middleware('can:consultar-inspeccion-sag')->prefix('inspeccion-sag')->group(function () {
        Route::get('/resumen', [InspeccionSagController::class, 'resumen']);
        Route::get('/catalogos', [InspeccionSagController::class, 'catalogos']);
        Route::get('/folios/opciones', [InspeccionSagController::class, 'opcionesFolios']);
        Route::get('/folios', [InspeccionSagController::class, 'folios']);
        Route::get('/lotes', [InspeccionSagController::class, 'index']);
        Route::get('/lotes/{loteInspeccionSag}', [InspeccionSagController::class, 'show']);
    });
    Route::middleware('can:gestionar-inspeccion-sag')->prefix('inspeccion-sag')->group(function () {
        Route::post('/lotes', [InspeccionSagController::class, 'store']);
        Route::post('/lotes/{loteInspeccionSag}/iniciar', [InspeccionSagController::class, 'iniciar']);
        Route::post('/lotes/{loteInspeccionSag}/resultados/{resultado}/resolver', [InspeccionSagController::class, 'resolver']);
        Route::post('/lotes/{loteInspeccionSag}/finalizar', [InspeccionSagController::class, 'finalizar']);
        Route::post('/lotes/{loteInspeccionSag}/cancelar', [InspeccionSagController::class, 'cancelar']);
    });

    Route::middleware('can:consultar-prefrio')->group(function () {
        Route::get('/prefrio/tuneles', [TunelPrefrioController::class, 'index']);
        Route::get('/prefrio/tuneles/{tunelPrefrio}', [TunelPrefrioController::class, 'show']);
        Route::get('/prefrio/folios-disponibles', [FolioPrefrioController::class, 'index']);
        Route::get('/prefrio/procesos', [ProcesoPrefrioController::class, 'index']);
        Route::get('/prefrio/resumen', [ProcesoPrefrioController::class, 'resumen']);
        Route::get('/prefrio/procesos/{procesoPrefrio}', [ProcesoPrefrioController::class, 'show']);
    });
    Route::middleware('can:operar-prefrio')->group(function () {
        Route::post('/prefrio/procesos', [ProcesoPrefrioController::class, 'store']);
        Route::post('/prefrio/procesos/{procesoPrefrio}/folios', [ProcesoPrefrioController::class, 'agregarFolio']);
        Route::post('/prefrio/procesos/{procesoPrefrio}/folios/{asignacionPrefrio}/retirar', [ProcesoPrefrioController::class, 'retirarFolio']);
        Route::post('/prefrio/procesos/{procesoPrefrio}/confirmar-armado', [ProcesoPrefrioController::class, 'confirmarArmado']);
        Route::post('/prefrio/procesos/{procesoPrefrio}/iniciar', [ProcesoPrefrioController::class, 'iniciar']);
        Route::post('/prefrio/procesos/{procesoPrefrio}/eventos/{tipo}', [ProcesoPrefrioController::class, 'registrarEvento']);
        Route::post('/prefrio/procesos/{procesoPrefrio}/verificar', [ProcesoPrefrioController::class, 'enviarAVerificacion']);
    });
    Route::middleware('can:supervisar-prefrio')->group(function () {
        Route::post('/prefrio/procesos/{procesoPrefrio}/aprobar', [ProcesoPrefrioController::class, 'aprobar']);
        Route::post('/prefrio/procesos/{procesoPrefrio}/reprocesar', [ProcesoPrefrioController::class, 'reprocesar']);
        Route::post('/prefrio/procesos/{procesoPrefrio}/cancelar', [ProcesoPrefrioController::class, 'cancelar']);
    });
    Route::put(
        '/administracion/prefrio/procesos/{procesoPrefrio}/corregir',
        [ProcesoPrefrioController::class, 'corregir'],
    )->middleware('can:corregir-procesos-prefrio');
    Route::middleware('can:administrar-tuneles-prefrio')->group(function () {
        Route::get('/administracion/prefrio/tuneles/siguiente-codigo', [TunelPrefrioController::class, 'siguienteCodigo']);
        Route::post('/administracion/prefrio/tuneles', [TunelPrefrioController::class, 'store']);
        Route::put('/administracion/prefrio/tuneles/{tunelPrefrio}', [TunelPrefrioController::class, 'update']);
    });

    Route::get('/validacion/catalogos', CatalogoValidacionController::class)
        ->middleware('can:validar-pallets');
    Route::get('/validacion/pallets/mi-sesion', [ValidacionPalletController::class, 'miSesion'])
        ->middleware('can:validar-pallets');
    Route::post('/validacion/pallets', [ValidacionPalletController::class, 'store'])
        ->middleware('can:validar-pallets');
    Route::put(
        '/validacion/pallets/{validacionPallet}/corregir',
        [ValidacionPalletController::class, 'corregir'],
    )->middleware('can:corregir-validaciones-pallet');
    Route::middleware('can:consultar-validaciones-pallet')->group(function () {
        Route::get('/validacion/pallets', [ValidacionPalletController::class, 'index']);
        Route::get('/validacion/registro/opciones', [ValidacionPalletController::class, 'opciones']);
        Route::get('/validacion/registro/rrpp-01/en-blanco', [ValidacionPalletController::class, 'exportarEnBlanco']);
        Route::get('/validacion/registro/rrpp-01', [ValidacionPalletController::class, 'exportar']);
        Route::get('/validacion/pallets/{validacionPallet}', [ValidacionPalletController::class, 'show']);
    });
    Route::middleware('can:validar-mp')->prefix('validacion-mp')->group(function () {
        Route::get('/pendientes', [ValidacionMpController::class, 'pendientes']);
        Route::get('/recepciones/buscar/{numeroRecepcion}', [ValidacionMpController::class, 'buscar']);
        Route::get('/recepciones/{recepcion}/catalogos', [ValidacionMpController::class, 'catalogos']);
        Route::post('/recepciones/{recepcion}/tomar', [ValidacionMpController::class, 'tomar']);
        Route::post('/validaciones/{validacionMp}/confirmar', [ValidacionMpController::class, 'confirmar']);
        Route::get('/recepciones/{recepcion}/defectos', [DefectoRecepcionMpController::class, 'porRecepcion']);
        Route::post('/recepciones/{recepcion}/defectos', [DefectoRecepcionMpController::class, 'store'])
            ->middleware('throttle:30,1');
    });
    Route::middleware('can:auditar-defectos-recepcion-mp')->prefix('materia-prima/defectos-recepcion')->group(function () {
        Route::get('/', [DefectoRecepcionMpController::class, 'index']);
        Route::get('/temporadas', [DefectoRecepcionMpController::class, 'temporadas']);
        Route::get('/exportaciones/{formato}', [DefectoRecepcionMpController::class, 'exportar']);
        Route::get('/{defecto}', [DefectoRecepcionMpController::class, 'show']);
    });
    Route::get('/materia-prima/defectos-recepcion/{defecto}/evidencias/{evidencia}',
        [DefectoRecepcionMpController::class, 'evidencia']);
    Route::middleware('can:consultar-materia-prima')->prefix('materia-prima')->group(function () {
        Route::get('/resumen', [MateriaPrimaController::class, 'resumen']);
        Route::get('/catalogos', [MateriaPrimaController::class, 'catalogos']);
        Route::get('/segmentos-pendientes', [MateriaPrimaController::class, 'segmentosPendientes']);
        Route::get('/lotes', [MateriaPrimaController::class, 'index']);
        Route::get('/lotes/{loteMateriaPrima}', [MateriaPrimaController::class, 'show']);
    });
    Route::middleware('can:gestionar-lotes-materia-prima')->prefix('materia-prima')->group(function () {
        Route::post('/lotes', [MateriaPrimaController::class, 'store']);
        Route::put('/lotes/{loteMateriaPrima}', [MateriaPrimaController::class, 'update']);
        Route::put('/lotes/{loteMateriaPrima}/corregir-origen', [MateriaPrimaController::class, 'corregirOrigen']);
        Route::post('/lotes/{loteMateriaPrima}/confirmar', [MateriaPrimaController::class, 'confirmar']);
        Route::post('/lotes/{loteMateriaPrima}/asignar-camara', [MateriaPrimaController::class, 'asignarCamara']);
    });
    Route::middleware('can:consultar-hidrocooler-materia-prima')->prefix('materia-prima/hidrocooler')->group(function () {
        Route::get('/registro/en-blanco', [HidrocoolerMateriaPrimaController::class, 'registroEnBlanco']);
        Route::get('/registro', [HidrocoolerMateriaPrimaController::class, 'registro']);
        Route::get('/resumen', [HidrocoolerMateriaPrimaController::class, 'resumen']);
        Route::get('/lotes', [HidrocoolerMateriaPrimaController::class, 'index']);
    });
    Route::middleware('can:operar-hidrocooler-materia-prima')->prefix('materia-prima')->group(function () {
        Route::post('/lotes/{loteMateriaPrima}/hidrocooler/iniciar', [MateriaPrimaController::class, 'iniciarHidrocooler']);
        Route::post('/lotes/{loteMateriaPrima}/hidrocooler/completar', [MateriaPrimaController::class, 'completarHidrocooler']);
    });
    Route::post('/materia-prima/lotes/{loteMateriaPrima}/hidrocooler/liberar', [MateriaPrimaController::class, 'liberarHidrocooler'])
        ->middleware('can:supervisar-lotes-materia-prima');
    Route::post('/materia-prima/lotes/{loteMateriaPrima}/anular', [MateriaPrimaController::class, 'anular'])
        ->middleware('can:supervisar-lotes-materia-prima');
    Route::middleware('can:consultar-fruta-proceso')->prefix('materia-prima/fruta-proceso')->group(function () {
        Route::get('/resumen', [FrutaProcesoController::class, 'resumen']);
        Route::get('/catalogos', [RetornoPackingController::class, 'catalogos']);
        Route::get('/lotes', [FrutaProcesoController::class, 'index']);
        Route::get('/lotes/{loteMateriaPrima}', [FrutaProcesoController::class, 'show']);
    });
    Route::post('/materia-prima/fruta-proceso/lotes/{loteMateriaPrima}/entregas', [FrutaProcesoController::class, 'store'])
        ->middleware('can:entregar-fruta-proceso');
    Route::post('/materia-prima/fruta-proceso/entregas/{entregaFrutaProceso}/anular', [FrutaProcesoController::class, 'anular'])
        ->middleware('can:anular-entregas-fruta-proceso');
    Route::post('/materia-prima/fruta-proceso/entregas/{entregaFrutaProceso}/retornos', [RetornoPackingController::class, 'store'])
        ->middleware('can:entregar-fruta-proceso');
    Route::post('/materia-prima/fruta-proceso/retornos/{retornoPacking}/anular', [RetornoPackingController::class, 'anular'])
        ->middleware('can:anular-entregas-fruta-proceso');
    Route::post('/materia-prima/fruta-proceso/sublotes/{subloteRetornoPacking}/ubicar', [RetornoPackingController::class, 'ubicar'])
        ->middleware('can:entregar-fruta-proceso');
    Route::prefix('administracion/validacion')
        ->group(function () {
            Route::middleware('can:consultar-catalogos-validacion')->group(function () {
                Route::get('/', [AdministracionValidacionController::class, 'index']);
                Route::get('/temporadas/{temporada}/catalogo', [CatalogoJerarquicoValidacionController::class, 'index']);
            });
            Route::middleware('can:administrar-catalogos-validacion')->group(function () {
                Route::post('/marcas', [CatalogoJerarquicoValidacionController::class, 'storeMarca']);
                Route::put('/marcas/{marcaValidacion}', [CatalogoJerarquicoValidacionController::class, 'updateMarca']);
                Route::delete('/marcas/{marcaValidacion}', [CatalogoJerarquicoValidacionController::class, 'destroyMarca']);
                Route::post('/especies', [CatalogoJerarquicoValidacionController::class, 'storeEspecie']);
                Route::put('/especies/{especieValidacion}', [CatalogoJerarquicoValidacionController::class, 'updateEspecie']);
                Route::delete('/especies/{especieValidacion}', [CatalogoJerarquicoValidacionController::class, 'destroyEspecie']);
                Route::post('/categorias', [CatalogoJerarquicoValidacionController::class, 'storeCategoria']);
                Route::put('/categorias/{categoriaValidacion}', [CatalogoJerarquicoValidacionController::class, 'updateCategoria']);
                Route::delete('/categorias/{categoriaValidacion}', [CatalogoJerarquicoValidacionController::class, 'destroyCategoria']);
                Route::post('/variedades', [CatalogoJerarquicoValidacionController::class, 'storeVariedad']);
                Route::put('/variedades/{variedadValidacion}', [CatalogoJerarquicoValidacionController::class, 'updateVariedad']);
                Route::delete('/variedades/{variedadValidacion}', [CatalogoJerarquicoValidacionController::class, 'destroyVariedad']);
                Route::post('/calibres', [CatalogoJerarquicoValidacionController::class, 'storeCalibre']);
                Route::put('/calibres/{calibreValidacion}', [CatalogoJerarquicoValidacionController::class, 'updateCalibre']);
                Route::delete('/calibres/{calibreValidacion}', [CatalogoJerarquicoValidacionController::class, 'destroyCalibre']);
                Route::post('/envases', [CatalogoJerarquicoValidacionController::class, 'storeEnvase']);
                Route::put('/envases/{envaseValidacion}', [CatalogoJerarquicoValidacionController::class, 'updateEnvase']);
                Route::delete('/envases/{envaseValidacion}', [CatalogoJerarquicoValidacionController::class, 'destroyEnvase']);
                Route::post('/csg', [CatalogoJerarquicoValidacionController::class, 'storeCsg']);
                Route::put('/csg/{csgValidacion}', [CatalogoJerarquicoValidacionController::class, 'updateCsg']);
                Route::delete('/csg/{csgValidacion}', [CatalogoJerarquicoValidacionController::class, 'destroyCsg']);
                Route::post('/articulos', [AdministracionValidacionController::class, 'storeArticulo']);
                Route::put('/articulos/{articuloValidacion}', [AdministracionValidacionController::class, 'updateArticulo']);
                Route::post('/origenes', [AdministracionValidacionController::class, 'storeOrigen']);
                Route::put('/origenes/{origenValidacion}', [AdministracionValidacionController::class, 'updateOrigen']);
                Route::post('/combinaciones', [AdministracionValidacionController::class, 'storeCombinacion']);
                Route::put('/combinaciones/{combinacionValidacion}', [AdministracionValidacionController::class, 'updateCombinacion']);
                Route::post('/importaciones/previsualizar', [AdministracionValidacionController::class, 'previsualizarImportacion']);
                Route::post('/importaciones/{importacionValidacion}/confirmar', [AdministracionValidacionController::class, 'confirmarImportacion']);
            });
        });

    Route::get('/notificaciones-operacionales', [NotificacionOperacionalController::class, 'index']);
    Route::get('/notificaciones-operacionales/resumen', [NotificacionOperacionalController::class, 'resumen']);
    Route::post('/notificaciones-operacionales/{notificacionOperacional}/leer', [NotificacionOperacionalController::class, 'marcarLeida']);
    Route::post('/notificaciones-operacionales/{notificacionOperacional}/confirmar', [NotificacionOperacionalController::class, 'confirmar']);

    Route::middleware('can:consultar-despachos-materiales')->group(function () {
        Route::get('/materiales/catalogo', [CatalogoMaterialController::class, 'catalogo']);
        Route::get('/materiales/inventario', [DespachoMaterialController::class, 'inventario']);
        Route::get('/materiales/despachos', [DespachoMaterialController::class, 'index']);
        Route::get('/materiales/despachos/camareros', [DespachoMaterialController::class, 'camareros'])
            ->middleware('can:gestionar-despachos-materiales');
        Route::get('/materiales/despachos/{despachoMaterial}', [DespachoMaterialController::class, 'show']);
    });
    Route::get('/materiales/kardex', [DespachoMaterialController::class, 'kardex'])
        ->middleware('can:consultar-kardex-materiales');
    Route::post('/materiales/inventario/{folioMaterial}/corregir-item', [CorreccionItemMaterialController::class, 'store'])
        ->middleware('can:corregir-items-estibados-materiales');
    Route::middleware('can:gestionar-bloqueos-materiales')->group(function () {
        Route::post('/materiales/inventario/{folioMaterial}/bloquear', [BloqueoMaterialController::class, 'bloquear']);
        Route::post('/materiales/inventario/{folioMaterial}/liberar-bloqueo', [BloqueoMaterialController::class, 'liberar']);
    });
    Route::post('/materiales/despachos', [DespachoMaterialController::class, 'store'])
        ->middleware('can:gestionar-despachos-materiales');
    Route::post('/materiales/despachos/{despachoMaterial}/reasignar', [DespachoMaterialController::class, 'reasignar'])
        ->middleware('can:gestionar-despachos-materiales');
    Route::post('/materiales/despachos/directos', [DespachoMaterialController::class, 'directo'])
        ->middleware('can:gestionar-despachos-materiales');
    Route::post('/materiales/despachos/{despachoMaterial}/retirar', [DespachoMaterialController::class, 'retirar'])
        ->middleware('can:retirar-materiales');
    Route::post('/materiales/despachos/{despachoMaterial}/cancelar', [DespachoMaterialController::class, 'cancelar'])
        ->middleware('can:cancelar-despachos-materiales');

    Route::middleware('can:consultar-despachos-materiales')->prefix('materiales/almacenes')->group(function () {
        Route::get('/', [AlmacenMaterialController::class, 'index']);
        Route::get('/exportar', [AlmacenMaterialController::class, 'exportar']);
    });
    Route::get('/materiales/almacenes/movimientos', [AlmacenMaterialController::class, 'movimientos'])
        ->middleware('can:consultar-kardex-materiales');
    Route::get('/materiales/almacenes/movimientos/exportar', [AlmacenMaterialController::class, 'exportarMovimientos'])
        ->middleware('can:consultar-kardex-materiales');
    Route::post('/materiales/almacenes/movimientos', [AlmacenMaterialController::class, 'store']);

    Route::prefix('materiales/recepciones')->group(function () {
        Route::middleware('can:consultar-recepciones-materiales')->group(function () {
            Route::get('/catalogos', [RecepcionMaterialController::class, 'catalogos']);
            Route::get('/folios-pendientes', [RecepcionMaterialController::class, 'foliosPendientes']);
            Route::get('/perfiles-impresion', [PerfilImpresionEtiquetaController::class, 'index']);
            Route::get('/', [RecepcionMaterialController::class, 'index']);
            Route::get('/registro-muestreo/en-blanco', [RecepcionMaterialController::class, 'registroMuestreoEnBlanco']);
            Route::get('/{recepcionMaterial}/registro-muestreo', [RecepcionMaterialController::class, 'registroMuestreo']);
            Route::get('/{recepcionMaterial}/impresiones', [ImpresionEtiquetaMaterialController::class, 'index']);
            Route::get('/{recepcionMaterial}', [RecepcionMaterialController::class, 'show']);
        });

        Route::middleware('can:gestionar-recepciones-materiales')->group(function () {
            Route::post('/importaciones/previsualizar', ImportacionProductosRecepcionMaterialController::class);
            Route::post('/', [RecepcionMaterialController::class, 'store']);
            Route::put('/{recepcionMaterial}', [RecepcionMaterialController::class, 'update']);
            Route::post('/{recepcionMaterial}/confirmar', [RecepcionMaterialController::class, 'confirmar']);
        });
        Route::post('/{recepcionMaterial}/etiquetas', [ImpresionEtiquetaMaterialController::class, 'store'])
            ->middleware('can:imprimir-etiquetas-materiales');
        Route::post('/trabajos-impresion/{trabajoImpresionMaterial}/resultado', [ImpresionEtiquetaMaterialController::class, 'resultado'])
            ->middleware('can:imprimir-etiquetas-materiales');

        Route::post('/{recepcionMaterial}/anular', [RecepcionMaterialController::class, 'anular'])
            ->middleware('can:anular-recepciones-materiales');
        Route::put('/{recepcionMaterial}/administrar', [RecepcionMaterialController::class, 'administrar'])
            ->middleware('can:administrar-recepciones-materiales');
        Route::delete('/{recepcionMaterial}', [RecepcionMaterialController::class, 'destroy'])
            ->middleware('can:administrar-recepciones-materiales');
    });

    Route::prefix('materiales/transformaciones')->group(function () {
        Route::middleware('can:consultar-transformaciones-materiales')->group(function () {
            Route::get('/recetas', [TransformacionMaterialController::class, 'recetas']);
            Route::get('/ordenes', [TransformacionMaterialController::class, 'ordenes']);
            Route::get('/ordenes/{ordenTransformacionMaterial}/impresiones', [ImpresionEtiquetaMaterialController::class, 'indexTransformacion']);
            Route::get('/ordenes/{ordenTransformacionMaterial}', [TransformacionMaterialController::class, 'mostrarOrden']);
        });

        Route::middleware('can:administrar-recetas-materiales')->group(function () {
            Route::post('/recetas', [TransformacionMaterialController::class, 'guardarReceta']);
            Route::post('/recetas/{recetaMaterial}/versiones', [TransformacionMaterialController::class, 'guardarVersionReceta']);
        });

        Route::middleware('can:gestionar-transformaciones-materiales')->group(function () {
            Route::post('/ordenes', [TransformacionMaterialController::class, 'guardarOrden']);
            Route::post('/ordenes/{ordenTransformacionMaterial}/planificar', [TransformacionMaterialController::class, 'planificar']);
            Route::post('/ordenes/{ordenTransformacionMaterial}/cancelar', [TransformacionMaterialController::class, 'cancelar']);
        });
        Route::post('/lotes/{loteTransformacionMaterial}/revertir', [TransformacionMaterialController::class, 'revertirLote'])
            ->middleware('can:revertir-transformaciones-materiales');

        Route::middleware('can:operar-transformaciones-materiales')->group(function () {
            Route::post('/ordenes/{ordenTransformacionMaterial}/iniciar', [TransformacionMaterialController::class, 'iniciar']);
            Route::post('/ordenes/{ordenTransformacionMaterial}/lotes', [TransformacionMaterialController::class, 'abrirLote']);
            Route::post('/lotes/{loteTransformacionMaterial}/cerrar', [TransformacionMaterialController::class, 'cerrarLote']);
            Route::post('/ordenes/{ordenTransformacionMaterial}/cerrar', [TransformacionMaterialController::class, 'cerrarOrden']);
            Route::post('/ordenes/{ordenTransformacionMaterial}/etiquetas', [ImpresionEtiquetaMaterialController::class, 'storeTransformacion']);
            Route::post('/trabajos-impresion/{trabajoImpresionMaterial}/resultado', [ImpresionEtiquetaMaterialController::class, 'resultado']);
        });
    });

    Route::middleware('can:consultar-cargas-operacion')->group(function () {
        Route::get('/cargas/pendientes', [CargaController::class, 'pendientes']);
        Route::get('/cargas/{carga}/tareas', [DespachoFrigorificoController::class, 'tareas']);
        Route::get('/cargas/{carga}/plan-extraccion', [DespachoFrigorificoController::class, 'planExtraccion']);
        Route::get('/andenes', [AndenController::class, 'index']);
    });
    Route::get('/cargas/folios-disponibles', [CargaController::class, 'foliosDisponibles'])
        ->middleware('can:gestionar-cargas');
    Route::get('/cargas/folios-salida-directa-prefrio', [CargaController::class, 'foliosSalidaDirectaPrefrio'])
        ->middleware('can:gestionar-cargas');
    Route::middleware('can:consultar-catalogo-cargas')->group(function () {
        Route::get('/embarques', [EmbarqueController::class, 'index']);
        Route::get('/embarques/{embarque}', [EmbarqueController::class, 'show']);
        Route::get('/cargas/incidencias', [DespachoFrigorificoController::class, 'incidencias']);
        Route::get('/cargas', [CargaController::class, 'index']);
        Route::get('/cargas/{carga}', [CargaController::class, 'show']);
    });
    Route::middleware('can:gestionar-cargas')->group(function () {
        Route::post('/embarques', [EmbarqueController::class, 'store']);
        Route::put('/embarques/{embarque}', [EmbarqueController::class, 'update']);
        Route::post('/embarques/{embarque}/confirmar', [EmbarqueController::class, 'confirmar']);
        Route::post('/embarques/{embarque}/cancelar', [EmbarqueController::class, 'cancelar']);
        Route::post('/cargas/despacho-directo-prefrio', [CargaController::class, 'registrarDespachoDirectoPrefrio']);
        Route::post('/cargas', [CargaController::class, 'store']);
        Route::put('/cargas/{carga}', [CargaController::class, 'update']);
        Route::post('/cargas/{carga}/folios', [CargaController::class, 'agregarFolios']);
        Route::delete('/cargas/{carga}/folios/{folio}', [CargaController::class, 'quitarFolio']);
        Route::post('/cargas/{carga}/publicar', [CargaController::class, 'publicar']);
        Route::post('/cargas/{carga}/cancelar', [CargaController::class, 'cancelar']);
        Route::post('/cargas/{carga}/camion-en-anden', [CargaController::class, 'registrarCamionEnAnden']);
        Route::post('/cargas/{carga}/camion-en-anden/finalizar', [CargaController::class, 'finalizarCamionEnAnden']);
    });
    Route::post('/cargas/asignaciones/{cargaFolio}/incidencias', [DespachoFrigorificoController::class, 'reportarIncidencia']);
    Route::post('/cargas/incidencias/{incidencia}/resolver', [DespachoFrigorificoController::class, 'resolverIncidencia']);
    Route::post('/cargas/asignaciones/{cargaFolio}/enviar-anden', [DespachoFrigorificoController::class, 'enviarAnden']);
    Route::post('/cargas/{carga}/cerrar-despacho', [DespachoFrigorificoController::class, 'cerrar']);

    Route::middleware('can:consultar-configuracion-camaras')->group(function () {
        Route::get('/configuracion/camaras', [ConfiguracionCamaraController::class, 'index']);
        Route::get('/configuracion/camaras/siguiente-codigo', [ConfiguracionCamaraController::class, 'siguienteCodigo']);
        Route::get('/configuracion/camaras/{camara}', [ConfiguracionCamaraController::class, 'show']);
    });
    Route::post('/configuracion/camaras', [ConfiguracionCamaraController::class, 'store']);
    Route::put('/configuracion/camaras/{camara}', [ConfiguracionCamaraController::class, 'update'])
        ->middleware('can:administrar-camaras');
    Route::put(
        '/configuracion/camaras/{camara}/bandas/{bandaOperacional}',
        [BandaOperacionalController::class, 'update'],
    )->middleware('can:administrar-camaras');
    Route::delete('/configuracion/camaras/{camara}', [ConfiguracionCamaraController::class, 'destroy'])
        ->middleware('can:administrar-camaras');

    Route::middleware('can:consultar-accesos')->group(function () {
        Route::get('/administracion/accesos', [AdministracionAccesoController::class, 'index']);
        Route::get('/administracion/perfiles-acceso', [PerfilAccesoController::class, 'index']);
        Route::get('/administracion/clientes', [ClienteGlobalController::class, 'index']);
        Route::get('/administracion/temporadas', [AdministracionTemporadaController::class, 'index']);
        Route::get('/administracion/etiquetas/materiales/perfiles', [PerfilImpresionEtiquetaController::class, 'administracion']);
    });
    Route::middleware('can:archivar-temporadas')->group(function () {
        Route::get('/administracion/temporadas/{temporada}/archivos', [ArchivoTemporadaController::class, 'index']);
        Route::post('/administracion/temporadas/{temporada}/archivos', [ArchivoTemporadaController::class, 'store'])
            ->middleware('throttle:3,1');
        Route::get('/administracion/archivos-temporada/{archivo}/descargar', [ArchivoTemporadaController::class, 'descargar']);
    });
    Route::get('/operacion/pines-operadores', [PinOperacionalController::class, 'operadores'])
        ->middleware('can:gestionar-pines-operadores');
    Route::post('/administracion/usuarios/{usuario}/restablecer-pin', [PinOperacionalController::class, 'restablecer'])
        ->middleware('can:restablecer-pin-operacional,usuario');
    Route::middleware('can:administrar-accesos')->group(function () {
        Route::get('/administracion/sesiones-acceso', [SesionesAccesoAdministracionController::class, 'index']);
        Route::delete('/administracion/sesiones-acceso/{sesionAcceso}', [SesionesAccesoAdministracionController::class, 'destroy']);
        Route::post('/administracion/perfiles-acceso', [PerfilAccesoController::class, 'store']);
        Route::put('/administracion/perfiles-acceso/{perfilAcceso}', [PerfilAccesoController::class, 'update']);
        Route::post('/administracion/usuarios', [AdministracionAccesoController::class, 'crearUsuario']);
        Route::put('/administracion/usuarios/{usuario}', [AdministracionAccesoController::class, 'actualizarUsuario']);
        Route::post('/administracion/dispositivos', [AdministracionAccesoController::class, 'crearDispositivo']);
        Route::post('/administracion/clientes', [ClienteGlobalController::class, 'store']);
        Route::put('/administracion/clientes/{cliente}', [ClienteGlobalController::class, 'update']);
        Route::post('/administracion/temporadas', [AdministracionTemporadaController::class, 'store']);
        Route::put('/administracion/temporadas/{temporada}', [AdministracionTemporadaController::class, 'update']);
        Route::post('/administracion/temporadas/{temporada}/activar', [AdministracionTemporadaController::class, 'activar']);
        Route::post('/administracion/temporadas/{temporada}/declarar-prueba', [AdministracionTemporadaController::class, 'declararPrueba']);
        Route::post('/administracion/temporadas/{temporada}/declarar-productiva', [AdministracionTemporadaController::class, 'declararProductiva']);
        Route::post('/administracion/temporadas/{temporada}/migrar', [AdministracionTemporadaController::class, 'migrar']);
        Route::get('/administracion/temporadas/{temporada}/cierre', [CierreTemporadaController::class, 'diagnostico']);
        Route::post('/administracion/temporadas/{temporada}/cierre/regularizar', [CierreTemporadaController::class, 'regularizar']);
        Route::post('/administracion/temporadas/{temporada}/cierre/avisar', [CierreTemporadaController::class, 'avisar']);
        Route::get('/administracion/temporadas/{temporada}/reinicio-operacional', [ReinicioOperacionalController::class, 'preview']);
        Route::post('/administracion/temporadas/{temporada}/reinicio-operacional', [ReinicioOperacionalController::class, 'store']);
        Route::post('/administracion/etiquetas/materiales/perfiles', [PerfilImpresionEtiquetaController::class, 'store']);
        Route::put('/administracion/etiquetas/materiales/perfiles/{perfilImpresionEtiqueta}', [PerfilImpresionEtiquetaController::class, 'update']);
    });
    Route::middleware('can:gestionar-andenes')->group(function () {
        Route::post('/administracion/andenes', [AndenController::class, 'store']);
        Route::put('/administracion/andenes/{anden}', [AndenController::class, 'update']);
    });
    Route::middleware('can:administrar-catalogos-materiales')->group(function () {
        Route::get('/administracion/materiales/temporadas', [CatalogoMaterialController::class, 'temporadas']);
        Route::get('/administracion/materiales/clientes', [CatalogoMaterialController::class, 'clientes']);
        Route::get('/administracion/materiales/proveedores', [ProveedorMaterialController::class, 'index']);
        Route::post('/administracion/materiales/proveedores', [ProveedorMaterialController::class, 'store']);
        Route::put('/administracion/materiales/proveedores/{proveedorMaterial}', [ProveedorMaterialController::class, 'update']);
        Route::get('/administracion/materiales/importaciones', [ImportacionCatalogoMaterialController::class, 'index']);
        Route::post('/administracion/materiales/importaciones/previsualizar', [ImportacionCatalogoMaterialController::class, 'previsualizar']);
        Route::post('/administracion/materiales/importaciones/{importacionCatalogoMaterial}/confirmar', [ImportacionCatalogoMaterialController::class, 'confirmar']);
        Route::get('/administracion/materiales/items', [CatalogoMaterialController::class, 'items']);
        Route::post('/administracion/materiales/items', [CatalogoMaterialController::class, 'storeItem']);
        Route::put('/administracion/materiales/items/{itemMaterial}', [CatalogoMaterialController::class, 'updateItem']);
        Route::post('/administracion/materiales/items/{itemMaterial}/regularizar', [CatalogoMaterialController::class, 'regularizarItem']);
        Route::get('/administracion/materiales/destinos', [CatalogoMaterialController::class, 'destinos']);
        Route::post('/administracion/materiales/destinos', [CatalogoMaterialController::class, 'storeDestino']);
        Route::put('/administracion/materiales/destinos/{destinoMaterial}', [CatalogoMaterialController::class, 'updateDestino']);
    });

    Route::post('/camaras/{camara}/sesiones', [SesionEstibaController::class, 'store']);
    Route::post('/sesiones/{sesion}/cerrar', [SesionEstibaController::class, 'cerrar']);
    Route::post('/sesiones/{sesion}/cerrar-forzosamente', [SesionEstibaController::class, 'cerrarForzosamente']);
    Route::get('/movimientos/recientes', [MovimientoController::class, 'recientes']);
    Route::get('/movimientos/consultar-folio', [MovimientoController::class, 'consultarFolio']);
    Route::get('/movimientos/folios-materiales-disponibles', [MovimientoController::class, 'buscarFoliosMateriales']);
    Route::post('/movimientos/ubicar', [MovimientoController::class, 'ubicar']);
    Route::post('/movimientos/mover', [MovimientoController::class, 'mover']);

    Route::middleware('can:operar-camaras-productos')->group(function () {
        Route::get('/planes-operacionales', [PlanOperacionalController::class, 'index']);
        Route::get('/planes-operacionales/{planOperacional}', [PlanOperacionalController::class, 'show']);
        Route::get('/tareas-movimiento', [PlanOperacionalController::class, 'tareas']);
        Route::post('/tareas-movimiento/{tareaMovimiento}/asumir', [PlanOperacionalController::class, 'asumir']);
        Route::post('/tareas-movimiento/{tareaMovimiento}/renovar', [PlanOperacionalController::class, 'renovar']);
        Route::post('/tareas-movimiento/{tareaMovimiento}/liberar', [PlanOperacionalController::class, 'liberar']);
    });

    Route::delete('/acceso-tablet', [AccesoTabletController::class, 'destroy']);
    Route::delete('/acceso-oficina', [AccesoOficinaController::class, 'destroy']);
});
