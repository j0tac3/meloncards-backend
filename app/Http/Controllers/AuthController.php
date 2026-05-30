<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // Método para REGISTRAR un nuevo usuario
    public function register(Request $request)
    {
        // 1. Validamos que nos envíen los datos correctos
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:8',
        ]);

        // 2. Creamos el usuario en la base de datos
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password), // Encriptamos la contraseña
        ]);

        // 3. Generamos su Token de acceso
        $token = $user->createToken('auth_token')->plainTextToken;

        // 4. Devolvemos la respuesta en formato JSON
        return response()->json([
            'message' => 'Usuario registrado con éxito',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    // Método para INICIAR SESIÓN
    public function login(Request $request)
    {
        // 1. Validamos los datos
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // 2. Buscamos al usuario por su email
        $user = User::where('email', $request->email)->first();

        // 3. Comprobamos si el usuario existe y si la contraseña coincide
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 401);
        }

        // 4. Generamos un nuevo Token
        $token = $user->createToken('auth_token')->plainTextToken;

        // 5. Devolvemos la respuesta
        return response()->json([
            'message' => 'Inicio de sesión exitoso',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
}