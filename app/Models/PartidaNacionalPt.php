<?php

namespace App\Models;

use App\Models\Concerns\ImpideEliminacionFisica;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'temporada_id', 'folio_origen_id', 'folio_id', 'despacho_id', 'user_id', 'cajas', 'motivo', 'payload_hash', 'snapshot',
])]
class PartidaNacionalPt extends Model
{
    use HasUuids, ImpideEliminacionFisica;

    protected $table = 'partidas_nacionales_pt';

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'cajas' => 'integer'];
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function despacho(): BelongsTo
    {
        return $this->belongsTo(DespachoNacionalPt::class, 'despacho_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
