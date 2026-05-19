<?php

namespace Tests\Unit\Providers;

use App\Database\Connectors\SqliteVecConnector;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    public function test_it_binds_the_custom_sqlite_connector()
    {
        $this->assertInstanceOf(SqliteVecConnector::class, $this->app->make('db.connector.sqlite'));
    }

    public function test_it_loads_the_sqlite_extension_when_enabled()
    {
        config()->set('database.connections.sqlite.vec_enabled', true);

        $vecPath = tempnam(sys_get_temp_dir(), 'vec');
        $pdo = new class
        {
            public array $loadedExtensions = [];

            public function loadExtension(string $path): void
            {
                $this->loadedExtensions[] = $path;
            }
        };

        $connection = new SQLiteConnection($pdo, ':memory:', '', [
            'driver' => 'sqlite',
            'vec_extension' => $vecPath,
        ]);

        $dispatcher = $this->swapEventDispatcher();

        (new AppServiceProvider($this->app))->boot();

        $dispatcher->dispatch(new ConnectionEstablished($connection));

        $this->assertSame([$vecPath], $pdo->loadedExtensions);

        @unlink($vecPath);
    }

    public function test_it_does_not_load_the_sqlite_extension_when_disabled()
    {
        config()->set('database.connections.sqlite.vec_enabled', false);

        $vecPath = tempnam(sys_get_temp_dir(), 'vec');
        $pdo = new class
        {
            public array $loadedExtensions = [];

            public function loadExtension(string $path): void
            {
                $this->loadedExtensions[] = $path;
            }
        };

        $connection = new SQLiteConnection($pdo, ':memory:', '', [
            'driver' => 'sqlite',
            'vec_extension' => $vecPath,
        ]);

        $dispatcher = $this->swapEventDispatcher();

        (new AppServiceProvider($this->app))->boot();

        $dispatcher->dispatch(new ConnectionEstablished($connection));

        $this->assertSame([], $pdo->loadedExtensions);

        @unlink($vecPath);
    }

    protected function swapEventDispatcher(): Dispatcher
    {
        $dispatcher = new Dispatcher($this->app);

        $this->app->instance('events', $dispatcher);
        Event::swap($dispatcher);

        return $dispatcher;
    }
}
