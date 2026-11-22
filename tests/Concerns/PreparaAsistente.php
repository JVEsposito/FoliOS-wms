<?php

namespace Tests\Concerns;

use App\Enums\RolUsuario;
use App\Models\CalibreValidacion;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\LoteMateriaPrima;
use App\Models\PerfilAcceso;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\User;
use App\Models\ValidacionMp;
use App\Models\VariedadValidacion;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Support\Str;

/**
 * Escenario del asistente: temporada activa, un cliente, Cereza y un administrador sin
 * perfil (todos los módulos), con fábricas de recepciones de Romana y lotes de MP.
 */
trait PreparaAsistente
{
    protected User $administrador;

    protected Temporada $temporada;

    protected Cliente $cliente;

    protected VariedadValidacion $variedad;

    protected CalibreValidacion $calibre;

    protected function prepararAsistente(): void
    {
        config(['app.operational_timezone' => 'America/Santiago']);
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $this->cliente = Cliente::create(['codigo' => 'ASI', 'nombre' => 'Exportadora Andina', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $this->temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $this->variedad = VariedadValidacion::firstOrCreate(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina'], ['activo' => true]);
        $this->calibre = CalibreValidacion::firstOrCreate(['especie_validacion_id' => $especie->id, 'nombre' => '28 mm'], ['activo' => true]);
    }

    /** @param  array<int, string>  $modulos */
    protected function usuarioAsistente(RolUsuario $rol, array $modulos = [CatalogoModulosAcceso::OFICINA_ASISTENTE]): User
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'ASI-'.Str::random(6),
            'nombre' => 'Perfil asistente',
            'rol_base' => $rol,
            'modulos' => $modulos,
            'activo' => true,
        ]);

        return User::factory()->create(['rol' => $rol, 'activo' => true, 'perfil_acceso_id' => $perfil->id]);
    }

    /** @param  array<string, mixed>  $datos */
    protected function recepcion(string $numero, float $neto, array $datos = []): RecepcionRomana
    {
        return RecepcionRomana::create([
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $numero),
            'numero_recepcion' => $numero,
            'temporada_id' => $this->temporada->id,
            'temporada_codigo_snapshot' => $this->temporada->codigo,
            'temporada_nombre_snapshot' => $this->temporada->nombre,
            'cliente_id' => $this->cliente->id,
            'cliente_codigo_snapshot' => $this->cliente->codigo,
            'cliente_nombre_snapshot' => $this->cliente->nombre,
            'tipo_recepcion' => 'fruta_con_envases',
            'tipo_servicio' => 'proceso',
            'cantidad_envases_declarados' => 100,
            'tipo_envase_declarado' => 'totes',
            'numero_guia_despacho' => 'GD-'.$numero,
            'patente_camion' => 'ABCD12',
            'tipo_camion' => 'termo',
            'rut_conductor' => '12345678-5',
            'nombre_conductor' => 'Transportista',
            'peso_bruto' => $neto + 8000,
            'peso_tara' => 8000,
            'peso_neto' => $neto,
            'estado' => 'cerrado',
            'estado_validacion_mp' => 'validada',
            'ingreso_at' => now()->subHours(2),
            'salida_at' => now()->subHour(),
            'creado_por_user_id' => $this->administrador->id,
            ...$datos,
        ]);
    }

    protected function csg(string $codigo): CsgValidacion
    {
        return CsgValidacion::firstOrCreate(['temporada_id' => $this->temporada->id, 'codigo' => $codigo], ['activo' => true]);
    }

    protected function lote(RecepcionRomana $recepcion, CsgValidacion $csg, int $numero, float $kilos, string $envase = 'totes', int $cantidad = 100): LoteMateriaPrima
    {
        $validacion = ValidacionMp::firstOrCreate(['recepcion_romana_id' => $recepcion->id], [
            'temporada_id' => $this->temporada->id, 'operacion_toma_id' => (string) Str::uuid(),
            'estado' => 'validada', 'validador_user_id' => $this->administrador->id, 'tomada_at' => now(),
        ]);
        $segmento = SegmentoValidacionMp::create([
            'validacion_mp_id' => $validacion->id, 'secuencia' => $numero, 'motivos' => [],
            'csg_validacion_id' => $csg->id, 'csg_snapshot' => $csg->codigo,
        ]);

        return LoteMateriaPrima::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) $numero),
            'segmento_validacion_mp_id' => $segmento->id, 'recepcion_romana_id' => $recepcion->id,
            'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id, 'numero_lote' => 'LOTE-'.$numero,
            'estado' => 'entregado_proceso', 'csg_validacion_id' => $csg->id, 'csg_snapshot' => $csg->codigo,
            'sdp' => '123456', 'ggn' => '1234567890123', 'fecha_cosecha' => '2026-11-17', 'predio' => 'Predio de prueba',
            'especie_validacion_id' => $this->variedad->especie_validacion_id, 'especie_snapshot' => 'Cereza',
            'variedad_validacion_id' => $this->variedad->id, 'variedad_snapshot' => $this->variedad->nombre,
            'calibre_validacion_id' => $this->calibre->id, 'calibre_snapshot' => $this->calibre->nombre,
            'cuartel' => '1', 'tipo_producto' => 'materia_prima', 'envase_primario' => $envase, 'cantidad_envases_primarios' => $cantidad,
            'kilos_brutos' => $kilos + 200, 'kilos_netos_calculados' => $kilos, 'kilos_netos_confirmados' => $kilos,
            'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id,
        ]);
    }
}
