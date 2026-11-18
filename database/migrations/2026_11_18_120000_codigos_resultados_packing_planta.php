<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // CECONG tiene seis caracteres; se conserva el campo usado por los clientes anteriores.
        $definicion = Schema::getColumnType('tipos_resultado_packing', 'prefijo_sublote', fullDefinition: true);
        if (preg_match('/^varchar\((\d+)\)$/i', $definicion, $coincidencia) && (int) $coincidencia[1] < 6) {
            Schema::table('tipos_resultado_packing', function (Blueprint $table): void {
                $table->string('prefijo_sublote', 10)->change();
            });
        }

        $tipos = [
            ['comercial', 'Comercial', 'CECOM'],
            ['congelado', 'Congelado', 'CECONG'],
            ['desecho', 'Desecho', 'CEDES'],
            ['descarte', 'Descarte', 'CEDCA'],
            ['precalibre', 'Precalibre', 'CEPRE'],
        ];
        foreach ($tipos as $indice => [$codigo, $nombre, $prefijo]) {
            $existente = DB::table('tipos_resultado_packing')->where('codigo', $codigo)->first();
            DB::table('tipos_resultado_packing')->updateOrInsert(['codigo' => $codigo], [
                'id' => $existente?->id ?? (string) Str::uuid(),
                'nombre' => $nombre, 'prefijo_sublote' => $prefijo, 'activo' => true,
                'orden' => ($indice + 1) * 10,
                'created_at' => $existente?->created_at ?? now(), 'updated_at' => now(),
            ]);
        }
        // «Otro» continúa como excepción manual; no se reclasifican registros históricos.
        DB::table('tipos_resultado_packing')->where('codigo', 'otro')->where('prefijo_sublote', 'OT')
            ->where('orden', 40)->update(['orden' => 90]);
    }

    public function down(): void
    {
        // No se borran clasificaciones ni se acortan prefijos que pueden tener retornos vinculados.
        // Una corrección del catálogo se aplica con una nueva migración, sin renumerar sublotes.
    }
};
