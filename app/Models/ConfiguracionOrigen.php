<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['clave', 'conexion_externa_id', 'ajustes', 'version', 'actualizado_por_user_id'])]
class ConfiguracionOrigen extends Model
{
    public const STOCK = 'stock_pt';

    protected $table = 'configuraciones_origen';

    protected $primaryKey = 'clave';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = ['ajustes'];

    protected function casts(): array
    {
        return ['ajustes' => 'encrypted:array', 'version' => 'integer'];
    }

    public function ambito(?ConexionExterna $conexion): string
    {
        return hash('sha256', implode('|', [$this->clave, $this->version, $this->conexion_externa_id, $conexion?->version]));
    }
}
