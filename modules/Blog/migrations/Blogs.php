<?php

namespace modules\Blog\Migrations;

#[\AllowDynamicProperties]
class Blogs
{
    static $storageEngine = "InnoDB";
    static $charset       = "utf8mb4_general_ci";
    static $table         = "blogs";
    static $db            = "local";
    static $prefix        = "";

    public static function columns()
    {
        return [
            'id'            => ['primary'],
            
            'title'         => ['varchar:200'],
            'slug'          => ['varchar:255'],

            'image'         => ['varchar'],
            'content'       => ['text'],

            'description'   => ['text'],
            'keywords'      => ['text'],

            'publish'       => ['bool', 'default:00'],
            'featured_post' => ['bool', 'default:00'],

            'user_id'       => ['int'],

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
