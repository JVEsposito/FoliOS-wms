<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsuarioEvaluadorAsistente;
use App\Services\Asistente\EvaluadorBancoAsistente;
use App\Services\Asistente\Haiku\ClienteAnthropic;
use Illuminate\Console\Command;

/** Solo lectura: interpreta el banco de preguntas sin ejecutar consultas ni registrar nada. */
class EvaluarAsistente extends Command
{
    use UsuarioEvaluadorAsistente;

    protected $signature = 'asistente:evaluar
        {--modo=reglas : «reglas», o «haiku» para reglas y luego Haiku como en el modo mixto (llamadas reales)}
        {--usuario= : Correo o id del usuario cuyos permisos se usan; por defecto, el primer administrador activo}
        {--json : Entrega el informe como JSON}';

    protected $description = 'Evalúa el intérprete del asistente con el banco de preguntas de la planta';

    public function handle(EvaluadorBancoAsistente $evaluador, ClienteAnthropic $anthropic): int
    {
        $modo = $this->option('modo');
        if (! in_array($modo, ['reglas', 'haiku'], true)) {
            $this->error('Indica --modo=reglas o --modo=haiku.');

            return self::INVALID;
        }
        if ($modo === 'haiku' && ! $anthropic->disponible()) {
            $this->error('Falta ANTHROPIC_API_KEY en el .env para evaluar con Haiku.');

            return self::FAILURE;
        }
        $usuario = $this->usuarioEvaluador();
        if ($usuario === null) {
            $this->error('No hay un usuario activo para evaluar. Indica --usuario=correo.');

            return self::FAILURE;
        }

        $informe = $evaluador->evaluar($usuario, $modo === 'haiku');
        if ($this->option('json')) {
            $this->line(json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $informe['falsas'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['#', 'Pregunta', 'Esperada', 'Elegida', 'Intérprete', 'Resultado'], array_map(fn (array $d): array => [
            $d['n'], mb_strimwidth($d['pregunta'], 0, 60, '…'), $d['esperada'] ?? '—', $d['elegida'] ?? '—', $d['interprete'], $d['clase'],
        ], $informe['detalle']));
        if ($modo === 'haiku') {
            $this->line(sprintf('Haiku: %d llamadas · %d tokens de entrada · %d de salida · US$ %s.',
                $informe['llamadas_haiku'], $informe['tokens_entrada'], $informe['tokens_salida'], number_format($informe['costo_usd'], 4, ',', '.')));
        }
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
