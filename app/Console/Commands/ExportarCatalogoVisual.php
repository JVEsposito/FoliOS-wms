<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportarCatalogoVisual extends Command
{
    protected $signature = 'ui:catalogo {--output= : Ruta de salida del HTML}';

    protected $description = 'Exporta el catálogo visual FoliOS con ejemplos ficticios y sin consultar datos operacionales';

    public function handle(): int
    {
        $output = $this->option('output') ?: storage_path('app/ui/catalogo.html');
        if (strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'html') {
            $this->error('La ruta de salida debe terminar en .html.');

            return self::INVALID;
        }

        $styles = str_replace(
            "@import './estiba-tokens.css';",
            File::get(resource_path('css/estiba-tokens.css')),
            File::get(resource_path('css/estiba-ui.css')),
        );
        $html = view('design.catalog', [
            'styles' => $styles."\n".File::get(resource_path('css/folios-components.css'))."\n".File::get(resource_path('css/estiba-catalog.css')),
            'script' => File::get(resource_path('js/estiba-catalog.js')),
            'tokens' => json_decode(File::get(base_path('design/estiba.tokens.json')), true, 512, JSON_THROW_ON_ERROR),
        ])->render();

        File::ensureDirectoryExists(dirname($output));
        File::put($output, $html);
        $this->info('Catálogo visual exportado: '.$output);

        return self::SUCCESS;
    }
}
