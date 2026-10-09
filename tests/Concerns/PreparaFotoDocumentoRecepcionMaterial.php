<?php

namespace Tests\Concerns;

use App\Models\FotoRecepcionMaterial;
use App\Models\RecepcionMaterial;
use Illuminate\Support\Str;

trait PreparaFotoDocumentoRecepcionMaterial
{
    /** Las pruebas históricas de inventario parten de un borrador con documento recibido. */
    protected function postJsonConDocumento(string $uri, array $data = [], array $headers = [], int $options = 0)
    {
        if (preg_match('~/materiales/recepciones/([^/]+)/confirmar$~', $uri, $match)) {
            $recepcion = RecepcionMaterial::find($match[1]);
            if ($recepcion && ! $recepcion->fotos()->where('tipo', 'documento')->exists()) {
                FotoRecepcionMaterial::create([
                    'recepcion_material_id' => $recepcion->id, 'tipo' => 'documento', 'orden' => 1,
                    'ruta_original' => 'fixture/documento.jpg', 'ruta_miniatura' => 'fixture/documento-mini.jpg',
                    'mime' => 'image/jpeg', 'bytes' => 100, 'ancho' => 100, 'alto' => 100,
                    'sha256' => hash('sha256', 'documento fixture'), 'operacion_id' => (string) Str::uuid(),
                    'subida_por_user_id' => $recepcion->creado_por_user_id,
                ]);
            }
        }

        return $this->postJson($uri, $data, $headers, $options);
    }
}
