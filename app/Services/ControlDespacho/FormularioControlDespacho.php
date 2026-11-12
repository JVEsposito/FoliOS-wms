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
     * Set fotográfico de consolidación para despachos marítimos de fruta fresca, en el orden en
     * que se toman (correo del equipo de consolidación, 12-11-2026). `grupo` es la etapa del
     * despacho e `indicacion` lo que debe verse en la foto. `condicion` dice cuándo se exige una
     * obligatoria: `contenedor` solo si el camión es contenedor; `segundo_termografo` si el
     * encabezado anota un segundo termógrafo de viaje; `pallets` una por cada pallet con
     * posición. Las obligatorias bloquean el cierre con `despacho.control_exige_fotos` activo.
     *
     * @return array<string, array{nombre: string, indicacion: string, grupo: string, obligatoria: bool, condicion: ?string, maximo: int}>
     */
    public static function tiposFoto(): array
    {
        return [
            'camion_patentes' => ['nombre' => 'Camión y patentes', 'indicacion' => 'Tracto y rampla, con ambas patentes legibles.', 'grupo' => 'exterior', 'obligatoria' => true, 'condicion' => null, 'maximo' => 4],
            'contenedor' => ['nombre' => 'Contenedor', 'indicacion' => 'Sigla y número de la unidad legibles.', 'grupo' => 'exterior', 'obligatoria' => true, 'condicion' => 'contenedor', 'maximo' => 3],
            'sello_lavado' => ['nombre' => 'Sello de lavado', 'indicacion' => 'El sello con el que llega la unidad.', 'grupo' => 'exterior', 'obligatoria' => true, 'condicion' => 'contenedor', 'maximo' => 2],
            'placa_contenedor' => ['nombre' => 'Placa de información del contenedor', 'indicacion' => 'Año de fabricación y tara.', 'grupo' => 'exterior', 'obligatoria' => true, 'condicion' => 'contenedor', 'maximo' => 2],
            'seteo_equipo' => ['nombre' => 'Pantalla del equipo de frío con el seteo', 'indicacion' => 'Temperatura prevista en la pantalla del reefer.', 'grupo' => 'exterior', 'obligatoria' => true, 'condicion' => null, 'maximo' => 3],
            'ventilacion' => ['nombre' => 'Ventilación', 'indicacion' => 'Configurada según el instructivo.', 'grupo' => 'exterior', 'obligatoria' => true, 'condicion' => 'contenedor', 'maximo' => 3],
            'unidad_vacia' => ['nombre' => 'Unidad vacía por dentro', 'indicacion' => 'El interior antes de subir el primer pallet.', 'grupo' => 'interior', 'obligatoria' => true, 'condicion' => null, 'maximo' => 3],
            'temperatura_pulpa' => ['nombre' => 'Temperatura de pulpa antes de cargar', 'indicacion' => 'Una toma por pallet, antes de subirlo.', 'grupo' => 'interior', 'obligatoria' => true, 'condicion' => 'pallets', 'maximo' => self::POSICION_MAXIMA],
            'primera_carga' => ['nombre' => 'Primera carga al fondo', 'indicacion' => 'Los dos primeros pallets, con el primer termógrafo instalado.', 'grupo' => 'interior', 'obligatoria' => true, 'condicion' => null, 'maximo' => 4],
            'mitad_carga' => ['nombre' => 'Mitad de la carga', 'indicacion' => 'El medio de la unidad durante la carga, con los pallets estibados.', 'grupo' => 'interior', 'obligatoria' => true, 'condicion' => null, 'maximo' => 4],
            'carton_bufandas' => ['nombre' => 'Cartón piso y bufandas', 'indicacion' => 'Durante la estiba.', 'grupo' => 'interior', 'obligatoria' => true, 'condicion' => 'contenedor', 'maximo' => 4],
            'segundo_termografo' => ['nombre' => 'Segundo termógrafo', 'indicacion' => 'Instalado en el penúltimo pallet (el N° 19 en un contenedor de 20).', 'grupo' => 'interior', 'obligatoria' => true, 'condicion' => 'segundo_termografo', 'maximo' => 3],
            'cierre_carga' => ['nombre' => 'Final de la carga y cierre', 'indicacion' => 'Cierre completo con cartones y bufandas, donde se vea la espiga (ninfa) del pallet.', 'grupo' => 'interior', 'obligatoria' => true, 'condicion' => null, 'maximo' => 4],
            'sello_cierre' => ['nombre' => 'Sello de cierre', 'indicacion' => 'En primer plano y legible.', 'grupo' => 'cierre', 'obligatoria' => true, 'condicion' => null, 'maximo' => 3],
            'trasera_cierre' => ['nombre' => 'Vista trasera con el cierre completo', 'indicacion' => 'Foto general de la parte trasera ya cerrada.', 'grupo' => 'cierre', 'obligatoria' => true, 'condicion' => null, 'maximo' => 3],
            'arranque_equipo' => ['nombre' => 'Pantalla al arrancar el equipo de frío', 'indicacion' => 'La temperatura cuando el equipo vuelve a funcionar.', 'grupo' => 'cierre', 'obligatoria' => true, 'condicion' => null, 'maximo' => 3],
            'general' => ['nombre' => 'Otras fotos del despacho', 'indicacion' => 'Lo que convenga dejar registrado.', 'grupo' => 'otras', 'obligatoria' => false, 'condicion' => null, 'maximo' => 20],
        ];
    }

    /** @return array<string, string> Etapas del set fotográfico, en orden. */
    public static function gruposFoto(): array
    {
        return [
            'exterior' => '1. Camión y contenedor (exterior)',
            'interior' => '2. Interior, carga y estiba',
            'cierre' => '3. Cierre y arranque',
            'otras' => 'Otras',
        ];
    }

    /**
     * Pizarra identificatoria: debe aparecer legible en las fotos del set. Se arma con el
     * encabezado para que el equipo la copie tal cual; `valor` nulo es un dato por completar.
     *
     * @param  array<string, mixed>  $encabezado
     * @return array<int, array{clave: string, etiqueta: string, valor: ?string}>
     */
    public static function pizarra(array $encabezado): array
    {
        $texto = fn (string $clave): ?string => filled($encabezado[$clave] ?? null) ? trim((string) $encabezado[$clave]) : null;
        $fecha = $texto('fecha_carga');
        if ($fecha !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $partes)) {
            $fecha = "{$partes[3]}-{$partes[2]}-{$partes[1]}";
        }

        return [
            ['clave' => 'instructivo', 'etiqueta' => 'Instructivo', 'valor' => $texto('referencia_embarque')],
            ['clave' => 'contenedor', 'etiqueta' => 'N° de contenedor', 'valor' => $texto('contenedor')],
            ['clave' => 'planta', 'etiqueta' => 'Planta', 'valor' => $texto('frigorifico')],
            ['clave' => 'exportadora', 'etiqueta' => 'Exportadora', 'valor' => $texto('exportadora')],
            ['clave' => 'fecha_carga', 'etiqueta' => 'Fecha de carga', 'valor' => $fecha],
        ];
    }

    public static function exigeFotos(): bool
    {
        return (bool) config('despacho.control_exige_fotos', true);
    }

    /**
     * Fotos que faltan según el set y lo registrado en la planilla.
     *
     * @param  array<string, mixed>  $encabezado
     * @param  array<string, int>  $cantidadPorTipo
     * @param  int  $palletsConPosicion  Pallets ya ubicados en el camión.
     * @return array<int, array{tipo: string, nombre: string, requeridas: int, tomadas: int}>
     */
    public static function fotosPendientes(array $encabezado, array $cantidadPorTipo, int $palletsConPosicion = 0): array
    {
        $pendientes = [];
        foreach (self::tiposFoto() as $tipo => $definicion) {
            if (! $definicion['obligatoria']) {
                continue;
            }
            $requeridas = match ($definicion['condicion']) {
                'contenedor' => ($encabezado['tipo_camion'] ?? null) === 'contenedor' ? 1 : 0,
                'segundo_termografo' => filled($encabezado['termografo_2'] ?? null) ? 1 : 0,
                'pallets' => max(1, $palletsConPosicion),
                default => 1,
            };
            $tomadas = (int) ($cantidadPorTipo[$tipo] ?? 0);
            if ($requeridas > $tomadas) {
                $pendientes[] = ['tipo' => $tipo, 'nombre' => $definicion['nombre'], 'requeridas' => $requeridas, 'tomadas' => $tomadas];
            }
        }

        return $pendientes;
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
            'grupos_foto' => collect(self::gruposFoto())->map(fn (string $titulo, string $clave): array => ['clave' => $clave, 'titulo' => $titulo])->values()->all(),
            'exige_fotos' => self::exigeFotos(),
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
