<?php

namespace App\Finanzas\Captura;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;

/**
 * Lo que comparten las fuentes durante una lectura: el lector de la operación y los
 * catálogos chicos (clientes, temporadas) cargados una sola vez.
 */
final class ContextoCaptura
{
    /** @var array<string, string>|null cliente_id => nombre */
    private ?array $clientes = null;

    /** @var array<string, string|false>|null clave de nombre o código => cliente_id (false si es ambigua) */
    private ?array $clientesPorClave = null;

    /** @var array<string, string>|null temporada_id => código */
    private ?array $temporadas = null;

    /** @var array<string, mixed> */
    private array $recordados = [];

    /**
     * @param  Closure(string): list<string>  $abiertos  Hechos vigentes sin término de una fuente.
     */
    public function __construct(
        public readonly LectorOperacion $lector,
        private readonly Closure $abiertos,
    ) {}

    /**
     * Cliente global de la operación. Se prefiere el identificador; si falta, se busca por
     * nombre o código, sin distinguir mayúsculas, tildes ni espacios.
     *
     * @return array{0: ?string, 1: ?string} [cliente_id, nombre]
     */
    public function cliente(?string $clienteId, ?string $nombre = null): array
    {
        $this->cargarClientes();

        if ($clienteId !== null && isset($this->clientes[$clienteId])) {
            return [$clienteId, $this->clientes[$clienteId]];
        }

        $encontrado = $this->clientesPorClave[self::clave($nombre)] ?? false;
        if ($encontrado !== false) {
            return [$encontrado, $this->clientes[$encontrado]];
        }

        $texto = trim((string) $nombre);

        return [null, $texto === '' ? null : $texto];
    }

    public function temporadaCodigo(?string $temporadaId): ?string
    {
        if ($temporadaId === null) {
            return null;
        }

        $this->temporadas ??= $this->lector->tabla('temporadas')->pluck('codigo', 'id')->all();

        return $this->temporadas[$temporadaId] ?? null;
    }

    /** @return list<string> */
    public function abiertos(string $fuente): array
    {
        return ($this->abiertos)($fuente);
    }

    /**
     * Valor que se calcula una sola vez por lectura (por ejemplo, las cámaras de producto).
     *
     * @template T
     *
     * @param  Closure(): T  $calcular
     * @return T
     */
    public function recordar(string $clave, Closure $calcular): mixed
    {
        if (! array_key_exists($clave, $this->recordados)) {
            $this->recordados[$clave] = $calcular();
        }

        return $this->recordados[$clave];
    }

    public static function fecha(mixed $valor): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $valor, 'UTC');
    }

    public static function clave(?string $valor): string
    {
        return mb_strtoupper(Str::squish(Str::ascii((string) $valor)));
    }

    private function cargarClientes(): void
    {
        if ($this->clientes !== null) {
            return;
        }

        $this->clientes = [];
        $this->clientesPorClave = [];

        $globales = $this->lector->tabla('clientes')->get(['id', 'codigo', 'nombre', 'codigo_externo']);
        foreach ($globales as $cliente) {
            $this->clientes[$cliente->id] = $cliente->nombre;
            foreach ([$cliente->nombre, $cliente->codigo, $cliente->codigo_externo] as $valor) {
                $this->asociar(self::clave($valor), $cliente->id);
            }
        }

        // El nombre con que cada temporada conoce al cliente en Validación.
        $deValidacion = $this->lector->tabla('clientes_validacion')
            ->whereNotNull('cliente_id')
            ->get(['nombre', 'cliente_id']);
        foreach ($deValidacion as $cliente) {
            if (isset($this->clientes[$cliente->cliente_id])) {
                $this->asociar(self::clave($cliente->nombre), $cliente->cliente_id);
            }
        }
    }

    private function asociar(string $clave, string $clienteId): void
    {
        if ($clave === '') {
            return;
        }

        $actual = $this->clientesPorClave[$clave] ?? null;
        $this->clientesPorClave[$clave] = $actual === null || $actual === $clienteId ? $clienteId : false;
    }
}
