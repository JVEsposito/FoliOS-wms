<?php

namespace App\Finanzas\Tarifario;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Captura\FuenteHechos;
use App\Finanzas\Captura\Fuentes\CargosDeclarados;
use App\Finanzas\Models\ConceptoServicio;
use App\Finanzas\Valorizacion\ReglaValorizacion;
use Illuminate\Http\Request;

/**
 * Conceptos comerciales y el proceso que los respalda. Conserva las siete claves originales
 * para que los precios y cierres existentes sigan significando lo mismo.
 */
final class CatalogoServicios
{
    /** Un bulto es un pallet o un saldo: cobrar por ambos en un servicio cobraría dos veces. */
    private const EXCLUYENTES = [
        'bultos' => ['pallets', 'saldos'],
        'pallets' => ['bultos'],
        'saldos' => ['bultos'],
    ];

    /** Nombres de unidad que la medida no dice por sí sola. */
    private const UNIDADES = [
        'kilos_netos' => 'kilo neto',
        'operaciones' => 'repaletizaje',
        'folios_origen' => 'folio de origen',
        'folios_resultantes' => 'folio resultante',
        'repaletizaje.cajas' => 'caja resultante',
    ];

    public function __construct(private readonly CatalogoFuentes $fuentes) {}

    private ?array $catalogo = null;

    private ?Request $solicitud = null;

    public function recargar(): void
    {
        $this->catalogo = null;
    }

    /** Conceptos originales más las definiciones inmutables creadas en Oficina. */
    public function descripcion(): array
    {
        // Los controladores pueden sobrevivir a una petición (workers persistentes o pruebas).
        // Se conserva la memoización dentro de cada lectura, nunca entre solicitudes.
        if ($this->solicitud !== request()) {
            $this->catalogo = null;
            $this->solicitud = request();
        }
        if ($this->catalogo === null) {
            $this->catalogo = [];
            foreach ($this->fuentes->todas() as $fuente) {
                if (in_array($fuente->clave(), [CargosDeclarados::CLAVE, ReglaValorizacion::FUENTE], true)) {
                    continue;
                }
                $this->catalogo[$fuente->clave()] = $this->describir($fuente, [
                    'clave' => $fuente->clave(), 'nombre' => $fuente->nombre(), 'base' => true,
                    'descripcion' => $fuente->descripcion(), 'aplicacion' => 'base',
                    'vigente_desde' => null, 'vigente_hasta' => null, 'dia_desde' => 1, 'dia_hasta' => null,
                ]);
            }
            foreach (ConceptoServicio::query()->orderBy('created_at')->orderBy('clave')->get() as $servicio) {
                $fuente = $this->fuentes->todas()[$servicio->fuente] ?? null;
                if ($fuente !== null) {
                    $this->catalogo[$servicio->clave] = $this->describir($fuente, [
                        'clave' => $servicio->clave, 'nombre' => $servicio->nombre, 'base' => false,
                        'descripcion' => $servicio->descripcion, 'aplicacion' => $servicio->aplicacion,
                        'vigente_desde' => $servicio->vigente_desde->toDateString(),
                        'vigente_hasta' => $servicio->vigente_hasta?->toDateString(),
                        'dia_desde' => $servicio->dia_desde, 'dia_hasta' => $servicio->dia_hasta,
                        'unidad_libre' => $servicio->unidad_libre, 'proceso_referencia' => $servicio->proceso_referencia,
                        'area' => $servicio->area, 'regla' => $servicio->regla,
                    ]);
                }
            }
        }

        return array_values($this->catalogo);
    }

    private function describir(FuenteHechos $fuente, array $datos): array
    {
        return [
            'area' => $this->fuentes->contexto($fuente->clave())['area'], ...$datos, 'fuente' => $fuente->clave(), 'fuente_nombre' => $fuente->nombre(),
            'por_dia' => $fuente->intervalo(),
            'unidades' => array_map(fn (string $unidad): array => [
                'clave' => $unidad, 'nombre' => in_array($fuente->clave(), [CargosDeclarados::CLAVE, ReglaValorizacion::FUENTE], true) ? 'por '.($datos['unidad_libre'] ?? 'unidad') : $this->etiquetaUnidad($fuente, $unidad),
            ], array_keys(array_filter($fuente->medidas(), fn (array $medida): bool => ! isset($medida['dimension_unidad'])))),
        ];
    }

    public function buscar(string $clave): ?array
    {
        $this->descripcion();

        return $this->catalogo[$clave] ?? null;
    }

    /** @return list<string> */
    public function claves(): array
    {
        $this->descripcion();

        return array_keys($this->catalogo);
    }

    public function existe(string $servicio, string $unidad): bool
    {
        return in_array($unidad, array_column($this->buscar($servicio)['unidades'] ?? [], 'clave'), true);
    }

    public function nombreServicio(string $servicio): string
    {
        return $this->buscar($servicio)['nombre'] ?? $servicio;
    }

    /**
     * Un hecho se lee una vez. Cada concepto que le corresponde usa sus medidas e identidad;
     * reemplazar al base nunca oculta un concepto nuevo sin precio: queda pendiente.
     * $diaEstadia cuenta desde el inicio de ESTA custodia del folio, no desde la cosecha.
     *
     * @return list<string>
     */
    public function paraHecho(string $fuente, string $fecha, int $diaEstadia = 1, ?string $conceptoDeclarado = null): array
    {
        if (in_array($fuente, [CargosDeclarados::CLAVE, ReglaValorizacion::FUENTE], true)) {
            $concepto = $conceptoDeclarado === null ? null : $this->buscar($conceptoDeclarado);

            // La vigencia se verifica al aprobar el acuerdo. Finalizar el catálogo no
            // revoca compromisos ya aprobados: eso requiere anular el cargo explícitamente.
            return $concepto !== null && $concepto['fuente'] === $fuente ? [$conceptoDeclarado] : [];
        }
        $adicionales = [];
        $reemplaza = false;
        foreach ($this->descripcion() as $servicio) {
            if ($servicio['base'] || $servicio['fuente'] !== $fuente
                || $servicio['vigente_desde'] > $fecha
                || ($servicio['vigente_hasta'] !== null && $servicio['vigente_hasta'] < $fecha)
                || $diaEstadia < $servicio['dia_desde']
                || ($servicio['dia_hasta'] !== null && $diaEstadia > $servicio['dia_hasta'])) {
                continue;
            }
            $adicionales[] = $servicio['clave'];
            $reemplaza = $reemplaza || $servicio['aplicacion'] === ConceptoServicio::REEMPLAZA_BASE;
        }

        return $reemplaza ? $adicionales : [$fuente, ...$adicionales];
    }

    /** «por caja», «por pallet y día»… */
    public function nombreUnidad(string $servicio, string $unidad): string
    {
        $concepto = $this->buscar($servicio);
        if (in_array($concepto['fuente'] ?? null, [CargosDeclarados::CLAVE, ReglaValorizacion::FUENTE], true)) {
            return 'por '.($concepto['unidad_libre'] ?? 'unidad');
        }
        $claveFuente = $concepto['fuente'] ?? $servicio;
        $fuente = $this->fuentes->todas()[$claveFuente] ?? null;

        return $fuente === null ? $unidad : $this->etiquetaUnidad($fuente, $unidad);
    }

    private function etiquetaUnidad(FuenteHechos $fuente, string $unidad): string
    {
        $medida = $fuente->medidas()[$unidad] ?? null;
        if ($medida === null) {
            return $unidad;
        }
        $nombre = self::UNIDADES[$fuente->clave().'.'.$unidad] ?? self::UNIDADES[$unidad] ?? $medida['unidad'];

        return 'por '.$nombre.($fuente->intervalo() ? ' y día' : '');
    }

    /** Posición en el recorrido de la fruta, para ordenar como en Hechos valorizables. */
    public function orden(string $servicio, ?string $unidad = null): int
    {
        $posicion = array_search($servicio, $this->claves(), true);
        $fuente = $this->fuentes->todas()[$this->buscar($servicio)['fuente'] ?? $servicio] ?? null;
        $subposicion = $unidad === null || $fuente === null ? 0 : array_search($unidad, array_keys($fuente->medidas()), true);

        return (($posicion === false ? 99 : $posicion) * 100) + ($subposicion === false ? 99 : $subposicion);
    }

    /** @return list<string> */
    public function excluyentes(string $unidad): array
    {
        return self::EXCLUYENTES[$unidad] ?? [];
    }
}
