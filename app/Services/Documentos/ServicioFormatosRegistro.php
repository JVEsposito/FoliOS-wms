<?php

namespace App\Services\Documentos;

use App\Enums\EstadoRecepcionRomana;
use App\Models\FormatoRegistro;
use App\Models\RecepcionRomana;
use DomainException;

class ServicioFormatosRegistro
{
    // Las recepciones históricas sin snapshot siempre usan el original de planta.
    private const RPR_INICIAL = [
        'codigo' => 'RPR-01',
        'version' => '1',
        'fecha_vigencia' => '2026-08-31',
        'localidad' => 'Rengo, Carretera 5 Sur, km 108, Rosario, comuna de Rengo',
    ];

    /** @return array{codigo: string, version: string, fecha_vigencia: string, localidad: string} */
    public function vigente(string $codigo, bool $bloquear = false): array
    {
        $formato = FormatoRegistro::query()->where('codigo', $codigo)
            ->when($bloquear, fn ($consulta) => $consulta->lockForUpdate())
            ->first();
        if (! $formato?->activo) {
            throw new DomainException("El formato {$codigo} no está activo. Revísalo en Administración → Formatos de registro.");
        }

        return [
            'codigo' => $formato->codigo,
            'version' => $formato->version,
            'fecha_vigencia' => $formato->fecha_vigencia->format('Y-m-d'),
            'localidad' => $formato->localidad,
        ];
    }

    /** @return array<string, string> */
    public function snapshotRomana(): array
    {
        $formato = $this->vigente('RPR-01', bloquear: true);

        return collect($formato)->mapWithKeys(
            fn (string $valor, string $campo): array => ['formato_registro_'.$campo => $valor],
        )->all();
    }

    /** @return array{codigo: string, version: string, fecha_vigencia: string, localidad: string} */
    public function paraRomana(RecepcionRomana $recepcion): array
    {
        if ($recepcion->formato_registro_codigo !== null) {
            return [
                'codigo' => $recepcion->formato_registro_codigo,
                'version' => $recepcion->formato_registro_version,
                'fecha_vigencia' => $recepcion->formato_registro_fecha_vigencia->format('Y-m-d'),
                'localidad' => $recepcion->formato_registro_localidad,
            ];
        }

        return $recepcion->estado === EstadoRecepcionRomana::Cerrado
            ? self::RPR_INICIAL
            : $this->vigente('RPR-01');
    }
}
