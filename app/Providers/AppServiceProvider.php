<?php

namespace App\Providers;

use App\Database\PinnedMariaDbConnection;
use App\Services\AuthService;
use App\Session\ReFindSessionManager;
use App\Support\Workspace;
use Illuminate\Database\Connectors\MariaDbConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['database.connections' => ['mariadb' => config('database.connections.mariadb')], 'filesystems.disks' => ['refind_private' => ['driver' => 'local', 'root' => Workspace::media(), 'visibility' => 'private', 'serve' => false, 'throw' => true]]]);
        $this->app->singleton('session', fn ($app) => new ReFindSessionManager($app));
        $this->app->scoped(AuthService::class);
    }

    public function boot(): void
    {
        DB::extend('mariadb', function ($config, $name) {
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
    }
}
