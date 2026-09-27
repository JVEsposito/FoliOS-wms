<?php

namespace App\Services\Estiba;

use App\Models\ConfirmacionInicioTarea;
use App\Models\Dispositivo;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Autenticacion\ServicioPinOperacional;
use Illuminate\Validation\ValidationException;

/**
 * Antes de retirar un pallet el camarero compara la etiqueta física con la
 * tarea: digita los últimos 4 dígitos del folio y su PIN. Cada intento queda
 * registrado, incluidos los rechazos, que revelan pallets equivocados.
 */
class ServicioConfirmacionInicioTarea
{
    public function __construct(
        private readonly ServicioPinOperacional $pines,
    ) {}

    public function exigida(?TareaMovimiento $tarea = null): bool
    {
        return (bool) data_get($tarea?->contexto, 'confirmar_folio_fisicamente')
            || (bool) config('planificador.confirmacion_inicio_tarea', true);
    }

    public static function digitosEsperados(string $numeroFolio): string
    {
        $digitos = preg_replace('/\D/', '', $numeroFolio) ?? '';
        $base = $digitos !== '' ? $digitos : strtoupper(trim($numeroFolio));

        return substr($base, -4);
    }

    /**
     * Acepta los últimos 4 dígitos o el folio completo (lector de código de barras).
     */
    public static function coincide(string $numeroFolio, string $ingresado): bool
    {
        $ingresado = strtoupper(trim($ingresado));

        if ($ingresado === '') {
            return false;
        }

        return $ingresado === strtoupper(trim($numeroFolio))
            || $ingresado === self::digitosEsperados($numeroFolio);
    }

    public function verificar(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
        string $digitos,
        string $pin,
    ): void {
        $numeroFolio = (string) $tarea->folio()->value('numero_folio');

        if (! self::coincide($numeroFolio, $digitos)) {
            $this->registrar($tarea, $numeroFolio, $usuario, $dispositivo, ConfirmacionInicioTarea::RECHAZADA_FOLIO, $digitos);

            throw ValidationException::withMessages([
                'confirmacion_folio' => 'Los dígitos no coinciden con el folio de la tarea. Revisa la etiqueta del pallet.',
            ]);
        }

        try {
            $this->pines->verificar($usuario, $pin);
        } catch (ValidationException $excepcion) {
            $this->registrar($tarea, $numeroFolio, $usuario, $dispositivo, ConfirmacionInicioTarea::RECHAZADA_PIN, $digitos);

            throw $excepcion;
        }
    }

    public function registrarConfirmada(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
        string $digitos,
    ): ConfirmacionInicioTarea {
        $numeroFolio = (string) $tarea->folio()->value('numero_folio');

        return $this->registrar($tarea, $numeroFolio, $usuario, $dispositivo, ConfirmacionInicioTarea::CONFIRMADA, $digitos);
    }

    private function registrar(
        TareaMovimiento $tarea,
        string $numeroFolio,
        User $usuario,
        Dispositivo $dispositivo,
        string $resultado,
        string $digitos,
    ): ConfirmacionInicioTarea {
        return ConfirmacionInicioTarea::create([
            'tarea_movimiento_id' => $tarea->id,
            'folio_id' => $tarea->folio_id,
            'numero_folio' => $numeroFolio,
            'user_id' => $usuario->id,
            'dispositivo_id' => $dispositivo->id,
            'resultado' => $resultado,
            'digitos_ingresados' => mb_substr(trim($digitos), 0, 50),
            'confirmado_at' => now(),
        ]);
    }
}
