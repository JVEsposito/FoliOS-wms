<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccesoOficinaRequest;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AccesoOficinaController extends Controller
{
    public function store(
        AccesoOficinaRequest $request,
        AlcanceOperacionalUsuario $alcance,
    ): JsonResponse {
        $datos = $request->validated();
        $usuario = User::query()
            ->where('email', mb_strtolower($datos['email']))
            ->first();

        if (! $usuario
            || ! $usuario->activo
            || ! Hash::check($datos['password'], $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales ingresadas no son válidas.',
            ]);
        }

        if (! $alcance->puedeAccederOficina($usuario)) {
            throw ValidationException::withMessages([
                'email' => 'El usuario no posee acceso a los módulos de oficina.',
            ]);
        }

        $token = $usuario->createToken(
            'oficina-'.now()->format('Ymd-His'),
            ['oficina'],
        );

        $capacidades = $alcance->capacidadesApi($usuario);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'rol' => $usuario->rol->value,
                'perfil_acceso' => $capacidades['perfil_acceso'],
                'solo_consulta' => $capacidades['solo_consulta'],
                'modulos_acceso' => $capacidades['modulos_acceso'],
                'ambito_camaras' => $alcance->ambitoCamaras($usuario),
                'capacidades' => $capacidades,
                'puede_configurar_camaras' => $usuario->can('crear-camaras-productos')
                    || $usuario->can('crear-camaras-materiales')
                    || $usuario->can('crear-camaras-materia-prima'),
                'puede_crear_camaras_productos' => $usuario->can('crear-camaras-productos'),
                'puede_crear_camaras_materiales' => $usuario->can('crear-camaras-materiales'),
                'puede_crear_camaras_materia_prima' => $usuario->can('crear-camaras-materia-prima'),
                'puede_administrar_camaras' => $usuario->can('administrar-camaras'),
                'puede_administrar_accesos' => $usuario->can('administrar-accesos'),
                'puede_habilitar_demo' => $usuario->rol === RolUsuario::Administrador,
                'puede_consultar_accesos' => $capacidades['puede_consultar_accesos'],
                'puede_consultar_configuracion_camaras' => $capacidades['puede_consultar_configuracion_camaras'],
                'puede_consultar_integridad_operacional' => $capacidades['puede_consultar_integridad_operacional'],
                'puede_ejecutar_integridad_operacional' => $capacidades['puede_ejecutar_integridad_operacional'],
                'puede_gestionar_cargas' => $capacidades['puede_gestionar_cargas'],
                'puede_autorizar_sobrecupo_embarques' => $capacidades['puede_autorizar_sobrecupo_embarques'],
                'puede_consultar_cargas' => $capacidades['puede_consultar_cargas'],
                'puede_consultar_catalogo_cargas' => $capacidades['puede_consultar_catalogo_cargas'],
                'puede_resolver_comercialmente_carga' => $capacidades['puede_resolver_comercialmente_carga'],
                'puede_resolver_reparacion_carga' => $capacidades['puede_resolver_reparacion_carga'],
                'puede_cerrar_despacho_frigorifico' => $capacidades['puede_cerrar_despacho_frigorifico'],
                'puede_gestionar_andenes' => $capacidades['puede_gestionar_andenes'],
                'puede_administrar_catalogos_materiales' => $usuario->can('administrar-catalogos-materiales'),
                'puede_administrar_recetas_materiales' => $usuario->can('administrar-recetas-materiales'),
                'puede_gestionar_despachos_materiales' => $capacidades['puede_gestionar_despachos_materiales'],
                'puede_retirar_materiales' => $capacidades['puede_retirar_materiales'],
                'puede_consultar_despachos_materiales' => $capacidades['puede_consultar_despachos_materiales'],
                'puede_cancelar_despachos_materiales' => $capacidades['puede_cancelar_despachos_materiales'],
                'puede_consultar_kardex_materiales' => $capacidades['puede_consultar_kardex_materiales'],
                'puede_corregir_items_estibados_materiales' => $capacidades['puede_corregir_items_estibados_materiales'],
                'puede_gestionar_bloqueos_materiales' => $capacidades['puede_gestionar_bloqueos_materiales'],
                'puede_consultar_recepciones_materiales' => $capacidades['puede_consultar_recepciones_materiales'],
                'puede_gestionar_recepciones_materiales' => $capacidades['puede_gestionar_recepciones_materiales'],
                'puede_anular_recepciones_materiales' => $capacidades['puede_anular_recepciones_materiales'],
                'puede_imprimir_etiquetas_materiales' => $capacidades['puede_imprimir_etiquetas_materiales'],
                'puede_consultar_validaciones_pallet' => $capacidades['puede_consultar_validaciones_pallet'],
                'puede_registrar_repaletizajes' => $capacidades['puede_registrar_repaletizajes'],
                'puede_anular_repaletizajes' => $capacidades['puede_anular_repaletizajes'],
                'puede_consultar_repaletizajes' => $capacidades['puede_consultar_repaletizajes'],
                'puede_corregir_validaciones_pallet' => $capacidades['puede_corregir_validaciones_pallet'],
                'puede_rechazar_pallets' => $capacidades['puede_rechazar_pallets'],
                'puede_administrar_catalogos_validacion' => $capacidades['puede_administrar_catalogos_validacion'],
                'puede_consultar_catalogos_validacion' => $capacidades['puede_consultar_catalogos_validacion'],
                'puede_consultar_prefrio' => $capacidades['puede_consultar_prefrio'],
                'puede_consultar_inspeccion_sag' => $capacidades['puede_consultar_inspeccion_sag'],
                'puede_gestionar_inspeccion_sag' => $capacidades['puede_gestionar_inspeccion_sag'],
                'puede_operar_prefrio' => $capacidades['puede_operar_prefrio'],
                'puede_supervisar_prefrio' => $capacidades['puede_supervisar_prefrio'],
                'puede_administrar_tuneles_prefrio' => $capacidades['puede_administrar_tuneles_prefrio'],
                'puede_consultar_panel_gerencial' => $capacidades['puede_consultar_panel_gerencial'],
                'puede_consultar_romana' => $capacidades['puede_consultar_romana'],
                'puede_auditar_defectos_recepcion_mp' => $capacidades['puede_auditar_defectos_recepcion_mp'],
                'puede_operar_romana' => $capacidades['puede_operar_romana'],
                'puede_corregir_recepciones_romana' => $capacidades['puede_corregir_recepciones_romana'],
                'puede_consultar_materia_prima' => $capacidades['puede_consultar_materia_prima'],
                'puede_gestionar_lotes_materia_prima' => $capacidades['puede_gestionar_lotes_materia_prima'],
                'puede_supervisar_lotes_materia_prima' => $capacidades['puede_supervisar_lotes_materia_prima'],
                'puede_consultar_hidrocooler_materia_prima' => $capacidades['puede_consultar_hidrocooler_materia_prima'],
                'puede_operar_hidrocooler_materia_prima' => $capacidades['puede_operar_hidrocooler_materia_prima'],
                'puede_consultar_fruta_proceso' => $capacidades['puede_consultar_fruta_proceso'],
                'puede_entregar_fruta_proceso' => $capacidades['puede_entregar_fruta_proceso'],
                'puede_corregir_entregas_fruta_proceso' => $capacidades['puede_corregir_entregas_fruta_proceso'],
                'puede_consultar_oficina_consultas' => $capacidades['puede_consultar_oficina_consultas'],
                'puede_gestionar_pines_operadores' => $capacidades['puede_gestionar_pines_operadores'],
                'puede_consultar_sag' => $capacidades['puede_consultar_sag'],
                'puede_asociar_productores_csg' => $capacidades['puede_asociar_productores_csg'],
                'puede_consultar_cuenta_envases' => $capacidades['puede_consultar_cuenta_envases'],
                'puede_revisar_cuenta_envases' => $capacidades['puede_revisar_cuenta_envases'],
                'puede_corregir_propiedad_envases' => $usuario->can('corregir-propiedad-envases'),
                'puede_corregir_cantidad_envases' => $usuario->can('corregir-cantidad-envases'),
                'puede_gestionar_despacho_envases' => $capacidades['puede_gestionar_despacho_envases'],
                'puede_anular_despacho_envases' => $capacidades['puede_anular_despacho_envases'],
            ],
        ]);
    }

    public function autorizarDemo(Request $request): JsonResponse
    {
        $usuario = $request->user();
        if (! $usuario || $usuario->rol !== RolUsuario::Administrador) {
            return response()->json([
                'message' => 'Solo un administrador puede habilitar la versión demo.',
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'data' => [
                'autorizado' => true,
                'version_escenario' => 1,
                'administrador' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->name,
                ],
                'autorizado_at' => now()->toAtomString(),
                'persistencia' => 'sesion_navegador',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
