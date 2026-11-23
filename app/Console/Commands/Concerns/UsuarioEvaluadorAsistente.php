<?php

namespace App\Console\Commands\Concerns;

use App\Enums\RolUsuario;
use App\Models\User;

/** Usuario con cuyos permisos se evalúa el asistente: el indicado, o el primer administrador activo. */
trait UsuarioEvaluadorAsistente
{
    protected function usuarioEvaluador(): ?User
    {
        $indicado = $this->option('usuario');
        $consulta = User::query()->where('activo', true);

        return $indicado
            ? $consulta->where(fn ($q) => $q->where('email', $indicado)->orWhere('id', $indicado))->first()
            : $consulta->where('rol', RolUsuario::Administrador->value)->orderBy('created_at')->first();
    }
}
