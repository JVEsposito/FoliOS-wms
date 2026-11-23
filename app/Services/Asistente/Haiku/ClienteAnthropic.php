<?php

namespace App\Services\Asistente\Haiku;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Llamada mínima a la API de mensajes de Anthropic. Se usa el cliente HTTP de Laravel:
 * el SDK oficial de PHP no estaba disponible en el entorno donde se construyó, y este
 * cliente se reemplaza por el SDK sin tocar el resto del intérprete.
 */
class ClienteAnthropic
{
    public function disponible(): bool
    {
        return filled(config('asistente.anthropic.api_key'));
    }

    public function modelo(): string
    {
        return (string) config('asistente.anthropic.modelo');
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     *
     * @throws FallaHaiku
     */
    public function mensajes(array $cuerpo): array
    {
        try {
            $respuesta = Http::withHeaders([
                'x-api-key' => (string) config('asistente.anthropic.api_key'),
                'anthropic-version' => (string) config('asistente.anthropic.version'),
            ])->acceptJson()->asJson()
                ->connectTimeout(5)
                ->timeout((int) config('asistente.anthropic.timeout'))
                ->post((string) config('asistente.anthropic.url'), $cuerpo);
        } catch (ConnectionException $error) {
            throw new FallaHaiku(FallaHaiku::SIN_RED, 'Sin conexión con la API de Anthropic.');
        }

        if ($respuesta->failed()) {
            $detalle = (string) ($respuesta->json('error.message') ?? $respuesta->body());

            throw new FallaHaiku(FallaHaiku::API, 'HTTP '.$respuesta->status().': '.mb_substr($detalle, 0, 300));
        }

        return (array) $respuesta->json();
    }
}
