<?php

use Illuminate\Database\ConfigurationUrlParser;

$databaseUrl = env(
    'DATABASE_URL',
    'sqlite:///'.str_replace('\\', '/', database_path('database.sqlite')),
);

$databaseUrlConfiguration = (new ConfigurationUrlParser)
    ->parseConfiguration($databaseUrl);

$defaultConnection = $databaseUrlConfiguration['driver'] ?? null;

if (! in_array($defaultConnection, ['pgsql', 'sqlite'], true)) {
    throw new InvalidArgumentException(
        'DATABASE_URL must use postgresql://, postgres://, pgsql://, sqlite:// or sqlite3://.',
    );
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => $defaultConnection,

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => $defaultConnection === 'sqlite' ? $databaseUrl : null,
            'database' => $defaultConnection === 'sqlite'
                ? ($databaseUrlConfiguration['database'] ?? database_path('database.sqlite'))
                : database_path('database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => $defaultConnection === 'pgsql' ? $databaseUrl : null,
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'crm',
            'username' => 'crm',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => extension_loaded('redis') ? 'phpredis' : 'predis',

        'options' => [
            'cluster' => 'redis',
            'prefix' => 'crm-database-',
            'persistent' => false,
        ],

        'default' => [
            'url' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
            'max_retries' => 3,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base' => 100,
            'backoff_cap' => 1000,
        ],

        'cache' => [
            'url' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
            'max_retries' => 3,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base' => 100,
            'backoff_cap' => 1000,
        ],

    ],

];
