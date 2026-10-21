<?php

namespace App\Rrhh;

use Illuminate\Support\Facades\DB;

final class LectorOperacion
{
    public function clientes(): array
    {
        return DB::connection(config('rrhh.conexion_lectura'))->table('clientes')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])->map(fn ($c) => (array) $c)->all();
    }

    public function nombreCliente(string $id): ?string
    {
        return DB::connection(config('rrhh.conexion_lectura'))->table('clientes')->where('id', $id)->value('nombre');
    }
}
