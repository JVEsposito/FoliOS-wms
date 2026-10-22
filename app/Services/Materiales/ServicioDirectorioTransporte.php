<?php

namespace App\Services\Materiales;

use App\Exceptions\ConflictoOperacion;
use App\Models\ConductorTransporte;
use App\Models\EmpresaTransporte;
use App\Models\EventoDirectorioTransporte;
use App\Models\RecepcionMaterial;
use App\Models\User;
use App\Models\VehiculoTransporte;
use Illuminate\Database\Eloquent\Model;

class ServicioDirectorioTransporte
{
    public static function rut(mixed $valor): ?string
    {
        if (! is_scalar($valor) && $valor !== null) {
            return '!INVALIDO!';
        }
        $texto = mb_strtoupper(preg_replace('/[.\s-]/u', '', (string) $valor) ?? '');
        if ($texto === '') {
            return null;
        }

        return substr($texto, 0, -1).'-'.substr($texto, -1);
    }

    public static function patente(mixed $valor): ?string
    {
        if (! is_scalar($valor) && $valor !== null) {
            return '!INVALIDO!';
        }
        $texto = mb_strtoupper(preg_replace('/[.\s-]/u', '', (string) $valor) ?? '');

        return $texto === '' ? null : $texto;
    }

    /**
     * Se ejecuta dentro de la transacción de la recepción, después de verificar
     * temporada, versión e idempotencia. Guardar un viaje no reemplaza fichas
     * existentes salvo petición explícita con su versión conocida.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function capturar(array $datos, User $actor, ?RecepcionMaterial $anterior = null): array
    {
        $viaje = $datos['transporte'] ?? null;
        if ($viaje === null) {
            $viaje = $anterior?->transporte_snapshot;
            // Clientes anteriores conservan el conductor. Un cambio de los textos
            // de cabecera rompe solo la asociación que dejó de corresponder.
            if ($viaje !== null) {
                if (($datos['patente'] ?? null) !== $anterior->patente) {
                    $viaje['vehiculo'] = ['patente' => self::patente($datos['patente'] ?? null)];
                }
                if (($datos['transportista'] ?? null) !== $anterior->transportista) {
                    $viaje['empresa'] = ['nombre' => $datos['transportista'] ?? null];
                }
            } else {
                return [
                    'patente' => $datos['patente'] ?? null,
                    'transportista' => $datos['transportista'] ?? null,
                ];
            }
        }

        $previo = $anterior?->transporte_snapshot ?? [];
        $conductor = $this->ficha(ConductorTransporte::class, 'conductor', 'rut', $viaje['conductor'] ?? [], $datos, $actor, $previo['conductor'] ?? null);
        $empresa = $this->ficha(EmpresaTransporte::class, 'empresa', 'rut', $viaje['empresa'] ?? [], $datos, $actor, $previo['empresa'] ?? null);
        $placa = $viaje['vehiculo']['patente'] ?? null;
        $vehiculo = ! isset($datos['transporte']) && $placa !== null && ! preg_match('/^[A-Z0-9]{4,12}$/', $placa)
            ? ['id' => null, 'patente' => $datos['patente'], 'descripcion' => null, 'version' => null]
            : $this->ficha(VehiculoTransporte::class, 'vehiculo', 'patente', $viaje['vehiculo'] ?? [], $datos, $actor, $previo['vehiculo'] ?? null);
        $snapshot = [
            'conductor' => $conductor,
            'empresa' => $empresa,
            'vehiculo' => $vehiculo,
            'patente_carro' => self::patente($viaje['patente_carro'] ?? null),
        ];

        return [
            'conductor_transporte_id' => $conductor['id'] ?? null,
            'empresa_transporte_id' => $empresa['id'] ?? null,
            'vehiculo_transporte_id' => $vehiculo['id'] ?? null,
            'transporte_snapshot' => $snapshot,
            'patente' => $vehiculo['patente'] ?? null,
            'transportista' => $empresa['nombre'] ?? null,
        ];
    }

    /** @param class-string<Model> $clase */
    private function ficha(string $clase, string $tipo, string $clave, array $entrada, array $datos, User $actor, ?array $previo): ?array
    {
        $campos = $tipo === 'vehiculo' ? ['patente', 'descripcion'] : ['rut', 'nombre', 'telefono'];
        $valores = [];
        foreach ($campos as $campo) {
            $valor = trim((string) ($entrada[$campo] ?? ''));
            $valores[$campo] = $valor === '' ? null : $valor;
        }
        $valores[$clave] = $clave === 'rut' ? self::rut($entrada[$clave] ?? null) : self::patente($entrada[$clave] ?? null);
        if ($previo !== null && ! ($entrada['actualizar_directorio'] ?? false)
            && $valores === array_intersect_key($previo, $valores)) {
            return $previo;
        }
        if (! array_filter($valores, fn ($valor) => $valor !== null)) {
            return null;
        }
        // Una empresa sin RUT queda descrita en el viaje, sin fusionar empresas
        // por un nombre parecido ni inventarles una identidad tributaria.
        if ($valores[$clave] === null) {
            return ['id' => null, ...$valores, 'version' => null];
        }

        $registro = $clase::query()->where($clave, $valores[$clave])->lockForUpdate()->first();
        if (! $registro) {
            $registro = $clase::query()->createOrFirst([$clave => $valores[$clave]], [
                ...$valores,
                'version' => 1,
                'creado_por_user_id' => $actor->id,
                'actualizado_por_user_id' => $actor->id,
            ]);
            if ($registro->wasRecentlyCreated) {
                $this->auditar($registro, $tipo, null, $campos, $datos, $actor);
            } else {
                $registro = $clase::query()->lockForUpdate()->findOrFail($registro->id);
            }
        }

        if (! $registro->wasRecentlyCreated && ($entrada['actualizar_directorio'] ?? false)) {
            if ((int) ($entrada['version_conocida'] ?? 0) !== $registro->version) {
                throw new ConflictoOperacion('Los datos guardados de '.$tipo.' cambiaron. Busca nuevamente antes de actualizarlos.');
            }
            $antes = $registro->only(['id', ...$campos, 'version']);
            $registro->fill($valores);
            if ($registro->isDirty($campos)) {
                $registro->version++;
                $registro->actualizado_por_user_id = $actor->id;
                $registro->save();
                $this->auditar($registro, $tipo, $antes, $campos, $datos, $actor);
            }
        }

        // Los datos del viaje pueden diferir deliberadamente del maestro.
        return ['id' => $registro->id, ...$valores, 'version' => $registro->version];
    }

    private function auditar(Model $registro, string $tipo, ?array $antes, array $campos, array $datos, User $actor): void
    {
        EventoDirectorioTransporte::create([
            'tipo' => $tipo,
            'registro_id' => $registro->id,
            'operacion_id' => $datos['operacion_id'],
            'antes' => $antes,
            'despues' => $registro->only(['id', ...$campos, 'version']),
            'user_id' => $actor->id,
            'usuario' => $actor->name,
        ]);
    }
}
