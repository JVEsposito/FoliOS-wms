<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FotoDocumentoRecepcionRequerida extends ValidationException
{
    public function __construct(string $codigo = 'recepcion_sin_foto_documento')
    {
        $mensaje = 'Falta la foto de la guía de despacho o factura. Agrégala antes de confirmar.';
        $validador = Validator::make([], []);
        $validador->errors()->add('fotos', $mensaje);
        parent::__construct($validador, response()->json([
            'message' => $mensaje, 'codigo' => $codigo, 'errors' => ['fotos' => [$mensaje]],
        ], 422));
    }
}
