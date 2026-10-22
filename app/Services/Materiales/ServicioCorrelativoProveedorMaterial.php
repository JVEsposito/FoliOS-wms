<?php

namespace App\Services\Materiales;

use App\Models\ProveedorMaterial;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioCorrelativoProveedorMaterial
{
    public function bloquear(): object
    {
        DB::table('correlativos_proveedores_materiales')->insertOrIgnore([
            'id' => 1, 'ultimo_numero' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('correlativos_proveedores_materiales')
            ->where('id', 1)->lockForUpdate()->first();
    }

    public function siguiente(): string
    {
        return DB::transaction(function (): string {
            $ultimo = (int) $this->bloquear()->ultimo_numero;

            do {
                if ($ultimo >= 999_999_999_999) {
                    throw new DomainException('Se agotó el correlativo de proveedores de materiales.');
                }
                $ultimo++;
                $codigo = sprintf('PRV-%03d', $ultimo);
            } while ($this->enUso($codigo));

            DB::table('correlativos_proveedores_materiales')->where('id', 1)->update([
                'ultimo_numero' => $ultimo, 'updated_at' => now(),
            ]);

            return $codigo;
        }, attempts: 3);
    }

    public function enUso(string $codigo, ?string $exceptoId = null): bool
    {
        $normalizado = self::normalizar($codigo);

        return ProveedorMaterial::query()
            ->when($exceptoId, fn ($consulta) => $consulta->whereKeyNot($exceptoId))
            ->where(function ($consulta) use ($normalizado): void {
                $consulta->whereRaw("REPLACE(REPLACE(REPLACE(UPPER(codigo), '-', ''), '.', ''), '_', '') = ?", [$normalizado])
                    ->orWhereRaw("REPLACE(UPPER(codigo_interno), '-', '') = ?", [$normalizado]);
            })->exists();
    }

    public function validarCodigoComercial(?string $codigo, ?ProveedorMaterial $proveedor = null): void
    {
        if ($codigo === null || $codigo === '' || $codigo === $proveedor?->codigo) {
            return;
        }

        if ($this->enUso($codigo, $proveedor?->id)) {
            throw ValidationException::withMessages([
                'codigo' => 'El código ya identifica a otro proveedor, incluso omitiendo guiones, puntos o guiones bajos.',
            ]);
        }
    }

    public static function normalizar(string $codigo): string
    {
        return preg_replace('/[._-]/', '', mb_strtoupper(trim($codigo)));
    }
}
