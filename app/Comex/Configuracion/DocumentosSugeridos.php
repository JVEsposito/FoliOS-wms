<?php

namespace App\Comex\Configuracion;

use App\Comex\EsquemaComex;
use App\Comex\Models\TipoDocumento;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Documentos habituales de una exportación de fruta fresca. Son un punto de partida: cada
 * planta los agrega cuando quiere y después los ajusta, desactiva o suma otros.
 */
final class DocumentosSugeridos
{
    /**
     * codigo, nombre, descripción, momento, exigencia, transportes, gestiones, pide número,
     * pide fecha, pide respaldo, dato de la operación.
     */
    private const CATALOGO = [
        ['instructivo', 'Instructivo de embarque', 'Instrucciones del exportador para el embarque: recibidor, destino y documentos.', 'antes_salida', 'siempre', [], [], true, false, true, 'instructivo'],
        ['booking', 'Reserva de espacio (booking)', 'Confirmación de la naviera o de la aerolínea.', 'antes_salida', 'siempre', ['maritimo', 'aereo'], [], true, false, true, 'booking'],
        ['crt', 'Carta de porte internacional (CRT)', 'Documento del transporte terrestre internacional.', 'antes_salida', 'siempre', ['terrestre'], [], true, false, true, null],
        ['planilla_sag', 'Planilla de inspección SAG', 'Resultado de la inspección fitosanitaria en origen.', 'antes_salida', 'siempre', [], [], true, false, true, 'planilla_sag'],
        ['dus', 'DUS (Documento Único de Salida)', 'Declaración de exportación ante Aduanas, aceptada a trámite.', 'antes_salida', 'siempre', [], [], true, false, true, 'dus'],
        ['guia_despacho', 'Guía de despacho', 'Guía tributaria que acompaña a la carga desde la planta.', 'antes_salida', 'siempre', [], [], true, true, true, null],
        ['fitosanitario', 'Certificado fitosanitario', 'Certificado del SAG para el país de destino.', 'despues_salida', 'siempre', [], [], true, true, true, null],
        ['bl', 'Conocimiento de embarque (BL)', 'Lo emite la naviera después del zarpe.', 'despues_salida', 'siempre', ['maritimo'], [], true, true, true, null],
        ['awb', 'Guía aérea (AWB)', 'La emite la aerolínea o el agente de carga.', 'despues_salida', 'siempre', ['aereo'], [], true, true, true, null],
        ['factura_exportacion', 'Factura de exportación', 'Se emite en el sistema tributario; aquí se registra su número y respaldo.', 'despues_salida', 'siempre', [], ['propia'], true, true, true, null],
        ['certificado_origen', 'Certificado de origen', 'Lo piden los destinos con acuerdo comercial. Indique en qué países es obligatorio.', 'despues_salida', 'opcional', [], [], true, true, true, null],
        ['termografo', 'Registro de termógrafo', 'Número de serie del termógrafo instalado en el contenedor.', 'antes_salida', 'opcional', [], [], true, false, false, null],
    ];

    public function __construct(private readonly EsquemaComex $esquema) {}

    /** @return list<array{codigo: string, nombre: string}> sugeridos que todavía no están configurados */
    public function faltantes(): array
    {
        $codigos = TipoDocumento::query()->pluck('codigo')->all();
        $nombres = TipoDocumento::query()->pluck('nombre')->map(fn (string $nombre): string => mb_strtolower($nombre))->all();

        return array_values(array_map(
            fn (array $tipo): array => ['codigo' => $tipo[0], 'nombre' => $tipo[1]],
            array_filter(self::CATALOGO, fn (array $tipo): bool => ! in_array($tipo[0], $codigos, true) && ! in_array(mb_strtolower($tipo[1]), $nombres, true)),
        ));
    }

    /** Agrega los sugeridos que falten, después de los ya configurados. Devuelve cuántos agregó. */
    public function cargar(User $usuario): int
    {
        // Dos administradores a la vez: el segundo espera y no duplica.
        if (! $this->esquema->tomarCandado('documentos-sugeridos', 10)) {
            throw ValidationException::withMessages(['sugeridos' => 'Otra persona está agregando los documentos sugeridos. Intente de nuevo en un momento.']);
        }

        try {
            return $this->esquema->transaccion(fn (): int => $this->insertar($usuario));
        } finally {
            $this->esquema->liberarCandado('documentos-sugeridos');
        }
    }

    private function insertar(User $usuario): int
    {
        $faltantes = array_column($this->faltantes(), 'codigo');
        $orden = (int) TipoDocumento::query()->max('orden');
        $agregados = 0;
        foreach (self::CATALOGO as $tipo) {
            if (! in_array($tipo[0], $faltantes, true)) {
                continue;
            }
            $orden += 10;
            TipoDocumento::query()->create([
                'codigo' => $tipo[0],
                'nombre' => $tipo[1],
                'descripcion' => $tipo[2],
                'momento' => $tipo[3],
                'exigencia' => $tipo[4],
                'transportes' => $tipo[5],
                'gestiones' => $tipo[6],
                'pide_numero' => $tipo[7],
                'pide_fecha' => $tipo[8],
                'pide_archivo' => $tipo[9],
                'dato_operacion' => $tipo[10],
                'orden' => $orden,
                'activo' => true,
                'sugerido' => true,
                'version' => 1,
                'actualizado_por_user_id' => $usuario->id,
                'actualizado_por_nombre' => Str::limit($usuario->name, 150, ''),
            ]);
            $agregados++;
        }

        return $agregados;
    }
}
