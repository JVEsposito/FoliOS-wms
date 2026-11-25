<?php

declare(strict_types=1);

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\CustodiaTemporalManiobra;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\InspeccionSag\ServicioExistenciasSag;
use App\Services\Planificador\ServicioDepuracionIngresosCamara;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Regularización de los catorce folios confirmados agotados el 25/11/2026.
 * No cambia validaciones, repas ni despachos. Sin argumentos: solo vista previa.
 */
final class RegularizacionCajasRepa20261124
{
    public const OPERACION = 'fe5bfe32-5e64-4b2d-a94f-f02411240002';

    public const TEMPORADA = '01a05e0d-de81-723a-b116-a91569028ff8';

    public const PASOS = [
        '6000000529' => ['id' => '01a1b8c3-6733-7360-ac9f-d4e0b1bf6ba6', 'antes' => 0, 'despues' => 0, 'activo' => false, 'estado' => 'agotado', 'actualizado' => '2026-11-11 21:13:31', 'componentes' => 0, 'ubicado' => false],
        '6000000530' => ['id' => '01a1b8c6-8ce8-71ad-b7cf-ae15053bc2ae', 'antes' => 0, 'despues' => 0, 'activo' => false, 'estado' => 'agotado', 'actualizado' => '2026-11-24 19:08:26', 'componentes' => 0, 'ubicado' => false],
        '6000000729' => ['id' => '01a1c0a6-27a1-72aa-a95b-64430c0def67', 'antes' => 131, 'despues' => 0, 'activo' => true, 'estado' => 'pendiente_ubicacion', 'actualizado' => '2026-11-24 16:22:12', 'componentes' => 1, 'ubicado' => false],
        '6000001053' => ['id' => '01a1c819-106c-7132-8b70-34e6ab2bed55', 'antes' => 43, 'despues' => 0, 'activo' => true, 'estado' => 'pendiente_ubicacion', 'actualizado' => '2026-11-12 12:38:40', 'componentes' => 3, 'ubicado' => false],
        '6000002215' => ['id' => '01a1db6c-20dc-725a-91bc-d8e64c965048', 'antes' => 27, 'despues' => 0, 'activo' => true, 'estado' => 'pendiente_ubicacion', 'actualizado' => '2026-11-24 16:32:18', 'componentes' => 1, 'ubicado' => false],
        '6000003100' => ['id' => '01a1eb4e-246f-723c-8be1-4d97788b30ca', 'antes' => 41, 'despues' => 0, 'activo' => true, 'estado' => 'disponible', 'actualizado' => '2026-11-24 08:24:24', 'componentes' => 1, 'ubicado' => true],
        '6000003213' => ['id' => '01a1e9e4-86c9-7317-a293-728937422501', 'antes' => 0, 'despues' => 0, 'activo' => false, 'estado' => 'agotado', 'actualizado' => '2026-11-24 21:15:27', 'componentes' => 0, 'ubicado' => false],
        '6000003215' => ['id' => '01a1e9e1-dbee-711c-af13-3a52ab8dcbc5', 'antes' => 32, 'despues' => 0, 'activo' => true, 'estado' => 'pendiente_ubicacion', 'actualizado' => '2026-11-19 17:50:28', 'componentes' => 1, 'ubicado' => false],
        '6000005028' => ['id' => '01a202f4-ecb3-73a0-bc65-cd284e4db52f', 'antes' => 82, 'despues' => 0, 'activo' => true, 'estado' => 'pendiente_ubicacion', 'actualizado' => '2026-11-24 20:30:25', 'componentes' => 2, 'ubicado' => false],
        '6000005121' => ['id' => '01a2032b-77aa-73be-8509-e3e844674677', 'antes' => 60, 'despues' => 0, 'activo' => false, 'estado' => 'agotado', 'actualizado' => '2026-11-23 11:27:13', 'componentes' => 4, 'ubicado' => false],
        '6000005124' => ['id' => '01a2032c-db9d-71d5-9911-c2d743f7921a', 'antes' => 49, 'despues' => 0, 'activo' => false, 'estado' => 'agotado', 'actualizado' => '2026-11-23 11:25:46', 'componentes' => 4, 'ubicado' => false],
        '6000005151' => ['id' => '01a20303-0f6e-7286-98b3-c3dc91946f86', 'antes' => 20, 'despues' => 0, 'activo' => false, 'estado' => 'agotado', 'actualizado' => '2026-11-23 12:05:25', 'componentes' => 1, 'ubicado' => false],
        '6000005153' => ['id' => '01a20305-1e7f-70d8-b80e-d26ac03b54d3', 'antes' => 56, 'despues' => 0, 'activo' => true, 'estado' => 'pendiente_ubicacion', 'actualizado' => '2026-11-23 18:53:26', 'componentes' => 2, 'ubicado' => false],
        '6100004254' => ['id' => '01a1febe-894c-70fd-9b7b-e32327e0b85b', 'antes' => 2, 'despues' => 0, 'activo' => true, 'estado' => 'pendiente_ubicacion', 'actualizado' => '2026-11-21 15:34:22', 'componentes' => 1, 'ubicado' => false],
    ];

    public function __construct(private readonly string $temporadaEsperada = self::TEMPORADA) {}

    private function validar(Folio $folio, string $numero, array $paso, bool $bloquear = false): array
    {
        $existe = static function ($consulta) use ($bloquear): bool {
            if ($bloquear) {
                $consulta->lockForUpdate();
            }

            return $consulta->first() !== null;
        };
        $cantidad = $folio->datos_externos['cantidad_cajas'] ?? null;
        if ($folio->id !== $paso['id'] || $folio->numero_folio !== $numero
            || $folio->temporada_id !== $this->temporadaEsperada
            || ! is_numeric($cantidad) || (float) $cantidad !== (float) $paso['antes']
            || $folio->activo !== $paso['activo'] || $folio->estado_operacional?->value !== $paso['estado']
            || $folio->tipo_bulto !== TipoBulto::Saldo
            || $folio->updated_at?->utc()->format('Y-m-d H:i:s') !== $paso['actualizado']) {
            throw new ConflictoOperacion("El folio {$numero} cambió respecto de la auditoría. No se aplicó el ajuste.");
        }
        if ($existe($folio->asignacionCargaActual()) || $existe($folio->reservaCargaActual())) {
            throw new ConflictoOperacion("El folio {$numero} tiene una carga o reserva vigente.");
        }
        $ubicacion = $folio->ubicacionActual;
        if (($ubicacion !== null) !== $paso['ubicado']) {
            throw new ConflictoOperacion("La ubicación de {$numero} cambió respecto de la consulta.");
        }
        if ($paso['antes'] > 0) {
            if ($existe($folio->tareasMovimiento()->whereIn('estado', ['asumida', 'en_proceso']))
                || $existe(CustodiaTemporalManiobra::query()->where('folio_id', $folio->id)->where('estado', 'activa'))) {
                throw new ConflictoOperacion("El folio {$numero} tiene una maniobra o custodia en curso.");
            }
            if ($existe($folio->procesosPrefrio()->where(fn ($q) => $q->whereIn('estado', ['cargado', 'en_proceso'])
                ->orWhere(fn ($pendiente) => $pendiente->pendienteRetiroParcial())))) {
                throw new ConflictoOperacion("El folio {$numero} participa en prefrío activo o tiene un retiro pendiente.");
            }
            if ($existe($folio->movimientos()->where('created_at', '>', $paso['actualizado']))) {
                throw new ConflictoOperacion("El folio {$numero} tiene movimientos posteriores a la consulta.");
            }
        }
        if ($ubicacion !== null) {
            if (! $ubicacion->camara_id || ! $ubicacion->camara) {
                throw new ConflictoOperacion("La ubicación de {$numero} no identifica una cámara válida.");
            }
            $posicion = $ubicacion->posicion;
            if ($posicion && ($existe($posicion->reservaTareaActiva()) || $existe($posicion->reservaPreparacionSagActiva()))) {
                throw new ConflictoOperacion("La posición de {$numero} tiene una reserva operacional vigente.");
            }
        }
        $datos = $folio->datos_externos;
        $composicion = collect($datos['composicion'] ?? []);
        if ($composicion->contains(fn ($linea) => ! is_array($linea)
                || ! isset($linea['cantidad_cajas']) || ! is_numeric($linea['cantidad_cajas'])
                || (float) $linea['cantidad_cajas'] !== (float) (int) $linea['cantidad_cajas']
                || (int) $linea['cantidad_cajas'] < 0)
            || $composicion->count() !== $paso['componentes']
            || (int) $composicion->sum('cantidad_cajas') !== $paso['antes']) {
            throw new ConflictoOperacion("La composición de {$numero} no coincide con sus cajas. Requiere revisión.");
        }
        $datos['cantidad_cajas'] = 0;
        $datos['composicion'] = [];
        $datos['csgs'] = [];
        $datos['fechas_embalaje'] = [];

        return $datos;
    }

    public function previsualizar(): array
    {
        $salida = [];
        foreach (self::PASOS as $numero => $paso) {
            $folio = Folio::query()->findOrFail($paso['id']);
            $this->validar($folio, (string) $numero, $paso);
            $ubicacion = $folio->ubicacionActual;
            $salida[] = ['folio' => (string) $numero, 'antes' => $paso['antes'], 'despues' => 0,
                'accion' => $paso['antes'] === 0 ? 'ya_agotado_sin_cambios' : 'agotar',
                'ubicacion_a_liberar' => $ubicacion ? ['camara' => $ubicacion->camara?->codigo,
                    'posicion' => $ubicacion->posicion?->etiqueta] : null];
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
            tipo: 'folios.regularizar_saldos',
            usuario: $usuario,
            payload: ['pasos' => self::PASOS, 'temporada' => $this->temporadaEsperada],
            operacionId: self::OPERACION,
            referencia: 'Regularización de catorce saldos agotados, 25/11/2026',
        );

        return app(MotorTransicionesOperacionales::class)->ejecutar($comando, function () use ($usuario, $respaldo): array {
            $ids = array_column(self::PASOS, 'id');
            $pasosConCambio = array_filter(self::PASOS, fn ($paso) => $paso['antes'] > 0);
            $idsConCambio = array_column($pasosConCambio, 'id');
            // Orden de bloqueo de la tablet: cámaras, folios, posiciones y ocupación vigente.
            $ubicacionesIniciales = UbicacionActual::query()->whereIn('folio_id', $ids)->get()->keyBy('folio_id');
            $camaras = Camara::query()->whereIn('id', $ubicacionesIniciales->pluck('camara_id')->filter()->unique())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $folios = Folio::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($folios->count() !== count(self::PASOS)) {
                throw new ConflictoOperacion('Falta uno de los folios del informe.');
            }
            $aplicados = $folios->only($idsConCambio)->filter(fn ($f) => data_get($f->datos_externos, 'regularizacion_saldos.operacion_id') === self::OPERACION);
            if ($aplicados->count() === count($pasosConCambio)) {
                return ['ya_aplicado' => true, 'operacion_id' => self::OPERACION];
            }
            if ($aplicados->isNotEmpty()) {
                throw new ConflictoOperacion('Hay marcas parciales de regularización. Revisa la auditoría antes de continuar.');
            }
            $temporada = app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido();
            if ($temporada?->id !== $this->temporadaEsperada) {
                throw new ConflictoOperacion('La temporada auditada ya no está activa.');
            }
            Posicion::query()->whereIn('id', $ubicacionesIniciales->pluck('posicion_id')->filter()->unique())
                ->orderBy('id')->lockForUpdate()->get();
            $ubicaciones = UbicacionActual::query()->whereIn('folio_id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('folio_id');
            if ($ubicaciones->map(fn ($u) => $u->getAttributes())->sortKeys()->all()
                !== $ubicacionesIniciales->map(fn ($u) => $u->getAttributes())->sortKeys()->all()) {
                throw new ConflictoOperacion('Una ubicación cambió durante la regularización. No se aplicó el ajuste.');
            }
            $nuevos = [];
            foreach (self::PASOS as $numero => $paso) {
                $folios->get($paso['id'])->setRelation('ubicacionActual', $ubicaciones->get($paso['id']));
                $nuevos[$paso['id']] = $this->validar($folios->get($paso['id']), (string) $numero, $paso, true);
            }
            $copia = ['operacion_id' => self::OPERACION, 'usuario_id' => $usuario->id, 'generado_utc' => now()->utc()->toAtomString(),
                'advertencia' => 'Respaldo previo al intento. La transición operacional confirma si se aplicó.',
                'folios_antes' => $folios->map(fn ($f) => $f->getAttributes())->values()->all(),
                'ubicaciones_antes' => $ubicaciones->map(fn ($u) => $u->getAttributes())->values()->all(),
                'camaras_antes' => $camaras->map(fn ($c) => $c->only(['id', 'codigo', 'version_plano']))->values()->all()];
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
                if ($paso['antes'] === 0) {
                    $resultado[] = ['folio' => (string) $numero, 'antes' => 0, 'despues' => 0, 'sin_cambios' => true];

                    continue;
                }
                $ubicacion = $ubicaciones->get($folio->id);
                if ($ubicacion !== null) {
                    $ubicacion->delete();
                    $camaras->get($ubicacion->camara_id)->increment('version_plano');
                }
                $datos = $nuevos[$folio->id];
                $datos['regularizacion_saldos'] = [
                    'operacion_id' => self::OPERACION, 'usuario_id' => $usuario->id,
                    'fecha_utc' => now()->utc()->toAtomString(), 'antes' => $paso['antes'], 'despues' => $paso['despues'],
                    'motivo' => 'Agotamiento confirmado tras consumo en repaletizajes y regularización de cantidades residuales.',
                    'respaldo' => basename($respaldo),
                ];
                $folio->update([
                    'datos_externos' => $datos,
                    'activo' => false, 'tipo_bulto' => TipoBulto::Saldo, 'estado_operacional' => EstadoOperacionalFolio::Agotado,
                ]);
                $resultado[] = ['folio' => (string) $numero, 'antes' => $paso['antes'], 'despues' => $paso['despues']];
            }
            app(ServicioExistenciasSag::class)->sincronizar($idsConCambio, 'regularizacion_saldos', self::OPERACION);
            foreach ($pasosConCambio as $paso) {
                app(ServicioDepuracionIngresosCamara::class)->depurarFolio($paso['id'], $usuario);
            }

            return ['aplicado' => true, 'operacion_id' => self::OPERACION, 'respaldo' => $respaldo, 'folios' => $resultado];
        });
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
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
