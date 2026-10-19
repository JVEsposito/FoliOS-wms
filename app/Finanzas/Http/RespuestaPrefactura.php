<?php

namespace App\Finanzas\Http;

use App\Finanzas\Models\Lectura;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\LibroPrefactura;
use Illuminate\Http\Request;

/** El mes de prefactura como lo recibe la oficina: datos, última lectura y permisos de quien consulta. */
final class RespuestaPrefactura
{
    public function __construct(
        private readonly LibroPrefactura $libro,
        private readonly PresentadorHechos $presentador,
    ) {}

    /** @return array<string, mixed> */
    public function mes(Request $request, Periodo $periodo): array
    {
        $ultima = Lectura::query()->latest('iniciada_at')->latest('id')->first();

        return [
            ...$this->libro->mes($periodo),
            'ultima_captura' => $ultima ? $this->presentador->lectura($ultima) : null,
            'permisos' => [
                'cerrar' => $request->user()->can('cerrar-prefactura-finanzas'),
                'reabrir' => $request->user()->can('reabrir-prefactura-finanzas'),
            ],
        ];
    }

    /**
     * Mes «AAAA-MM» de la consulta o de la ruta; sin mes (y si no es obligatorio), el actual.
     *
     * @param  array<string, mixed>  $datos
     */
    public function periodo(array $datos, bool $obligatorio = false): Periodo
    {
        $valido = validator($datos, ['mes' => [$obligatorio ? 'required' : 'nullable', 'date_format:Y-m']], [
            'mes.required' => 'Indique el mes.',
            'mes.date_format' => 'El mes debe tener el formato AAAA-MM.',
        ])->validate();

        return Periodo::mes($valido['mes'] ?? null);
    }
}
