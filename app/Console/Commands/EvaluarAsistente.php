<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsuarioEvaluadorAsistente;
use App\Services\Asistente\EvaluadorBancoAsistente;
use App\Services\Asistente\Haiku\ClienteAnthropic;
use Illuminate\Console\Command;

/** Interpreta el banco sin ejecutar consultas de negocio; las llamadas reales registran consumo. */
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
        $haikuIncompleto = $modo === 'haiku' && ($informe['llamadas_exitosas_haiku'] === 0 || $informe['errores_haiku'] > 0
            || $informe['no_disponibles_haiku'] > 0 || $informe['tokens_entrada'] + $informe['tokens_salida'] === 0);
        if ($this->option('json')) {
            $this->line(json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $informe['falsas'] > 0 || $haikuIncompleto ? self::FAILURE : self::SUCCESS;
        }

        $columnas = ['#', 'Pregunta', 'Esperada', 'Elegida', 'Intérprete', 'Resultado'];
        // Con Haiku se agrega por qué decidió así: lo que entendió o lo que la consulta no cubrió.
        $this->table($modo === 'haiku' ? [...$columnas, 'Detalle'] : $columnas, array_map(fn (array $d): array => [
            $d['n'], mb_strimwidth($d['pregunta'], 0, 60, '…'), $d['esperada'] ?? '—',
            $d['elegida'] === null ? '—' : $d['elegida'].(in_array($d['vista_elegida'], [null, 'general'], true) ? '' : ' · '.$d['vista_elegida']),
            $d['interprete'], $d['clase'],
            ...($modo === 'haiku' ? [mb_strimwidth((string) $d['detalle'], 0, 60, '…')] : []),
        ], $informe['detalle']));
        if ($modo === 'haiku') {
            $this->line(sprintf('API: %d llamadas exitosas · %d errores · %d preguntas sin Haiku disponible.',
                $informe['llamadas_exitosas_haiku'], $informe['errores_haiku'], $informe['no_disponibles_haiku']));
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
        if ($haikuIncompleto) {
            $this->error('Evaluación incompleta: revisa los errores, el piloto y los topes de Haiku antes de activar el modo mixto.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
