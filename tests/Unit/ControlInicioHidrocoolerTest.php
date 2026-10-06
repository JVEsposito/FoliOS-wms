<?php

namespace Tests\Unit;

use App\Http\Requests\IniciarHidrocoolerMateriaPrimaRequest;
use App\Services\MateriaPrima\ControlCloroHidrocooler;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ControlInicioHidrocoolerTest extends TestCase
{
    public function test_sin_limites_aprobados_no_se_inventa_un_rango(): void
    {
        config(['hidrocooler.cloro_min_ppm' => null, 'hidrocooler.cloro_max_ppm' => '']);
        $control = app(ControlCloroHidrocooler::class);
        $this->assertNull($control->rango());
        $this->assertNull($control->validar(['cloro_libre_ppm' => 40]));
    }

    public function test_limites_inclusivos_y_correccion_obligatoria_fuera_de_rango(): void
    {
        // Límites de prueba: no representan un rango aprobado por planta.
        config(['hidrocooler.cloro_min_ppm' => 90, 'hidrocooler.cloro_max_ppm' => 110]);
        $control = app(ControlCloroHidrocooler::class);
        foreach ([90, 100, 110] as $lectura) {
            $this->assertSame(['min' => 90.0, 'max' => 110.0], $control->validar(['cloro_libre_ppm' => $lectura]));
        }
        foreach ([89.99, 110.01] as $lectura) {
            foreach ([null, 89.99, 110.01] as $correccion) {
                try {
                    $control->validar(['cloro_libre_ppm' => $lectura, 'correccion_cloro_ppm' => $correccion]);
                    $this->fail('Debe rechazar una lectura fuera de rango sin corrección válida.');
                } catch (ValidationException $error) {
                    $this->assertArrayHasKey('correccion_cloro_ppm', $error->errors());
                }
            }
            foreach ([90, 110] as $correccion) {
                $this->assertNotNull($control->validar(['cloro_libre_ppm' => $lectura, 'correccion_cloro_ppm' => $correccion]));
            }
        }
    }

    public function test_configuracion_parcial_o_invalida_no_omite_el_control(): void
    {
        foreach ([[90, null], [null, 110], [110, 90], [-1, 100], [90, 501], ['pendiente', 110]] as [$min, $max]) {
            config(['hidrocooler.cloro_min_ppm' => $min, 'hidrocooler.cloro_max_ppm' => $max]);
            try {
                app(ControlCloroHidrocooler::class)->validar(['cloro_libre_ppm' => 100]);
                $this->fail('Una configuración inválida debe impedir el inicio.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('cloro_libre_ppm', $error->errors());
            }
        }
    }

    public function test_inicio_exige_mediciones_y_decisiones_explicitas_pero_acepta_no_y_hr_cero(): void
    {
        $datos = $this->inicio();
        $this->assertTrue($this->validar($datos)->passes());
        foreach (['temperatura_ambiente_c', 'humedad_relativa_pct', 'pozo_accutab_mv', 'recarga_pastilla', 'aplicacion_producto'] as $campo) {
            $incompleto = $datos;
            unset($incompleto[$campo]);
            $this->assertArrayHasKey($campo, $this->validar($incompleto)->errors()->toArray());
        }
        foreach ([0, 100] as $hr) {
            $this->assertTrue($this->validar([...$datos, 'humedad_relativa_pct' => $hr])->passes());
        }
        foreach ([-0.01, 100.01] as $hr) {
            $this->assertArrayHasKey('humedad_relativa_pct', $this->validar([...$datos, 'humedad_relativa_pct' => $hr])->errors()->toArray());
        }
    }

    public function test_aplicacion_exige_producto_dosis_y_unidad_y_no_acepta_dosis_cero(): void
    {
        $datos = [...$this->inicio(), 'aplicacion_producto' => true];
        $errores = $this->validar($datos)->errors()->toArray();
        foreach (['producto_hidrocooler_id', 'producto_dosis', 'producto_unidad_dosis'] as $campo) {
            $this->assertArrayHasKey($campo, $errores);
        }
        foreach ([0, -1, 0.12345] as $dosis) {
            $this->assertArrayHasKey('producto_dosis', $this->validar([...$datos, 'producto_dosis' => $dosis])->errors()->toArray());
        }
        $errores = $this->validar([...$datos, 'producto_dosis' => 0.1254, 'producto_unidad_dosis' => 'ml/L'])->errors()->toArray();
        $this->assertArrayNotHasKey('producto_dosis', $errores);
        $this->assertArrayNotHasKey('producto_unidad_dosis', $errores);
    }

    private function validar(array $datos): \Illuminate\Contracts\Validation\Validator
    {
        $solicitud = IniciarHidrocoolerMateriaPrimaRequest::create('/inicio', 'POST', $datos);

        return Validator::make($datos, $solicitud->rules());
    }

    private function inicio(): array
    {
        return [
            'operacion_id' => '019c2a55-389f-7d81-a0dd-6d1f1ad29f8c',
            'equipo' => 'HC-1', 'turno' => 'A', 'cantidad_bombas_funcionando' => 1,
            'inicio_at' => now()->subMinute()->toIso8601String(),
            'temperatura_inicial_c' => 18, 'temperatura_objetivo_c' => 4,
            'cloro_libre_ppm' => 100, 'ph_agua' => 6.5,
            'temperatura_ambiente_c' => 21.5, 'humedad_relativa_pct' => 0,
            'pozo_accutab_mv' => 700.5, 'recarga_pastilla' => false, 'aplicacion_producto' => false,
            'control_inicial_conforme' => true, 'condicion_visual_agua' => 'conforme',
            'dosificador_operativo' => true, 'manejo_agua' => 'sin_novedad',
        ];
    }
}
