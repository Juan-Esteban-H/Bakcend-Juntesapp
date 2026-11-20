<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NotificacionPedido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class PedidoController extends Controller
{
    public function enviarNotificacion(Request $request)
    {
        // 1. Validar los parámetros de entrada de la API
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'usuario' => 'required|string|max:100',
            'pedido_id' => 'required|integer',
            'estado' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // 2. Extraer datos limpios
            $datos = $request->only(['usuario', 'pedido_id', 'estado']);
            $destinatario = $request->input('email');

            // 3. Enviar el correo usando la clase Mailable
            Mail::to($destinatario)->send(new NotificacionPedido($datos));

            // 4. Respuesta exitosa en JSON
            return response()->json([
                'status' => 'success',
                'message' => 'Correo enviado exitosamente mediante Google SMTP.',
                'data' => [
                    'enviado_a' => $destinatario,
                    'pedido_id' => $datos['pedido_id'],
                ],
            ], 200);

        } catch (\Exception $e) {
            // Capturar errores en caso de fallo de red o credenciales SMTP inválidas
            return response()->json([
                'status' => 'error',
                'message' => 'Error al conectar con el servidor SMTP de Google.',
                'error_detail' => $e->getMessage(),
            ], 500);
        }
    }
}
