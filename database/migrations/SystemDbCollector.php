<?php

namespace Database\Migrations;

#[\AllowDynamicProperties]
class SystemDbCollector
{
    static $storageEngine = "InnoDB";
    static $charset       = "utf8mb4_general_ci";
    static $table         = "system_db_collector";
    static $db            = "local";
    static $prefix        = "";

    public static function columns()
    {
        return [
            'id'                   => ['primary'],
            'analyze_id'           => ['varchar'],
            'fingerprint'          => ['varchar'],
            'query'                => ['text'],
            'executed'             => ['text'],
            'query_time_ms'        => ['real'],
            'tables'               => ['json'],
            'used_indexes'         => ['json'],
            'used_columns'         => ['json'],
            'warnings'             => ['json'],
            'index_suggestions'    => ['json'],
            'metrics'              => ['json'],
            'row_stats'            => ['json'],
            'estimated_total_cost' => ['json'],
            'timestamps'
        ];
    }

    # e.g. a self seeder 
    # public static function oncreateSeeder()
    # {
    #     $user = new User;
    #     $user->insert([
    #         'username'  => 'admin',
    #         'password'  => Crypter::encode('admin'),
    #         'email'     => Str::rand(15) . '@localhost.com',
    #         'api_token' => Str::rand(60)
    #     ]);
    # }
}
