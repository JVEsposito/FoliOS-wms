<?php

namespace App\Comex\Expediente;

use App\Services\Existencias\GeneradorLibroXlsx;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Packing list de un embarque, leído de su carga: una fila por pallet (o por línea de un pallet
 * mixto) con especie, variedad, calibre, envase, productor, cajas y kilos netos. Los datos del
 * embarque y los totales van en la hoja de corte.
 */
final class PackingList
{
    public function __construct(
        private readonly EmbarquesOperacion $embarques,
        private readonly GeneradorLibroXlsx $excel,
    ) {}

    /**
     * @param  array<string, mixed>  $embarque
     * @return array{ruta: string, nombre: string}
     *
     * @throws ValidationException
     */
    public function generar(array $embarque, string $usuario): array
    {
        if ($embarque['carga'] === null) {
            throw ValidationException::withMessages(['embarque' => 'El embarque todavía no tiene una carga asignada: no hay pallets para el packing list.']);
        }

        $lineas = $this->embarques->foliosCarga($embarque['carga']['id']);
        if ($lineas === []) {
            throw ValidationException::withMessages(['embarque' => 'La carga del embarque todavía no tiene pallets asignados.']);
        }

        $pallets = count(array_unique(array_column($lineas, 'folio')));
        $cajas = array_sum(array_column($lineas, 'cajas'));
        $sinKilos = array_filter($lineas, fn (array $linea): bool => $linea['kilos'] === null);
        $kilos = array_sum(array_map(fn (array $linea): int => (int) round((float) $linea['kilos'] * 100), array_filter($lineas, fn (array $linea): bool => $linea['kilos'] !== null)));
        $instructivos = $embarque['instructivos'];
        $unir = fn (string $campo): string => implode(', ', array_values(array_unique(array_filter(array_column($instructivos, $campo))))) ?: 'Sin informar';

        $ruta = $this->excel->generar('Packing list '.$embarque['codigo'], [
            ['clave' => 'n', 'titulo' => 'N°', 'ancho' => 6, 'tipo' => 'numero'],
            ['clave' => 'folio', 'titulo' => 'Folio', 'ancho' => 16],
            ['clave' => 'tipo_bulto', 'titulo' => 'Tipo de bulto', 'ancho' => 12],
            ['clave' => 'especie', 'titulo' => 'Especie', 'ancho' => 14],
            ['clave' => 'variedad', 'titulo' => 'Variedad', 'ancho' => 16],
            ['clave' => 'calibre', 'titulo' => 'Calibre', 'ancho' => 10],
            ['clave' => 'envase', 'titulo' => 'Envase', 'ancho' => 18],
            ['clave' => 'categoria', 'titulo' => 'Categoría', 'ancho' => 12],
            ['clave' => 'marca', 'titulo' => 'Marca', 'ancho' => 16],
            ['clave' => 'exportadora', 'titulo' => 'Exportadora', 'ancho' => 24],
            ['clave' => 'csg', 'titulo' => 'CSG', 'ancho' => 12],
            ['clave' => 'predio', 'titulo' => 'Predio', 'ancho' => 20],
            ['clave' => 'fecha_embalaje', 'titulo' => 'Fecha de embalaje', 'ancho' => 14, 'tipo' => 'fecha'],
            ['clave' => 'cajas', 'titulo' => 'Cajas', 'ancho' => 9, 'tipo' => 'numero'],
            ['clave' => 'kilos_por_caja', 'titulo' => 'Kg netos por caja', 'ancho' => 12, 'tipo' => 'numero'],
            ['clave' => 'kilos', 'titulo' => 'Kg netos', 'ancho' => 12, 'tipo' => 'numero'],
            ['clave' => 'estado', 'titulo' => 'Estado en la carga', 'ancho' => 16],
        ], $this->filas($lineas), [
            'fecha_corte' => now((string) config('app.operational_timezone'))->format('d-m-Y H:i'),
            'usuario' => $usuario,
            'temporada' => $embarque['temporada_nombre'],
            'cliente' => $embarque['cliente_nombre'],
            'periodo' => 'Embarque '.$embarque['codigo'].' del '.CarbonImmutable::parse($embarque['fecha_programada'])->format('d-m-Y'),
            'adicionales' => [
                ['Carga', $embarque['carga']['codigo'].($embarque['carga']['salio'] ? ' (salió de la planta)' : ' (en preparación)')],
                ['Modalidad', $this->modalidad($embarque['modalidad'])],
                ['Nave o vuelo', $embarque['nave_vuelo'] ?: 'Sin informar'],
                ['Puerto de embarque', $embarque['puerto_embarque'] ?: 'Sin informar'],
                ['Contenedor', $embarque['contenedor'] ?: 'Sin informar'],
                ['Sello', $embarque['sello'] ?: 'Sin informar'],
                ['Recibidor', $unir('recibidor')],
                ['Destino', $unir('pais')],
                ['Booking', $unir('booking')],
                ['Pallets', (string) $pallets],
                ['Cajas', (string) $cajas],
                ['Kg netos', number_format($kilos / 100, 2, ',', '.').($sinKilos === [] ? '' : ' (sin '.count($sinKilos).' líneas cuyo envase no tiene kilos por caja)')],
            ],
        ], 'Packing list');

        return ['ruta' => $ruta, 'nombre' => 'packing-list-'.strtolower($embarque['codigo']).'.xlsx'];
    }

    /**
     * @param  list<array<string, mixed>>  $lineas
     * @return iterable<array<string, mixed>>
     */
    private function filas(array $lineas): iterable
    {
        $estados = ['pendiente' => 'Por cargar', 'con_incidencia' => 'Con incidencia', 'en_anden' => 'En andén', 'despachado' => 'Despachado'];
        foreach ($lineas as $indice => $linea) {
            yield [
                'n' => $indice + 1,
                'folio' => $linea['lineas_folio'] > 1 ? "{$linea['folio']} ({$linea['linea']}/{$linea['lineas_folio']})" : $linea['folio'],
                'tipo_bulto' => $linea['tipo_bulto'] === 'pallet' ? 'Pallet' : ucfirst(str_replace('_', ' ', $linea['tipo_bulto'])),
                'especie' => $linea['especie'],
                'variedad' => $linea['variedad'],
                'calibre' => $linea['calibre'],
                'envase' => $linea['envase'],
                'categoria' => $linea['categoria'],
                'marca' => $linea['marca'],
                'exportadora' => $linea['exportadora'],
                'csg' => $linea['csg'],
                'predio' => $linea['predio'],
                'fecha_embalaje' => $linea['fecha_embalaje'],
                'cajas' => $linea['cajas'],
                'kilos_por_caja' => $linea['kilos_por_caja'],
                'kilos' => $linea['kilos'],
                'estado' => $estados[$linea['estado']] ?? $linea['estado'],
            ];
        }
    }

    private function modalidad(string $modalidad): string
    {
        return match ($modalidad) {
            'maritimo' => 'Marítimo',
            'aereo' => 'Aéreo',
            'terrestre' => 'Terrestre',
            default => 'Por confirmar',
        };
    }
}
