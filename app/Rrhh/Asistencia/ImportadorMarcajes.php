<?php

namespace App\Rrhh\Asistencia;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\FuenteMarcaje;
use App\Rrhh\Models\ImportacionMarcajes;
use App\Rrhh\Models\Persona;
use App\Rrhh\Models\VinculoMarcaje;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ImportadorMarcajes
{
    public function __construct(private EsquemaRrhh $esquema, private ServicioMarcajes $marcajes, private NormalizadorGeoVictoria $geo, private Auditoria $auditoria) {}

    public function ejecutar(FuenteMarcaje $fuente, array $filas, User $actor): ImportacionMarcajes
    {
        if (! $fuente->activa) {
            throw ValidationException::withMessages(['fuente' => 'La fuente está inactiva.']);
        }
        $candado = 'importar-'.$fuente->id;
        if (! $this->esquema->tomarCandado($candado)) {
            throw ValidationException::withMessages(['fuente' => 'Hay otra importación de esta fuente. Reintente.']);
        }
        try {
            $aceptadas = 0;
            $repetidas = 0;
            $errores = [];
            foreach ($filas as $indice => $fila) {
                try {
                    $fila = $fuente->tipo === 'geovictoria' ? $this->geo->normalizar($fila, $fuente->configuracion['zona_origen'] ?? null) : $fila;
                    $fila = Validator::make($fila, ['id_externo' => ['required', 'string', 'max:256', 'not_regex:/^correccion:/'],
                        'identificador' => ['required', 'string', 'max:100'], 'tipo' => ['required', 'in:entrada,salida'], 'instante' => ['required', 'string', 'max:40']])->validate();
                    $vinculo = VinculoMarcaje::query()->where('fuente_id', $fuente->id)->where('identificador_externo', $fila['identificador'])->first();
                    if ($vinculo === null) {
                        throw ValidationException::withMessages(['identificador' => 'No existe un vínculo explícito para el identificador externo.']);
                    }
                    $persona = Persona::query()->findOrFail($vinculo->persona_id);
                    if ($persona->fuente_marcaje !== $fuente->codigo) {
                        throw ValidationException::withMessages(['fuente' => 'La persona tiene otra fuente de marcaje asignada.']);
                    }
                    $resultado = $this->marcajes->importar($persona, $fuente->codigo, $fila, $actor);
                    $resultado['repetido'] ? $repetidas++ : $aceptadas++;
                } catch (ValidationException $error) {
                    // No se descarta el lote completo ni se guardan credenciales o contenido libre del proveedor.
                    $referencia = [];
                    foreach (['id_externo' => ['id_externo', 'id_punch'], 'identificador' => ['identificador', 'identifier', 'UserIdentifier']] as $destino => $claves) {
                        foreach ($claves as $clave) {
                            if (is_string($fila[$clave] ?? null)) {
                                $referencia[$destino] = mb_substr($fila[$clave], 0, $destino === 'id_externo' ? 256 : 100);
                                break;
                            }
                        }
                    }
                    $errores[] = ['fila' => $indice + 1, 'referencia' => $referencia, 'errores' => $error->errors()];
                }
            }

            return $this->esquema->transaccion(function () use ($fuente, $filas, $aceptadas, $repetidas, $errores, $actor): ImportacionMarcajes {
                $importacion = ImportacionMarcajes::query()->create(['id' => (string) Str::uuid(), 'fuente_id' => $fuente->id,
                    'recibidas' => count($filas), 'aceptadas' => $aceptadas, 'repetidas' => $repetidas, 'errores' => $errores]);
                $this->auditoria->registrar('importacion', $importacion->id, 'recibida', $importacion->toArray(), $actor);

                return $importacion;
            });
        } finally {
            $this->esquema->liberarCandado($candado);
        }
    }
}
