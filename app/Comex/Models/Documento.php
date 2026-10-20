<?php

namespace App\Comex\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Un documento de un embarque. Sin registro, el documento está pendiente.
 *
 * @property int $id
 * @property string $embarque_id
 * @property string $embarque_codigo
 * @property ?string $cliente_id
 * @property int $tipo_documento_id
 * @property string $estado
 * @property ?string $numero
 * @property ?Carbon $fecha_emision
 * @property ?string $observacion
 * @property ?string $motivo_no_aplica
 * @property int $version
 * @property ?int $actualizado_por_user_id
 * @property ?string $actualizado_por_nombre
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Documento extends Model
{
    public const PENDIENTE = 'pendiente';

    public const EN_TRAMITE = 'en_tramite';

    public const LISTO = 'listo';

    public const NO_APLICA = 'no_aplica';

    /** Estados que se registran; «pendiente» es la ausencia de registro. */
    public const ESTADOS = [self::EN_TRAMITE, self::LISTO, self::NO_APLICA];

    protected $connection = 'comex';

    protected $table = 'cx_documentos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<TipoDocumento, $this> */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    /** @return HasOne<ArchivoDocumento, $this> */
    public function archivoVigente(): HasOne
    {
        return $this->hasOne(ArchivoDocumento::class, 'documento_id')->whereNull('reemplazado_at');
    }

    /** @return HasMany<ArchivoDocumento, $this> */
    public function archivos(): HasMany
    {
        return $this->hasMany(ArchivoDocumento::class, 'documento_id');
    }

    /** @return HasMany<EventoDocumento, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(EventoDocumento::class, 'documento_id');
    }

    public function resuelto(): bool
    {
        return in_array($this->estado, [self::LISTO, self::NO_APLICA], true);
    }
}
