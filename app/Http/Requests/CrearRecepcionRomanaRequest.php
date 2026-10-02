<?php

namespace App\Http\Requests;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\TipoCamionRomana;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoRecepcionRomana;
use App\Enums\TipoServicioRomana;
use App\Rules\RutChileno;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CrearRecepcionRomanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-romana') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $esSoloEnvases = $this->input('tipo_recepcion') === TipoRecepcionRomana::SoloEnvases->value;
        $esPesajeEnvases = $this->input('tipo_recepcion') === TipoRecepcionRomana::FrutaPesajeEnvases->value;
        $requierePesoBruto = $this->input('tipo_recepcion') === TipoRecepcionRomana::FrutaConEnvases->value;

        return [
            'operacion_id' => ['required', 'uuid'],
            'temporada_id' => ['required', 'uuid', Rule::exists('temporadas', 'id')->where('id', app(ServicioTemporadaActiva::class)->buscar()?->id)],
            'cliente_id' => ['required', 'uuid', Rule::exists('clientes', 'id')->where('activo', true)],
            'tipo_recepcion' => ['required', Rule::enum(TipoRecepcionRomana::class)],
            'especie_validacion_id' => [
                Rule::requiredIf(! $esSoloEnvases),
                'nullable', 'uuid',
                Rule::exists('especies_validacion', 'id')->where('temporada_id', $this->input('temporada_id'))->where('activo', true),
            ],
            'fecha_ingreso' => [
                'nullable',
                Rule::requiredIf($esSoloEnvases),
                'date_format:Y-m-d',
                'before_or_equal:'.CarbonImmutable::now(config('app.operational_timezone'))->toDateString(),
            ],
            'concepto_envases' => [
                'nullable',
                Rule::requiredIf($esSoloEnvases),
                Rule::enum(ConceptoEnvasesRomana::class),
            ],
            'tipo_servicio' => [
                'nullable',
                Rule::requiredIf($this->input('tipo_recepcion') !== TipoRecepcionRomana::SoloEnvases->value),
                Rule::enum(TipoServicioRomana::class),
            ],
            'envases' => ['required', 'array', 'min:1', 'max:'.count(TipoEnvaseRomana::cases())],
            'envases.*.tipo_envase' => ['required', 'distinct', Rule::enum(TipoEnvaseRomana::class)],
            'envases.*.cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
            'tipo_envase_pesaje' => [
                'nullable',
                Rule::requiredIf($esPesajeEnvases),
                Rule::enum(TipoEnvaseRomana::class),
            ],
            'tara_unitaria_envase' => [
                'nullable',
                Rule::requiredIf($esPesajeEnvases),
                'numeric',
                'min:0.001',
                'max:1000',
                'decimal:0,3',
            ],
            'numero_guia_despacho' => ['required', 'string', 'max:80'],
            'patente_camion' => ['required', 'regex:/^[A-Z0-9]{5,8}$/'],
            'tipo_camion' => ['required', Rule::enum(TipoCamionRomana::class)],
            'patente_carro' => ['nullable', 'regex:/^[A-Z0-9]{5,8}$/'],
            'rut_conductor' => ['required', new RutChileno],
            'nombre_conductor' => ['required', 'string', 'max:150'],
            'peso_bruto' => [
                'nullable',
                Rule::requiredIf($requierePesoBruto),
                'numeric',
                'min:1',
                'max:200000',
                'decimal:0,2',
            ],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'temporada_id.required' => 'Selecciona la temporada operacional.',
            'temporada_id.exists' => 'La temporada seleccionada no es la temporada global activa.',
            'cliente_id.required' => 'Selecciona el cliente del servicio.',
            'cliente_id.exists' => 'El cliente seleccionado no está activo.',
            'tipo_recepcion.required' => 'Selecciona el tipo de recepción.',
            'especie_validacion_id.required' => 'Selecciona la especie de la fruta recibida.',
            'fecha_ingreso.required' => 'Selecciona la fecha de ingreso de los envases.',
            'fecha_ingreso.date_format' => 'La fecha de ingreso de los envases no es válida.',
            'fecha_ingreso.before_or_equal' => 'La fecha de ingreso de los envases no puede ser futura.',
            'concepto_envases.required' => 'Indica si los envases ingresan por compra o arriendo.',
            'tipo_servicio.required' => 'Selecciona el servicio contratado para la fruta.',
            'envases.required' => 'Registra al menos un tipo de envase declarado en la guía.',
            'envases.*.tipo_envase.distinct' => 'Cada tipo de envase puede declararse solo una vez.',
            'envases.*.cantidad.min' => 'La guía debe declarar al menos una unidad por tipo de envase.',
            'tipo_envase_pesaje.required' => 'Selecciona el tipo de envase que se pesará.',
            'tara_unitaria_envase.required' => 'Configura la tara unitaria del envase.',
            'tara_unitaria_envase.min' => 'La tara unitaria debe ser mayor que cero.',
            'numero_guia_despacho.required' => 'Ingresa el número de guía de despacho.',
            'patente_camion.required' => 'Ingresa la patente del camión.',
            'patente_camion.regex' => 'Ingresa una patente de camión válida, sin puntos ni guiones.',
            'tipo_camion.required' => 'Selecciona el tipo de camión.',
            'tipo_camion' => 'Selecciona camión termo o camión plano.',
            'patente_carro.regex' => 'Ingresa una patente de carro válida, sin puntos ni guiones.',
            'nombre_conductor.required' => 'Ingresa el nombre del conductor.',
            'peso_bruto.required' => 'Ingresa el peso bruto capturado por la romana.',
            'peso_bruto.max' => 'El peso bruto supera el máximo operacional de 200.000 kg.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('tipo_recepcion') !== TipoRecepcionRomana::FrutaPesajeEnvases->value) {
                return;
            }

            $envases = collect($this->input('envases', []));
            if ($envases->count() !== 1) {
                $validator->errors()->add(
                    'envases',
                    'El pesaje acumulativo debe declarar un único tipo de envase.',
                );

                return;
            }

            $primerEnvase = $envases->first();
            $tipoDeclarado = is_array($primerEnvase)
                ? ($primerEnvase['tipo_envase'] ?? null)
                : null;
            if ($tipoDeclarado !== $this->input('tipo_envase_pesaje')) {
                $validator->errors()->add(
                    'tipo_envase_pesaje',
                    'El envase seleccionado debe coincidir con el declarado en la guía.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $tipoRecepcion = (string) $this->input('tipo_recepcion');
        $this->merge([
            'concepto_envases' => $tipoRecepcion === TipoRecepcionRomana::SoloEnvases->value
                ? $this->input('concepto_envases')
                : null,
            'fecha_ingreso' => $tipoRecepcion === TipoRecepcionRomana::SoloEnvases->value
                ? $this->input('fecha_ingreso')
                : null,
            'tipo_servicio' => $tipoRecepcion === TipoRecepcionRomana::SoloEnvases->value
                ? 'almacenaje'
                : $this->input('tipo_servicio'),
            'tipo_envase_pesaje' => $tipoRecepcion === TipoRecepcionRomana::FrutaPesajeEnvases->value
                ? $this->input('tipo_envase_pesaje')
                : null,
            'tara_unitaria_envase' => $tipoRecepcion === TipoRecepcionRomana::FrutaPesajeEnvases->value
                ? $this->input('tara_unitaria_envase')
                : null,
            'peso_bruto' => ($tipoRecepcion === TipoRecepcionRomana::SoloEnvases->value
                || $tipoRecepcion === TipoRecepcionRomana::FrutaPesajeEnvases->value)
                    ? null
                    : $this->input('peso_bruto'),
            'numero_guia_despacho' => mb_strtoupper(trim((string) $this->input('numero_guia_despacho'))),
            'patente_camion' => $this->normalizarPatente($this->input('patente_camion')),
            'patente_carro' => $this->normalizarPatente($this->input('patente_carro')),
            'rut_conductor' => $this->normalizarRut($this->input('rut_conductor')),
            'nombre_conductor' => trim((string) $this->input('nombre_conductor')),
            'observacion' => filled($this->input('observacion')) ? trim((string) $this->input('observacion')) : null,
        ]);
    }

    private function normalizarPatente(mixed $valor): ?string
    {
        $patente = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $valor) ?? '');

        return $patente !== '' ? $patente : null;
    }

    private function normalizarRut(mixed $valor): string
    {
        $rut = strtoupper(preg_replace('/[^0-9K]/i', '', (string) $valor) ?? '');

        return strlen($rut) > 1 ? substr($rut, 0, -1).'-'.substr($rut, -1) : $rut;
    }
}
