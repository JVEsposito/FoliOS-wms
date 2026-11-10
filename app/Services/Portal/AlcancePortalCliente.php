<?php

namespace App\Services\Portal;

use App\Models\AliasCliente;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Límite de lo que ve un usuario de cliente: solo las exportadoras que tiene asignadas.
 * Toda consulta del portal parte de aquí; nunca de un identificador enviado sin validar.
 */
class AlcancePortalCliente
{
    /** @var array<string, list<string>> */
    private array $nombres = [];

    public function cliente(User $usuario, string $clienteId): Cliente
    {
        $cliente = $usuario->clientesPortal()->where('clientes.activo', true)->whereKey($clienteId)->first();
        // 404 y no 403: no confirma la existencia de un cliente ajeno.
        if (! $usuario->esCliente() || ! $usuario->activo || ! $cliente) {
            throw new NotFoundHttpException('Cliente no disponible para tu cuenta.');
        }

        return $cliente;
    }

    /**
     * Textos con que los folios PT registran la exportadora (folios.exportadora no es una
     * llave): nombre del cliente, sus nombres en los catálogos de Validación y sus alias.
     *
     * @return list<string>
     */
    public function nombresExportadora(Cliente $cliente): array
    {
        if (isset($this->nombres[$cliente->id])) {
            return $this->nombres[$cliente->id];
        }
        $propios = $this->textos($cliente->id, true);
        // Un texto que también identifica a otro cliente es ambiguo: no se usa.
        $ajenos = $this->textos($cliente->id, false);

        return $this->nombres[$cliente->id] = array_values(array_diff($propios, $ajenos));
    }

    /** @return list<string> */
    private function textos(string $clienteId, bool $propios): array
    {
        $operador = $propios ? '=' : '!=';
        $clientes = Cliente::where('id', $operador, $clienteId)->get(['nombre', 'codigo']);

        return $clientes->pluck('nombre')->merge($clientes->pluck('codigo'))
            ->merge(ClienteValidacion::where('cliente_id', $operador, $clienteId)->pluck('nombre'))
            ->merge(AliasCliente::where('cliente_id', $operador, $clienteId)->pluck('nombre'))
            ->map(fn (?string $nombre): string => self::normalizar($nombre))
            ->filter()->unique()->values()->all();
    }

    public static function normalizar(?string $texto): string
    {
        return Str::upper(Str::squish((string) $texto));
    }
}
