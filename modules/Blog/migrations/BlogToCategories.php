<?php

namespace modules\Blog\Migrations;

#[\AllowDynamicProperties]
class BlogToCategories
{
    static $storageEngine = "InnoDB";
    static $charset       = "utf8mb4_general_ci";
    static $table         = "blog_to_categories";
    static $db            = "local";
    static $prefix        = "";

    public static function columns()
    {
        return [
            'id'          => ['primary'],
            'post_id'     => ['int'],
            'category_id' => ['int'],
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
