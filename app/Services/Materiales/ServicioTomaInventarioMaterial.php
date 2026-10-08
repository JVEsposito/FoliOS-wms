<?php

namespace App\Services\Materiales;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\Posicion;
use App\Models\SaldoMaterialAlmacen;
use App\Models\TomaInventarioMaterial;
use App\Models\TomaInventarioMaterialPosicion;
use App\Models\TomaInventarioMaterialResultado;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Gerencia\ServicioPanelGerencial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServicioTomaInventarioMaterial
{
    public function supervisar(User $user): void
    {
        abort_unless($user->activo && in_array($user->rol, [RolUsuario::Administrador, RolUsuario::SupervisorMateriales], true), 403);
        abort_unless(app(AlcanceOperacionalUsuario::class)->puedeSupervisarCamara($user, ContenidoCamara::Materiales), 403);
    }

    public function crear(array $datos, User $user): TomaInventarioMaterial
    {
        $this->supervisar($user);
        $datos['camara_ids'] = collect($datos['camara_ids'])->unique()->sort()->values()->all();
        $hash = $this->hash($datos);

        return DB::transaction(function () use ($datos, $user, $hash) {
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            $previa = TomaInventarioMaterial::where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
            if ($previa) {
                if ($previa->payload_hash !== $hash || $previa->creada_por_user_id !== $user->id) {
                    throw new ConflictoOperacion('La operación ya existe con otros datos o responsable.');
                }

                return $previa;
            }
            $cantidad = Camara::whereIn('id', $datos['camara_ids'])->where('estado', 'activa')->where('contenido', 'materiales')->count();
            if ($cantidad !== count($datos['camara_ids'])) {
                throw ValidationException::withMessages(['camara_ids' => 'Selecciona cámaras de materiales activas.']);
            }

            return TomaInventarioMaterial::create(['temporada_id' => $temporada->id, 'camara_ids' => $datos['camara_ids'],
                'categoria' => $datos['categoria'] ?? null, 'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash,
                'creada_por_user_id' => $user->id, 'abierta_por_user_id' => $user->id, 'estado' => 'borrador', 'version' => 1, 'operaciones' => []]);
        }, 3);
    }

    public function operar(TomaInventarioMaterial $toma, string $accion, array $datos, User $user): TomaInventarioMaterial
    {
        $this->supervisar($user);

        return $this->mutar($toma, $accion, $datos, $user, function ($toma) use ($accion, $datos, $user) {
            if ($accion === 'abrir') {
                $this->abrir($toma, $datos['camarero_ids'], $user);
            } elseif ($accion === 'revisar') {
                $this->exigirEstado($toma, ['en_conteo']);
                if ($toma->posiciones()->where('estado', 'pendiente')->exists()) {
                    throw new ConflictoOperacion('Faltan posiciones por contar.');
                }
                $toma->fill(['estado' => 'en_revision', 'revisada_por_user_id' => $user->id, 'revisada_at' => now()]);
            } elseif ($accion === 'anular') {
                $this->exigirEstado($toma, ['borrador', 'en_conteo', 'en_revision']);
                $toma->fill(['estado' => 'anulada', 'anulada_at' => now(), 'motivo_anulacion' => $datos['motivo']]);
            } elseif ($accion === 'aprobar') {
                $this->aprobar($toma, $user);
            } else {
                throw new ConflictoOperacion('Acción desconocida.');
            }
        });
    }

    private function abrir(TomaInventarioMaterial $toma, array $usuarios, User $user): void
    {
        $this->exigirEstado($toma, ['borrador']);
        $usuarios = collect($usuarios)->unique()->sort()->values();
        $validos = User::whereIn('id', $usuarios)->where('activo', true)->where('rol', 'camarero_materiales')->get();
        if ($validos->count() !== $usuarios->count() || $usuarios->isEmpty()
            || $validos->contains(fn ($u) => ! app(AlcanceOperacionalUsuario::class)->puedeOperarCamara($u, ContenidoCamara::Materiales))) {
            throw ValidationException::withMessages(['camarero_ids' => 'Asigna camareros activos de materiales.']);
        }
        if (TomaInventarioMaterial::where('temporada_id', $toma->temporada_id)->whereIn('estado', ['en_conteo', 'en_revision'])
            ->get()->contains(fn ($otra) => count(array_intersect($otra->camara_ids, $toma->camara_ids)) > 0)) {
            throw new ConflictoOperacion('Una cámara seleccionada ya tiene una toma abierta.');
        }
        if (Camara::whereIn('id', $toma->camara_ids)->where('estado', 'activa')->where('contenido', 'materiales')->count() !== count($toma->camara_ids)) {
            throw new ConflictoOperacion('Una cámara del borrador ya no está activa para materiales.');
        }
        $saldos = $this->saldosAlcance($toma)->get();
        $this->bloquearFolios($saldos->pluck('folio_id')->all());
        $saldos = $this->saldosAlcance($toma)->orderBy('folio_id')->lockForUpdate()->get();
        $posiciones = Posicion::whereIn('camara_id', $toma->camara_ids)->where('estado', 'activa')
            ->whereHas('camara', fn ($q) => $q->whereColumn('posiciones.banda', '<=', 'camaras.cantidad_bandas')->whereColumn('posiciones.posicion', '<=', 'camaras.posiciones_por_banda')->whereColumn('posiciones.nivel', '<=', 'camaras.cantidad_niveles'))
            ->orderBy('id')->lockForUpdate()->get();
        if ($posiciones->isEmpty()) {
            throw new ConflictoOperacion('El alcance no contiene posiciones activas.');
        }
        if ($saldos->contains(fn ($s) => ! $posiciones->contains('id', $s->posicion_id))) {
            throw new ConflictoOperacion('Hay saldos sin una posición activa dentro del plano. Regulariza su ubicación antes de abrir la toma.');
        }
        // Se bloquean por id, pero se asignan sectores siguiendo el plano.
        $posiciones = $posiciones->sortBy([['camara_id', 'asc'], ['banda', 'asc'], ['posicion', 'asc'], ['nivel', 'asc']])->values();
        $porUsuario = intdiv($posiciones->count(), $usuarios->count());
        $restantes = $posiciones->count() % $usuarios->count();
        $inicio = 0;
        foreach ($usuarios as $indice => $usuario) {
            $cantidad = $porUsuario + ($indice < $restantes ? 1 : 0);
            foreach ($posiciones->slice($inicio, $cantidad) as $posicion) {
                $toma->posiciones()->create(['posicion_id' => $posicion->id, 'user_id' => $usuario, 'estado' => 'pendiente', 'version' => 1]);
            }
            $inicio += $cantidad;
        }
        $toma->fill(['estado' => 'en_conteo', 'abierta_por_user_id' => $user->id, 'abierta_at' => now(), 'foto' => $saldos->map(fn ($s) => [
            'saldo_id' => $s->id, 'folio_id' => $s->folio_id, 'almacen_id' => $s->almacen_material_id,
            'camara_id' => $s->camara_id, 'posicion_id' => $s->posicion_id,
            'cantidad' => $s->cantidad_actual, 'version' => $s->version,
        ])->all()]);
    }

    public function contar(TomaInventarioMaterialPosicion $tarea, array $datos, User $user): TomaInventarioMaterial
    {
        abort_unless($user->activo && $user->rol === RolUsuario::CamareroMateriales, 403);
        abort_unless($tarea->user_id === $user->id, 403);
        $lecturas = collect($datos['folios'] ?? [])->map(fn ($l) => [
            'numero_folio' => mb_strtoupper(trim($l['numero_folio'])), 'cantidad_contada' => round((float) $l['cantidad_contada'], 3),
        ])->sortBy('numero_folio')->values();
        if ((bool) ($datos['vacia'] ?? false) !== $lecturas->isEmpty() || $lecturas->pluck('numero_folio')->unique()->count() !== $lecturas->count()) {
            throw ValidationException::withMessages(['folios' => 'Escanea cada folio una vez o marca la posición vacía.']);
        }
        $datos['folios'] = $lecturas->all();

        return $this->mutar($tarea->toma, 'contar:'.$tarea->id, $datos, $user, function ($toma) use ($tarea, $datos, $lecturas, $user) {
            $this->exigirEstado($toma, ['en_conteo']);
            $tarea = TomaInventarioMaterialPosicion::whereKey($tarea->id)->lockForUpdate()->firstOrFail();
            if ($tarea->version !== (int) $datos['posicion_version'] || $tarea->estado !== 'pendiente' || $tarea->user_id !== $user->id) {
                throw new ConflictoOperacion('La posición ya se contó o cambió de versión.');
            }
            $posicion = $tarea->posicion;
            $folios = Folio::where('temporada_id', $toma->temporada_id)->where('activo', true)->whereHas('material')
                ->whereIn('numero_folio', $lecturas->pluck('numero_folio'))->get()->keyBy('numero_folio');
            $saldos = $this->saldosAlcance($toma)->where('posicion_id', $posicion->id)->get();
            $this->bloquearFolios($saldos->pluck('folio_id')->merge($folios->pluck('id'))->unique()->all());
            $saldos = $this->saldosAlcance($toma)->where('posicion_id', $posicion->id)->orderBy('folio_id')->lockForUpdate()->get();
            Posicion::whereKey($posicion->id)->lockForUpdate()->firstOrFail();
            $tarea->resultados()->where('vigente', true)->update(['vigente' => false]);
            $restantes = $lecturas->keyBy('numero_folio');
            foreach ($saldos as $saldo) {
                $folio = Folio::findOrFail($saldo->folio_id);
                $leida = $restantes->pull($folio->numero_folio);
                $this->resultado($toma, $tarea, $folio, $saldo, $leida, $saldo->cantidad_actual);
            }
            foreach ($restantes as $numero => $lectura) {
                $folio = $folios->get($numero);
                if ($folio && $toma->categoria && $folio->material->item->categoria !== $toma->categoria) {
                    continue; // Un material ajeno a la categoría no es una diferencia del alcance.
                }
                // El sobrante se enlaza a su saldo real; no al total distribuido del folio.
                $opciones = $folio ? SaldoMaterialAlmacen::where('folio_id', $folio->id)->whereNotNull('camara_id')->whereNotNull('posicion_id')
                    ->where('cantidad_actual', '>', 0)->orderBy('id')->lockForUpdate()->get() : collect();
                $saldo = $opciones->count() === 1 ? $opciones->first() : null;
                $this->resultado($toma, $tarea, $folio, $saldo, $lectura, 0, $numero);
            }
            $historial = $tarea->lecturas ?? [];
            $historial[] = ['user_id' => $user->id, 'fecha' => now()->toAtomString(), 'version' => $tarea->version, 'folios' => $lecturas->all(), 'vacia' => $lecturas->isEmpty()];
            $tarea->update(['estado' => 'contada', 'contada_at' => now(), 'version' => $tarea->version + 1, 'lecturas' => $historial]);
        });
    }

    private function resultado($toma, $tarea, ?Folio $folio, ?SaldoMaterialAlmacen $saldo, ?array $lectura, $esperada, ?string $numero = null): void
    {
        $contada = (float) ($lectura['cantidad_contada'] ?? 0);
        $esperada = (float) $esperada;
        $diferencia = round($contada - $esperada, 3);
        $tipo = ! $lectura ? 'faltante' : ($esperada <= 0 ? 'sobrante' : (abs($diferencia) < 0.0005 ? 'coincide' : 'diferencia_cantidad'));
        $inicial = collect($toma->foto)->first(fn ($f) => $f['saldo_id'] === $saldo?->id && $f['posicion_id'] === $tarea->posicion_id);
        $material = $folio?->material;
        $movimientos = $saldo ? DB::table('movimientos_almacenes_materiales')->where('folio_id', $saldo->folio_id)
            ->where('ocurrido_at', '>=', $toma->abierta_at)->where(fn ($q) => $q->where('almacen_origen_id', $saldo->almacen_material_id)->orWhere('almacen_destino_id', $saldo->almacen_material_id))
            ->orderBy('ocurrido_at')->lockForUpdate()->pluck('id')->all() : [];
        $tarea->resultados()->create(['lectura_version' => $tarea->version, 'vigente' => true,
            'folio_id' => $folio?->id, 'numero_folio' => $numero ?? $folio->numero_folio, 'saldo_id' => $saldo?->id,
            'item_material_id' => $material?->item_material_id, 'unidad_medida' => $material?->item?->unidad_medida,
            'cantidad_inicial' => $inicial['cantidad'] ?? 0, 'cantidad_esperada' => $esperada, 'cantidad_contada' => $contada,
            'diferencia' => $diferencia, 'diferencia_pct' => $esperada > 0 ? round($diferencia / $esperada * 100, 3) : null,
            'tipo' => $tipo, 'movimientos' => $movimientos, 'saldo_confirmado' => $saldo ? ['camara_id' => $saldo->camara_id, 'posicion_id' => $saldo->posicion_id, 'almacen_id' => $saldo->almacen_material_id, 'version' => $saldo->version, 'cantidad' => (float) $saldo->cantidad_actual] : null]);
    }

    public function decidir(TomaInventarioMaterialResultado $resultado, array $datos, User $user): TomaInventarioMaterial
    {
        $this->supervisar($user);

        return $this->mutar($resultado->tarea->toma, 'decidir:'.$resultado->id, $datos, $user, function ($toma) use ($resultado, $datos) {
            $this->exigirEstado($toma, ['en_revision']);
            $resultado = TomaInventarioMaterialResultado::whereKey($resultado->id)->lockForUpdate()->firstOrFail();
            if (! $resultado->vigente || $resultado->tipo === 'coincide') {
                throw new ConflictoOperacion('La diferencia ya fue recontada o el folio coincide.');
            }
            if ($datos['accion'] === 'recontar') {
                $tarea = $resultado->tarea;
                $tarea->resultados()->where('vigente', true)->update(['vigente' => false]);
                $tarea->update(['estado' => 'pendiente', 'version' => $tarea->version + 1]);
                $toma->estado = 'en_conteo';

                return;
            }
            if (in_array($datos['accion'], ['ajustar', 'reubicar', 'reubicar_y_ajustar'], true) && (! $resultado->folio_id || ! $resultado->saldo_id)) {
                throw ValidationException::withMessages(['accion' => 'El número desconocido requiere recontar o aceptar sin ajuste; identifica primero el folio real.']);
            }
            if ($datos['accion'] === 'ajustar' && $resultado->tipo === 'sobrante' && $resultado->saldo_confirmado['posicion_id'] !== $resultado->tarea->posicion_id) {
                throw ValidationException::withMessages(['accion' => 'El folio tiene saldo en otra posición. Reubícalo o solicita recontar.']);
            }
            if ($datos['accion'] === 'reubicar_y_ajustar') {
                $this->diferenciaReubicacion($resultado);
                if ($datos['posicion_destino_id'] !== $resultado->tarea->posicion_id) {
                    throw ValidationException::withMessages(['posicion_destino_id' => 'Reubica a la posición donde se contó el folio.']);
                }
            }
            $resultado->update(['accion' => $datos['accion'], 'motivo' => $datos['motivo'], 'posicion_destino_id' => $datos['posicion_destino_id'] ?? null]);
        });
    }

    private function aprobar(TomaInventarioMaterial $toma, User $user): void
    {
        $this->exigirEstado($toma, ['en_revision']);
        $resultados = TomaInventarioMaterialResultado::whereHas('tarea', fn ($q) => $q->where('toma_id', $toma->id))
            ->where('vigente', true)->orderBy('folio_id')->orderBy('id')->lockForUpdate()->get();
        if ($resultados->contains(fn ($r) => $r->tipo !== 'coincide' && ! $r->accion)) {
            throw new ConflictoOperacion('Elige una acción para cada diferencia antes de aprobar.');
        }
        $acciones = $resultados->whereIn('accion', ['ajustar', 'reubicar', 'reubicar_y_ajustar']);
        if ($acciones->pluck('saldo_id')->unique()->count() !== $acciones->count()) {
            throw new ConflictoOperacion('Un mismo saldo no puede recibir dos correcciones. Resuelve el sobrante con reubicar o reubicar y ajustar, y acepta el faltante sin ajuste.');
        }
        $this->bloquearFolios($acciones->pluck('folio_id')->all());
        foreach ($acciones as $resultado) {
            $saldo = SaldoMaterialAlmacen::whereKey($resultado->saldo_id)->lockForUpdate()->firstOrFail();
            $foto = $resultado->saldo_confirmado;
            if ($saldo->camara_id !== $foto['camara_id'] || $saldo->posicion_id !== $foto['posicion_id'] || $saldo->almacen_material_id !== $foto['almacen_id']) {
                throw new ConflictoOperacion('Un folio se movió de posición desde el conteo. Solicita recontar.');
            }
            $cantidad = $resultado->accion === 'reubicar_y_ajustar'
                ? $this->diferenciaReubicacion($resultado) : (float) $resultado->diferencia;
            if (in_array($resultado->accion, ['reubicar', 'reubicar_y_ajustar'], true)) {
                $this->reubicar($resultado, $saldo, $user);
            }
            if (in_array($resultado->accion, ['ajustar', 'reubicar_y_ajustar'], true)) {
                $movimiento = app(ServicioMovimientoAlmacenMaterial::class)->registrar([
                    'operacion_id' => (string) Str::uuid(), 'tipo' => 'ajuste', 'folio_id' => $resultado->folio_id,
                    'almacen_origen_id' => $saldo->almacen_material_id, 'cantidad' => $cantidad,
                    'motivo' => $resultado->motivo, 'toma_inventario_id' => $toma->id,
                    'documento_relacionado' => 'Toma '.$toma->id,
                    'camara_destino_id' => $saldo->camara_id, 'posicion_destino_id' => $saldo->posicion_id,
                ], $user, null);
                $resultado->update(['movimiento_almacen_id' => $movimiento->id]);
            }
        }
        $toma->fill(['estado' => 'aprobada', 'aprobada_at' => now(), 'aprobada_por_user_id' => $user->id]);
        DB::afterCommit(fn () => app(ServicioPanelGerencial::class)->invalidar());
    }

    private function diferenciaReubicacion(TomaInventarioMaterialResultado $resultado): float
    {
        $foto = $resultado->saldo_confirmado;
        if ($resultado->tipo !== 'sobrante' || ! $resultado->saldo_id || ($foto['posicion_id'] ?? null) === $resultado->tarea->posicion_id) {
            throw ValidationException::withMessages(['accion' => 'Esta acción requiere un folio encontrado en otra posición con saldo identificado.']);
        }
        if (! isset($foto['cantidad'])) {
            throw ValidationException::withMessages(['accion' => 'Solicita recontar para guardar el saldo al contar antes de reubicar y ajustar.']);
        }
        $diferencia = round((float) $resultado->cantidad_contada - (float) $foto['cantidad'], 3);
        if (abs($diferencia) < 0.0005) {
            throw ValidationException::withMessages(['accion' => 'La cantidad coincide; elige reubicar sin ajuste.']);
        }

        return $diferencia;
    }

    private function reubicar(TomaInventarioMaterialResultado $resultado, SaldoMaterialAlmacen $saldo, User $user): void
    {
        $destino = Posicion::with('camara')->whereKey($resultado->posicion_destino_id)->lockForUpdate()->firstOrFail();
        if ($destino->estado->value !== 'activa' || $destino->camara->estado->value !== 'activa'
            || $destino->camara->contenido !== ContenidoCamara::Materiales || $destino->banda > $destino->camara->cantidad_bandas
            || $destino->posicion > $destino->camara->posiciones_por_banda || $destino->nivel > $destino->camara->cantidad_niveles) {
            throw new ConflictoOperacion('La posición destino no está habilitada para materiales.');
        }
        if ((float) $saldo->cantidad_reservada > 0 || $destino->reservaTareaActiva()->exists()
            || DB::table('custodias_temporales_maniobra')->where('folio_id', $saldo->folio_id)->where('estado', 'activa')->exists()
            || DB::table('reservas_materiales')->where('folio_id', $saldo->folio_id)->where('estado', 'activa')->exists()
            || DB::table('reservas_transformacion_materiales')->where('folio_id', $saldo->folio_id)->where('estado', 'activa')->exists()
            || DB::table('tareas_movimiento')->where('folio_id', $saldo->folio_id)->whereIn('estado', ['asumida', 'en_proceso'])->exists()) {
            throw new ConflictoOperacion('El folio o destino tiene una reserva o maniobra en curso.');
        }
        if ($saldo->cantidad_actual <= 0 || $saldo->posicion_id === $destino->id) {
            throw new ConflictoOperacion('El saldo está agotado o ya está en la posición destino.');
        }
        DB::table('reubicaciones_toma_inventario_materiales')->insert(['id' => (string) Str::uuid(), 'resultado_id' => $resultado->id,
            'saldo_id' => $saldo->id, 'posicion_origen_id' => $saldo->posicion_id, 'posicion_destino_id' => $destino->id,
            'cantidad' => $saldo->cantidad_actual, 'user_id' => $user->id, 'motivo' => $resultado->motivo, 'created_at' => now(), 'updated_at' => now()]);
        Camara::whereIn('id', [$saldo->camara_id, $destino->camara_id])->orderBy('id')->lockForUpdate()->get()->each(fn ($c) => $c->increment('version_plano'));
        $saldo->update(['camara_id' => $destino->camara_id, 'posicion_id' => $destino->id, 'version' => $saldo->version + 1]);
        app(ServicioAlmacenMaterial::class)->sincronizarProyeccion($saldo->folio_id);
    }

    private function saldosAlcance(TomaInventarioMaterial $toma)
    {
        return SaldoMaterialAlmacen::whereIn('camara_id', $toma->camara_ids)->where('cantidad_actual', '>', 0)
            ->whereHas('folioMaterial.folio', fn ($q) => $q->where('temporada_id', $toma->temporada_id)->where('activo', true))
            ->when($toma->categoria, fn ($q) => $q->whereHas('folioMaterial.item', fn ($i) => $i->where('categoria', $toma->categoria)));
    }

    private function bloquearFolios(array $ids): void
    {
        FolioMaterial::whereIn('folio_id', $ids)->orderBy('folio_id')->lockForUpdate()->get();
        Folio::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    private function exigirEstado($toma, array $estados): void
    {
        if (! in_array($toma->estado, $estados, true)) {
            throw new ConflictoOperacion('La toma no permite esta acción en su estado actual.');
        }
    }

    private function hash(array $datos): string
    {
        unset($datos['operacion_id'], $datos['version']);

        return hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
    }

    private function mutar(TomaInventarioMaterial $toma, string $accion, array $datos, User $user, callable $operacion): TomaInventarioMaterial
    {
        return DB::transaction(function () use ($toma, $accion, $datos, $user, $operacion) {
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            $toma = TomaInventarioMaterial::whereKey($toma->id)->lockForUpdate()->firstOrFail();
            if ($toma->temporada_id !== $temporada->id) {
                throw new ConflictoOperacion('La toma no pertenece a la temporada activa.');
            }
            $hash = $this->hash(['accion_operacion' => $accion, ...$datos]);
            $operaciones = $toma->operaciones ?? [];
            $anterior = $operaciones[$datos['operacion_id']] ?? null;
            if ($anterior) {
                if ($anterior['hash'] !== $hash || $anterior['user_id'] !== $user->id) {
                    throw new ConflictoOperacion('La operación repetida contiene otros datos.');
                }

                return $toma;
            }
            // El conteo controla la versión de su posición: camareros distintos
            // pueden confirmar posiciones sin invalidarse mutuamente.
            if (! str_starts_with($accion, 'contar:') && $toma->version !== (int) $datos['version']) {
                throw new ConflictoOperacion('La toma cambió de versión. Actualiza antes de continuar.');
            }
            $operacion($toma);
            $operaciones[$datos['operacion_id']] = ['hash' => $hash, 'user_id' => $user->id, 'accion' => $accion, 'fecha' => now()->toAtomString()];
            $toma->operaciones = $operaciones;
            $toma->version++;
            $toma->save();

            return $toma;
        }, 3);
    }
}
