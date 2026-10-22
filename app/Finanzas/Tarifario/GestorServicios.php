<?php

namespace App\Finanzas\Tarifario;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Captura\Fuentes\AcuerdosPeriodo;
use App\Finanzas\Captura\Fuentes\CargosDeclarados;
use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\ConceptoServicio;
use App\Finanzas\Models\EventoServicio;
use App\Finanzas\Periodo;
use App\Finanzas\Valorizacion\ReglaValorizacion;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GestorServicios
{
    public function __construct(private readonly EsquemaFinanzas $esquema, private readonly CatalogoFuentes $fuentes) {}

    public function crear(array $datos, User $usuario): ConceptoServicio
    {
        $fuente = $this->fuentes->todas()[$datos['fuente']] ?? null;
        if ($fuente === null) {
            throw ValidationException::withMessages(['fuente' => 'El proceso no está disponible para Finanzas.']);
        }
        if ((in_array($datos['fuente'], [CargosDeclarados::CLAVE, AcuerdosPeriodo::CLAVE], true)) !== ($datos['aplicacion'] === 'declarado')) {
            throw ValidationException::withMessages(['aplicacion' => 'La modalidad acordada/manual requiere la fuente de cargos declarados.']);
        }
        if (($datos['fuente'] === ReglaValorizacion::FUENTE) !== ($datos['aplicacion'] === 'calculado')) {
            throw ValidationException::withMessages(['aplicacion' => 'La modalidad calculada requiere la fuente de cálculo entre medidas.']);
        }
        if (in_array($datos['fuente'], [CargosDeclarados::CLAVE, AcuerdosPeriodo::CLAVE, ReglaValorizacion::FUENTE], true) && trim((string) ($datos['unidad_libre'] ?? '')) === '') {
            throw ValidationException::withMessages(['unidad_libre' => 'Indique la unidad de cobro del concepto.']);
        }
        $datos['area'] = trim((string) ($datos['area'] ?? $this->fuentes->contexto($datos['fuente'])['area']));
        $filtros = $datos['filtros'] ?? [];
        if (array_diff(array_keys($filtros), array_keys($this->fuentes->contexto($datos['fuente'])['dimensiones'])) !== []) {
            throw ValidationException::withMessages(['filtros' => 'Use las dimensiones publicadas por el proceso.']);
        }
        ksort($filtros);
        $datos['filtros'] = $filtros;
        if ($datos['fuente'] === ReglaValorizacion::FUENTE) {
            $datos['regla'] = app(ReglaValorizacion::class)->normalizar($datos['regla'] ?? []);
        } elseif (! empty($datos['regla'])) {
            throw ValidationException::withMessages(['regla' => 'Las fórmulas corresponden a conceptos calculados.']);
        }
        if (! $fuente->intervalo() && ($datos['dia_desde'] !== 1 || $datos['dia_hasta'] !== null)) {
            throw ValidationException::withMessages(['dia_desde' => 'Los rangos de días solo corresponden a una estadía.']);
        }

        return $this->conCandado(function () use ($datos, $usuario): ConceptoServicio {
            if ($datos['aplicacion'] === ConceptoServicio::REEMPLAZA_BASE) {
                $cruza = ConceptoServicio::query()->where('fuente', $datos['fuente'])
                    ->where('aplicacion', ConceptoServicio::REEMPLAZA_BASE)
                    ->where('vigente_desde', '<=', $datos['vigente_hasta'] ?? '9999-12-31')
                    ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $datos['vigente_desde']))
                    ->where('dia_desde', '<=', $datos['dia_hasta'] ?? 65535)
                    ->where(fn ($q) => $q->whereNull('dia_hasta')->orWhere('dia_hasta', '>=', $datos['dia_desde']))
                    ->get()->contains(function (ConceptoServicio $otro) use ($datos): bool {
                        foreach ($datos['filtros'] as $campo => $valor) {
                            if (isset($otro->filtros[$campo]) && $otro->filtros[$campo] !== $valor) {
                                return false;
                            }
                        }

                        return true;
                    });
                if ($cruza) {
                    throw ValidationException::withMessages(['aplicacion' => 'Ya hay un concepto que reemplaza al base en esas fechas y días. Ajuste sus rangos.']);
                }
            }
            $servicio = ConceptoServicio::query()->create(['clave' => 'svc_'.Str::lower((string) Str::ulid()), ...$datos]);
            $this->registrar($servicio, 'creado', $datos, null, $usuario);

            return $servicio;
        });
    }

    public function finalizar(ConceptoServicio $servicio, string $hasta, string $motivo, User $usuario): ConceptoServicio
    {
        return $this->conCandado(function () use ($servicio, $hasta, $motivo, $usuario): ConceptoServicio {
            $servicio = ConceptoServicio::query()->lockForUpdate()->findOrFail($servicio->clave);
            $anterior = $servicio->vigente_hasta?->toDateString();
            if ($hasta < Periodo::hoy() || $hasta < $servicio->vigente_desde->toDateString() || ($anterior !== null && $hasta >= $anterior)) {
                throw ValidationException::withMessages(['vigente_hasta' => 'El término debe ser igual o posterior al inicio y adelantar el término actual.']);
            }
            $servicio->update(['vigente_hasta' => $hasta]);
            $this->registrar($servicio, 'finalizado', ['antes' => $anterior, 'despues' => $hasta], $motivo, $usuario);

            return $servicio;
        });
    }

    private function conCandado(callable $operacion): mixed
    {
        if (! $this->esquema->tomarCandado('conceptos-servicio')) {
            throw ValidationException::withMessages(['servicio' => 'Se está guardando otro concepto. Intente nuevamente.']);
        }
        try {
            return $this->esquema->transaccion($operacion);
        } finally {
            $this->esquema->liberarCandado('conceptos-servicio');
        }
    }

    private function registrar(ConceptoServicio $servicio, string $accion, array $datos, ?string $motivo, User $usuario): void
    {
        EventoServicio::query()->create([
            'servicio' => $servicio->clave, 'accion' => $accion, 'datos' => $datos, 'motivo' => $motivo,
            'user_id' => $usuario->id, 'user_nombre' => $usuario->name, 'created_at' => now(),
        ]);
    }
}
