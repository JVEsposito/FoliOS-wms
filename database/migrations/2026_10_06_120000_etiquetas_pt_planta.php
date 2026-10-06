<?php

use App\Services\Validacion\FechaProcesoEtiquetaPt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envases_validacion', fn (Blueprint $table) => $table->decimal('kilos_netos_por_caja', 10, 4)->nullable());
        Schema::table('folios', fn (Blueprint $table) => $table->date('fecha_proceso_pt')->nullable());
        $this->completarFechasHistoricas();
    }

    public function completarFechasHistoricas(): void
    {
        $fechas = new FechaProcesoEtiquetaPt;
        // Reproduce el orden de confirmación y usa snapshots anteriores, nunca la fecha de la repa.
        $anteriores = [];
        DB::table('repaletizajes')->where('estado', 'confirmado')->orderBy('confirmado_at')->orderBy('codigo')->cursor()->each(function ($repa) use ($fechas, &$anteriores): void {
            $origenes = DB::table('repaletizaje_detalles')->where('repaletizaje_id', $repa->id)->get();
            $fecha = $fechas->masAntigua($origenes->map(function ($detalle) use ($fechas, $anteriores): ?string {
                $snapshot = json_decode($detalle->snapshot_antes, true);
                $atributos = $snapshot['atributos'] ?? [];
                if (($atributos['origen_sistema'] ?? null) === 'repaletizaje') {
                    return $fechas->fecha($atributos['fecha_proceso_pt'] ?? null) ?? ($anteriores[$detalle->folio_origen_id] ?? null);
                }

                return $fechas->deDatos($atributos['datos_externos'] ?? []);
            })->all());
            $ids = DB::table('repaletizaje_resultados')->where('repaletizaje_id', $repa->id)->pluck('folio_id');
            if ($ids->isEmpty() && $repa->folio_resultante_id) {
                $ids->push($repa->folio_resultante_id);
            }
            foreach ($ids as $id) {
                $anteriores[$id] = $fecha;
                DB::table('folios')->where('id', $id)->where('origen_sistema', 'repaletizaje')->update(['fecha_proceso_pt' => $fecha]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('folios', fn (Blueprint $table) => $table->dropColumn('fecha_proceso_pt'));
        Schema::table('envases_validacion', fn (Blueprint $table) => $table->dropColumn('kilos_netos_por_caja'));
    }
};
