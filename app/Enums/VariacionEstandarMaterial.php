<?php

namespace App\Enums;

/** Dimensión de la caja que hace variar el consumo de un insumo (como en «Consumos con variaciones» de planta). */
enum VariacionEstandarMaterial: string
{
    case Marca = 'marca';
    case Variedad = 'variedad';
    case Calibre = 'calibre';
    case Categoria = 'categoria';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Marca => 'Marca',
            self::Variedad => 'Variedad',
            self::Calibre => 'Calibre',
            self::Categoria => 'Categoría',
        };
    }
}
