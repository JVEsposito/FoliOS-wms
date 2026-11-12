<?php

namespace App\Services\ControlDespacho;

use App\Models\ControlDespacho;
use App\Models\FotoControlDespacho;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Archiva las fotos de una planilla cerrada en un único PDF «Set fotográfico R-24» y borra
 * sus archivos sueltos: en el servidor queda solo el PDF.
 *
 * Si la planilla se reabre y cambian sus fotos, al volver a cerrarla el PDF se rearma con las
 * fotos vigentes. Las ya archivadas se leen desde el PDF anterior, donde cada una quedó
 * guardada tal cual (su posición está en `pdf_offset` / `pdf_longitud`).
 */
class ArchivoFotosControlDespacho
{
    public function __construct(private readonly GeneradorPdfFotosControlDespacho $generador) {}

    /** El PDF de la planilla, armándolo si falta (por ejemplo, si el cierre no alcanzó a generarlo). */
    public function pdf(ControlDespacho $control): ?string
    {
        $control = $this->archivar($control);

        return $control->fotos_pdf_ruta && Storage::disk('local')->exists($control->fotos_pdf_ruta) ? $control->fotos_pdf_ruta : null;
    }

    public function pendiente(ControlDespacho $control): bool
    {
        if (! $control->estaCerrado()) {
            return false;
        }
        $fotos = FotoControlDespacho::query()->where('control_despacho_id', $control->id);

        return (clone $fotos)->whereNull('archivada_at')->exists()
            || (int) $control->fotos_pdf_cantidad !== (clone $fotos)->count();
    }

    public function archivar(ControlDespacho $control): ControlDespacho
    {
        $nuevo = null;
        $borrar = [];
        try {
            $resultado = DB::transaction(function () use ($control, &$nuevo, &$borrar): ControlDespacho {
                $bloqueado = ControlDespacho::query()->lockForUpdate()->findOrFail($control->id);
                if (! $this->pendiente($bloqueado)) {
                    return $bloqueado;
                }
                $fotos = $this->ordenar(FotoControlDespacho::query()
                    ->where('control_despacho_id', $bloqueado->id)
                    ->with('subidaPor:id,name')
                    ->lockForUpdate()
                    ->get());
                $anterior = $bloqueado->fotos_pdf_ruta;
                if ($fotos->isEmpty()) {
                    $borrar = array_filter([$anterior]);
                    $bloqueado->update(['fotos_pdf_ruta' => null, 'fotos_pdf_bytes' => null, 'fotos_pdf_sha256' => null, 'fotos_pdf_cantidad' => 0, 'fotos_pdf_generado_at' => now()]);

                    return $bloqueado;
                }

                $pdfAnterior = $anterior && Storage::disk('local')->exists($anterior) ? Storage::disk('local')->get($anterior) : null;
                $nombres = collect(FormularioControlDespacho::tiposFoto())->map(fn (array $tipo): string => $tipo['nombre']);
                $porTipo = $fotos->countBy('tipo');
                $numero = [];
                $entradas = $fotos->map(function (FotoControlDespacho $foto) use ($pdfAnterior, $nombres, $porTipo, &$numero): array {
                    $numero[$foto->tipo] = ($numero[$foto->tipo] ?? 0) + 1;

                    return [
                        'id' => $foto->id,
                        'jpeg' => $this->jpeg($foto, $pdfAnterior),
                        'ancho' => $foto->ancho,
                        'alto' => $foto->alto,
                        'titulo' => ($nombres[$foto->tipo] ?? $foto->tipo).($porTipo[$foto->tipo] > 1 ? " · {$numero[$foto->tipo]} de {$porTipo[$foto->tipo]}" : ''),
                        'detalle' => 'Tomada '.$this->hora($foto->created_at).($foto->subidaPor ? ' por '.$foto->subidaPor->name : ''),
                    ];
                })->values()->all();

                $generado = $this->generador->generar($this->encabezado($bloqueado, count($entradas)), $entradas);
                $nuevo = 'control-despacho/'.$bloqueado->id.'/set-fotografico-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)).'.pdf';
                if (! Storage::disk('local')->put($nuevo, $generado['pdf'])) {
                    throw new RuntimeException('No se pudo guardar el PDF de fotos del despacho.');
                }
                foreach ($fotos as $foto) {
                    $ubicacion = $generado['ubicaciones'][$foto->id];
                    if (! $foto->estaArchivada()) {
                        array_push($borrar, $foto->ruta, $foto->ruta_miniatura);
                    }
                    $foto->update([
                        'archivada_at' => $foto->archivada_at ?? now(),
                        'ruta' => null,
                        'ruta_miniatura' => null,
                        'pdf_pagina' => $ubicacion['pagina'],
                        'pdf_offset' => $ubicacion['offset'],
                        'pdf_longitud' => $ubicacion['longitud'],
                    ]);
                }
                if ($anterior) {
                    $borrar[] = $anterior;
                }
                $bloqueado->update([
                    'fotos_pdf_ruta' => $nuevo,
                    'fotos_pdf_bytes' => strlen($generado['pdf']),
                    'fotos_pdf_sha256' => hash('sha256', $generado['pdf']),
                    'fotos_pdf_cantidad' => count($entradas),
                    'fotos_pdf_generado_at' => now(),
                ]);

                return $bloqueado;
            });
        } catch (Throwable $error) {
            if ($nuevo) {
                Storage::disk('local')->delete($nuevo);
            }

            throw $error;
        }
        // Lo reemplazado se borra solo cuando el PDF nuevo ya quedó registrado.
        Storage::disk('local')->delete(array_values(array_filter($borrar)));

        return $resultado->refresh();
    }

    /** @param  Collection<int, FotoControlDespacho>  $fotos */
    private function ordenar(Collection $fotos): Collection
    {
        $orden = array_flip(array_keys(FormularioControlDespacho::tiposFoto()));

        return $fotos->sortBy(fn (FotoControlDespacho $foto): string => sprintf('%03d-%05d', $orden[$foto->tipo] ?? 999, $foto->orden))->values();
    }

    private function jpeg(FotoControlDespacho $foto, ?string $pdfAnterior): string
    {
        if (! $foto->estaArchivada()) {
            if (! $foto->ruta || ! Storage::disk('local')->exists($foto->ruta)) {
                throw new RuntimeException('Falta el archivo de una foto del despacho; no se puede armar el PDF.');
            }

            return (string) Storage::disk('local')->get($foto->ruta);
        }
        $jpeg = $pdfAnterior === null ? '' : substr($pdfAnterior, (int) $foto->pdf_offset, (int) $foto->pdf_longitud);
        if (strlen($jpeg) !== (int) $foto->pdf_longitud || ! str_starts_with($jpeg, "\xFF\xD8")) {
            throw new RuntimeException('No se encontró el PDF de fotos anterior; no se puede rearmar el set fotográfico.');
        }

        return $jpeg;
    }

    /** @return array{titulo: string, detalle: string, pie: string} */
    private function encabezado(ControlDespacho $control, int $cantidad): array
    {
        $encabezado = $control->encabezado ?? [];
        $codigo = $control->carga()->value('codigo');

        return [
            'titulo' => "SET FOTOGRÁFICO · POEMP R-24 · CARGA {$codigo}",
            // La pizarra identificatoria del set, más la patente.
            'detalle' => collect(FormularioControlDespacho::pizarra($encabezado))
                ->filter(fn (array $dato): bool => $dato['valor'] !== null)
                ->map(fn (array $dato): string => "{$dato['etiqueta']} {$dato['valor']}")
                ->push(filled($encabezado['patente_camion'] ?? null) ? 'Patente '.$encabezado['patente_camion'] : null)
                ->filter()->implode(' · '),
            'pie' => sprintf('FoliOS · %d fotos · Generado %s', $cantidad, $this->hora(now())),
        ];
    }

    private function hora(?CarbonInterface $fecha): string
    {
        return $fecha ? $fecha->copy()->setTimezone(config('app.operational_timezone', config('app.timezone')))->format('d-m-Y H:i') : '—';
    }
}
