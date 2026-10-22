<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores_materiales', function (Blueprint $table): void {
            $table->string('codigo_interno', 80)->nullable()->unique();
        });

        Schema::create('correlativos_proveedores_materiales', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestamps();
        });

        DB::transaction(function (): void {
            $proveedores = DB::table('proveedores_materiales')
                ->orderBy('created_at')->orderBy('id')->get(['id', 'codigo']);
            $ocupados = $proveedores->mapWithKeys(fn ($proveedor): array => [
                preg_replace('/[._-]/', '', strtoupper(trim($proveedor->codigo))) => true,
            ])->all();
            $ultimo = 0;

            foreach ($proveedores as $proveedor) {
                do {
                    $ultimo++;
                    $codigo = sprintf('PRV-%03d', $ultimo);
                } while (isset($ocupados[str_replace('-', '', $codigo)]));

                // El código comercial, los UUID y las fechas históricas se conservan.
                DB::table('proveedores_materiales')->where('id', $proveedor->id)
                    ->update(['codigo_interno' => $codigo]);
            }

            DB::table('correlativos_proveedores_materiales')->insert([
                'id' => 1, 'ultimo_numero' => $ultimo,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('proveedores_materiales', function (Blueprint $table): void {
            $table->dropUnique(['codigo_interno']);
            $table->dropColumn('codigo_interno');
        });

        Schema::dropIfExists('correlativos_proveedores_materiales');
    }
};
