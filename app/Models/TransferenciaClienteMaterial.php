<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use App\Models\Concerns\TemporadaPorColumna;
use App\Models\Contracts\PerteneceATemporada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferenciaClienteMaterial extends Model implements PerteneceATemporada
{
    use HasUuids, ImpideEliminacionFisica, TemporadaPorColumna;

    protected $table = 'transferencias_clientes_materiales';

    protected $guarded = ['id'];

    public function folioOrigen(): BelongsTo
    {
        return $this->belongsTo(FolioMaterial::class, 'folio_origen_id', 'folio_id');
    }

    public function folioDestino(): BelongsTo
    {
        return $this->belongsTo(FolioMaterial::class, 'folio_destino_id', 'folio_id');
    }

    public function clienteOrigen(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_origen_id');
    }

    public function clienteDestino(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_destino_id');
    }

    public function itemOrigen(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_origen_id');
    }

    public function itemDestino(): BelongsTo
    {
        return $this->belongsTo(ItemMaterial::class, 'item_destino_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:3', 'snapshot' => 'array', 'ocurrido_at' => 'datetime'];
    }
}
