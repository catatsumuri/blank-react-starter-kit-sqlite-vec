<?php

namespace App\Providers;

use App\Database\Connectors\SqliteVecConnector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('db.connector.sqlite', SqliteVecConnector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSqliteVec();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureSqliteVec(): void
    {
        if (! config('database.connections.sqlite.vec_enabled', false)) {
            return;
        }

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $connection = $event->connection;

            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $vecPath = $connection->getConfig('vec_extension');

            if (! is_string($vecPath) || $vecPath === '') {
                report(new RuntimeException('The SQLite vec extension path is not configured.'));

                return;
            }

            if (! is_file($vecPath)) {
                report(new RuntimeException("The sqlite-vec extension was not found at [{$vecPath}]."));

                return;
            }

            $pdo = $connection->getPdo();

            if (! method_exists($pdo, 'loadExtension')) {
                report(new RuntimeException('This PHP SQLite build does not support loading SQLite extensions.'));

                return;
            }

            try {
                $pdo->loadExtension($vecPath);
            } catch (Throwable $e) {
                report(new RuntimeException("Failed to load sqlite-vec from [{$vecPath}].", previous: $e));
            }
        });
    }
}
