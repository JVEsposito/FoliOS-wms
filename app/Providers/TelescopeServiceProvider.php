<?php

namespace App\Providers;

use App\Enums\RolUsuario;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            // Estas solicitudes y sus respuestas contienen datos privados de conexión.
            if ($this->app->bound('request') && request()->is('api/administracion/conexiones-externas*', 'api/integraciones/operaciones*')) {
                return false;
            }

            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent credentials and session data from being persisted by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'token',
            'access_token',
        ]);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);

        Telescope::hideResponseParameters([
            'token',
            'access_token',
        ]);
    }

    /**
     * Register the Telescope gate as an additional safeguard.
     */
    protected function gate(): void
    {
        Gate::define(
            'viewTelescope',
            fn (User $user): bool => $user->rol === RolUsuario::Administrador,
        );
    }
}
