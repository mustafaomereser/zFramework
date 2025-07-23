<?php

namespace App\Controllers;

use App\Models\User;
use App\Requests\Auth\SigninRequest;
use App\Requests\Auth\SignupRequest;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Crypter;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Response;
use zFramework\Core\Facades\Str;

class AuthController extends Controller
{

    public function __construct()
    {
        //
    }

    public function auth()
    {
        return view('app.modals.auth');
    }

    public function signin(SigninRequest $validate)
    {
        $validate = $validate->validated();
        $response = ['status' => 0];


        if (Auth::attempt(['email' => $validate['email'], 'password' => $validate['password']], (bool) $validate['keep-logged-in'])) {
            $response['status'] = 1;
            Alerts::success('Welcome again!');
        } else {
            Alerts::danger('E-mail or Password not match!');
        }

        return Response::json($response);
    }

    public function signup(SignupRequest $validate)
    {
        $validate = $validate->validated();
        $response = ['status' => 0];

        (new User)->insert([
            'username'  => $validate['username'],
            'email'     => $validate['email'],
            'password'  => Crypter::encode($validate['password']),
            'api_token' => Str::rand(60)
        ]);

        $response['status'] = 1;
        Alerts::success('Signup Complete.');

        return Response::json($response);
    }

    public function signout()
    {
        $response = ['status' => 0];

        Auth::logout();

        $response['status'] = 1;
        Alerts::success('Bye.');

        return Response::json($response);
    }

    public function content()
    {
        return view('app.layouts.auth.content');
    }
}
