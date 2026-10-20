<?php

namespace App\Comex\Configuracion;

use App\Comex\EsquemaComex;
use App\Comex\Expediente\EmbarquesOperacion;
use App\Comex\Models\ClienteComex;
use App\Comex\Models\PaisTipoDocumento;
use App\Comex\Models\TipoDocumento;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * La configuración de Comex: qué documentos se piden y cómo se sigue a cada cliente. Cada
 * planta la ajusta a sus mercados; los cambios valen desde ya para todos los expedientes, sin
 * tocar lo que ya se registró.
 */
final class GestorConfiguracion
{
    public function __construct(
        private readonly EsquemaComex $esquema,
        private readonly EmbarquesOperacion $operacion,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  validados por el controlador
     *
     * @throws ValidationException
     */
    public function crearTipo(array $datos, User $usuario): TipoDocumento
    {
        return $this->esquema->transaccion(function () use ($datos, $usuario): TipoDocumento {
            $this->exigirNombreLibre($datos['nombre'], null);
            $tipo = new TipoDocumento([
                'codigo' => $this->codigoLibre($datos['nombre']),
                'orden' => $datos['orden'] ?? ((int) TipoDocumento::query()->max('orden') + 10),
                'sugerido' => false,
                'version' => 0,
            ]);

            return $this->guardarTipo($tipo, $datos, $usuario);
        });
    }

    /**
     * @param  array<string, mixed>  $datos  validados por el controlador
     *
     * @throws ValidationException
     */
    public function actualizarTipo(TipoDocumento $tipo, array $datos, User $usuario): TipoDocumento
    {
        return $this->esquema->transaccion(function () use ($tipo, $datos, $usuario): TipoDocumento {
            $tipo = TipoDocumento::query()->lockForUpdate()->findOrFail($tipo->id);
            if ($tipo->version !== (int) $datos['version']) {
                throw ValidationException::withMessages(['version' => 'Otra persona cambió este documento mientras lo editaba. Revise la configuración actual y vuelva a guardar.']);
            }
            $this->exigirNombreLibre($datos['nombre'], $tipo->id);

            return $this->guardarTipo($tipo, $datos, $usuario);
        });
    }

    /**
     * @throws ValidationException
     */
    public function guardarCliente(string $clienteId, string $gestion, ?string $observacion, int $version, User $usuario): ClienteComex
    {
        $cliente = $this->operacion->cliente($clienteId);
        if ($cliente === null) {
            throw ValidationException::withMessages(['cliente' => 'El cliente no existe en la operación.']);
        }

        return $this->esquema->transaccion(function () use ($cliente, $gestion, $observacion, $version, $usuario): ClienteComex {
            $registro = ClienteComex::query()->lockForUpdate()->find($cliente['id']);
            if (($registro->version ?? 0) !== $version) {
                throw ValidationException::withMessages(['version' => 'Otra persona cambió la gestión de este cliente. Revise la configuración actual y vuelva a guardar.']);
            }

            $registro ??= new ClienteComex(['cliente_id' => $cliente['id'], 'version' => 0]);
            $registro->fill([
                'cliente_nombre' => Str::limit($cliente['nombre'], 180, ''),
                'gestion' => $gestion,
                'observacion' => filled($observacion) ? trim($observacion) : null,
                'version' => $registro->version + 1,
                'actualizado_por_user_id' => $usuario->id,
                'actualizado_por_nombre' => Str::limit($usuario->name, 150, ''),
            ])->save();

            return $registro;
        });
    }

    /** @param  array<string, mixed>  $datos */
    private function guardarTipo(TipoDocumento $tipo, array $datos, User $usuario): TipoDocumento
    {
        $paises = $datos['exigencia'] === TipoDocumento::PAISES ? $this->paises($datos['paises'] ?? []) : [];

        $tipo->fill([
            'nombre' => trim($datos['nombre']),
            'descripcion' => filled($datos['descripcion'] ?? null) ? trim($datos['descripcion']) : null,
            'momento' => $datos['momento'],
            'exigencia' => $datos['exigencia'],
            'transportes' => $this->subconjunto($datos['transportes'] ?? [], TipoDocumento::TRANSPORTES),
            'gestiones' => $this->subconjunto($datos['gestiones'] ?? [], [ClienteComex::SERVICIO, ClienteComex::PROPIA]),
            'pide_numero' => (bool) $datos['pide_numero'],
            'pide_fecha' => (bool) $datos['pide_fecha'],
            'pide_archivo' => (bool) $datos['pide_archivo'],
            'dato_operacion' => $datos['dato_operacion'] ?? null,
            'activo' => (bool) $datos['activo'],
            'version' => $tipo->version + 1,
            'actualizado_por_user_id' => $usuario->id,
            'actualizado_por_nombre' => Str::limit($usuario->name, 150, ''),
            ...(isset($datos['orden']) ? ['orden' => (int) $datos['orden']] : []),
        ])->save();

        PaisTipoDocumento::query()->where('tipo_documento_id', $tipo->id)->delete();
        foreach ($paises as $iso => $nombre) {
            PaisTipoDocumento::query()->create(['tipo_documento_id' => $tipo->id, 'pais_iso2' => $iso, 'pais_nombre' => Str::limit($nombre, 150, '')]);
        }

        return $tipo->load('paises');
    }

    /**
     * Países del catálogo operativo, con su nombre a la fecha.
     *
     * @param  list<string>  $codigos
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function paises(array $codigos): array
    {
        $catalogo = array_column($this->operacion->paises(), 'nombre', 'iso2');
        $elegidos = [];
        foreach (array_unique(array_map('strtoupper', $codigos)) as $codigo) {
            if (! isset($catalogo[$codigo])) {
                throw ValidationException::withMessages(['paises' => "El país «{$codigo}» no está en el catálogo de países."]);
            }
            $elegidos[$codigo] = $catalogo[$codigo];
        }
        if ($elegidos === []) {
            throw ValidationException::withMessages(['paises' => 'Elija al menos un país de destino que exija este documento.']);
        }

        return $elegidos;
    }

    /**
     * @param  list<string>  $elegidos
     * @param  list<string>  $todos
     * @return list<string> vacío si están todos (aplica siempre)
     */
    private function subconjunto(array $elegidos, array $todos): array
    {
        $validos = array_values(array_intersect($todos, $elegidos));

        return count($validos) === count($todos) ? [] : $validos;
    }

    private function exigirNombreLibre(string $nombre, ?int $excepto): void
    {
        $existe = TipoDocumento::query()
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($nombre))])
            ->when($excepto !== null, fn ($consulta) => $consulta->whereKeyNot($excepto))
            ->exists();
        if ($existe) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un documento con ese nombre.']);
        }
    }

    private function codigoLibre(string $nombre): string
    {
        $base = Str::limit(Str::slug($nombre, '_'), 32, '') ?: 'documento';
        $codigo = $base;
        for ($intento = 2; TipoDocumento::query()->where('codigo', $codigo)->exists(); $intento++) {
            $codigo = "{$base}_{$intento}";
        }

        return $codigo;
    }
}
