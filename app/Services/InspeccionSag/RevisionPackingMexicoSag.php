<?php

namespace App\Services\InspeccionSag;

use App\Services\Validacion\LectorPlanillaValidacion;
use DateTimeImmutable;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RevisionPackingMexicoSag
{
    private const CODIGOS_POR_CONSULTA = 8;

    public function __construct(
        private readonly LectorPlanillaValidacion $planillas,
        private readonly RegistroSdpMexico $registro,
    ) {}

    public function iniciar(UploadedFile $archivo, string $usuario): array
    {
        $planilla = $this->leer($archivo);
        $codigos = array_values(array_unique(array_filter(array_column($planilla['filas'], 'sdp'))));
        if (count($codigos) > 500) {
            throw new DomainException('El packing list supera los 500 códigos SdP distintos por revisión.');
        }
        $consulta = (string) Str::uuid();
        $grupos = array_chunk($codigos, self::CODIGOS_POR_CONSULTA);
        $vigencia = now()->addHour()->timestamp;
        Cache::put($this->clave($consulta), [
            ...$planilla, 'grupos' => $grupos, 'archivo' => Str::limit($archivo->getClientOriginalName(), 180), 'usuario' => $usuario,
            'vigencia' => $vigencia,
        ], 3600);

        return ['id' => $consulta, 'grupos' => count($grupos), 'codigos' => count($codigos)];
    }

    public function consultarGrupo(string $id, string $usuario, int $grupo): void
    {
        $revision = $this->revision($id, $usuario);
        abort_unless(isset($revision['grupos'][$grupo]), 404);
        $clave = $this->clave($id, $grupo);
        if (! Cache::has($clave)) {
            $resultados = $this->registro->buscar($revision['grupos'][$grupo]);
            Cache::add($clave, $resultados, $revision['vigencia'] - now()->timestamp);
        }
    }

    public function resultado(string $id, string $usuario): array
    {
        $revision = $this->revision($id, $usuario);
        $resultados = [];
        $instantes = [];
        foreach (array_keys($revision['grupos']) as $grupo) {
            $parcial = Cache::get($this->clave($id, $grupo));
            abort_if($parcial === null, 409, 'La consulta de SdP todavía está en curso.');
            $resultados += $parcial;
            $instantes = [...$instantes, ...array_column($parcial, 'consultado')];
        }
        $filas = [];
        $fechas = [];
        $conObservacion = 0;
        foreach ($revision['filas'] as $fila) {
            $fila = $this->cruzar($fila, $resultados[$fila['sdp']] ?? null);
            $filas[] = $fila;
            $fecha = $fila['fecha'] ?: 'Sin fecha válida';
            $fechas[$fecha] = ($fechas[$fecha] ?? 0) + $fila['cajas'];
            $conObservacion += (int) ($fila['observaciones'] !== []);
        }
        ksort($fechas);
        sort($instantes, SORT_STRING);

        return [
            'archivo' => $revision['archivo'], 'hoja' => $revision['hoja'], 'fuente' => RegistroSdpMexico::URL,
            'consulta_desde' => $instantes[0] ?? null, 'consulta_hasta' => $instantes[array_key_last($instantes)] ?? null,
            'fechas' => array_map(fn (string $fecha, int $cajas): array => ['fecha' => $fecha, 'cajas' => $cajas], array_keys($fechas), array_values($fechas)),
            'filas' => $filas, 'filas_con_observaciones' => $conObservacion, 'total_cajas' => array_sum($fechas),
        ];
    }

    private function clave(string $id, ?int $grupo = null): string
    {
        return 'sag:mexico:revision:'.$id.($grupo === null ? '' : ':'.$grupo);
    }

    private function revision(string $id, string $usuario): array
    {
        $revision = Cache::get($this->clave($id));
        abort_unless($revision && $revision['usuario'] === $usuario, 404, 'La revisión no está disponible. Vuelve a cargar el packing list.');

        return $revision;
    }

    private function leer(UploadedFile $archivo): array
    {
        $extension = strtolower($archivo->getClientOriginalExtension());
        foreach ($this->planillas->hojas($archivo->getRealPath(), $extension) as $hoja) {
            foreach (array_slice($hoja['filas'], 0, 40, true) as $numero => $fila) {
                $columnas = array_flip(array_map($this->cabecera(...), $fila));
                if (isset($columnas['sdp'], $columnas['fecha'], $columnas['cajas'])) {
                    $filas = $this->filasPacking($hoja, $columnas, $numero, $extension === 'xlsx');
                    if ($filas !== []) {
                        return ['hoja' => $hoja['nombre'], 'filas' => $filas];
                    }
                }
            }
        }

        throw new DomainException('Se necesita un packing list con datos y columnas SdP, Fecha de embalaje y Cajas. El CSG corresponde a otro registro.');
    }

    private function filasPacking(array $hoja, array $columnas, int $encabezado, bool $excel): array
    {
        $filas = [];
        foreach ($hoja['filas'] as $numero => $fila) {
            if ($numero <= $encabezado) {
                continue;
            }
            $valor = fn (string $columna): string => $this->utf8($fila[$columnas[$columna] ?? -1] ?? '');
            $codigo = $valor('sdp');
            $fecha = $valor('fecha');
            $cajas = $valor('cajas');
            $folio = $valor('folio');
            $especie = $valor('especie');
            $variedad = $valor('variedad');
            $clase = $valor('clasificacion');
            if (implode('', [$codigo, $fecha, $cajas, $folio, $especie, $variedad, $clase]) === '') {
                continue;
            }
            $primera = $this->cabecera(current(array_filter($fila, fn (string $valor): bool => $valor !== '')) ?: '');
            if ($codigo === '' && in_array($primera, ['total', 'total_general', 'totales', 'subtotal'], true)) {
                continue;
            }
            if (count($filas) >= 5000) {
                throw new DomainException('El packing list supera las 5.000 líneas por revisión.');
            }

            $observaciones = [];
            $sdp = preg_match('/^[0-9]{1,12}$/D', $codigo) && (int) $codigo > 0 ? str_pad(ltrim($codigo, '0'), 8, '0', STR_PAD_LEFT) : null;
            if ($sdp === null) {
                $observaciones[] = $codigo === '' ? 'Falta código SdP.' : 'Código SdP inválido.';
            }
            $fechaIso = $this->fecha($fecha, $excel, $hoja['fecha_1904']);
            if ($fechaIso === null) {
                $observaciones[] = 'Fecha de embalaje sin informar o inválida.';
            }
            $cantidad = preg_match('/^[0-9]{1,7}(?:[.,]0+)?$/D', $cajas) ? (int) $cajas : 0;
            if ($cantidad < 1 || $cantidad > 1000000) {
                $observaciones[] = 'Cantidad de cajas faltante o inválida; se excluyó del total.';
                $cantidad = 0;
            }
            $filas[] = [
                'fila' => $numero, 'folio' => mb_substr($folio, 0, 60), 'sdp' => $sdp, 'sdp_original' => mb_substr($codigo, 0, 60),
                'fecha' => $fechaIso, 'cajas' => $cantidad, 'especie' => mb_substr($especie, 0, 80),
                'variedad' => mb_substr($variedad, 0, 80), 'clasificacion_declarada' => mb_substr($clase, 0, 20),
                'observaciones' => $observaciones,
            ];
        }

        return $filas;
    }

    private function cruzar(array $fila, ?array $resultado): array
    {
        $estado = $resultado['estado'] ?? 'sin_codigo';
        $registros = $resultado['registros'] ?? [];
        $clases = [];
        if ($estado === 'no_verificable') {
            $fila['observaciones'][] = 'SAG no pudo verificarse en esta consulta.';
        } elseif ($estado === 'no_encontrado') {
            $fila['observaciones'][] = 'SdP sin registro en la búsqueda del SAG.';
        } elseif ($estado === 'encontrado') {
            $coincidencias = array_filter($registros, fn (array $r): bool => (
                $fila['especie'] === '' || $this->especie($r['especie']) === $this->especie($fila['especie'])
            ) && (
                $fila['variedad'] === '' || $this->comparar($r['variedad']) === $this->comparar($fila['variedad'])
            ));
            if ($registros === []) {
                $fila['observaciones'][] = 'El SAG devolvió el SdP sin detalle de especie o variedad legible.';
            } elseif ($coincidencias === []) {
                $fila['observaciones'][] = 'La especie o variedad del packing list no coincide con el registro.';
            }
            $clases = array_values(array_unique(array_filter(array_column($coincidencias, 'clasificacion'))));
            if ($coincidencias !== [] && $clases === []) {
                $fila['observaciones'][] = 'El SAG no informó una clasificación para la combinación consultada.';
            }
            if (in_array('excluido', array_map($this->comparar(...), $clases), true)) {
                $fila['observaciones'][] = 'El SAG informa clasificación Excluido.';
            }
            if ($fila['clasificacion_declarada'] !== '' && $clases !== [] && ! in_array(strtoupper($fila['clasificacion_declarada']), array_map('strtoupper', $clases), true)) {
                $fila['observaciones'][] = 'La clasificación declarada no coincide con el SAG.';
            }
        }

        return [...$fila, 'estado' => $estado, 'clasificaciones' => $clases,
            'consultado' => $resultado['consultado'] ?? null, 'registro_sag' => $registros];
    }

    private function cabecera(string $valor): string
    {
        $valor = $this->comparar($this->utf8($valor));

        return match ($valor) {
            'sdp', 's_d_p', 'sdp_mexico', 'codigo_sdp', 'cod_sdp', 'n_sdp', 'nro_sdp', 'sitio_de_produccion', 'codigo_sitio_de_produccion' => 'sdp',
            'fecha', 'fecha_de_embalaje', 'fecha_embalaje', 'packing_date', 'pack_date', 'fecha_proceso', 'fecha_de_proceso' => 'fecha',
            'cajas', 'n_cajas', 'n_de_cajas', 'nro_cajas', 'cantidad_cajas', 'cantidad_de_cajas', 'boxes', 'cartons' => 'cajas',
            'especie', 'fruta', 'species', 'fruit' => 'especie',
            'variedad', 'variety' => 'variedad',
            'folio', 'folio_pallet', 'pallet', 'pallet_id', 'n_pallet' => 'folio',
            'clasificacion_sdp', 'categoria_sdp' => 'clasificacion',
            default => $valor,
        };
    }

    private function especie(string $valor): string
    {
        $valor = $this->comparar($valor);

        return match ($valor) {
            'cherry', 'cherries', 'cerezas' => 'cereza',
            'nectarina', 'nectarines', 'nectarine' => 'nectarin',
            default => $valor,
        };
    }

    private function comparar(string $valor): string
    {
        return Str::of($valor)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function utf8(string $valor): string
    {
        return trim(mb_check_encoding($valor, 'UTF-8') ? $valor : mb_convert_encoding($valor, 'UTF-8', 'Windows-1252'));
    }

    private function fecha(string $fecha, bool $excel, bool $fecha1904): ?string
    {
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[T ]\d{2}:\d{2}(?::\d{2})?)?$/D', $fecha, $partes)
            || preg_match('/^(\d{4})(\d{2})(\d{2})$/D', $fecha, $partes)) {
            [, $anio, $mes, $dia] = $partes;
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})(?: \d{2}:\d{2}(?::\d{2})?)?$/D', $fecha, $partes)) {
            [, $dia, $mes, $anio] = $partes;
        } elseif ($excel && preg_match('/^[0-9]{1,7}(?:\.[0-9]+)?$/D', $fecha) && (int) $fecha >= 61 && (int) $fecha <= 2957000) {
            return (new DateTimeImmutable($fecha1904 ? '1904-01-01' : '1899-12-30'))->modify('+'.(int) $fecha.' days')->format('Y-m-d');
        } else {
            return null;
        }

        return checkdate((int) $mes, (int) $dia, (int) $anio) ? sprintf('%04d-%02d-%02d', (int) $anio, (int) $mes, (int) $dia) : null;
    }
}
