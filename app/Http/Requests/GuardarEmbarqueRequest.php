<?php

namespace App\Http\Requests;

use App\Enums\ModalidadEmbarque;
use App\Models\Puerto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GuardarEmbarqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-cargas') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_id' => [
                'required',
                'uuid',
                Rule::exists('clientes', 'id')->where('activo', true),
            ],
            'fecha_programada' => ['required', 'date'],
            'hora_programada' => ['required', 'date_format:H:i'],
            'modalidad' => ['required', Rule::enum(ModalidadEmbarque::class)],
            'referencia_correo' => ['nullable', 'string', 'max:200'],
            'nave_vuelo' => ['nullable', 'string', 'max:150'],
            'transportista' => ['nullable', 'string', 'max:180'],
            'puerto_embarque_id' => [
                'nullable',
                'uuid',
                Rule::exists('puertos', 'id')->where('activo', true),
            ],
            'contenedor' => ['nullable', 'string', 'max:100'],
            'sello' => ['nullable', 'string', 'max:100'],
            'patente_camion' => ['nullable', 'string', 'max:30'],
            'patente_trasera' => ['nullable', 'string', 'max:30'],
            'documentos' => ['nullable', 'string', 'max:2000'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'instructivos' => ['required', 'array', 'min:1', 'max:20'],
            'instructivos.*.id' => ['nullable', 'uuid', 'distinct'],
            'instructivos.*.numero_externo' => ['nullable', 'string', 'max:150'],
            'instructivos.*.recibidor' => ['nullable', 'string', 'max:180'],
            'instructivos.*.pais_destino_id' => [
                'nullable',
                'uuid',
                Rule::exists('paises', 'id')->where('activo', true),
            ],
            'instructivos.*.puerto_destino_id' => [
                'nullable',
                'uuid',
                Rule::exists('puertos', 'id')->where('activo', true),
            ],
            'instructivos.*.cantidad_pallets' => ['nullable', 'integer', 'min:0', 'max:999'],
            'instructivos.*.cantidad_cajas' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'instructivos.*.booking' => ['nullable', 'string', 'max:150'],
            'instructivos.*.sps' => ['nullable', 'string', 'max:150'],
            'instructivos.*.dus' => ['nullable', 'string', 'max:150'],
            'instructivos.*.planilla_sag' => ['nullable', 'string', 'max:150'],
            'instructivos.*.sello_sag' => ['nullable', 'string', 'max:150'],
            'instructivos.*.observacion' => ['nullable', 'string', 'max:1000'],
            'autorizar_sobrecupo' => ['sometimes', 'boolean'],
            'motivo_sobrecupo' => [
                Rule::requiredIf($this->boolean('autorizar_sobrecupo')),
                'nullable',
                'string',
                'min:10',
                'max:1000',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $campos = [
            'referencia_correo', 'nave_vuelo', 'transportista',
            'contenedor', 'sello', 'patente_camion', 'patente_trasera',
            'documentos', 'observacion', 'motivo_sobrecupo',
        ];
        $normalizados = [];

        foreach ($campos as $campo) {
            $normalizados[$campo] = $this->textoOpcional($this->input($campo));
        }

        $normalizados['patente_camion'] = $normalizados['patente_camion']
            ? mb_strtoupper($normalizados['patente_camion'])
            : null;
        $normalizados['patente_trasera'] = $normalizados['patente_trasera']
            ? mb_strtoupper($normalizados['patente_trasera'])
            : null;
        $normalizados['autorizar_sobrecupo'] = $this->boolean('autorizar_sobrecupo');
        $normalizados['instructivos'] = collect($this->input('instructivos', []))
            ->map(function (mixed $fila): mixed {
                if (! is_array($fila)) {
                    return $fila;
                }

                foreach ([
                    'numero_externo', 'recibidor',
                    'booking', 'sps', 'dus', 'planilla_sag', 'sello_sag', 'observacion',
                ] as $campo) {
                    $fila[$campo] = $this->textoOpcional($fila[$campo] ?? null);
                }

                return $fila;
            })
            ->all();

        $this->merge($normalizados);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $instructivos = collect($this->input('instructivos', []));
            $puertos = Puerto::query()
                ->whereIn('id', $instructivos->pluck('puerto_destino_id')->filter())
                ->get(['id', 'pais_id'])
                ->keyBy('id');

            $instructivos->each(function (mixed $fila, int $indice) use ($validator, $puertos): void {
                if (! is_array($fila) || empty($fila['puerto_destino_id'])) {
                    return;
                }

                if (empty($fila['pais_destino_id'])) {
                    $validator->errors()->add(
                        "instructivos.{$indice}.pais_destino_id",
                        'Selecciona el país antes de elegir el puerto de destino.',
                    );

                    return;
                }

                $puerto = $puertos->get($fila['puerto_destino_id']);

                if ($puerto && $puerto->pais_id !== $fila['pais_destino_id']) {
                    $validator->errors()->add(
                        "instructivos.{$indice}.puerto_destino_id",
                        'El puerto seleccionado no pertenece al país de destino.',
                    );
                }
            });
        }];
    }

    private function textoOpcional(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return $valor === null ? null : trim((string) $valor);
        }

        $texto = trim($valor);

        return $texto === '' ? null : $texto;
    }
}
