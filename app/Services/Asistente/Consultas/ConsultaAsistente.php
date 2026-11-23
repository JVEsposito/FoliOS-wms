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

    /** @return list<string> palabras que también usa otra consulta: cuentan la mitad */
    public function palabrasSecundarias(): array;

    /**
     * Filtros que la consulta aplica de verdad: periodo, cliente, csg, especie, codigo o
     * por_dia (una fila por jornada).
     * Un filtro reconocido que no está aquí se informa en «Entendí», nunca se ignora en silencio.
     *
     * @return list<string>
     */
    public function filtros(): array;

    /** Si se puede pedir una recomendación a Haiku sobre su resultado (fase B, a pedido). */
    public function admiteRecomendacion(): bool;

    /**
     * Capacidades de la planta que vienen al caso para recomendar: túneles, andenes,
     * rangos. Solo cifras y nombres de instalaciones, nunca personas ni clientes.
     *
     * @return list<array{nombre: string, valor: int|float|string}>
     */
    public function capacidades(): array;

    /**
     * Vistas del tema, nunca vacía. Una consulta que no es tema tiene solo `general`.
     *
     * @return list<VistaConsulta>
     */
    public function vistas(): array;

    /** Clave de la vista que rige si la pregunta no elige una, o null si hay que preguntar. */
    public function vistaPorDefecto(): ?string;

    /** La vista pedida; con null, la por defecto; con `general`, la única si hay una sola. Null si no existe. */
    public function vista(?string $clave): ?VistaConsulta;

    public function puedeVer(User $usuario, VistaConsulta $vista): bool;

    public function puedeEjecutar(User $usuario): bool;

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta;
}
