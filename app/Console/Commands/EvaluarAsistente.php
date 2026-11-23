<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsuarioEvaluadorAsistente;
use App\Services\Asistente\EvaluadorBancoAsistente;
use Illuminate\Console\Command;

/** Solo lectura: interpreta el banco de preguntas sin ejecutar consultas ni registrar nada. */
class EvaluarAsistente extends Command
{
    use UsuarioEvaluadorAsistente;

    protected $signature = 'asistente:evaluar
        {--modo=reglas : Intérprete a evaluar; por ahora solo «reglas»}
        {--usuario= : Correo o id del usuario cuyos permisos se usan; por defecto, el primer administrador activo}
        {--json : Entrega el informe como JSON}';

    protected $description = 'Evalúa el intérprete del asistente con el banco de preguntas de la planta';

    public function handle(EvaluadorBancoAsistente $evaluador): int
    {
        if ($this->option('modo') !== 'reglas') {
            $this->error('Por ahora solo se puede evaluar --modo=reglas.');

            return self::INVALID;
        }
        $usuario = $this->usuarioEvaluador();
        if ($usuario === null) {
            $this->error('No hay un usuario activo para evaluar. Indica --usuario=correo.');

            return self::FAILURE;
        }

        $informe = $evaluador->evaluar($usuario);
        if ($this->option('json')) {
            $this->line(json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $informe['falsas'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['#', 'Pregunta', 'Esperada', 'Elegida', 'Resultado'], array_map(fn (array $d): array => [
            $d['n'], mb_strimwidth($d['pregunta'], 0, 60, '…'), $d['esperada'] ?? '—', $d['elegida'] ?? '—', $d['clase'],
        ], $informe['detalle']));
        $this->line(sprintf(
            'Banco: %d preguntas · %d correctas · %d preguntan · %d preguntan de más · %d falsas.',
            $informe['total'], $informe['correctas'], $informe['preguntas'], $informe['preguntas_de_mas'], $informe['falsas'],
        ));
        if ($informe['falsas'] > 0) {
            $this->error('Hay respuestas falsas: el intérprete eligió otra consulta.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
