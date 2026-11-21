<?php

namespace App\Services\InspeccionSag;

use App\Models\RetencionLoteSag;
use App\Services\Documentos\DocumentoPdfPlanta;
use App\Services\Existencias\GeneradorLibroXlsx;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/** Informe del seguimiento de un lote retenido por el SAG: PDF para archivo y firma, y planilla Excel. */
class GeneradorRetencionLoteSag
{
    private const IZQUIERDA = 36;

    private const DERECHA = 559;

    private const ANCHO = 523;

    private const INICIO = 742;

    private const LIMITE = 62;

    private const PRIMARIO = '0.063 0.184 0.263';

    private const GRIS = '0.36 0.41 0.45';

    private const FONDO = '0.945 0.957 0.965';

    private const BORDE = '0.78 0.82 0.85';

    private const ESTADOS = ['con_observaciones' => 'Con observaciones', 'retenido' => 'Retenido',
        RetencionLoteSag::LIBERADO_CON_RESTRICCIONES => 'Liberado con restricciones', 'liberado' => 'Liberado'];

    private const COLORES_ESTADO = ['con_observaciones' => '0.70 0.42 0.02', 'retenido' => '0.66 0.11 0.11',
        RetencionLoteSag::LIBERADO_CON_RESTRICCIONES => '0.70 0.42 0.02', 'liberado' => '0.11 0.45 0.24'];

    private const ESTADOS_FOLIO = ['pendiente_prefrio' => 'Pendiente de prefrío', 'pendiente_ubicacion' => 'Pendiente de ubicación',
        'disponible' => 'Disponible', 'bloqueado' => 'Bloqueado', 'anulado' => 'Anulado',
        'retirado_definitivo' => 'Retirado definitivo', 'despachado' => 'Despachado', 'agotado' => 'Agotado'];

    /** Columnas de pallets: título, x, ancho y alineación. */
    private const COLUMNAS = [
        'folio' => ['Folio', 40, 74, 'izquierda'],
        'cajas_lote' => ['Cajas lote', 170, 50, 'derecha'],
        'cajas_pallet' => ['Cajas pallet', 232, 56, 'derecha'],
        'csg' => ['CSG', 244, 84, 'izquierda'],
        'ubicacion' => ['Ubicación', 332, 82, 'izquierda'],
        'estado' => ['Estado', 418, 72, 'izquierda'],
        'carga' => ['Carga', 494, 62, 'izquierda'],
    ];

    /** @var array<string, array<string, int>> */
    private static array $anchos = [];

    public function __construct(private readonly DocumentoPdfPlanta $pdf, private readonly ServicioRetencionesLoteSag $servicio) {}

    public function pdf(RetencionLoteSag $caso): string
    {
        $datos = $this->informe($caso);
        $hoja = ['paginas' => [], 'contenido' => '', 'y' => 0.0];
        $this->nuevaPagina($caso, $datos, $hoja);
        $ultima = $datos['eventos'] ? end($datos['eventos']) : null;

        $this->seccion($caso, $datos, $hoja, '1. Identificación del lote');
        $this->fichas($caso, $datos, $hoja, [
            ['Cliente', $datos['cliente']],
            ['Lote de recepción', $caso->numero_lote],
            ['Temporada', $datos['temporada']],
            ['Fecha de detección', $caso->fecha_deteccion->format('d-m-Y')],
            ['Estado actual', $this->estado($caso->estado)],
            ['Condición del resultado', $caso->definitivo ? 'Resultado definitivo' : 'En seguimiento'],
            ['N.º de informe / antecedentes', $caso->referencia_informe ?: 'No informado'],
            ['Última actualización', 'Versión '.$caso->version.($ultima ? ' · '.$ultima['fecha'].' · '.$ultima['usuario'] : '')],
        ]);

        $this->seccion($caso, $datos, $hoja, '2. Restricción de destinos vigente');
        $this->destacado($caso, $datos, $hoja, $this->destinos($caso->toArray()), $this->alcance($caso, $datos['paises']));

        $this->seccion($caso, $datos, $hoja, '3. Detección y resolución');
        $this->parrafo($caso, $datos, $hoja, 'Observación de la detección', $caso->observacion);
        $this->parrafo($caso, $datos, $hoja, 'Resolución vigente', $caso->resolucion ?: 'Pendiente de resolución.');
        $this->parrafo($caso, $datos, $hoja, 'Instrucción de segregación física', $caso->instruccion_segregacion ?: 'No se indicó segregación física.');

        $this->seccion($caso, $datos, $hoja, '4. Pallets del lote al emitir el informe');
        $this->pallets($caso, $datos, $hoja);

        $this->seccion($caso, $datos, $hoja, '5. Historial de versiones');
        foreach ($datos['eventos'] as $evento) {
            $this->version($caso, $datos, $hoja, $evento);
        }

        // Las firmas no se separan de su título.
        $this->reservar($caso, $datos, $hoja, 196);
        $this->seccion($caso, $datos, $hoja, '6. Validación');
        $this->firmas($hoja);

        $hoja['paginas'][] = $hoja['contenido'];
        $total = count($hoja['paginas']);
        foreach ($hoja['paginas'] as $indice => &$pagina) {
            $pagina .= $this->pie($datos, $indice + 1, $total);
        }

        return $this->pdf->generar($hoja['paginas']);
    }

    /** @return array<string, mixed> */
    private function informe(RetencionLoteSag $caso): array
    {
        $caso->loadMissing('cliente');
        $planta = json_decode(DB::table('configuracion_contraparte_sag')->where('id', 'planta')->value('datos') ?? '{}', true) ?: [];
        $eventos = DB::table('eventos_retencion_lote_sag')->where('retencion_id', $caso->id)->orderBy('version')->get()
            ->map(fn ($evento): array => ['version' => (int) $evento->version, 'usuario' => $evento->usuario_nombre,
                'fecha' => $this->fechaHora($evento->created_at), 'antes' => json_decode($evento->antes ?? 'null', true),
                'despues' => json_decode($evento->despues, true), 'folios' => json_decode($evento->folios_afectados, true) ?: []]);

        return [
            'establecimiento' => $planta['nombre_establecimiento'] ?? config('app.name'),
            'csp' => $planta['csp'] ?? null,
            'temporada' => DB::table('temporadas')->where('id', $caso->temporada_id)->value('codigo') ?? '—',
            'cliente' => $caso->cliente->codigo.' · '.$caso->cliente->nombre,
            'folios' => $this->servicio->foliosDelLote($caso)->all(),
            'paises' => count($this->servicio->paisesRestringidos($caso)),
            'eventos' => $eventos->all(),
            'emitido' => now()->setTimezone(config('app.operational_timezone'))->format('d-m-Y H:i'),
            'usuario' => auth()->user()?->name,
        ];
    }

    private function nuevaPagina(RetencionLoteSag $caso, array $datos, array &$hoja): void
    {
        if ($hoja['contenido'] !== '') {
            $hoja['paginas'][] = $hoja['contenido'];
        }
        $estado = mb_strtoupper($this->estado($caso->estado));
        $ancho = $this->ancho($estado, 8, true) + 20;
        $c = "q 60 0 0 30 36 790 cm /Logo Do Q\n";
        $c .= $this->texto(106, 807, 13, 'INFORME DE LOTE RETENIDO SAG', true, self::PRIMARIO);
        $c .= $this->texto(106, 794, 8, 'Control de lotes de recepción · '.$datos['establecimiento'].($datos['csp'] ? ' · CSP '.$datos['csp'] : ''), false, self::GRIS);
        $c .= $this->rectangulo(self::DERECHA - $ancho, 798, $ancho, 16, self::COLORES_ESTADO[$caso->estado] ?? self::PRIMARIO);
        $c .= $this->texto(self::DERECHA - $ancho + 10, 803, 8, $estado, true, '1 1 1');
        $c .= $this->derecha(self::DERECHA, 786, 7.5, 'Versión '.$caso->version.' · '.($caso->definitivo ? 'Resultado definitivo' : 'En seguimiento'), false, self::GRIS);
        $c .= $this->linea(self::IZQUIERDA, 777, self::DERECHA, self::PRIMARIO, 1.2);
        $derecha = 'Temporada '.$datos['temporada'].' · Emitido el '.$datos['emitido'];
        $lote = $this->recortar('Lote '.$caso->numero_lote.' · '.$datos['cliente'], self::ANCHO - $this->ancho($derecha, 8) - 16, 9);
        $c .= $this->texto(self::IZQUIERDA, 763, 9, $lote, true);
        $c .= $this->derecha(self::DERECHA, 763, 8, $derecha, false, self::GRIS);
        $hoja['contenido'] = $c;
        $hoja['y'] = self::INICIO;
    }

    private function pie(array $datos, int $pagina, int $total): string
    {
        return $this->linea(self::IZQUIERDA, 46, self::DERECHA, self::BORDE, 0.5)
            .$this->texto(self::IZQUIERDA, 34, 7, 'Documento de control interno de planta · Generado por FoliOS'
                .($datos['usuario'] ? ' · Emitido por '.$datos['usuario'] : ''), false, self::GRIS)
            .$this->derecha(self::DERECHA, 34, 7, 'Página '.$pagina.' de '.$total, false, self::GRIS);
    }

    /** Devuelve true si tuvo que empezar otra página. */
    private function reservar(RetencionLoteSag $caso, array $datos, array &$hoja, float $alto): bool
    {
        if ($hoja['y'] - $alto >= self::LIMITE) {
            return false;
        }
        $this->nuevaPagina($caso, $datos, $hoja);

        return true;
    }

    private function seccion(RetencionLoteSag $caso, array $datos, array &$hoja, string $titulo): void
    {
        // Un título no queda solo al pie de una hoja.
        $this->reservar($caso, $datos, $hoja, 60);
        $y = $hoja['y'];
        $hoja['contenido'] .= $this->texto(self::IZQUIERDA, $y - 10, 9.5, mb_strtoupper($titulo), true, self::PRIMARIO)
            .$this->linea(self::IZQUIERDA, $y - 15, self::DERECHA, self::PRIMARIO, 0.6);
        $hoja['y'] -= 25;
    }

    /** @param list<array{0: string, 1: string}> $pares */
    private function fichas(RetencionLoteSag $caso, array $datos, array &$hoja, array $pares): void
    {
        $ancho = self::ANCHO / 2;
        foreach (array_chunk($pares, 2) as $fila) {
            $lineas = array_map(fn (array $par): array => $this->envolver($par[1], $ancho - 18, 9), $fila);
            $alto = 24 + max(array_map('count', $lineas)) * 11;
            $this->reservar($caso, $datos, $hoja, $alto);
            $y = $hoja['y'];
            foreach ($fila as $i => [$etiqueta]) {
                $x = self::IZQUIERDA + $i * $ancho;
                $hoja['contenido'] .= $this->borde($x, $y - $alto, $ancho, $alto)
                    .$this->texto($x + 9, $y - 11, 6.5, mb_strtoupper($etiqueta), true, self::GRIS);
                foreach ($lineas[$i] as $n => $linea) {
                    $hoja['contenido'] .= $this->texto($x + 9, $y - 24 - $n * 11, 9, $linea);
                }
            }
            $hoja['y'] -= $alto;
        }
        $hoja['y'] -= 14;
    }

    private function destacado(RetencionLoteSag $caso, array $datos, array &$hoja, string $titulo, string $nota): void
    {
        $lineas = $this->envolver($titulo, self::ANCHO - 32, 10.5, true);
        $notas = $this->envolver($nota, self::ANCHO - 32, 8);
        $alto = 16 + count($lineas) * 13 + count($notas) * 10.5;
        $this->reservar($caso, $datos, $hoja, $alto);
        $y = $hoja['y'];
        $hoja['contenido'] .= $this->rectangulo(self::IZQUIERDA, $y - $alto, self::ANCHO, $alto, self::FONDO)
            .$this->rectangulo(self::IZQUIERDA, $y - $alto, 4, $alto, self::COLORES_ESTADO[$caso->estado] ?? self::PRIMARIO);
        $y -= 15;
        foreach ($lineas as $linea) {
            $hoja['contenido'] .= $this->texto(self::IZQUIERDA + 16, $y, 10.5, $linea, true);
            $y -= 13;
        }
        foreach ($notas as $linea) {
            $hoja['contenido'] .= $this->texto(self::IZQUIERDA + 16, $y, 8, $linea, false, self::GRIS);
            $y -= 10.5;
        }
        $hoja['y'] -= $alto + 14;
    }

    private function parrafo(RetencionLoteSag $caso, array $datos, array &$hoja, string $etiqueta, string $texto): void
    {
        $lineas = $this->envolver($texto, self::ANCHO - 16, 9);
        $this->reservar($caso, $datos, $hoja, 15 + min(count($lineas), 2) * 11.5);
        $hoja['contenido'] .= $this->texto(self::IZQUIERDA, $hoja['y'] - 8, 6.5, mb_strtoupper($etiqueta), true, self::GRIS);
        $hoja['y'] -= 13;
        foreach ($lineas as $linea) {
            $this->reservar($caso, $datos, $hoja, 11.5);
            $hoja['contenido'] .= $this->rectangulo(self::IZQUIERDA, $hoja['y'] - 11.5, 2, 11.5, self::BORDE)
                .$this->texto(self::IZQUIERDA + 10, $hoja['y'] - 8.5, 9, $linea);
            $hoja['y'] -= 11.5;
        }
        $hoja['y'] -= 10;
    }

    private function pallets(RetencionLoteSag $caso, array $datos, array &$hoja): void
    {
        if (! $datos['folios']) {
            $this->parrafo($caso, $datos, $hoja, 'Sin pallets', 'No hay pallets identificados con este lote al emitir el informe.');

            return;
        }
        $this->reservar($caso, $datos, $hoja, 40);
        $this->encabezadoPallets($hoja);
        foreach (array_values($datos['folios']) as $indice => $folio) {
            $celdas = [
                'folio' => [$folio['folio']],
                'cajas_lote' => [$this->numero($folio['cajas_lote'])],
                'cajas_pallet' => [$this->numero($folio['cajas_pallet'])],
                'csg' => $this->envolver($folio['csg'] ?: '—', self::COLUMNAS['csg'][2], 8),
                'ubicacion' => $this->envolver($folio['ubicacion'] ?: '—', self::COLUMNAS['ubicacion'][2], 8),
                'estado' => $this->envolver($this->estadoFolio($folio), self::COLUMNAS['estado'][2], 8),
                'carga' => $this->envolver($folio['carga'] ?? 'Sin carga', self::COLUMNAS['carga'][2], 8),
            ];
            $alto = 7 + max(array_map('count', $celdas)) * 10;
            if ($this->reservar($caso, $datos, $hoja, $alto)) {
                $this->encabezadoPallets($hoja, true);
            }
            $y = $hoja['y'];
            if ($indice % 2 === 1) {
                $hoja['contenido'] .= $this->rectangulo(self::IZQUIERDA, $y - $alto, self::ANCHO, $alto, '0.975 0.98 0.985');
            }
            foreach ($celdas as $clave => $lineas) {
                [, $x, $ancho, $alineacion] = self::COLUMNAS[$clave];
                foreach ($lineas as $n => $linea) {
                    $hoja['contenido'] .= $alineacion === 'derecha'
                        ? $this->derecha($x, $y - 10 - $n * 10, 8, $linea)
                        : $this->texto($x, $y - 10 - $n * 10, 8, $linea, $clave === 'folio');
                }
            }
            $hoja['y'] -= $alto;
            $hoja['contenido'] .= $this->linea(self::IZQUIERDA, $hoja['y'], self::DERECHA, self::BORDE, 0.4);
        }
        $this->reservar($caso, $datos, $hoja, 18);
        $y = $hoja['y'];
        $total = count($datos['folios']);
        $hoja['contenido'] .= $this->linea(self::IZQUIERDA, $y, self::DERECHA, self::PRIMARIO, 0.8)
            .$this->texto(40, $y - 11, 8, 'Total: '.$total.($total === 1 ? ' pallet' : ' pallets'), true)
            .$this->derecha(self::COLUMNAS['cajas_lote'][1], $y - 11, 8, $this->numero(array_sum(array_column($datos['folios'], 'cajas_lote'))), true)
            .$this->derecha(self::COLUMNAS['cajas_pallet'][1], $y - 11, 8, $this->numero(array_sum(array_column($datos['folios'], 'cajas_pallet'))), true);
        $hoja['y'] -= 18;
        $hoja['contenido'] .= $this->texto(self::IZQUIERDA, $hoja['y'] - 8, 7, 'Cajas lote: cajas de este lote en el pallet. Cajas pallet: total del pallet. Ubicación y carga al momento de emitir.', false, self::GRIS);
        $hoja['y'] -= 24;
    }

    private function encabezadoPallets(array &$hoja, bool $continuacion = false): void
    {
        if ($continuacion) {
            $hoja['contenido'] .= $this->texto(self::IZQUIERDA, $hoja['y'] - 8, 7.5, 'PALLETS DEL LOTE (CONTINUACIÓN)', true, self::GRIS);
            $hoja['y'] -= 14;
        }
        $y = $hoja['y'];
        $hoja['contenido'] .= $this->rectangulo(self::IZQUIERDA, $y - 17, self::ANCHO, 17, self::FONDO);
        foreach (self::COLUMNAS as [$titulo, $x, , $alineacion]) {
            $hoja['contenido'] .= $alineacion === 'derecha'
                ? $this->derecha($x, $y - 11.5, 7.5, $titulo, true, self::PRIMARIO)
                : $this->texto($x, $y - 11.5, 7.5, $titulo, true, self::PRIMARIO);
        }
        $hoja['y'] -= 17;
    }

    private function version(RetencionLoteSag $caso, array $datos, array &$hoja, array $evento): void
    {
        $despues = $evento['despues'];
        $filas = [
            ['Estado', $this->estado($despues['estado']).' · '.(! empty($despues['definitivo']) ? 'Resultado definitivo' : 'En seguimiento')],
            ['Destinos restringidos', $this->destinos($despues)],
            ['Cambios registrados', $this->cambios($evento['antes'], $despues)],
        ];
        if (! $evento['antes'] || ($evento['antes']['observacion'] ?? null) !== $despues['observacion']) {
            $filas[] = ['Observación', $despues['observacion']];
        }
        if (filled($despues['resolucion'] ?? null) && ($evento['antes']['resolucion'] ?? null) !== $despues['resolucion']) {
            $filas[] = ['Resolución', $despues['resolucion']];
        }
        if (filled($despues['instruccion_segregacion'] ?? null) && ($evento['antes']['instruccion_segregacion'] ?? null) !== $despues['instruccion_segregacion']) {
            $filas[] = ['Segregación física', $despues['instruccion_segregacion']];
        }
        $filas[] = ['Pallets identificados', $this->foliosVersion($evento['folios'])];
        $filas = array_map(fn (array $fila): array => [$fila[0], $this->envolver($fila[1], 372, 8.5)], $filas);

        // Cada versión se mantiene en una hoja si cabe; si no, continúa en la siguiente con su encabezado.
        $alto = 24 + array_sum(array_map(fn (array $fila): float => count($fila[1]) * 11 + 3, $filas));
        $this->reservar($caso, $datos, $hoja, min($alto, self::INICIO - self::LIMITE));
        $this->encabezadoVersion($hoja, $evento);
        foreach ($filas as [$etiqueta, $lineas]) {
            foreach ($lineas as $n => $linea) {
                if ($this->reservar($caso, $datos, $hoja, 11)) {
                    $this->encabezadoVersion($hoja, $evento, true);
                }
                if ($n === 0) {
                    $hoja['contenido'] .= $this->texto(44, $hoja['y'] - 8.5, 7.5, $etiqueta, true, self::GRIS);
                }
                $hoja['contenido'] .= $this->texto(180, $hoja['y'] - 8.5, 8.5, $linea);
                $hoja['y'] -= 11;
            }
            $hoja['y'] -= 3;
        }
        $hoja['y'] -= 10;
    }

    private function encabezadoVersion(array &$hoja, array $evento, bool $continuacion = false): void
    {
        $y = $hoja['y'];
        $hoja['contenido'] .= $this->rectangulo(self::IZQUIERDA, $y - 18, self::ANCHO, 18, self::FONDO)
            .$this->rectangulo(self::IZQUIERDA, $y - 18, 3, 18, self::PRIMARIO)
            .$this->texto(44, $y - 12, 8.5, 'Versión '.$evento['version'].($continuacion ? ' (continuación)' : ''), true, self::PRIMARIO)
            .$this->derecha(self::DERECHA - 8, $y - 12, 8, $evento['fecha'].' · '.$evento['usuario'], false, self::GRIS);
        $hoja['y'] -= 24;
    }

    private function firmas(array &$hoja): void
    {
        $y = $hoja['y'];
        $ancho = (self::ANCHO - 14) / 2;
        foreach (['Responsable de planta', 'Contraparte SAG'] as $i => $titulo) {
            $x = self::IZQUIERDA + $i * ($ancho + 14);
            $hoja['contenido'] .= $this->borde($x, $y - 92, $ancho, 92)
                .$this->texto($x + 10, $y - 15, 8.5, $titulo, true, self::PRIMARIO);
            foreach (['Nombre' => 38, 'Firma' => 60, 'Fecha' => 82] as $campo => $baja) {
                $hoja['contenido'] .= $this->texto($x + 10, $y - $baja, 7.5, $campo, false, self::GRIS)
                    .$this->linea($x + 48, $y - $baja - 1, $x + $ancho - 12, self::BORDE, 0.6);
            }
        }
        $y -= 108;
        $hoja['contenido'] .= $this->texto(self::IZQUIERDA, $y - 8, 6.5, 'OBSERVACIONES DE SEGUIMIENTO', true, self::GRIS)
            .$this->borde(self::IZQUIERDA, $y - 68, self::ANCHO, 56);
        foreach ([30, 46, 62] as $baja) {
            $hoja['contenido'] .= $this->linea(self::IZQUIERDA + 10, $y - $baja, self::DERECHA - 10, self::BORDE, 0.5);
        }
        $hoja['y'] = $y - 76;
    }

    private function alcance(RetencionLoteSag $caso, int $paises): string
    {
        if ($caso->estado === 'liberado') {
            return 'La liberación eliminó las restricciones de despacho de este lote.';
        }
        if (! $caso->todos_destinos && ! $caso->destinos) {
            return 'El lote queda en seguimiento sin restricción de destinos.';
        }
        $bloques = collect($caso->destinos)->contains('tipo', 'bloque') ? ', incluidos los miembros vigentes de los bloques indicados' : '';

        return ($caso->todos_destinos ? 'Todos los países activos' : $paises.($paises === 1 ? ' país' : ' países')).$bloques
            .'. Los pallets del lote pueden asignarse a cargas y movilizarse; la salida del camión se impide cuando su destino está restringido.';
    }

    private function destinos(array $registro): string
    {
        if (($registro['estado'] ?? null) === 'liberado') {
            return 'Sin restricciones: lote liberado';
        }
        if (! empty($registro['todos_destinos'])) {
            return 'Todos los destinos';
        }

        return collect($registro['destinos'] ?? [])->map(fn (array $destino): string => $destino['tipo'] === 'bloque'
            ? 'Bloque '.$destino['nombre']
            : $destino['nombre'].(isset($destino['codigo']) ? ' ('.$destino['codigo'].')' : ''))->implode(' · ') ?: 'Sin restricción de destinos';
    }

    private function cambios(?array $antes, array $despues): string
    {
        if (! $antes) {
            return 'Registro inicial del seguimiento.';
        }
        $cambios = [];
        if ($antes['estado'] !== $despues['estado']) {
            $cambios[] = 'Estado: de «'.$this->estado($antes['estado']).'» a «'.$this->estado($despues['estado']).'»';
        }
        if ($this->destinos($antes) !== $this->destinos($despues)) {
            $cambios[] = 'Destinos: de «'.$this->destinos($antes).'» a «'.$this->destinos($despues).'»';
        }
        if ((bool) ($antes['definitivo'] ?? false) !== (bool) ($despues['definitivo'] ?? false)) {
            $cambios[] = ! empty($despues['definitivo']) ? 'Se marcó como resultado definitivo' : 'Volvió a seguimiento';
        }
        foreach (['fecha_deteccion' => 'Fecha de detección', 'referencia_informe' => 'N.º de informe'] as $campo => $etiqueta) {
            if (($antes[$campo] ?? null) !== ($despues[$campo] ?? null)) {
                $cambios[] = $etiqueta.': de «'.$this->valorCambio($campo, $antes[$campo] ?? null).'» a «'.$this->valorCambio($campo, $despues[$campo] ?? null).'»';
            }
        }
        foreach (['observacion' => 'Observación actualizada', 'resolucion' => 'Resolución actualizada',
            'instruccion_segregacion' => 'Instrucción de segregación actualizada'] as $campo => $texto) {
            if (($antes[$campo] ?? null) !== ($despues[$campo] ?? null)) {
                $cambios[] = $texto;
            }
        }

        return $cambios ? implode('; ', $cambios).'.' : 'Sin cambios en los datos del seguimiento.';
    }

    private function valorCambio(string $campo, ?string $valor): string
    {
        if (blank($valor)) {
            return 'sin dato';
        }

        return $campo === 'fecha_deteccion' ? CarbonImmutable::parse($valor)->format('d-m-Y') : $valor;
    }

    private function foliosVersion(array $folios): string
    {
        if (! $folios) {
            return 'Ninguno identificado al registrar.';
        }
        $cantidad = count($folios);

        return $cantidad.($cantidad === 1 ? ' pallet · ' : ' pallets · ').$this->numero(array_sum(array_column($folios, 'cajas_lote')))
            .' cajas del lote: '.implode(', ', array_column($folios, 'folio')).'.';
    }

    private function estado(?string $estado): string
    {
        return self::ESTADOS[$estado] ?? ucfirst(str_replace('_', ' ', (string) $estado));
    }

    private function estadoFolio(array $folio): string
    {
        return (self::ESTADOS_FOLIO[$folio['estado']] ?? ucfirst(str_replace('_', ' ', $folio['estado'])))
            .(($folio['activo'] ?? true) ? '' : ' (inactivo)');
    }

    private function fechaHora(string $fecha): string
    {
        return CarbonImmutable::parse($fecha, 'UTC')->setTimezone(config('app.operational_timezone'))->format('d-m-Y H:i');
    }

    private function numero(int $valor): string
    {
        return number_format($valor, 0, ',', '.');
    }

    private function texto(float $x, float $y, float $tamano, string $texto, bool $negrita = false, ?string $color = null): string
    {
        $salida = $this->pdf->texto($x, $y, $tamano, $texto, $negrita);

        // DocumentoPdfPlanta pinta en negro («0 g»); el color se cambia solo para este texto.
        return $color ? $color.' rg'.substr($salida, 3) : $salida;
    }

    private function derecha(float $x, float $y, float $tamano, string $texto, bool $negrita = false, ?string $color = null): string
    {
        return $this->texto($x - $this->ancho($texto, $tamano, $negrita), $y, $tamano, $texto, $negrita, $color);
    }

    private function recortar(string $texto, float $ancho, float $tamano): string
    {
        $lineas = $this->envolver($texto, $ancho, $tamano);

        return count($lineas) > 1 ? rtrim($lineas[0], ' ·').'…' : $lineas[0];
    }

    private function rectangulo(float $x, float $y, float $ancho, float $alto, string $color): string
    {
        return sprintf("%s rg %.2F %.2F %.2F %.2F re f 0 g\n", $color, $x, $y, $ancho, $alto);
    }

    private function borde(float $x, float $y, float $ancho, float $alto): string
    {
        return sprintf("q %s RG 0.6 w %.2F %.2F %.2F %.2F re S Q\n", self::BORDE, $x, $y, $ancho, $alto);
    }

    private function linea(float $desde, float $y, float $hasta, string $color, float $grosor): string
    {
        return sprintf("q %s RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n", $color, $grosor, $desde, $y, $hasta, $y);
    }

    /**
     * Divide un texto en líneas con las métricas reales de Helvetica. DocumentoPdfPlanta::envolver
     * usa anchos conservadores y deja los párrafos a dos tercios de la hoja.
     *
     * @return list<string>
     */
    private function envolver(string $texto, float $ancho, float $tamano, bool $negrita = false): array
    {
        $lineas = [];
        foreach (preg_split('/\R/u', $texto) ?: [''] as $parrafo) {
            $linea = '';
            foreach (preg_split('/\s+/u', trim($parrafo), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
                $candidata = $linea === '' ? $palabra : $linea.' '.$palabra;
                if ($this->ancho($candidata, $tamano, $negrita) <= $ancho) {
                    $linea = $candidata;

                    continue;
                }
                if ($linea !== '') {
                    $lineas[] = $linea;
                }
                // Una palabra más ancha que la columna (un folio largo, una URL) se corta por caracteres.
                $linea = '';
                foreach (mb_str_split($palabra) as $caracter) {
                    if ($linea !== '' && $this->ancho($linea.$caracter, $tamano, $negrita) > $ancho) {
                        $lineas[] = $linea;
                        $linea = '';
                    }
                    $linea .= $caracter;
                }
            }
            $lineas[] = $linea;
        }

        return $lineas;
    }

    /** Ancho en puntos según las métricas de Helvetica, para alinear a la derecha. */
    private function ancho(string $texto, float $tamano, bool $negrita = false): float
    {
        $tabla = self::$anchos[$negrita ? 'negrita' : 'normal'] ??= $this->metricas($negrita);
        $total = 0;
        foreach (mb_str_split($texto) as $caracter) {
            $base = $tabla[$caracter] ?? null;
            if ($base === null) {
                $simple = iconv('UTF-8', 'ASCII//TRANSLIT', $caracter) ?: '';
                $base = $tabla[$simple[0] ?? ''] ?? 556;
            }
            $total += $base;
        }

        return $total * $tamano / 1000;
    }

    /** Filas de la hoja «Corte» de la planilla: los mismos datos del PDF, uno por fila. */
    private function antecedentes(RetencionLoteSag $caso, array $datos): array
    {
        $ultima = $datos['eventos'] ? end($datos['eventos']) : null;
        $filas = [
            ['Establecimiento', $datos['establecimiento'].($datos['csp'] ? ' · CSP '.$datos['csp'] : '')],
            ['Lote de recepción', $caso->numero_lote],
            ['Estado actual', $this->estado($caso->estado)],
            ['Condición del resultado', $caso->definitivo ? 'Resultado definitivo' : 'En seguimiento'],
            ['Fecha de detección', $caso->fecha_deteccion->format('d-m-Y')],
            ['N.º de informe / antecedentes', $caso->referencia_informe ?: 'No informado'],
            ['Destinos restringidos', $this->destinos($caso->toArray())],
            ['Alcance de la restricción', $this->alcance($caso, $datos['paises'])],
            ['Observación de la detección', $caso->observacion],
            ['Resolución vigente', $caso->resolucion ?: 'Pendiente de resolución.'],
            ['Segregación física', $caso->instruccion_segregacion ?: 'No se indicó segregación física.'],
            ['Versión del registro', $caso->version.($ultima ? ' · '.$ultima['fecha'].' · '.$ultima['usuario'] : '')],
        ];
        foreach ($datos['eventos'] as $evento) {
            $despues = $evento['despues'];
            $filas[] = ['Versión '.$evento['version'], $evento['fecha'].' · '.$evento['usuario'].' · '.$this->estado($despues['estado'])
                .' · Destinos: '.$this->destinos($despues).' · '.$this->cambios($evento['antes'], $despues)];
            $filas[] = ['Pallets identificados v'.$evento['version'], $this->foliosVersion($evento['folios'])];
        }

        return $filas;
    }

    /** @return array<string, int> */
    private function metricas(bool $negrita): array
    {
        $mayusculas = $negrita
            ? [722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611]
            : [667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611];
        $minusculas = $negrita
            ? [556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500]
            : [556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500];
        $signos = [' ' => 278, '.' => 278, ',' => 278, ':' => $negrita ? 333 : 278, ';' => $negrita ? 333 : 278, '-' => 333,
            '/' => 278, '(' => 333, ')' => 333, '·' => 278, '%' => 889, '#' => 556, 'º' => 365, '+' => 584, '—' => 1000, '«' => 556, '»' => 556, '…' => 1000];

        return array_combine(range('A', 'Z'), $mayusculas) + array_combine(range('a', 'z'), $minusculas)
            + array_fill_keys(range('0', '9'), 556) + $signos;
    }

    public function xlsx(RetencionLoteSag $caso): string
    {
        $datos = $this->informe($caso);
        $filas = collect($datos['folios'])->map(fn (array $folio): array => [...$folio, 'estado' => $this->estadoFolio($folio),
            'ubicacion' => $folio['ubicacion'] ?: null, 'carga' => $folio['carga'] ?? 'Sin carga']);
        $columnas = collect(['folio' => 'Folio', 'cajas_lote' => 'Cajas del lote', 'cajas_pallet' => 'Cajas del pallet',
            'csg' => 'CSG', 'ubicacion' => 'Ubicación', 'estado' => 'Estado físico', 'carga' => 'Carga'])
            ->map(fn ($titulo, $clave) => ['clave' => $clave, 'titulo' => $titulo, 'ancho' => 24])->values()->all();
        $ruta = app(GeneradorLibroXlsx::class)->generar('Informe de lote retenido SAG · Lote '.$caso->numero_lote, $columnas, $filas, [
            'fecha_corte' => $datos['emitido'], 'temporada' => $datos['temporada'],
            'cliente' => $datos['cliente'], 'usuario' => $datos['usuario'], 'adicionales' => $this->antecedentes($caso, $datos),
        ], 'Pallets del lote');
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('No se pudo incorporar el logo al reporte.');
        }
        $tipos = $zip->getFromName('[Content_Types].xml');
        $tipos = str_replace('</Types>', '<Default Extension="jpg" ContentType="image/jpeg"/><Override PartName="/xl/drawings/logo.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/></Types>', $tipos);
        $zip->addFromString('[Content_Types].xml', $tipos);
        $hoja = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->addFromString('xl/worksheets/sheet2.xml', str_replace('</worksheet>', '<drawing xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rIdLogo"/></worksheet>', $hoja));
        $zip->addFile(resource_path('images/logo-agrorosario.jpg'), 'xl/media/logo-planta.jpg');
        $zip->addFromString('xl/worksheets/_rels/sheet2.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/logo.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/_rels/logo.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdImage" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo-planta.jpg"/></Relationships>');
        $zip->addFromString('xl/drawings/logo.xml', '<?xml version="1.0"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><xdr:oneCellAnchor><xdr:from><xdr:col>3</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext cx="1524000" cy="685800"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="Logo planta"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rIdImage"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="1524000" cy="685800"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
        if (! $zip->close()) {
            throw new RuntimeException('No se pudo finalizar la planilla SAG.');
        }

        return $ruta;
    }
}
