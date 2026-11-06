<?php

namespace App\Console\Commands;

use App\Models\GuiaDespachoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Envases\ServicioConciliacionSalidaEnvases;
use App\Services\Envases\ServicioGuiaEmitidaEnvases;
use Illuminate\Console\Command;
use Throwable;

class ConciliarSalidaDuplicadaEnvases extends Command
{
    protected $signature = 'envases:conciliar-salida
        {recepcion : UUID, correlativo REC o número exacto de guía de ingreso}
        {guia : UUID, correlativo GDE o número de guía de salida}
        {--conservar= : Cantidades a conservar: romana o despacho, sin selección automática}
        {--aplicar : Crea compensaciones; por defecto solo diagnostica}
        {--hash= : Hash de la previsualización revisada}
        {--usuario= : ID o email del administrador responsable}
        {--motivo= : Motivo y respaldo de la conciliación}';

    protected $description = 'Diagnostica y compensa una doble salida Romana/Despacho conservando cuenta, existencia y trazabilidad';

    public function handle(ServicioConciliacionSalidaEnvases $servicio): int
    {
        try {
            $referencia = (string) $this->argument('guia');
            $numero = app(ServicioGuiaEmitidaEnvases::class)->normalizar($referencia);
            $guias = GuiaDespachoEnvase::query()->where(fn ($q) => $q->where('id', $referencia)->orWhere('numero', $referencia)
                ->when($numero, fn ($q) => $q->orWhere('documento_salida_normalizado', $numero)))->get();
            if ($guias->count() !== 1) {
                $this->error('La guía no es única o no existe. Utiliza su UUID o correlativo GDE.');

                return self::FAILURE;
            }
            $guia = $guias->first();
            $referencia = (string) $this->argument('recepcion');
            $recepciones = RecepcionRomana::query()->where('cliente_id', $guia->cliente_id)->where('temporada_id', $guia->temporada_id)
                ->where(fn ($q) => $q->where('id', $referencia)->orWhere('numero_recepcion', $referencia)->orWhere('numero_guia_despacho', $referencia))->get();
            if ($recepciones->count() !== 1) {
                $this->error('La recepción no es única o no existe para ese cliente/temporada. Utiliza su UUID o correlativo REC.');

                return self::FAILURE;
            }
            $recepcion = $recepciones->first();
            $conservar = (string) $this->option('conservar');
            $plan = $servicio->previsualizar($recepcion, $guia, $conservar);
            $this->info("{$recepcion->numero_recepcion} · {$guia->numero} · guía {$guia->numero_guia_despacho} · conservar {$conservar}");
            if ($plan['aplicada']) {
                $this->info('Esta pareja ya fue conciliada. No se crean movimientos adicionales.');

                return self::SUCCESS;
            }
            $this->table(['Envase | propiedad', 'Romana', 'Despacho', 'Diferencia', 'Salida final'], array_map(fn ($f) => array_values($f), $plan['filas']));
            $this->line('Hash: '.$plan['hash']);
            if (! $this->option('aplicar')) {
                $this->info('Solo diagnóstico. No se modificaron cuenta ni existencia. Revisa las diferencias antes de aplicar con este hash, usuario y motivo.');

                return self::SUCCESS;
            }
            $referencia = (string) $this->option('usuario');
            $actor = User::query()->where(fn ($q) => $q->where('email', $referencia)
                ->when(ctype_digit($referencia), fn ($q) => $q->orWhere('id', $referencia)))->firstOrFail();
            $resultado = $servicio->aplicar($recepcion, $guia, $conservar, (string) $this->option('hash'), (string) $this->option('motivo'), $actor);
            $this->info('Conciliación aplicada: '.count($resultado['movimientos']).' compensaciones. Se conservan los asientos originales.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
