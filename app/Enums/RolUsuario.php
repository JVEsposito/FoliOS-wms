<?php

namespace App\Enums;

enum RolUsuario: string
{
    case Administrador = 'administrador';
    case SupervisorFrio = 'supervisor_frio';
    case SupervisorMateriales = 'supervisor_materiales';
    case Despachador = 'despachador';
    case OperadorPrefrio = 'operador_prefrio';
    case OperadorRomana = 'operador_romana';
    case GuardiaPorteria = 'guardia_porteria';
    case DigitadorMateriaPrima = 'digitador_materia_prima';
    case CamareroFrio = 'camarero_frio';
    case CamareroMateriales = 'camarero_materiales';
    case Validador = 'validador';
    case ValidadorMp = 'validador_mp';
    case Tarjador = 'tarjador';
    case Consulta = 'consulta';
}
