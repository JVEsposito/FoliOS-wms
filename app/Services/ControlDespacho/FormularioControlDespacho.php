<?php

namespace App\Services\ControlDespacho;

/**
 * Campos de la planilla POEMP R-24 «Registro planilla control de despacho área frío».
 *
 * Es la única definición del formulario: la usan la validación de la API, la PDA (que
 * recibe el catálogo en cada lectura) y la planilla Excel.
 */
final class FormularioControlDespacho
{
    public const CODIGO = 'POEMP R-24';

    public const SI_NO = ['si', 'no'];

    public const SI_NO_NA = ['si', 'no', 'na'];

    public const CUMPLE = ['cumple', 'no_cumple', 'na'];

    public const TEXTO_CORTO = 150;

    /** Posiciones que admite un camión o contenedor; la plantilla trae 22 por hoja. */
    public const POSICION_MAXIMA = 60;

    /**
     * Encabezado. `tipo` es texto, `opcion` una lista cerrada, `fecha` AAAA-MM-DD y `hora` HH:MM.
     *
     * @return array<string, array{etiqueta: string, tipo: string, opciones?: array<int, string>, grupo: string}>
     */
    public static function encabezado(): array
    {
        return [
            'frigorifico_tipo' => ['etiqueta' => 'Frigorífico', 'tipo' => 'opcion', 'opciones' => ['agrorosario', 'otra'], 'grupo' => 'operacion'],
            'frigorifico' => ['etiqueta' => 'Nombre del frigorífico', 'tipo' => 'texto', 'grupo' => 'operacion'],
            'tipo_operacion' => ['etiqueta' => 'Tipo de operación', 'tipo' => 'texto', 'grupo' => 'operacion'],
            'tipo_camion' => ['etiqueta' => 'Tipo de camión', 'tipo' => 'opcion', 'opciones' => ['contenedor', 'thermo'], 'grupo' => 'operacion'],
            'exportadora' => ['etiqueta' => 'Exportadora', 'tipo' => 'texto', 'grupo' => 'operacion'],
            'referencia_embarque' => ['etiqueta' => 'N° referencia embarque', 'tipo' => 'texto', 'grupo' => 'operacion'],
            'fecha_instructivo' => ['etiqueta' => 'Fecha instructivo', 'tipo' => 'fecha', 'grupo' => 'operacion'],
            'hora_instructivo' => ['etiqueta' => 'Hora instructivo', 'tipo' => 'hora', 'grupo' => 'operacion'],
            'fecha_carga' => ['etiqueta' => 'Fecha de carga (real)', 'tipo' => 'fecha', 'grupo' => 'operacion'],
            'hora_inicio' => ['etiqueta' => 'Hora inicio', 'tipo' => 'hora', 'grupo' => 'operacion'],
            'hora_termino' => ['etiqueta' => 'Hora término', 'tipo' => 'hora', 'grupo' => 'operacion'],
            'numero_guia' => ['etiqueta' => 'N° guía de despacho', 'tipo' => 'texto', 'grupo' => 'operacion'],
            'empresa_consolidacion' => ['etiqueta' => 'Empresa consolidación', 'tipo' => 'texto', 'grupo' => 'operacion'],
            'recibidor' => ['etiqueta' => 'Recibidor', 'tipo' => 'texto', 'grupo' => 'destino'],
            'destino' => ['etiqueta' => 'Destino', 'tipo' => 'texto', 'grupo' => 'destino'],
            'nave' => ['etiqueta' => 'Nave', 'tipo' => 'texto', 'grupo' => 'destino'],
            'naviera' => ['etiqueta' => 'Naviera', 'tipo' => 'texto', 'grupo' => 'destino'],
            'puerto_zarpe' => ['etiqueta' => 'Puerto de zarpe', 'tipo' => 'texto', 'grupo' => 'destino'],
            'puerto_destino' => ['etiqueta' => 'Puerto de destino', 'tipo' => 'texto', 'grupo' => 'destino'],
            'especie' => ['etiqueta' => 'Especie', 'tipo' => 'texto', 'grupo' => 'destino'],
            'empresa_transporte' => ['etiqueta' => 'Empresa de transporte', 'tipo' => 'texto', 'grupo' => 'transporte'],
            'conductor' => ['etiqueta' => 'Nombre conductor', 'tipo' => 'texto', 'grupo' => 'transporte'],
            'rut_conductor' => ['etiqueta' => 'RUT conductor', 'tipo' => 'texto', 'grupo' => 'transporte'],
            'celular_conductor' => ['etiqueta' => 'Celular conductor', 'tipo' => 'texto', 'grupo' => 'transporte'],
            'patente_camion' => ['etiqueta' => 'Patente camión', 'tipo' => 'texto', 'grupo' => 'transporte'],
            'patente_carro' => ['etiqueta' => 'Patente carro', 'tipo' => 'texto', 'grupo' => 'transporte'],
            'contenedor' => ['etiqueta' => 'N° contenedor', 'tipo' => 'texto', 'grupo' => 'unidad'],
            'contenedor_anio' => ['etiqueta' => 'Año contenedor', 'tipo' => 'texto', 'grupo' => 'unidad'],
            'contenedor_tara' => ['etiqueta' => 'Tara', 'tipo' => 'texto', 'grupo' => 'unidad'],
            'contenedor_peso_bruto' => ['etiqueta' => 'Peso bruto', 'tipo' => 'texto', 'grupo' => 'unidad'],
            'sello_planta' => ['etiqueta' => 'N° sello planta', 'tipo' => 'texto', 'grupo' => 'unidad'],
            'sello_naviera' => ['etiqueta' => 'N° sello naviera', 'tipo' => 'texto', 'grupo' => 'unidad'],
            'temperatura_seteo' => ['etiqueta' => 'Temperatura de seteo (°C)', 'tipo' => 'texto', 'grupo' => 'unidad'],
            'temperatura_cumple' => ['etiqueta' => 'Temperatura cumple norma', 'tipo' => 'opcion', 'opciones' => self::SI_NO, 'grupo' => 'unidad'],
            'ventilacion' => ['etiqueta' => 'Ventilación', 'tipo' => 'texto', 'grupo' => 'unidad'],
            'ventilacion_cumple' => ['etiqueta' => 'Ventilación cumple norma', 'tipo' => 'opcion', 'opciones' => self::SI_NO, 'grupo' => 'unidad'],
            'termografo_1' => ['etiqueta' => 'Termógrafo viaje 1', 'tipo' => 'texto', 'grupo' => 'termografos'],
            'termografo_1_pallet' => ['etiqueta' => 'En pallet N°', 'tipo' => 'texto', 'grupo' => 'termografos'],
            'termografo_2' => ['etiqueta' => 'Termógrafo viaje 2', 'tipo' => 'texto', 'grupo' => 'termografos'],
            'termografo_2_pallet' => ['etiqueta' => 'En pallet N°', 'tipo' => 'texto', 'grupo' => 'termografos'],
            'termografo_3' => ['etiqueta' => 'Termógrafo viaje 3', 'tipo' => 'texto', 'grupo' => 'termografos'],
            'termografo_3_pallet' => ['etiqueta' => 'En pallet N°', 'tipo' => 'texto', 'grupo' => 'termografos'],
            'termografo_control' => ['etiqueta' => 'Termógrafo control a puerto (interno)', 'tipo' => 'texto', 'grupo' => 'termografos'],
            'termografo_control_pallet' => ['etiqueta' => 'En pallet N°', 'tipo' => 'texto', 'grupo' => 'termografos'],
        ];
    }

    /**
     * Inspección visual del thermo o contenedor, en el orden de la planilla. `aplica` dice a
     * qué tipo de camión corresponde; en el otro se responde «no aplica».
     *
     * @return array<string, array{etiqueta: string, aplica: ?string}>
     */
    public static function inspeccion(): array
    {
        return [
            'enfriado_previo' => ['etiqueta' => 'Enfriado de unidad previo a carga', 'aplica' => null],
            'horometro' => ['etiqueta' => 'Horómetro en buen estado (thermos)', 'aplica' => 'thermo'],
            'termostato' => ['etiqueta' => 'Termostato en buen estado (thermos)', 'aplica' => 'thermo'],
            'chute_aire' => ['etiqueta' => 'Chute de aire en buen estado (thermos)', 'aplica' => 'thermo'],
            'puertas' => ['etiqueta' => 'Puertas en buen estado', 'aplica' => null],
            'danos_visuales' => ['etiqueta' => 'Unidad presenta daños aparentes visuales', 'aplica' => null],
            'objetos_olores' => ['etiqueta' => 'Unidad presenta objetos u olores extraños en su interior', 'aplica' => null],
            'trinca' => ['etiqueta' => 'Trinca instalada (solo cargas incompletas)', 'aplica' => null],
            'linea_roja' => ['etiqueta' => 'Estiba con carga bajo línea roja (contenedor)', 'aplica' => 'contenedor'],
            'carton_piso' => ['etiqueta' => 'Cartón instalado en pisos (contenedor)', 'aplica' => 'contenedor'],
            'termografos_norma' => ['etiqueta' => 'Termógrafos instalados según norma', 'aplica' => null],
            'unidad_frio_funciona' => ['etiqueta' => 'Unidad de frío funciona una vez cargado', 'aplica' => null],
            'presencia_consolidacion' => ['etiqueta' => 'Presencia empresa consolidación de contenedor', 'aplica' => null],
        ];
    }

    /**
     * Cierre de la planilla: totales, etiquetado, tipos de pallet y responsables.
     *
     * @return array<string, array{etiqueta: string, tipo: string, opciones?: array<int, string>}>
     */
    public static function cierre(): array
    {
        return [
            'cajas_guia' => ['etiqueta' => 'Cajas totales guía de despacho', 'tipo' => 'entero'],
            'sello_lavado' => ['etiqueta' => 'Sello de lavado', 'tipo' => 'texto'],
            'observaciones' => ['etiqueta' => 'Observaciones', 'tipo' => 'nota'],
            'etiquetado_asia' => ['etiqueta' => 'Etiquetado pallets (Corea, China, Canadá)', 'tipo' => 'opcion', 'opciones' => self::CUMPLE],
            'etiquetado_america' => ['etiqueta' => 'Etiquetado pallets (Corea, Brasil, México)', 'tipo' => 'opcion', 'opciones' => self::CUMPLE],
            'etiquetado_cliente_nombre' => ['etiqueta' => 'Cliente con etiquetado específico', 'tipo' => 'texto'],
            'etiquetado_cliente' => ['etiqueta' => 'Etiquetado del cliente', 'tipo' => 'opcion', 'opciones' => self::CUMPLE],
            'walmart' => ['etiqueta' => 'Cumple requerimiento Walmart', 'tipo' => 'opcion', 'opciones' => self::SI_NO_NA],
            'walmart_etiqueta' => ['etiqueta' => 'N° etiqueta Walmart', 'tipo' => 'texto'],
            'pallets_taco_1000' => ['etiqueta' => 'Taco 1000 × 1200', 'tipo' => 'entero'],
            'pallets_chep_1000' => ['etiqueta' => 'CHEP 1000 × 1200', 'tipo' => 'entero'],
            'pallets_yugo_1000' => ['etiqueta' => 'Yugo 1000 × 1200', 'tipo' => 'entero'],
            'pallets_extendido_1016' => ['etiqueta' => 'Extendido 1016 × 1220', 'tipo' => 'entero'],
            'pallets_taco_800' => ['etiqueta' => 'Taco 800 × 1200', 'tipo' => 'entero'],
            'responsable' => ['etiqueta' => 'Responsable', 'tipo' => 'texto'],
            'jefe_seccion' => ['etiqueta' => 'Jefe de sección', 'tipo' => 'texto'],
        ];
    }

    /**
     * Columna P/E de la grilla: cómo va el pallet respecto del frente del camión o contenedor.
     * En un pallet 1000 × 1200, «punta» lleva el lado angosto hacia el frente y «espejo» el
     * lado ancho. En la planilla se escribe la letra.
     *
     * @return array<string, array{nombre: string, descripcion: string}>
     */
    public static function orientaciones(): array
    {
        return [
            'P' => ['nombre' => 'Punta', 'descripcion' => 'Lado angosto hacia el frente'],
            'E' => ['nombre' => 'Espejo', 'descripcion' => 'Lado ancho hacia el frente'],
        ];
    }

    /** @return array<int, string> */
    public static function tiposPallet(): array
    {
        return ['pallets_taco_1000', 'pallets_chep_1000', 'pallets_yugo_1000', 'pallets_extendido_1016', 'pallets_taco_800'];
    }

    /**
     * Set fotográfico. Mientras no llegue el set estándar hay una sola categoría opcional;
     * las categorías obligatorias se agregan aquí y la PDA las muestra sin cambios.
     *
     * @return array<string, array{nombre: string, obligatoria: bool, maximo: int}>
     */
    public static function tiposFoto(): array
    {
        return [
            'general' => ['nombre' => 'Fotos del despacho', 'obligatoria' => false, 'maximo' => 20],
        ];
    }

    /** @return array<string, mixed> */
    public static function catalogo(): array
    {
        return [
            'codigo' => self::CODIGO,
            'posicion_maxima' => self::POSICION_MAXIMA,
            'encabezado' => collect(self::encabezado())->map(fn (array $campo, string $clave): array => ['clave' => $clave, ...$campo])->values()->all(),
            'inspeccion' => collect(self::inspeccion())->map(fn (array $campo, string $clave): array => ['clave' => $clave, ...$campo])->values()->all(),
            'cierre' => collect(self::cierre())->map(fn (array $campo, string $clave): array => ['clave' => $clave, ...$campo])->values()->all(),
            'orientaciones' => collect(self::orientaciones())->map(fn (array $orientacion, string $clave): array => ['clave' => $clave, ...$orientacion])->values()->all(),
            'tipos_foto' => collect(self::tiposFoto())->map(fn (array $tipo, string $clave): array => ['clave' => $clave, ...$tipo])->values()->all(),
        ];
    }

    /**
     * Reglas de Laravel para cada sección. Todo es opcional al guardar: la planilla se llena
     * de a poco. Lo exigible se revisa al cerrar.
     *
     * @return array<string, mixed>
     */
    public static function reglas(): array
    {
        $reglas = [
            'encabezado' => ['sometimes', 'array:'.implode(',', array_keys(self::encabezado()))],
            'inspeccion' => ['sometimes', 'array:'.implode(',', array_keys(self::inspeccion()))],
            'cierre' => ['sometimes', 'array:'.implode(',', array_keys(self::cierre()))],
        ];
        foreach (self::encabezado() as $clave => $campo) {
            $reglas["encabezado.{$clave}"] = match ($campo['tipo']) {
                'opcion' => ['nullable', 'string', 'in:'.implode(',', $campo['opciones'])],
                'fecha' => ['nullable', 'date_format:Y-m-d'],
                'hora' => ['nullable', 'date_format:H:i'],
                default => ['nullable', 'string', 'max:'.self::TEXTO_CORTO],
            };
        }
        foreach (array_keys(self::inspeccion()) as $clave) {
            $reglas["inspeccion.{$clave}"] = ['nullable', 'string', 'in:'.implode(',', self::SI_NO_NA)];
        }
        foreach (self::cierre() as $clave => $campo) {
            $reglas["cierre.{$clave}"] = match ($campo['tipo']) {
                'opcion' => ['nullable', 'string', 'in:'.implode(',', $campo['opciones'])],
                'entero' => ['nullable', 'integer', 'min:0', 'max:100000'],
                'nota' => ['nullable', 'string', 'max:1000'],
                default => ['nullable', 'string', 'max:'.self::TEXTO_CORTO],
            };
        }

        return $reglas;
    }
}
