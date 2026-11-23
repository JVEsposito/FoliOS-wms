<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsuarioEvaluadorAsistente;
use App\Services\Asistente\CompuertaAsistente as Compuerta;
use Illuminate\Console\Command;

/** Solo lectura: mide el registro real y el banco para decidir si se conecta Haiku. */
class CompuertaAsistente extends Command
{
    use UsuarioEvaluadorAsistente;

    protected $signature = 'asistente:compuerta
        {--dias=7 : Días de registro real a medir}
        {--usuario= : Correo o id del usuario con que se evalúa el banco; por defecto, el primer administrador activo}
        {--json : Entrega el informe como JSON}';

    protected $description = 'Mide si las preguntas reales justifican conectar Haiku (umbral: 15 % en sugerencias)';

    public function handle(Compuerta $compuerta): int
    {
        $dias = filter_var($this->option('dias'), FILTER_VALIDATE_INT);
        if ($dias === false || $dias < 1 || $dias > 90) {
            $this->error('Indica --dias como entero entre 1 y 90.');

            return self::INVALID;
        }
        $usuario = $this->usuarioEvaluador();
        if ($usuario === null) {
            $this->error('No hay un usuario activo para evaluar. Indica --usuario=correo.');

            return self::FAILURE;
        }

        $informe = $compuerta->medir($usuario, $dias);
        if ($this->option('json')) {
            $this->line(json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $porcentaje = fn (float $p): string => number_format($p * 100, 1, ',', '.').' %';
        $r = $informe['registro'];
        $a = $informe['reinterpretado'];
        $b = $informe['banco'];
        $this->line("Registro de {$dias} días: {$r['total']} preguntas de {$r['usuarios']} usuarios.");
        $this->line("  Como se registró: {$r['respondidas']} respondidas, {$r['sin_datos']} sin datos, {$r['sugerencias']} en sugerencias ({$porcentaje($r['proporcion_sugerencias'])}).");
        $this->line("  Con las reglas de hoy: {$a['sin_consulta']} de {$a['total']} sin consulta ({$porcentaje($a['proporcion_sugerencias'])}).");
        $this->line("Banco: {$b['correctas']} correctas, {$b['preguntas']} preguntan, {$b['preguntas_de_mas']} preguntan de más, {$b['falsas']} falsas.");
        if ($informe['sin_respuesta'] !== []) {
            $this->line('Preguntas reales que siguen sin consulta (para sumar al banco):');
            $this->table(['Veces', 'Pregunta'], array_map(fn (array $t): array => [$t['veces'], mb_strimwidth($t['texto'], 0, 80, '…')], $informe['sin_respuesta']));
        }
        $this->newLine();
        $this->info('Decisión: '.match ($informe['decision']) {
            'conectar_haiku' => 'conectar Haiku',
            'seguir_sin_ia' => 'seguir sin IA',
            'datos_insuficientes' => 'faltan datos',
            default => 'corregir reglas',
        }.'. '.$informe['motivo']);

        return self::SUCCESS;
    }
}
