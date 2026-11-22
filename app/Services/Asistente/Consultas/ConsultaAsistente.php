<?php

namespace App\Services\Asistente\Consultas;

use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/**
 * Una consulta de solo lectura del catálogo del asistente. Cada una exige el mismo
 * permiso que su oficina de origen y responde con cifras que salen de los datos.
 */
interface ConsultaAsistente
{
    public function clave(): string;

    public function titulo(): string;

    public function descripcion(): string;

    /** materia-prima | frigorifico | materiales | consultas | gerencia | administracion */
    public function dominio(): string;

    /** RRHH y Finanzas: nunca salen de la planta (fase B). */
    public function sensible(): bool;

    /** @return list<string> palabras ya normalizadas con TextoNormalizado */
    public function palabrasClave(): array;

    public function puedeEjecutar(User $usuario): bool;

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta;
}
