<?php

namespace App\Finanzas\Tarifario;

use App\Finanzas\Captura\LectorOperacion;

/** Clientes globales de la operación, leídos sin modificarla, para asignarles precios. */
final class ClientesOperacion
{
    /** @var array<string, array{id: string, codigo: string, nombre: string, activo: bool}>|null */
    private ?array $todos = null;

    public function __construct(private readonly LectorOperacion $lector) {}

    /** @return array<string, array{id: string, codigo: string, nombre: string, activo: bool}> */
    public function todos(): array
    {
        return $this->todos ??= $this->lector->tabla('clientes')
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'activo'])
            ->mapWithKeys(fn (object $cliente): array => [(string) $cliente->id => [
                'id' => (string) $cliente->id,
                'codigo' => (string) $cliente->codigo,
                'nombre' => (string) $cliente->nombre,
                'activo' => (bool) $cliente->activo,
            ]])
            ->all();
    }

    /** @return array{id: string, codigo: string, nombre: string, activo: bool}|null */
    public function buscar(string $id): ?array
    {
        return $this->todos()[$id] ?? null;
    }
}
