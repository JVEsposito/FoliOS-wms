<?php

namespace App\Models;

use App\Enums\CategoriaOperacionalMaterial;
use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'cliente_material_id',
    'codigo',
    'nombre',
    'categoria',
    'categoria_operacional',
    'unidad_medida',
    'codigo_externo',
    'origen_sistema',
    'sincronizado_at',
    'activo',
    'dias_alerta_vencimiento',
    'stock_minimo',
    'punto_reorden',
    'stock_maximo',
    'creado_por_user_id',
    'actualizado_por_user_id',
])]
class ItemMaterial extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'items_materiales';

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(ClienteMaterial::class, 'cliente_material_id');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(FotoItemMaterial::class, 'item_material_id');
    }

    public function fotoPrincipal(): HasOne
    {
        return $this->hasOne(FotoItemMaterial::class, 'item_material_id')->where('principal', true);
    }

    public function foliosMateriales(): HasMany
    {
        return $this->hasMany(FolioMaterial::class, 'item_material_id');
    }

    public function detallesRecepciones(): HasMany
    {
        return $this->hasMany(DetalleRecepcionMaterial::class, 'item_material_id');
    }

    public function regularizacionComoDuplicado(): HasOne
    {
        return $this->hasOne(RegularizacionItemMaterial::class, 'item_duplicado_id');
    }

    public function regularizacionesComoCanonico(): HasMany
    {
        return $this->hasMany(RegularizacionItemMaterial::class, 'item_canonico_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }

    protected function casts(): array
    {
        return [
            'categoria_operacional' => CategoriaOperacionalMaterial::class,
            'activo' => 'boolean',
            'dias_alerta_vencimiento' => 'integer',
            'stock_minimo' => 'decimal:3',
            'punto_reorden' => 'decimal:3',
            'stock_maximo' => 'decimal:3',
            'reposicion_revision' => 'integer',
            'fotos_version' => 'integer',
            'reposicion_calculada_at' => 'datetime',
            'sincronizado_at' => 'datetime',
        ];
    }
}
