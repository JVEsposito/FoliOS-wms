<?php

namespace App\Services\Asistente\Haiku;

use App\Models\Cliente;
use App\Services\Asistente\TextoNormalizado;

/**
 * Cambia los nombres que el reconocedor conoce por fichas antes de que la pregunta salga
 * de la planta: «kilos de [CLIENTE_1] esta semana». Haiku devuelve la ficha y FoliOS la
 * traduce. Un nombre que el reconocedor no conoce sale tal como se escribió.
 */
class FichasAsistente
{
    /**
     * @param  array<string, mixed>  $entidades  resultado de ReconocedorEntidades::reconocer()
     * @return array{texto: string, fichas: array<string, array{tipo: string, id: string, etiqueta: string}>}
     */
    public function preparar(string $texto, array $entidades): array
    {
        $normalizado = TextoNormalizado::de($texto);
        $fichas = [];

        if ($entidades['cliente_id'] ?? null) {
            $cliente = Cliente::query()->find($entidades['cliente_id'], ['id', 'codigo', 'nombre']);
            if ($cliente) {
                $normalizado = $this->reemplazarCliente($normalizado, $cliente, '[CLIENTE_1]');
                $fichas['CLIENTE_1'] = ['tipo' => 'cliente', 'id' => (string) $cliente->id, 'etiqueta' => (string) $cliente->nombre];
            }
        }
        if (($entidades['csg_id'] ?? null) && ($entidades['csg'] ?? null)) {
            $normalizado = (string) preg_replace('/\b'.preg_quote((string) $entidades['csg'], '/').'\b/', '[CSG_1]', $normalizado);
            $fichas['CSG_1'] = ['tipo' => 'csg', 'id' => (string) $entidades['csg_id'], 'etiqueta' => (string) $entidades['csg']];
        }

        return ['texto' => (string) preg_replace('/(\[CLIENTE_1\]\s*)+/', '[CLIENTE_1] ', $normalizado), 'fichas' => $fichas];
    }

    private function reemplazarCliente(string $texto, Cliente $cliente, string $ficha): string
    {
        $nombre = TextoNormalizado::de((string) $cliente->nombre);
        if ($nombre !== '' && str_contains($texto, $nombre)) {
            $texto = str_replace($nombre, $ficha, $texto);
        }
        $codigo = TextoNormalizado::de((string) $cliente->codigo);
        if (strlen($codigo) >= 3) {
            $texto = (string) preg_replace('/\b'.preg_quote($codigo, '/').'\b/', $ficha, $texto);
        }
        // Lo que el reconocedor aceptó con una letra de diferencia también se oculta.
        foreach (TextoNormalizado::palabras((string) $cliente->nombre) as $parte) {
            if (strlen($parte) < 5) {
                continue;
            }
            $texto = (string) preg_replace_callback('/\b[a-z0-9]{5,}\b/', fn (array $m): string => levenshtein($parte, $m[0]) <= 1 ? $ficha : $m[0], $texto);
        }

        return $texto;
    }
}
