<?php

namespace Tests\Support;

use App\Enums\RolUsuario;
use App\Models\ArticuloValidacion;
use App\Models\CalibreValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\CondicionSag;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\OrigenValidacion;
use App\Models\UmbralPrefrioEspecie;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait PreparaRecepcionFrutaEmbalada
{
    protected User $usuario;

    protected string $token;

    protected string $recepcion;

    protected array $pallet;

    protected EspecieValidacion $especie;

    protected function prepararRecepcionFrutaEmbalada(): void
    {
        config(['planificador.mode' => 'guided', 'planificador.compute' => 'tablet', 'planificador.horizon' => 'rolling', 'planificador.generacion_automatica' => true]);
        $temporada = $this->crearTemporadaActivaPrueba(['codigo' => 'EXTERNA', 'nombre' => 'Fruta embalada']);
        $this->usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->token = $this->usuario->createToken('oficina', ['oficina'])->plainTextToken;
        $global = Cliente::create(['codigo' => 'EXT', 'nombre' => 'EXPORTADORA', 'activo' => true]);
        $cliente = ClienteValidacion::create(['temporada_id' => $temporada->id, 'cliente_id' => $global->id, 'nombre' => 'EXPORTADORA', 'activo' => true]);
        $this->especie = EspecieValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'Uva', 'activo' => true]);
        UmbralPrefrioEspecie::create(['especie' => 'UVA', 'temperatura_maxima_c' => 2]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $this->especie->id, 'nombre' => 'Thompson', 'activo' => true]);
        $envase = EnvaseValidacion::create(['especie_validacion_id' => $this->especie->id, 'cliente_validacion_id' => $cliente->id, 'nombre' => 'Caja 9 kg', 'codigo_externo' => 'RGEN90BAM', 'kilos_netos_por_caja' => 9, 'activo' => true]);
        $calibre = CalibreValidacion::create(['especie_validacion_id' => $this->especie->id, 'nombre' => 'XL', 'activo' => true]);
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => '105410', 'activo' => true]);
        $csg->variedades()->attach($variedad);
        $sag = CondicionSag::create(['codigo' => 'EX-SAG', 'nombre' => 'Aprobado', 'activo' => true]);
        $planta = (string) Str::uuid();
        DB::table('plantas_origen')->insert(['id' => $planta, 'nombre' => 'Planta de origen', 'codigo' => 'PORI', 'activa' => true]);
        $origen = OrigenValidacion::create(['temporada_id' => $temporada->id, 'cliente_validacion_id' => $cliente->id, 'csg_validacion_id' => $csg->id, 'cliente' => $cliente->nombre, 'marca' => 'EXTERNO', 'csg' => $csg->codigo, 'activo' => true]);
        $articulo = ArticuloValidacion::create(['temporada_id' => $temporada->id, 'cliente_validacion_id' => $cliente->id, 'especie_validacion_id' => $this->especie->id, 'variedad_validacion_id' => $variedad->id, 'envase_validacion_id' => $envase->id, 'calibre_validacion_id' => $calibre->id, 'especie' => 'Uva', 'variedad' => 'Thompson', 'envase' => 'Caja 9 kg', 'calibre' => 'XL', 'activo' => true]);
        DB::table('combinaciones_validacion')->insert(['id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen->id, 'activo' => true]);
        $pallet = ['folio_origen' => 'EXT00001', 'tipo_bulto' => 'pallet', 'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen->id,
            'csp' => 'CSP-123', 'cantidad_cajas' => 77, 'fecha_proceso_origen' => '2026-02-06', 'temperatura_pulpa_c' => 1, 'condicion_sag_personalizada' => true, 'condicion_sag_id' => $sag->id];
        $this->recepcion = $this->withToken($this->token)->postJson('/api/recepciones-fruta-embalada', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $global->id, 'planta_origen_id' => $planta,
            'numero_guia' => 'GUIA-100', 'servicio' => 'almacenaje', 'turno' => 'A', 'validador_id' => $this->usuario->id,
            'recepcion_at' => now()->toISOString(), 'salida_at' => null, 'chofer' => 'Chofer externo', 'rut_chofer' => null,
            'patente_delantera' => 'ABCD12', 'patente_carro' => null, 'llega_con_prefrio' => true, 'condicion_sag_id' => null,
            'pallets' => [$pallet],
        ])->assertCreated()->json('data.id');
        $this->pallet = (array) DB::table('recepciones_fruta_embalada_pallets')->where('recepcion_fruta_embalada_id', $this->recepcion)->first();
    }

    protected function payload(): array
    {
        $version = $this->withToken($this->token)->getJson($this->ruta('aceptacion'))->assertOk()->json('data.revision.version');

        return ['operacion_id' => (string) Str::uuid(), 'version' => $version];
    }

    protected function ruta(string $accion): string
    {
        return '/api/recepciones-fruta-embalada/'.$this->recepcion.'/'.$accion;
    }
}
