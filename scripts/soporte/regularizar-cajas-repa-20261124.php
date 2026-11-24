<?php

declare(strict_types=1);

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\Folio;
use App\Models\User;
use App\Services\InspeccionSag\ServicioExistenciasSag;
use App\Services\Planificador\ServicioDepuracionIngresosCamara;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Regularización acotada al informe del 24/11/2026 y a los conteos confirmados.
 * No cambia validaciones, repas ni despachos. Sin argumentos: solo vista previa.
 */
final class RegularizacionCajasRepa20261124
{
    public const OPERACION = 'fe5bfe32-5e64-4b2d-a94f-f02411240001';

    public const TEMPORADA = '01a05e0d-de81-723a-b116-a91569028ff8';

    public const PASOS = [
        '6000005153' => ['id' => '01a20305-1e7f-70d8-b80e-d26ac03b54d3', 'antes' => 56, 'despues' => 0, 'activo' => true, 'estado' => 'pendiente_ubicacion', 'actualizado' => '2026-11-23 18:53:26'],
        '6000005028' => ['id' => '01a202f4-ecb3-73a0-bc65-cd284e4db52f', 'antes' => 89, 'despues' => 7, 'activo' => true, 'estado' => 'pendiente_ubicacion', 'actualizado' => '2026-11-23 15:55:31'],
        '6000005124' => ['id' => '01a2032c-db9d-71d5-9911-c2d743f7921a', 'antes' => 49, 'despues' => 0, 'activo' => false, 'estado' => 'agotado', 'actualizado' => '2026-11-23 11:25:46'],
        '6000005121' => ['id' => '01a2032b-77aa-73be-8509-e3e844674677', 'antes' => 60, 'despues' => 0, 'activo' => false, 'estado' => 'agotado', 'actualizado' => '2026-11-23 11:27:13'],
        '6000005151' => ['id' => '01a20303-0f6e-7286-98b3-c3dc91946f86', 'antes' => 20, 'despues' => 0, 'activo' => false, 'estado' => 'agotado', 'actualizado' => '2026-11-23 12:05:25'],
    ];

    public function __construct(private readonly string $temporadaEsperada = self::TEMPORADA) {}

    private function validar(Folio $folio, string $numero, array $paso): array
    {
        $cantidad = $folio->datos_externos['cantidad_cajas'] ?? null;
        if ($folio->id !== $paso['id'] || $folio->numero_folio !== $numero
            || $folio->temporada_id !== $this->temporadaEsperada
            || ! is_numeric($cantidad) || (float) $cantidad !== (float) $paso['antes']
            || $folio->activo !== $paso['activo'] || $folio->estado_operacional?->value !== $paso['estado']
            || $folio->tipo_bulto !== TipoBulto::Saldo
            || $folio->updated_at?->utc()->format('Y-m-d H:i:s') !== $paso['actualizado']) {
            throw new ConflictoOperacion("El folio {$numero} cambió respecto de la auditoría. No se aplicó el ajuste.");
        }
        if ($folio->asignacionCargaActual()->exists() || $folio->reservaCargaActual()->exists()) {
            throw new ConflictoOperacion("El folio {$numero} tiene una carga o reserva vigente.");
        }
        if ($paso['despues'] === 0 && $folio->ubicacionActual()->exists()) {
            throw new ConflictoOperacion("El folio {$numero} tiene una ubicación. Revisa esa ocupación antes de regularizar.");
        }
        $datos = $folio->datos_externos;
        $composicion = collect($datos['composicion'] ?? []);
        if ($composicion->contains(fn ($linea) => ! is_array($linea)
                || ! isset($linea['cantidad_cajas']) || ! is_numeric($linea['cantidad_cajas'])
                || (float) $linea['cantidad_cajas'] !== (float) (int) $linea['cantidad_cajas']
                || (int) $linea['cantidad_cajas'] < 0)
            || (int) $composicion->sum('cantidad_cajas') !== $paso['antes']) {
            throw new ConflictoOperacion("La composición de {$numero} no coincide con sus cajas. Requiere revisión.");
        }
        if ($paso['despues'] > 0 && $composicion->count() !== 1) {
            throw new ConflictoOperacion("{$numero} tiene varios componentes. No se distribuirá el ajuste por estimación.");
        }
        $datos['cantidad_cajas'] = $paso['despues'];
        $datos['composicion'] = $paso['despues'] === 0 ? []
            : [[...$composicion->first(), 'cantidad_cajas' => $paso['despues']]];
        if ($paso['despues'] === 0) {
            $datos['csgs'] = [];
            $datos['fechas_embalaje'] = [];
        }

        return $datos;
    }

    public function previsualizar(): array
    {
        $salida = [];
        foreach (self::PASOS as $numero => $paso) {
            $folio = Folio::query()->findOrFail($paso['id']);
            $this->validar($folio, (string) $numero, $paso);
            $salida[] = ['folio' => (string) $numero, 'antes' => $paso['antes'], 'despues' => $paso['despues']];
        }

        return $salida;
    }

    public function aplicar(User $usuario, string $respaldo): array
    {
        if (! $usuario->activo || ! $usuario->can('corregir-validaciones-pallet')) {
            throw new ConflictoOperacion('El ejecutor debe tener permiso para corregir validaciones de pallets.');
        }
        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Administracion,
            tipo: 'folios.regularizar_cajas_fecha',
            usuario: $usuario,
            payload: ['pasos' => self::PASOS, 'temporada' => $this->temporadaEsperada],
            operacionId: self::OPERACION,
            referencia: 'Regularización de cajas por correcciones de fecha, 24/11/2026',
        );

        return app(MotorTransicionesOperacionales::class)->ejecutar($comando, function () use ($usuario, $respaldo): array {
            $ids = array_column(self::PASOS, 'id');
            $folios = Folio::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($folios->count() !== count(self::PASOS)) {
                throw new ConflictoOperacion('Falta uno de los folios del informe.');
            }
            $aplicados = $folios->filter(fn ($f) => data_get($f->datos_externos, 'regularizacion_cajas_fecha.operacion_id') === self::OPERACION);
            if ($aplicados->count() === count(self::PASOS)) {
                return ['ya_aplicado' => true, 'operacion_id' => self::OPERACION];
            }
            if ($aplicados->isNotEmpty()) {
                throw new ConflictoOperacion('Hay marcas parciales de regularización. Revisa la auditoría antes de continuar.');
            }
            $temporada = app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido();
            if ($temporada?->id !== $this->temporadaEsperada) {
                throw new ConflictoOperacion('La temporada auditada ya no está activa.');
            }
            $nuevos = [];
            foreach (self::PASOS as $numero => $paso) {
                $nuevos[$paso['id']] = $this->validar($folios->get($paso['id']), (string) $numero, $paso);
            }
            $copia = ['operacion_id' => self::OPERACION, 'usuario_id' => $usuario->id, 'generado_utc' => now()->utc()->toAtomString(),
                'advertencia' => 'Respaldo previo al intento. La transición operacional confirma si se aplicó.',
                'folios_antes' => $folios->map(fn ($f) => $f->getAttributes())->values()->all()];
            $archivo = @fopen($respaldo, 'xb');
            if ($archivo === false) {
                throw new RuntimeException('No se pudo crear un respaldo nuevo. No se modificó el inventario.');
            }
            try {
                $json = json_encode($copia, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (fwrite($archivo, $json) !== strlen($json) || ! fflush($archivo)) {
                    throw new RuntimeException('El respaldo no se escribió completo. No se modificó el inventario.');
                }
            } finally {
                fclose($archivo);
            }
            $resultado = [];
            foreach (self::PASOS as $numero => $paso) {
                $folio = $folios->get($paso['id']);
                $datos = $nuevos[$folio->id];
                $datos['regularizacion_cajas_fecha'] = [
                    'operacion_id' => self::OPERACION, 'usuario_id' => $usuario->id,
                    'fecha_utc' => now()->utc()->toAtomString(), 'antes' => $paso['antes'], 'despues' => $paso['despues'],
                    'motivo' => 'Corrección del efecto de cambios de fecha posteriores a repaletizaje; conteos confirmados y trazabilidad de folios agotados.',
                    'respaldo' => basename($respaldo),
                ];
                $folio->update([
                    'datos_externos' => $datos,
                    ...($paso['despues'] === 0 ? ['activo' => false, 'tipo_bulto' => TipoBulto::Saldo,
                        'estado_operacional' => EstadoOperacionalFolio::Agotado] : []),
                ]);
                $resultado[] = ['folio' => (string) $numero, 'antes' => $paso['antes'], 'despues' => $paso['despues']];
            }
            app(ServicioExistenciasSag::class)->sincronizar($ids, 'regularizacion_cajas_fecha', self::OPERACION);
            foreach (self::PASOS as $paso) {
                if ($paso['despues'] === 0) {
                    app(ServicioDepuracionIngresosCamara::class)->depurarFolio($paso['id'], $usuario);
                }
            }

            return ['aplicado' => true, 'operacion_id' => self::OPERACION, 'respaldo' => $respaldo, 'folios' => $resultado];
        });
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $raiz = is_file(__DIR__.'/artisan') ? __DIR__ : dirname(__DIR__, 2);
        if (! is_file($raiz.'/artisan')) {
            throw new RuntimeException('Coloca este archivo junto a artisan, en la carpeta de FoliOS.');
        }
        require $raiz.'/vendor/autoload.php';
        $app = require $raiz.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if (DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'estiba_wms') {
            throw new RuntimeException('Esta regularización está limitada a la base oficial estiba_wms auditada.');
        }
        $opciones = getopt('', ['aplicar', 'usuario:']);
        $servicio = new RegularizacionCajasRepa20261124;
        if (! array_key_exists('aplicar', $opciones)) {
            echo json_encode(['vista_previa' => $servicio->previsualizar(),
                'usuarios_habilitados' => User::query()->where('activo', true)->get()->filter(fn ($u) => $u->can('corregir-validaciones-pallet'))
                    ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name])->values()->all()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
            echo 'Vista previa: no se modificaron registros. Para aplicar: --aplicar --usuario=TU_ID'.PHP_EOL;
        } else {
            $idUsuario = $opciones['usuario'] ?? null;
            if (! is_string($idUsuario) || ! ctype_digit($idUsuario)) {
                throw new RuntimeException('Indica --usuario=TU_ID para registrar al ejecutor de la corrección.');
            }
            $usuario = User::query()->findOrFail((int) $idUsuario);
            $respaldo = $raiz.'/storage/app/respaldo_regularizacion_repa_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(4)).'.json';
            echo json_encode($servicio->aplicar($usuario, $respaldo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'Regularización detenida: '.$error->getMessage().PHP_EOL);
        exit(1);
    }
}
