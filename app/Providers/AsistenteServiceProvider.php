<?php

namespace App\Providers;

use App\Services\Asistente\CatalogoConsultasAsistente;
use App\Services\Asistente\Consultas\AnulacionesValidacion;
use App\Services\Asistente\Consultas\CajasPt;
use App\Services\Asistente\Consultas\CamionesEnPlanta;
use App\Services\Asistente\Consultas\ConsumosOt;
use App\Services\Asistente\Consultas\DespachosEnvasesPendientes;
use App\Services\Asistente\Consultas\EnvasesRecibidos;
use App\Services\Asistente\Consultas\EstadoCargas;
use App\Services\Asistente\Consultas\ExpedientePorCodigo;
use App\Services\Asistente\Consultas\FoliosDespachados;
use App\Services\Asistente\Consultas\FormatosPt;
use App\Services\Asistente\Consultas\KilosPorCsg;
use App\Services\Asistente\Consultas\KilosRecepciones;
use App\Services\Asistente\Consultas\PrefrioEnCurso;
use App\Services\Asistente\Consultas\Repaletizajes;
use App\Services\Asistente\Consultas\RevisarTaras;
use App\Services\Asistente\Consultas\SaldosPt;
use App\Services\Asistente\Consultas\TiemposCargas;
use App\Services\Asistente\Consultas\TiemposPrefrio;
use App\Services\Asistente\Consultas\TiemposRomana;
use App\Services\Asistente\Consultas\ValidacionesPt;
use Illuminate\Support\ServiceProvider;

/**
 * Catálogo de consultas del asistente de Oficina (docs/ASISTENTE_OFICINA.md). Para
 * agregar una consulta: implementar ConsultaAsistente y sumarla a CONSULTAS.
 */
class AsistenteServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    public const CONSULTAS = [
        TiemposRomana::class,
        KilosRecepciones::class,
        KilosPorCsg::class,
        RevisarTaras::class,
        CajasPt::class,
        FormatosPt::class,
        SaldosPt::class,
        Repaletizajes::class,
        TiemposPrefrio::class,
        TiemposCargas::class,
        ConsumosOt::class,
        ExpedientePorCodigo::class,
        CamionesEnPlanta::class,
        EnvasesRecibidos::class,
        DespachosEnvasesPendientes::class,
        EstadoCargas::class,
        FoliosDespachados::class,
        PrefrioEnCurso::class,
        ValidacionesPt::class,
        AnulacionesValidacion::class,
    ];

    public function register(): void
    {
        $this->app->tag(self::CONSULTAS, 'asistente.consultas');
        $this->app->singleton(
            CatalogoConsultasAsistente::class,
            fn ($app): CatalogoConsultasAsistente => new CatalogoConsultasAsistente($app->tagged('asistente.consultas')),
        );
    }
}
