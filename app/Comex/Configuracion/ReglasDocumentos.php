<?php

namespace App\Comex\Configuracion;

use App\Comex\Models\ClienteComex;
use App\Comex\Models\TipoDocumento;
use Illuminate\Support\Collection;

/**
 * Qué documentos pide un embarque según la configuración de Comex: la gestión del cliente, la
 * modalidad de transporte y los países de destino de sus instructivos.
 */
final class ReglasDocumentos
{
    public const EXIGIDO = 'exigido';

    public const OPCIONAL = 'opcional';

    /** Se exigiría, pero falta la modalidad o el destino para saberlo. */
    public const POR_DEFINIR = 'por_definir';

    /** @var Collection<int, TipoDocumento>|null */
    private ?Collection $tipos = null;

    /** @var array<string, string>|null */
    private ?array $gestiones = null;

    /**
     * Vuelve a leer la configuración en la próxima consulta. Cada panel o detalle parte de la
     * configuración vigente, aunque el servicio viva más que una solicitud.
     */
    public function olvidar(): void
    {
        $this->tipos = null;
        $this->gestiones = null;
    }

    /** @return Collection<int, TipoDocumento> Todos los tipos (también inactivos), por orden. */
    public function tipos(): Collection
    {
        return $this->tipos ??= TipoDocumento::query()->with('paises')->orderBy('orden')->orderBy('nombre')->get()->keyBy('id');
    }

    /** Gestión del cliente en Comex; sin configuración, se atiende como servicio. */
    public function gestion(string $clienteId): string
    {
        $this->gestiones ??= ClienteComex::query()->pluck('gestion', 'cliente_id')->all();

        return $this->gestiones[$clienteId] ?? ClienteComex::SERVICIO;
    }

    /**
     * Cómo pide el embarque cada tipo de documento activo. Los tipos que no aplican no aparecen.
     *
     * @param  list<?string>  $paises  códigos ISO de los destinos (null si un instructivo no tiene país)
     * @return array<int, array{exigencia: string, motivo: ?string}> por id de tipo
     */
    public function evaluar(string $modalidad, string $gestion, array $paises): array
    {
        $resultado = [];
        foreach ($this->tipos() as $tipo) {
            if (! $tipo->activo) {
                continue;
            }
            $evaluacion = $this->evaluarTipo($tipo, $modalidad, $gestion, $paises);
            if ($evaluacion !== null) {
                $resultado[$tipo->id] = $evaluacion;
            }
        }

        return $resultado;
    }

    /**
     * @param  list<?string>  $paises
     * @return array{exigencia: string, motivo: ?string}|null
     */
    private function evaluarTipo(TipoDocumento $tipo, string $modalidad, string $gestion, array $paises): ?array
    {
        if ($tipo->gestiones !== [] && ! in_array($gestion, $tipo->gestiones, true)) {
            return null;
        }

        $porDefinir = [];
        if ($tipo->transportes !== []) {
            if (! in_array($modalidad, TipoDocumento::TRANSPORTES, true)) {
                $porDefinir[] = 'la modalidad de transporte';
            } elseif (! in_array($modalidad, $tipo->transportes, true)) {
                return null;
            }
        }

        if ($tipo->exigencia === TipoDocumento::OPCIONAL) {
            return $porDefinir === [] ? ['exigencia' => self::OPCIONAL, 'motivo' => null] : null;
        }

        if ($tipo->exigencia === TipoDocumento::PAISES) {
            $exigentes = $tipo->paises->pluck('pais_iso2')->all();
            $conocidos = array_values(array_filter($paises));
            if (array_intersect($conocidos, $exigentes) === []) {
                if ($paises === [] || count($conocidos) < count($paises)) {
                    $porDefinir[] = 'el país de destino';
                } else {
                    return null;
                }
            } elseif ($porDefinir === []) {
                return ['exigencia' => self::EXIGIDO, 'motivo' => 'Lo exige el destino.'];
            }
        }

        if ($porDefinir !== []) {
            return ['exigencia' => self::POR_DEFINIR, 'motivo' => 'Falta '.implode(' y ', $porDefinir).' en el calendario de embarques.'];
        }

        return ['exigencia' => self::EXIGIDO, 'motivo' => null];
    }
}
