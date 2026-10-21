<?php

namespace App\Rrhh\Nomina;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\Afiliacion;
use App\Rrhh\Models\CalculoLegal;
use App\Rrhh\Models\ContratoLaboral;
use App\Rrhh\Models\IndiceNomina;
use App\Rrhh\Models\Institucion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PreparacionLegal
{
    public function __construct(private EsquemaRrhh $esquema, private CalculosLegalesChile $motor, private Auditoria $auditoria) {}

    public function indice(array $d, User $actor): IndiceNomina
    {
        if (! $this->esquema->tomarCandado('indices-nomina')) {
            $this->error('version_anterior', 'Hay otra actualización de índices. Reintente.');
        }
        try {
            return $this->esquema->transaccion(function () use ($d, $actor) {
                $anterior = IndiceNomina::query()->where('mes', $d['mes'])->orderByDesc('version')->first();
                if (($anterior?->version ?? 0) !== (int) ($d['version_anterior'] ?? 0)) {
                    $this->error('version_anterior', 'Los índices cambiaron. Recargue y revise la versión anterior.');
                }
                if (! ReglasChile::decimal($d['uf'])->isGreaterThan(0) || ! ReglasChile::decimal($d['utm'])->isGreaterThan(0) || $d['fecha_uf'] !== CarbonImmutable::parse($d['mes'].'-01')->endOfMonth()->toDateString()) {
                    $this->error('fecha_uf', 'UF y UTM deben ser positivas; la UF previsional corresponde al último día del mes.');
                }
                $comisiones = [];
                foreach ($d['comisiones'] ?? [] as $c) {
                    $i = Institucion::query()->findOrFail($c['institucion_id']);
                    if ($i->tipo !== 'afp' || ! $i->activo || $i->desde > $d['mes'].'-01' || $i->hasta < $d['fecha_uf'] || ReglasChile::decimal($c['tasa'])->isGreaterThan(5)) {
                        $this->error('comisiones', 'Las comisiones deben corresponder a AFP activas y vigentes durante todo el mes.');
                    }
                    $comisiones[$i->id] = ['institucion' => $i->toArray(), 'tasa' => $c['tasa']];
                }
                unset($d['version_anterior']);
                $fila = IndiceNomina::query()->create(['id' => (string) Str::uuid(), ...$d, 'comisiones' => $comisiones, 'version' => ($anterior?->version ?? 0) + 1]);
                $this->auditoria->registrar('indice_nomina', $fila->id, 'creado', $fila->toArray(), $actor);

                return $fila;
            });
        } finally {
            $this->esquema->liberarCandado('indices-nomina');
        }
    }

    public function calcular(array $d): array
    {
        $a = $d['datos'];
        $p = empty($d['parametro_id']) ? null : IndiceNomina::query()->findOrFail($d['parametro_id']);
        if ($p && $p->mes !== $d['mes']) {
            $this->error('parametro_id', 'Los índices no corresponden al mes seleccionado.');
        }
        $contrato = empty($d['contrato_id']) ? null : ContratoLaboral::query()->findOrFail($d['contrato_id']);
        if ($contrato && $contrato->persona_id !== $d['persona_id']) {
            $this->error('contrato_id', 'El contrato no corresponde a la persona seleccionada.');
        }
        if ($contrato && $contrato->empleador['tipo'] !== 'propio') {
            $this->error('contrato_id', 'La remuneración de personal contratista pertenece a su empleador real. Use su expediente de cumplimiento.');
        }
        if ($contrato && (($d['tipo'] === 'honorarios') !== ($contrato->tipo === 'honorarios'))) {
            $this->error('contrato_id', 'No mezcle boletas de honorarios con contratos laborales dependientes.');
        }
        $r = match ($d['tipo']) {
            'anticipo50' => $this->motor->anticipo50($a['base_computable'], $a['imm_aplicable']),
            'anual50' => $this->motor->anual50($a['remuneraciones_computables'], $a['imm_diciembre'], $a['meses_computables'], $a['anticipos'] ?? []),
            'reparto47' => $this->motor->reparto47($a['utilidad_liquida'], $a['porcentaje_reparto'], $a['remuneracion_persona'], $a['remuneracion_total']),
            'impuesto' => $p ? $this->motor->impuesto($a['base_tributaria'], $p->utm, $p->mes) : $this->error('parametro_id', 'Seleccione índices respaldados para el mes tributario.'),
            'honorarios' => $this->motor->honorarios($a['bruto'], $d['mes'], $a['retiene_receptor']),
            'cotizaciones' => $this->cotizaciones($d, $contrato, $p),
        };

        return [...$r, 'parametros' => $p?->toArray(), 'contrato' => $contrato?->toArray(), 'preparacion' => true,
            'ejercicio_abierto' => in_array($d['tipo'], ['anual50', 'reparto47'], true) && substr($d['mes'], 0, 4) >= now()->format('Y'),
            'alcance' => 'Cálculo de apoyo sobre bases revisadas. No es una liquidación completa, declaración ni comprobante de pago.'];
    }

    public function guardar(array $d, User $actor): CalculoLegal
    {
        $hash = hash('sha256', json_encode($this->ordenar($d), JSON_THROW_ON_ERROR));
        $clave = 'calculo-legal-'.$d['operacion_id'];
        if (! $this->esquema->tomarCandado($clave)) {
            $this->error('operacion_id', 'El cálculo se está guardando. Reintente la misma solicitud.');
        }
        try {
            return $this->esquema->transaccion(function () use ($d, $hash, $actor) {
                $previo = CalculoLegal::query()->where('operacion_id', $d['operacion_id'])->first();
                if ($previo) {
                    if ($previo->payload_hash !== $hash) {
                        $this->error('operacion_id', 'El identificador corresponde a otro cálculo.');
                    }

                    return $previo;
                }
                $calculo = $this->calcular($d);
                $fila = CalculoLegal::query()->create(['id' => (string) Str::uuid(), 'operacion_id' => $d['operacion_id'], 'payload_hash' => $hash, 'tipo' => $d['tipo'], 'mes' => $d['mes'],
                    'persona_id' => $d['persona_id'], 'contrato_id' => $d['contrato_id'] ?? null, 'parametro_id' => $d['parametro_id'] ?? null, 'entrada' => $d, 'calculo' => $calculo]);
                $this->auditoria->registrar('calculo_legal', $fila->id, 'preparado', ['tipo' => $d['tipo'], 'mes' => $d['mes'], 'regla' => $calculo['regla']], $actor);

                return $fila;
            });
        } finally {
            $this->esquema->liberarCandado($clave);
        }
    }

    private function cotizaciones(array $d, ?ContratoLaboral $contrato, ?IndiceNomina $p): array
    {
        $inicio = $d['mes'].'-01';
        $fin = CarbonImmutable::parse($inicio)->endOfMonth()->toDateString();
        if (! $p || ! $contrato || $contrato->desde > $inicio || $contrato->hasta < $fin || $contrato->tipo === 'honorarios') {
            $this->error('contrato_id', 'Este cálculo requiere índices del período y un contrato laboral propio que cubra el mes completo.');
        }
        if (ContratoLaboral::query()->where('persona_id', $contrato->persona_id)->whereKeyNot($contrato->id)->where('tipo', '!=', 'honorarios')->where('desde', '<=', $fin)->where('hasta', '>=', $inicio)->exists()) {
            $this->error('contrato_id', 'Pluriempleo, cambios de contrato y meses parciales requieren revisión particular de sus topes.');
        }
        $afp = $this->afiliacion('persona_id', $contrato->persona_id, 'afp', $inicio, $fin, true);
        $salud = $this->afiliacion('persona_id', $contrato->persona_id, 'salud', $inicio, $fin, true);
        $ccaf = $this->afiliacion('empleador_id', $contrato->empleador_id, 'ccaf', $inicio, $fin, false);
        $accidentes = $this->afiliacion('empleador_id', $contrato->empleador_id, 'accidentes', $inicio, $fin, true);
        $comision = $p->comisiones[$afp->institucion_id]['tasa'] ?? null;
        if ($comision === null) {
            $this->error('parametro_id', 'Falta la comisión respaldada de la AFP afiliada para este mes.');
        }
        $plan = $salud->precio_plan ? ReglasChile::decimal($salud->precio_plan) : ReglasChile::decimal(0);
        if ($salud->moneda_plan === 'UF') {
            $plan = $plan->multipliedBy($p->uf);
        }
        $datos = [...$d['datos'], 'comision_afp' => $comision, 'tipo_contrato' => $contrato->tipo, 'tipo_salud' => $salud->institucion['tipo'], 'distribuir_ccaf' => $ccaf !== null, 'plan_salud_clp' => ReglasChile::pesos($plan)];
        $r = $this->motor->cotizaciones($datos, $p->toArray());
        $instituciones = ['afp' => $afp->institucion, 'salud' => $salud->institucion, 'ccaf' => $ccaf?->institucion, 'accidentes' => $accidentes->institucion];
        foreach (['afc', 'fapp'] as $tipo) {
            $id = $d['datos'][$tipo.'_id'] ?? null;
            $i = $id ? Institucion::query()->findOrFail($id) : null;
            if ($i && ($i->tipo !== $tipo || ! $i->activo || $i->desde > $inicio || $i->hasta < $fin)) {
                $this->error($tipo.'_id', 'El destinatario debe tener el tipo y vigencia correspondientes.');
            }
            $instituciones[$tipo] = $i?->toArray();
        }
        foreach ($r['lineas'] as &$linea) {
            $linea['institucion'] = $instituciones[$linea['destino']] ?? null;
        }
        unset($linea);

        return [...$r, 'afiliaciones' => ['afp' => $afp->toArray(), 'salud' => $salud->toArray(), 'ccaf' => $ccaf?->toArray(), 'accidentes' => $accidentes->toArray()]];
    }

    private function afiliacion(string $campo, string $id, string $regimen, string $inicio, string $fin, bool $requerida): ?Afiliacion
    {
        $filas = Afiliacion::query()->where($campo, $id)->where('regimen', $regimen)->where('desde', '<=', $fin)->where('hasta', '>=', $inicio)->get();
        if ($filas->isEmpty() && ! $requerida) {
            return null;
        }
        if ($filas->count() !== 1 || $filas[0]->desde > $inicio || $filas[0]->hasta < $fin) {
            $this->error('afiliaciones', 'Falta afiliación de '.$regimen.' para el mes completo o cambió dentro del mes. Revise ese caso antes de calcular.');
        }

        return $filas[0];
    }

    private function ordenar(array $d): array
    {
        if (! array_is_list($d)) {
            ksort($d);
        }

        return array_map(fn ($v) => is_array($v) ? $this->ordenar($v) : $v, $d);
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
