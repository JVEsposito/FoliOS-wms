<?php

use App\Enums\RolUsuario;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfiles_acceso', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 80)->unique();
            $table->string('nombre', 150);
            $table->string('descripcion', 500)->nullable();
            $table->string('rol_base', 60);
            $table->json('modulos');
            $table->boolean('activo')->default(true);
            $table->boolean('predeterminado')->default(false);
            $table->boolean('protegido')->default(false);
            $table->foreignId('creado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['rol_base', 'activo']);
        });

        $catalogo = app(CatalogoModulosAcceso::class);
        $nombres = [
            'administrador' => 'Administrador',
            'supervisor_frio' => 'Supervisor de frío',
            'supervisor_materiales' => 'Supervisor de materiales',
            'despachador' => 'Despachador',
            'operador_prefrio' => 'Operador de prefrío',
            'operador_romana' => 'Operador de romana',
            'digitador_materia_prima' => 'Digitador de materia prima',
            'camarero_frio' => 'Camarero de frío',
            'camarero_materiales' => 'Camarero de materiales',
            'validador' => 'Validador de pallets',
            'validador_mp' => 'Validador MP',
            // Roles incorporados después de esta migración: permiten migrar una base nueva.
            'tarjador' => 'Tarjador',
            'guardia_porteria' => 'Guardia de portería',
            'consulta' => 'Solo consulta',
        ];
        $ahora = now();
        $ids = [];

        foreach (RolUsuario::cases() as $rol) {
            $id = (string) Str::uuid();
            $ids[$rol->value] = $id;
            DB::table('perfiles_acceso')->insert([
                'id' => $id,
                'codigo' => mb_strtoupper($rol->value),
                'nombre' => $nombres[$rol->value],
                'descripcion' => 'Perfil operacional inicial compatible con el rol '.$nombres[$rol->value].'.',
                'rol_base' => $rol->value,
                'modulos' => json_encode($catalogo->modulosPredeterminados($rol), JSON_THROW_ON_ERROR),
                'activo' => true,
                'predeterminado' => true,
                'protegido' => $rol === RolUsuario::Administrador,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('perfil_acceso_id')
                ->nullable()
                ->after('rol')
                ->constrained('perfiles_acceso')
                ->nullOnDelete();
        });

        foreach ($ids as $rol => $perfilId) {
            DB::table('users')
                ->where('rol', $rol)
                ->update(['perfil_acceso_id' => $perfilId]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('perfil_acceso_id');
        });

        Schema::dropIfExists('perfiles_acceso');
    }
};
