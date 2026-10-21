<?php

namespace App\Finanzas\Captura;

use App\Finanzas\Captura\Fuentes\CalculosConcepto;
use App\Finanzas\Captura\Fuentes\CargosDeclarados;
use App\Finanzas\Captura\Fuentes\Despacho;
use App\Finanzas\Captura\Fuentes\EstadiaCamara;
use App\Finanzas\Captura\Fuentes\Prefrio;
use App\Finanzas\Captura\Fuentes\RecepcionFrutaEmbalada;
use App\Finanzas\Captura\Fuentes\RecepcionRomana;
use App\Finanzas\Captura\Fuentes\Repaletizaje;
use App\Finanzas\Captura\Fuentes\ValidacionPt;
use InvalidArgumentException;

/** Fuentes de hechos, en el orden del recorrido de la fruta por la planta. */
final class CatalogoFuentes
{
    private const FUENTES = [
        RecepcionRomana::class,
        ValidacionPt::class,
        RecepcionFrutaEmbalada::class,
        Prefrio::class,
        EstadiaCamara::class,
        Repaletizaje::class,
        Despacho::class,
        CargosDeclarados::class,
        CalculosConcepto::class,
    ];

    /** @var array<string, FuenteHechos>|null */
    private ?array $fuentes = null;

    /** @return array<string, FuenteHechos> */
    public function todas(): array
    {
        if ($this->fuentes === null) {
            $this->fuentes = [];
            foreach (self::FUENTES as $clase) {
                $fuente = app($clase);
                $this->fuentes[$fuente->clave()] = $fuente;
            }
        }

        return $this->fuentes;
    }

    /**
     * @param  list<string>|null  $claves
     * @return array<string, FuenteHechos>
     */
    public function seleccion(?array $claves): array
    {
        if ($claves === null || $claves === []) {
            return $this->todas();
        }

        $desconocidas = array_diff($claves, array_keys($this->todas()));
        if ($desconocidas !== []) {
            throw new InvalidArgumentException('Fuente desconocida: '.implode(', ', $desconocidas).'.');
        }

        return array_intersect_key($this->todas(), array_flip($claves));
    }

    /** @return list<string> */
    public function intervalos(): array
    {
        return array_keys(array_filter($this->todas(), fn (FuenteHechos $fuente): bool => $fuente->intervalo()));
    }

    /** @return list<array{clave: string, nombre: string, descripcion: string, intervalo: bool, medidas: array<string, array{nombre: string, unidad: string}>}> */
    public function descripcion(): array
    {
        return array_values(array_map(fn (FuenteHechos $fuente): array => [
            'clave' => $fuente->clave(),
            'nombre' => $fuente->nombre(),
            'descripcion' => $fuente->descripcion(),
            'intervalo' => $fuente->intervalo(),
            'medidas' => $fuente->medidas(),
            ...$this->contexto($fuente->clave()),
        ], $this->todas()));
    }

    /** Dimensiones públicas para filtrar y relacionar hechos, sin exponer tablas ni SQL. */
    public function contexto(string $clave): array
    {
        $fuente = $this->todas()[$clave] ?? null;
        if ($fuente !== null && method_exists($fuente, 'contexto')) {
            return $fuente->contexto();
        }
        $dimensiones = match ($clave) {
            'romana_recepcion' => ['tipo_recepcion' => 'Tipo de recepción', 'tipo_servicio' => 'Servicio', 'especie' => 'Especie', 'tipo_envase' => 'Tipo de envase'],
            default => [],
        };

        return ['area' => $clave === 'romana_recepcion' ? 'recepcion' : (in_array($clave, ['declarado', 'calculado'], true) ? 'general' : 'frigorifico'), 'dimensiones' => $dimensiones];
    }
}
