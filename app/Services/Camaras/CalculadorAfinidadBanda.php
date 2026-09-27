<?php

namespace App\Services\Camaras;

use App\Enums\NivelAfinidadUbicacion;
use App\Enums\TipoBulto;
use App\Models\Folio;
use Illuminate\Support\Collection;

class CalculadorAfinidadBanda
{
    /**
     * @param  Collection<int, Folio>  $folios
     * @return array<string, mixed>
     */
    public function resumir(Collection $folios): array
    {
        $completos = $this->palletsCompletos($folios);
        $fueraAlcance = $folios->count() - $completos->count();

        if ($completos->isEmpty()) {
            return [
                'activa' => false,
                'cliente' => null,
                'marca' => null,
                'formato' => null,
                'pallets_completos' => 0,
                'perfiles_diferentes' => 0,
                'perfiles' => [],
                'fuera_alcance' => $fueraAlcance,
            ];
        }

        $perfiles = $completos->map(fn (Folio $folio): array => $this->perfil($folio));
        $perfilesResumidos = $perfiles
            ->groupBy(fn (array $perfil): string => json_encode([
                $perfil['cliente_clave'],
                $perfil['marca_clave'],
                $perfil['formato_clave'],
            ], JSON_THROW_ON_ERROR))
            ->map(function (Collection $grupo): array {
                $perfil = $grupo->first();

                return [
                    'cliente' => $perfil['cliente'],
                    'marca' => $perfil['marca'],
                    'formato' => $perfil['formato'],
                    'pallets' => $grupo->count(),
                ];
            })
            ->values();
        $cliente = $this->dominante($perfiles, 'cliente_clave', 'cliente');
        $perfilesCliente = $cliente
            ? $perfiles->where('cliente_clave', $cliente['clave'])->values()
            : collect();
        $marca = $this->dominante($perfilesCliente, 'marca_clave', 'marca');
        $perfilesMarca = $marca
            ? $perfilesCliente->where('marca_clave', $marca['clave'])->values()
            : collect();
        $formato = $this->dominante($perfilesMarca, 'formato_clave', 'formato');

        return [
            'activa' => true,
            'cliente' => $this->atributoDominante($cliente),
            'marca' => $this->atributoDominante($marca),
            'formato' => $this->atributoDominante($formato),
            'pallets_completos' => $completos->count(),
            'perfiles_diferentes' => $perfilesResumidos->count(),
            'perfiles' => $perfilesResumidos->all(),
            'fuera_alcance' => $fueraAlcance,
        ];
    }

    /**
     * @param  Collection<int, Folio>  $folios
     * @return array<string, mixed>
     */
    public function evaluar(Folio $folio, Collection $folios): array
    {
        $objetivo = $this->perfil($folio);
        $completos = $this->palletsCompletos($folios);

        if ($completos->isEmpty()) {
            return [
                'nivel' => NivelAfinidadUbicacion::BandaLibre,
                'puntaje' => 400,
                'coincidencias' => [
                    'cliente_marca_formato' => 0,
                    'cliente_marca' => 0,
                    'cliente' => 0,
                ],
                'mezclaria_clientes' => false,
                'motivo' => 'Banda libre, sin afinidad vigente; puede iniciar una agrupación para este cliente.',
            ];
        }

        $perfiles = $completos->map(fn (Folio $existente): array => $this->perfil($existente));
        $mismoCliente = $this->coincidencias($perfiles, $objetivo, ['cliente_clave']);
        $mismaMarca = $this->coincidencias(
            $perfiles,
            $objetivo,
            ['cliente_clave', 'marca_clave'],
        );
        $mismoFormato = $this->coincidencias(
            $perfiles,
            $objetivo,
            ['cliente_clave', 'marca_clave', 'formato_clave'],
        );
        $otrosClientes = $completos->count() - $mismoCliente;
        $otrasMarcas = max(0, $mismoCliente - $mismaMarca);
        $otrosFormatos = max(0, $mismaMarca - $mismoFormato);

        $nivel = match (true) {
            $mismoFormato > 0 => NivelAfinidadUbicacion::ClienteMarcaFormato,
            $mismaMarca > 0 => NivelAfinidadUbicacion::ClienteMarca,
            $mismoCliente > 0 => NivelAfinidadUbicacion::Cliente,
            default => NivelAfinidadUbicacion::SinAfinidad,
        };
        $base = match ($nivel) {
            NivelAfinidadUbicacion::ClienteMarcaFormato => 1000,
            NivelAfinidadUbicacion::ClienteMarca => 800,
            NivelAfinidadUbicacion::Cliente => 600,
            NivelAfinidadUbicacion::BandaLibre => 400,
            NivelAfinidadUbicacion::SinAfinidad => 100,
        };
        $puntaje = $base
            + ($mismoFormato * 25)
            + ($mismaMarca * 12)
            + ($mismoCliente * 5)
            - ($otrosClientes * 250)
            - ($otrasMarcas * 60)
            - ($otrosFormatos * 20);

        return [
            'nivel' => $nivel,
            'puntaje' => $puntaje,
            'coincidencias' => [
                'cliente_marca_formato' => $mismoFormato,
                'cliente_marca' => $mismaMarca,
                'cliente' => $mismoCliente,
            ],
            'mezclaria_clientes' => $otrosClientes > 0,
            'motivo' => $this->motivo($nivel, $mismoFormato, $mismaMarca, $mismoCliente),
        ];
    }

    /** @return array<string, string|null> */
    public function perfil(Folio $folio): array
    {
        $datosExternos = is_array($folio->datos_externos) ? $folio->datos_externos : [];
        $cliente = $this->texto($folio->exportadora);
        $marca = $this->texto($folio->marca);
        $formato = $this->texto($datosExternos['envase'] ?? null);

        return [
            'cliente' => $cliente,
            'cliente_clave' => $this->clave($cliente),
            'marca' => $marca,
            'marca_clave' => $this->clave($marca),
            'formato' => $formato,
            'formato_clave' => $this->clave($formato),
        ];
    }

    /**
     * @param  Collection<int, Folio>  $folios
     * @return Collection<int, Folio>
     */
    private function palletsCompletos(Collection $folios): Collection
    {
        return $folios
            ->filter(fn (Folio $folio): bool => $folio->activo
                && $folio->tipo_bulto === TipoBulto::Pallet)
            ->values();
    }

    /**
     * @param  Collection<int, array<string, string|null>>  $perfiles
     * @param  array<int, string>  $campos
     */
    private function coincidencias(Collection $perfiles, array $objetivo, array $campos): int
    {
        foreach ($campos as $campo) {
            if ($objetivo[$campo] === null) {
                return 0;
            }
        }

        return $perfiles
            ->filter(function (array $perfil) use ($objetivo, $campos): bool {
                foreach ($campos as $campo) {
                    if ($perfil[$campo] !== $objetivo[$campo]) {
                        return false;
                    }
                }

                return true;
            })
            ->count();
    }

    /**
     * @param  Collection<int, array<string, string|null>>  $perfiles
     * @return array{clave: string, valor: string, pallets: int}|null
     */
    private function dominante(Collection $perfiles, string $campoClave, string $campoValor): ?array
    {
        return $perfiles
            ->filter(fn (array $perfil): bool => $perfil[$campoClave] !== null)
            ->groupBy($campoClave)
            ->map(function (Collection $grupo, string|int $clave) use ($campoValor): array {
                return [
                    'clave' => (string) $clave,
                    'valor' => (string) $grupo->first()[$campoValor],
                    'pallets' => $grupo->count(),
                ];
            })
            ->sort(function (array $izquierda, array $derecha): int {
                return ($derecha['pallets'] <=> $izquierda['pallets'])
                    ?: strcmp($izquierda['clave'], $derecha['clave']);
            })
            ->first();
    }

    /**
     * @param  array{clave: string, valor: string, pallets: int}|null  $atributo
     * @return array{valor: string, pallets: int}|null
     */
    private function atributoDominante(?array $atributo): ?array
    {
        if ($atributo === null) {
            return null;
        }

        return [
            'valor' => $atributo['valor'],
            'pallets' => $atributo['pallets'],
        ];
    }

    private function motivo(
        NivelAfinidadUbicacion $nivel,
        int $formatos,
        int $marcas,
        int $clientes,
    ): string {
        return match ($nivel) {
            NivelAfinidadUbicacion::ClienteMarcaFormato => sprintf(
                'Concentra %d pallet(s) del mismo cliente, etiqueta y formato.',
                $formatos,
            ),
            NivelAfinidadUbicacion::ClienteMarca => sprintf(
                'Mantiene juntos %d pallet(s) del mismo cliente y etiqueta; inicia una subseparación de formato.',
                $marcas,
            ),
            NivelAfinidadUbicacion::Cliente => sprintf(
                'Mantiene juntos %d pallet(s) del mismo cliente; inicia una subseparación de etiqueta.',
                $clientes,
            ),
            NivelAfinidadUbicacion::BandaLibre => 'Banda libre, sin afinidad vigente; puede iniciar una agrupación para este cliente.',
            NivelAfinidadUbicacion::SinAfinidad => 'Última alternativa disponible; mezclaría clientes y debe revisarse antes de usarla.',
        };
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function clave(?string $valor): ?string
    {
        return $valor === null ? null : mb_strtoupper($valor);
    }
}
