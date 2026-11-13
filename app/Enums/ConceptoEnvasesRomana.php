<?php

namespace App\Enums;

enum ConceptoEnvasesRomana: string
{
    case Compra = 'compra';
    case Arriendo = 'arriendo';
    // Envases despachados a un productor (CSG) que vuelven a la planta sin fruta: abonan su cuenta.
    case Devolucion = 'devolucion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Compra => 'Compra de envases propios',
            self::Arriendo => 'Arriendo de envases',
            self::Devolucion => 'Devolución de envases de productor (CSG)',
        };
    }
}
