<?php

namespace App\Services\InspeccionSag;

use App\Exceptions\ServicioSagNoDisponible;
use App\Services\Validacion\LectorPlanillaValidacion;
use DomainException;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RegistroCerezasChinaSag
{
    public const URL = 'https://www.sag.gob.cl/content/listado-de-participantes-inscritos-para-exportacion-de-cerezas-china';

    public const URL_PLANTAS_AUTORIZADAS = 'https://www.sag.gob.cl/content/listados-de-participantes-para-exportacion-de-cerezas-china';

    public function __construct(private readonly LectorPlanillaValidacion $planillas) {}

    public function consultar(array $solicitud): array
    {
        $solicitud = [
            'CSG' => $this->codigos($solicitud['csg'] ?? ''),
            'CSP' => $this->codigos($solicitud['csp'] ?? ''),
        ];
        if ($solicitud['CSG'] === [] && $solicitud['CSP'] === []) {
            throw new DomainException('Ingresa al menos un código CSG o CSP.');
        }
        $lista = Cache::remember('sag:china:cerezas', now()->addHour(), $this->descargar(...));
        $resultados = [];
        foreach ($solicitud as $tipo => $codigos) {
            foreach ($codigos as $codigo) {
                $ubicaciones = $lista['registros'][$tipo][$codigo] ?? [];
                $resultados[] = [
                    'tipo' => $tipo, 'codigo' => $codigo, 'inscrito' => $ubicaciones !== [],
                    'ubicaciones' => $ubicaciones,
                ];
            }
        }
        unset($lista['registros']);

        return [...$lista, 'resultados' => $resultados, 'plantas_autorizadas_url' => self::URL_PLANTAS_AUTORIZADAS];
    }

    private function codigos(string $texto): array
    {
        $codigos = [];
        foreach (preg_split('/[\s,;]+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $valor) {
            if (! preg_match('/^[0-9]{1,12}$/D', $valor) || (int) $valor === 0) {
                throw new DomainException("El código {$valor} debe ser numérico y mayor que cero.");
            }
            $codigos[] = ltrim($valor, '0');
        }
        $codigos = array_values(array_unique($codigos));
        if (count($codigos) > 200) {
            throw new DomainException('Ingresa hasta 200 CSG y 200 CSP por consulta.');
        }

        return $codigos;
    }

    private function descargar(): array
    {
        $cliente = Http::withoutRedirecting()->connectTimeout(8)->timeout(12);
        try {
            $pagina = $cliente->get(self::URL);
            $documento = new DOMDocument;
            if (! $pagina->successful() || $pagina->body() === '' || ! $documento->loadHTML($pagina->body(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new DomainException('No se pudo leer la página del registro.');
            }
            $url = $this->enlaceXlsx($documento);
            $libro = $cliente->get($url);
            $contenido = $libro->body();
            if (! $libro->successful() || strlen($contenido) > 5000000 || ! str_starts_with($contenido, "PK\x03\x04")) {
                throw new DomainException('No se pudo descargar el libro del registro.');
            }
            $ruta = tempnam(sys_get_temp_dir(), 'folios-sag-china-');
            if ($ruta === false) {
                throw new DomainException('No se pudo abrir un archivo temporal para el registro.');
            }
            try {
                if (file_put_contents($ruta, $contenido) !== strlen($contenido)) {
                    throw new DomainException('No se pudo guardar el libro del registro.');
                }
                $registros = $this->leerRegistro($ruta);
            } finally {
                unlink($ruta);
            }
        } catch (DomainException|ConnectionException $error) {
            throw new ServicioSagNoDisponible('No se pudo verificar el registro de cerezas a China publicado por el SAG. Intenta nuevamente o consulta el enlace oficial.', previous: $error);
        }

        $archivo = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
        preg_match('/\b20[0-9]{2}-[0-9]{2}-[0-9]{2}\b/', $archivo, $fecha);

        return [
            'fuente' => self::URL, 'archivo' => $archivo, 'actualizado' => $fecha[0] ?? null,
            'consultado' => now(config('app.operational_timezone'))->toIso8601String(),
            'registros' => $registros,
        ];
    }

    private function enlaceXlsx(DOMDocument $documento): string
    {
        $xpath = new DOMXPath($documento);
        foreach ($xpath->query('//a[@href]/@href') as $enlace) {
            $url = $enlace->value;
            if (str_starts_with($url, '/sites/default/files/')) {
                $url = 'https://www.sag.gob.cl'.$url;
            }
            if (preg_match('~^https://www\.sag\.gob\.cl/sites/default/files/[^?#]+\.xlsx$~iD', $url)) {
                return $url;
            }
        }

        throw new DomainException('La publicación SAG no contiene un enlace XLSX reconocido.');
    }

    private function leerRegistro(string $ruta): array
    {
        $registros = [];
        foreach ($this->planillas->hojas($ruta, 'xlsx') as $hoja) {
            $tipo = null;
            foreach ($hoja['filas'] as $fila) {
                if ($tipo === null) {
                    if (preg_match('/^(?:ORCHARD CODE|FACILITY CODE) \((CSG|CSP)\)/i', $fila[0], $partes)) {
                        $tipo = $partes[1];
                    }

                    continue;
                }
                [$codigo, , $region, $comuna] = array_pad($fila, 4, '');
                if (preg_match('/^[0-9]{1,12}$/D', $codigo) && (int) $codigo > 0) {
                    $registros[$tipo][ltrim($codigo, '0')][] = ['region' => $region, 'comuna' => $comuna];
                }
            }
        }
        if (empty($registros['CSG']) || empty($registros['CSP'])) {
            throw new DomainException('El libro oficial del SAG no contiene ambos registros, CSG y CSP.');
        }

        return $registros;
    }
}
