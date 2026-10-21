<?php

namespace App\Rrhh\Integracion;

use App\Rrhh\Models\FuenteMarcaje;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Contrato OAuth 1.0 ListPendingCheckPoint, habilitado explícitamente por cuenta. Nunca consume ListPending. */
final class ClienteGeoVictoria
{
    public function consultar(FuenteMarcaje $fuente, string $desde, array $identificadores): array
    {
        $c = $fuente->configuracion ?? [];
        if (! $fuente->activa || $fuente->tipo !== 'geovictoria' || ($c['contrato_checkpoint'] ?? '') !== 'validado' || empty($c['api_key']) || empty($c['api_secret']) || empty($c['zona_origen'])) {
            $this->error('Confirme con GeoVictoria el método Checkpoint, su zona horaria y las credenciales de la cuenta.');
        }
        $base = rtrim($c['base_url'] ?? '', '/');
        $url = parse_url($base);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || ! preg_match('/^[a-z0-9-]+\.geovictoria\.com$/i', $url['host'] ?? '') || ! in_array($url['port'] ?? 443, [443], true) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ! empty($url['path'])) {
            $this->error('La dirección debe ser un origen HTTPS oficial de GeoVictoria, sin ruta, usuario ni redirecciones.');
        }
        $endpoint = $base.'/api/Punch/ListPendingCheckPoint';
        $oauth = ['oauth_consumer_key' => $c['api_key'], 'oauth_nonce' => Str::random(32), 'oauth_signature_method' => 'HMAC-SHA1', 'oauth_timestamp' => (string) now()->getTimestamp(), 'oauth_version' => '1.0'];
        ksort($oauth);
        $parametros = http_build_query($oauth, '', '&', PHP_QUERY_RFC3986);
        $firma = 'POST&'.rawurlencode($endpoint).'&'.rawurlencode($parametros);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $firma, rawurlencode($c['api_secret']).'&', true));
        $cabecera = 'OAuth '.implode(', ', array_map(fn ($k, $v) => rawurlencode($k).'="'.rawurlencode($v).'"', array_keys($oauth), array_values($oauth)));
        try {
            $respuesta = Http::acceptJson()->asJson()->withHeaders(['Authorization' => $cabecera])->withOptions(['allow_redirects' => false])->connectTimeout(10)->timeout(25)->post($endpoint, ['CheckpointDate' => $desde, 'Range' => implode(',', $identificadores)]);
        } catch (ConnectionException) {
            $this->error('No fue posible conectar a GeoVictoria. Puede repetir el mismo checkpoint sin avanzar la recuperación.');
        }
        // Nunca devolver cuerpos/errores del proveedor que puedan incluir secretos o datos personales.
        if (! $respuesta->successful() || strlen($respuesta->body()) > 8_000_000) {
            $this->error('GeoVictoria no entregó un lote válido. Revise la habilitación del contrato y reintente el mismo checkpoint.');
        }
        $filas = $respuesta->json();
        if (! is_array($filas) || ! array_is_list($filas) || count($filas) > 1000 || array_filter($filas, fn ($f) => ! is_array($f) || array_is_list($f))) {
            $this->error('La respuesta no corresponde al contrato de hasta 1.000 registros. Valide el esquema de la cuenta antes de continuar.');
        }

        return array_map(fn ($f) => array_intersect_key($f, array_flip(['id_punch', 'identifier', 'UserIdentifier', 'type', 'Type', 'Date', 'lastModifiedDate'])), $filas);
    }

    private function error(string $mensaje): never
    {
        throw ValidationException::withMessages(['fuente' => $mensaje]);
    }
}
