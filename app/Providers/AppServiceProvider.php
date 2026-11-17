<?php

namespace App\Providers;

use App\Database\PinnedMariaDbConnection;
use App\Foundation\WriteCoordinator;
use App\Services\AuthService;
use App\Services\ReportEffects;
use App\Session\ReFindSessionManager;
use App\Support\ReportQueryCounter;
use App\Support\Workspace;
use Illuminate\Database\Connectors\MariaDbConnector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['database.connections' => ['mariadb' => config('database.connections.mariadb')], 'filesystems.disks' => ['refind_private' => ['driver' => 'local', 'root' => Workspace::media(), 'visibility' => 'private', 'serve' => false, 'throw' => true]]]);
        // Resolve only the manager. A cached connection here is a fatal ordering error.
        $manager = $this->app->make('db');
        if ($manager->getConnections() !== []) {
            throw new \RuntimeException('Database connection resolved before guarded driver registration.');
        }
        $manager->extend('mariadb', function ($config, $name) {
            Workspace::assertDatabase($config);
            $pdo = function () use ($config) {
                Workspace::assertDatabase($config);
                $pdo = (new MariaDbConnector)->connect($config);
                $server = $pdo->query('SELECT @@port AS port,@@datadir AS datadir')->fetch(\PDO::FETCH_ASSOC);
                if ((int) $server['port'] !== 3307 || Workspace::norm($server['datadir']) !== Workspace::norm(Workspace::ROOT.'/state/mariadb-data')) {
                    throw new \RuntimeException('Unexpected database instance.');
                }
                $owner = $pdo->query('SELECT workspace_id FROM _workspace_owner')->fetchColumn();
                if ($owner !== Workspace::profile()['workspace_id']) {
                    throw new \RuntimeException('Database ownership mismatch.');
                }

                return $pdo;
            };

            return new PinnedMariaDbConnection($pdo, $config['database'], '', $config);
        });
        $this->app->singleton('session', fn ($app) => new ReFindSessionManager($app));
        $this->app->scoped(AuthService::class);
        $this->app->scoped(ReportQueryCounter::class);
        $this->app->scoped(WriteCoordinator::class, fn () => new WriteCoordinator(DB::connection()));
        $this->app->scoped(ReportEffects::class, fn () => new ReportEffects(DB::connection()));

    }

    public function boot(): void
    {
        // Listening on the dispatcher must never resolve the default connection.
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (config('refind.query_metrics')) {
                $this->app->make(ReportQueryCounter::class)->record((float) $query->time);
            }
        });
    }
}
