<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Entrada única de Oficina: tras iniciar sesión, cada perfil llega a su pantalla de inicio.
Route::view('/oficina', 'office.domain-lobby', [
    'lobbyDomain' => 'consultas',
    'officeHomeRedirect' => true,
]);
Route::redirect('/oficina/camaras', '/oficina/frigorifico/camaras');
Route::view('/oficina/frigorifico', 'office.domain-lobby', [
    'lobbyDomain' => 'frigorifico',
]);
Route::view('/oficina/frigorifico/camaras', 'office.cameras', [
    'navigationDomain' => 'frigorifico',
    'navigationOffice' => 'camaras',
    'cameraMode' => 'operacion',
]);
Route::view('/oficina/frigorifico/discrepancias', 'office.discrepancies');
Route::view('/oficina/administracion/camaras', 'office.cameras', [
    'navigationDomain' => 'administracion',
    'navigationOffice' => 'configuracion-camaras',
    'cameraMode' => 'configuracion',
]);
Route::view('/oficina/administracion', 'office.domain-lobby', [
    'lobbyDomain' => 'administracion',
]);
Route::view('/oficina/demo', 'office.demo');
Route::redirect('/oficina/cargas', '/oficina/frigorifico/despacho/cargas', 301);
Route::redirect('/oficina/frigorifico/calendario-embarques', '/oficina/frigorifico/despacho/calendario', 301);
Route::view('/oficina/frigorifico/despacho/cargas', 'office.loads');
Route::view('/oficina/frigorifico/despacho/estatus', 'office.load-status');
Route::view('/oficina/frigorifico/despacho/calendario', 'office.shipment-calendar');
Route::view('/oficina/accesos', 'office.accesses');
Route::view('/oficina/materiales', 'office.materials', [
    'navigationOffice' => 'resumen',
    'materialsSection' => 'resumen',
]);
Route::view('/oficina/materiales/catalogos', 'office.materials', [
    'navigationOffice' => 'catalogos',
    'materialsSection' => 'catalogos',
]);
Route::view('/oficina/materiales/recepcion', 'office.materials', [
    'navigationOffice' => 'recepcion',
    'materialsSection' => 'recepcion',
]);
Route::view('/oficina/materiales/recepciones', 'office.materials', [
    'navigationOffice' => 'recepciones',
    'materialsSection' => 'recepciones',
]);
Route::view('/oficina/materiales/inventario', 'office.materials', [
    'navigationOffice' => 'inventario',
    'materialsSection' => 'inventario',
]);
Route::view('/oficina/materiales/almacenes', 'office.material-warehouses');
Route::view('/oficina/materiales/tomas', 'office.material-inventory-counts');
Route::view('/oficina/materiales/despachos', 'office.materials', [
    'navigationOffice' => 'despachos',
    'materialsSection' => 'despachos',
]);
Route::view('/oficina/materiales/recetas', 'office.materials', [
    'navigationOffice' => 'recetas',
    'materialsSection' => 'recetas',
]);
Route::view('/oficina/materiales/ordenes', 'office.materials', [
    'navigationOffice' => 'ordenes',
    'materialsSection' => 'ordenes',
]);
Route::redirect('/oficina/materiales/transformacion', '/oficina/materiales/recetas');
Route::redirect('/oficina/materiales/existencias', '/oficina/existencias');
Route::view('/oficina/validacion', 'office.validation');
Route::view('/oficina/validacion/etiquetas', 'office.pt-labels');
Route::redirect('/oficina/validacion/catalogo', '/oficina/administracion/maestros-temporada', 301);
Route::view('/oficina/administracion/maestros-temporada', 'office.validation-catalog');
Route::view('/oficina/administracion/integridad-operacional', 'office.operational-integrity');
Route::view('/oficina/prefrio', 'office.precooling');
Route::view('/oficina/frigorifico/inspeccion-sag', 'office.sag-inspections');
Route::view('/oficina/operacion-ahora', 'office.operation-now');
Route::view('/oficina/gerencia', 'office.management');
Route::redirect('/oficina/existencias', '/oficina/materiales/exportaciones');
Route::view('/oficina/frigorifico/existencias', 'office.inventory-exports', [
    'inventoryType' => 'producto-terminado,despachos-producto-terminado',
    'inventoryDomain' => 'frigorifico',
    'inventoryOffice' => 'existencias-pt',
    'inventoryContext' => 'FRIGORÍFICO · PT',
    'inventoryArea' => 'Producto terminado',
    'inventoryTitle' => 'Existencia y despachos de producto terminado',
    'inventoryDescription' => 'Folios activos desde Validación y Prefrío hasta cámara y carga, y folios despachados por cliente.',
    'inventoryIcon' => '◇',
]);
Route::view('/oficina/materiales/exportaciones', 'office.inventory-exports', [
    'inventoryType' => 'materiales',
    'inventoryDomain' => 'materiales',
    'inventoryOffice' => 'exportaciones',
    'inventoryContext' => 'MATERIALES',
    'inventoryArea' => 'Materiales',
    'inventoryTitle' => 'Existencia de materiales',
    'inventoryDescription' => 'Saldos por folio, almacén, centro de costo, reserva, disponibilidad y ubicación.',
    'inventoryIcon' => '▦',
]);
Route::view('/oficina/materia-prima/existencias', 'office.inventory-exports', [
    'inventoryType' => 'materia-prima',
    'inventoryDomain' => 'materia-prima',
    'inventoryOffice' => 'existencias-mp',
    'inventoryContext' => 'MATERIA PRIMA',
    'inventoryArea' => 'Materia prima',
    'inventoryTitle' => 'Existencia de materia prima',
    'inventoryDescription' => 'Lotes vigentes, condición de hidrocooler, cámara asignada y continuidad hacia proceso.',
    'inventoryIcon' => '⌁',
]);
Route::view('/oficina/romana', 'office.weighbridge');
Route::view('/oficina/envases/cuenta-corriente', 'office.container-accounts');
Route::view('/oficina/envases/despachos', 'office.container-dispatches');
Route::view('/oficina/materia-prima', 'office.domain-lobby', [
    'lobbyDomain' => 'materia-prima',
]);
Route::view('/oficina/materia-prima/despacho-comercial', 'office.raw-material-commercial-dispatch');
Route::view('/oficina/consultas', 'office.domain-lobby', [
    'lobbyDomain' => 'consultas',
]);
Route::view('/oficina/consultas/busqueda', 'office.queries', [
    'navigationOffice' => 'busqueda',
    'queriesSection' => 'busqueda',
]);
Route::view('/oficina/consultas/sag', 'office.queries', [
    'navigationOffice' => 'sag',
    'queriesSection' => 'sag',
]);
Route::view('/oficina/consultas/trazabilidad', 'office.queries', [
    'navigationOffice' => 'trazabilidad',
    'queriesSection' => 'trazabilidad',
]);
Route::view('/oficina/consultas/productores', 'office.queries', [
    'navigationOffice' => 'productores',
    'queriesSection' => 'productores',
]);
Route::view('/oficina/materia-prima/lotes', 'office.raw-material');
Route::view('/oficina/materia-prima/defectos-recepcion', 'office.raw-material-defects');
Route::view('/oficina/materia-prima/hidrocooler', 'office.raw-material-hydrocooler');
Route::view('/oficina/materia-prima/fruta-a-proceso', 'office.raw-material-process');
Route::redirect('/oficina/materia-prima/romana', '/oficina/romana');
Route::redirect('/oficina/materia-prima/envases', '/oficina/envases/cuenta-corriente');

Route::view('/oficina/recepcion-fruta-embalada', 'office.packed-fruit-reception');
Route::view('/oficina/administracion/fruta-embalada', 'office.packed-fruit-catalogs');

Route::view('/oficina/materiales/vencimientos', 'office.materials', [
    'navigationOffice' => 'vencimientos',
    'materialsSection' => 'vencimientos',
]);

Route::view('/oficina/materiales/reposicion', 'office.material-replenishment');

Route::view('/oficina/materiales/items/niveles', 'office.material-replenishment', ['niveles' => true]);
