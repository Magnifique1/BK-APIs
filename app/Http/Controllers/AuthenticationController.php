<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AuthenticationController extends Controller
{
    public function login(Request $request)
    {

//        return Hash::make('123456789');

        $validator = Validator::make($request->all(), array(
            'user_name' => 'required|string', // client sends this
            'password'  => 'required|string',
        ));

        if ($validator->fails()) {
            return response()->json(array(
                'timestamp' => Carbon::now()->format('Y-m-d H:i:s'),
                'message'   => 'Invalid credentials',
                'status'    => 401,
            ), 401);
        }

        // Map user_name -> email (actual DB column)
        $credentials = array(
            'email'    => $request->input('user_name'),
            'password' => $request->input('password'),
        );

        if (!Auth::attempt($credentials)) {
            return response()->json(array(
                'timestamp' => Carbon::now()->format('Y-m-d H:i:s'),
                'message'   => 'Invalid credentials',
                'status'    => 401,
            ), 401);
        }

        $user = $request->user();

        $plainTextToken = $user->createToken('api')->plainTextToken;

        return response()->json(array(
            'timestamp' => Carbon::now()->format('Y-m-d H:i:s'),
            'message'   => 'Successful',
            'status'    => 200,
            'data'      => array(
                'token' => 'Bearer ' . $plainTextToken,
            ),
        ), 200);
    }
}
