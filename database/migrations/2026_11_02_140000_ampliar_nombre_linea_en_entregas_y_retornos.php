<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->cambiarLongitud(60);
    }

    public function down(): void
    {
        // No se reduce a 50: en MySQL estricto fallaría con nombres de 51 a 60
        // caracteres ya registrados, y truncarlos perdería trazabilidad.
    }

    private function cambiarLongitud(int $longitud): void
    {
        // El catálogo permite nombres de 60 caracteres. Conserva el mismo nombre
        // completo en el viaje y en la trazabilidad por bin que lo copia del viaje.
        foreach (['entregas_fruta_proceso', 'bin_retorno_packing_origenes'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($longitud): void {
                $table->string('linea_proceso', $longitud)->change();
            });
        }
    }
};
