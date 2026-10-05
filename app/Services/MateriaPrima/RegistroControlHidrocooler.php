<?php

namespace App\Services\MateriaPrima;

use App\Models\FormatoRegistro;
use App\Models\ProcesoHidrocoolerMateriaPrima;
use App\Services\Documentos\DocumentoPdfPlanta;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RegistroControlHidrocooler
{
    public const FILAS = 24;

    public const ANCHOS = [43, 44, 48, 60, 66, 36, 42, 35, 40, 40, 38, 43, 44, 50, 68, 48, 36, 42, 88, 49, 65, 86];

    public const COLUMNAS = [
        'Fecha', 'Hora llegada', 'Hora hidrocooler', 'Lote', 'Variedad', 'T° agua',
        'T° ambiente', '% HR', 'T° ingreso', 'T° salida', 'Cloro: ppm', 'Cloro: corrección',
        'Recarga pastilla', 'Aplicación de producto', 'Producto', 'Dosis',
        'Pozo Accutab: pH', 'Pozo Accutab: mV', 'Productor', 'Tiempo de exposición (min)', 'Servicio', 'Responsable',
    ];

    public const OFICIAL = [
        'codigo' => 'POEMP-R3', 'nombre' => 'Registro control hidrocooler', 'version' => '2',
        'fecha_vigencia' => '2026-08-31',
        'localidad' => 'Rengo, Carretera 5 Sur, km 108, Rosario, comuna de Rengo',
    ];

    public function vigente(bool $bloquear = false): array
    {
        $formato = FormatoRegistro::query()->where('codigo', 'POEMP-R3')
            ->when($bloquear, fn ($query) => $query->lockForUpdate())->first();
        if (! $formato?->activo) {
            throw ValidationException::withMessages(['formato_registro' => 'Activa el formato POEMP-R3 antes de iniciar un ciclo o imprimir el formulario en blanco.']);
        }

        return [
            ...$formato->only('codigo', 'nombre', 'version', 'localidad'),
            'fecha_vigencia' => $formato->fecha_vigencia->format('Y-m-d'),
        ];
    }

    /** Cada hoja comparte un único encabezado congelado y hasta 24 ciclos. */
    public function hojas(Collection $procesos, bool $enBlanco = false): array
    {
        if ($enBlanco || $procesos->isEmpty()) {
            return [['formato' => $this->vigente(), 'filas' => [], 'observaciones' => '']];
        }
        $hojas = [];
        // Un histórico sin versión se presenta en la plantilla oficial v2, sin modificar su ciclo.
        foreach ($procesos->groupBy(fn ($p) => json_encode($p->formato_registro_snapshot ?? self::OFICIAL)) as $grupo) {
            foreach ($grupo->chunk(self::FILAS) as $ciclos) {
                $hojas[] = [
                    'formato' => $ciclos->first()->formato_registro_snapshot ?? self::OFICIAL,
                    'filas' => $ciclos->map(fn ($p) => $this->valores($p))->values()->all(),
                    'observaciones' => $ciclos->map(function ($p): string {
                        $notas = collect([
                            $p->observacion_inicio, $p->observacion,
                            $p->accion_correctiva ? 'Acción: '.$p->accion_correctiva : null,
                            $p->motivo_retencion ? 'Retención: '.$p->motivo_retencion : null,
                            $p->evaluacion_producto ? 'Evaluación: '.$p->evaluacion_producto : null,
                            $p->verificacion_liberacion ? 'Liberación: '.$p->verificacion_liberacion : null,
                        ])->filter(fn ($n) => filled($n))->implode(' · ');

                        return $notas === '' ? '' : 'Lote '.($p->lote?->numero_lote ?? '').': '.$notas;
                    })->filter()->implode("\n"),
                ];
            }
        }

        return $hojas;
    }

    public function valores(ProcesoHidrocoolerMateriaPrima $p): array
    {
        $lote = $p->lote;
        $terminado = $p->termino_at !== null;

        return [
            $p->inicio_at?->format('d-m-Y') ?? '',
            $lote?->recepcion?->ingreso_at?->format('H:i') ?? '',
            $p->inicio_at?->format('H:i') ?? '',
            $lote?->numero_lote ?? '', $lote?->variedad_snapshot ?? '',
            $this->numero($p->temperatura_agua_inicial_c), $this->numero($p->temperatura_ambiente_c),
            $this->numero($p->humedad_relativa_pct), $this->numero($p->temperatura_inicial_c),
            $terminado ? $this->numero($p->temperatura_c) : '',
            $this->numero($p->cloro_libre_ppm), $this->numero($p->correccion_cloro_ppm),
            $this->siNo($p->recarga_pastilla), $this->siNo($p->aplicacion_producto),
            $p->producto_nombre_snapshot ?? '',
            $p->producto_dosis === null ? '' : $this->numero($p->producto_dosis).' '.($p->producto_unidad_dosis ?? ''),
            $this->numero($p->ph_agua), $this->numero($p->pozo_accutab_mv),
            $lote?->csg?->productor?->razon_social ?? '',
            $terminado ? $this->numero($p->duracion_minutos) : '',
            ucfirst($lote?->recepcion?->tipo_servicio?->value ?? ''),
            $p->operador_snapshot ?: ($p->iniciadoPor?->name ?? ''),
        ];
    }

    public function paginas(Collection $procesos, bool $enBlanco = false): array
    {
        $paginas = [];
        foreach ($this->hojas($procesos, $enBlanco) as $hoja) {
            // Conserva textos largos en las notas comunes, sin recortar PDF ni XLSX.
            foreach ($hoja['filas'] as $fila => $valores) {
                foreach ($valores as $columna => $valor) {
                    $ancho = self::ANCHOS[$columna] * 1151 / array_sum(self::ANCHOS) - 6;
                    if (count(app(DocumentoPdfPlanta::class)->envolver($valor, $ancho, 7)) > 2) {
                        $hoja['observaciones'] .= "\nLote ".$valores[3].' · '.self::COLUMNAS[$columna].': '.$valor;
                        $hoja['filas'][$fila][$columna] = 'Ver notas';
                    }
                }
            }
            $lineas = app(DocumentoPdfPlanta::class)->envolver($hoja['observaciones'], 1130, 7.5);
            $origen = count($paginas) + 1;
            $continuacion = count($lineas) > 8;
            $notas = array_splice($lineas, 0, $continuacion ? 7 : 8);
            if ($continuacion) {
                $notas[] = 'Observaciones continúan en las hojas anexas de esta hoja '.$origen.'.';
            }
            $paginas[] = [...$hoja, 'notas' => $notas, 'anexo' => false, 'origen' => $origen];
            foreach (array_chunk($lineas, 60) as $notasAnexo) {
                $paginas[] = [...$hoja, 'filas' => [], 'notas' => $notasAnexo, 'anexo' => true, 'origen' => $origen];
            }
        }

        return $paginas;
    }

    private function siNo(?bool $valor): string
    {
        return $valor === null ? '' : ($valor ? 'Sí' : 'No');
    }

    private function numero(mixed $valor): string
    {
        return $valor === null ? '' : rtrim(rtrim(number_format((float) $valor, 4, ',', ''), '0'), ',');
    }
}
