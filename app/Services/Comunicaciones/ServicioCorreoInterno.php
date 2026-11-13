<?php

namespace App\Services\Comunicaciones;

use App\Enums\RolUsuario;
use App\Models\CorreoInterno;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioCorreoInterno
{
    public function visibles(User $usuario): Builder
    {
        return CorreoInterno::query()->where(fn ($q) => $q->where('remitente_user_id', $usuario->id)
            ->orWhereHas('destinatarios', fn ($d) => $d->where('users.id', $usuario->id)));
    }

    public function obtener(User $usuario, int $id): CorreoInterno
    {
        return $this->visibles($usuario)->with(['remitente:id,name', 'destinatarios:id,name'])->findOrFail($id);
    }

    public function listar(User $usuario, string $bandeja, string $buscar, int $pagina): array
    {
        $consulta = $this->visibles($usuario)->with(['remitente:id,name', 'destinatarios:id,name']);
        if ($bandeja === 'enviados') {
            $consulta->where('remitente_user_id', $usuario->id);
        } else {
            $consulta->whereHas('destinatarios', function ($q) use ($usuario, $bandeja): void {
                $q->where('users.id', $usuario->id);
                if ($bandeja === 'archivados') {
                    $q->whereNotNull('com_destinatarios.archivado_at');
                } else {
                    $q->whereNull('com_destinatarios.archivado_at');
                }
            });
        }
        if ($buscar !== '') {
            $consulta->where(fn ($q) => $q->where('asunto', 'like', '%'.$buscar.'%')->orWhere('texto', 'like', '%'.$buscar.'%'));
        }
        $lista = $consulta->orderByDesc('id')->paginate(25, ['*'], 'pagina', $pagina);

        return ['data' => $lista->getCollection()->map(fn ($c) => $this->representar($c, $usuario, false))->all(),
            'pagina' => $lista->currentPage(), 'ultima_pagina' => $lista->lastPage(), 'total' => $lista->total()];
    }

    public function enviar(User $usuario, array $datos): CorreoInterno
    {
        $ids = array_map('intval', $datos['destinatarios']);
        sort($ids, SORT_NUMERIC);
        $asunto = trim($datos['asunto']);
        $texto = trim($datos['texto']);
        $respuesta = isset($datos['respuesta_a_id']) ? (int) $datos['respuesta_a_id'] : null;
        try {
            return DB::transaction(function () use ($usuario, $datos, $ids, $asunto, $texto, $respuesta): CorreoInterno {
                $existente = CorreoInterno::where('remitente_user_id', $usuario->id)->where('operacion_id', $datos['operacion_id'])->first();
                if ($existente) {
                    return $this->reintento($existente, $ids, $asunto, $texto, $respuesta);
                }
                $validos = User::whereIn('id', $ids)->where('activo', true)->where('rol', '!=', RolUsuario::Cliente->value)->where('id', '!=', $usuario->id)->count();
                if ($validos !== count($ids)) {
                    throw ValidationException::withMessages(['destinatarios' => 'Selecciona destinatarios activos del equipo, distintos de ti.']);
                }
                if ($respuesta !== null) {
                    $this->obtener($usuario, $respuesta);
                }
                $correo = CorreoInterno::create(['remitente_user_id' => $usuario->id, 'operacion_id' => $datos['operacion_id'],
                    'asunto' => $asunto, 'texto' => $texto, 'respuesta_a_id' => $respuesta]);
                $correo->destinatarios()->attach($ids);

                return $correo;
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            $existente = CorreoInterno::where('remitente_user_id', $usuario->id)->where('operacion_id', $datos['operacion_id'])->first();
            if (! $existente) {
                throw $error;
            }

            return $this->reintento($existente, $ids, $asunto, $texto, $respuesta);
        }
    }

    public function lectura(User $usuario, int $id, bool $leido): void
    {
        $this->destinatario($usuario, $id)->update(['leido_at' => $leido ? now() : null]);
    }

    public function archivar(User $usuario, int $id, bool $archivado): void
    {
        $this->destinatario($usuario, $id)->update(['archivado_at' => $archivado ? now() : null]);
    }

    public function representar(CorreoInterno $correo, User $usuario, bool $completo = true): array
    {
        $correo->loadMissing(['remitente:id,name', 'destinatarios:id,name']);
        $recibido = $correo->destinatarios->firstWhere('id', $usuario->id);
        $datos = ['id' => $correo->id, 'asunto' => $correo->asunto,
            'remitente' => ['id' => $correo->remitente_user_id, 'nombre' => $correo->remitente->name],
            'destinatarios' => $correo->destinatarios->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name])->all(),
            'es_destinatario' => $recibido !== null, 'leido' => $recibido ? $recibido->pivot->leido_at !== null : true,
            'archivado' => $recibido ? $recibido->pivot->archivado_at !== null : false,
            'creado_at' => $correo->created_at->toAtomString(), 'respuesta_a_id' => $correo->respuesta_a_id,
            'extracto' => mb_substr($correo->texto, 0, 140),
            'operacion_id' => $correo->remitente_user_id === $usuario->id ? $correo->operacion_id : null];
        if ($completo) {
            $datos['texto'] = $correo->texto;
        }

        return $datos;
    }

    private function destinatario(User $usuario, int $id)
    {
        $consulta = DB::table('com_destinatarios')->where('correo_id', $id)->where('user_id', $usuario->id);
        abort_unless($consulta->exists(), 404);

        return $consulta;
    }

    private function reintento(CorreoInterno $correo, array $ids, string $asunto, string $texto, ?int $respuesta): CorreoInterno
    {
        $anteriores = $correo->destinatarios()->orderBy('users.id')->pluck('users.id')->all();
        abort_unless($anteriores === $ids && $correo->asunto === $asunto && $correo->texto === $texto && $correo->respuesta_a_id === $respuesta,
            409, 'Este identificador ya se usó para otro correo.');

        return $correo;
    }
}
