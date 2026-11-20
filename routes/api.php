<?php

use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\AmistadController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\JunteController;
use App\Http\Controllers\LocationSearchController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
    Route::post('/google', [AuthController::class, 'googleLogin'])->middleware('throttle:10,1')->name('google');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1')->name('forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('reset-password');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::match(['put', 'post'], '/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/locations/search', [LocationSearchController::class, 'search'])
        ->middleware('throttle:30,1')
        ->name('locations.search');
    Route::get('/juntes', [JunteController::class, 'index'])->name('juntes.index');
    Route::get('/juntes/explorar', [JunteController::class, 'explorar'])->name('juntes.explore');
    Route::get('/juntes/{id}', [JunteController::class, 'show'])->whereNumber('id')->name('juntes.show');
    Route::post('/juntes', [JunteController::class, 'store'])->name('juntes.store');
    Route::post('/juntes/{id}/encuestas/fechas', [JunteController::class, 'storeDatePoll'])->whereNumber('id')->name('juntes.polls.store');
    Route::post('/juntes/{id}/encuestas/ubicaciones', [JunteController::class, 'storeLocationPoll'])->whereNumber('id')->name('juntes.location-polls.store');
    Route::post('/encuestas/{id}/opciones', [JunteController::class, 'storePollOption'])->whereNumber('id')->name('polls.options.store');
    Route::put('/encuestas/{id}/voto', [JunteController::class, 'votePoll'])->whereNumber('id')->name('polls.vote');
    Route::put('/encuestas/{id}/cerrar', [JunteController::class, 'closePoll'])->whereNumber('id')->name('polls.close');
    Route::put('/encuestas/{id}/ubicacion-oficial', [JunteController::class, 'setOfficialPollLocation'])->whereNumber('id')->name('polls.location.official');
    Route::put('/juntes/{id}/rsvp', [JunteController::class, 'updateRsvp'])->whereNumber('id')->name('juntes.rsvp');
    Route::put('/juntes/{id}', [JunteController::class, 'update'])->whereNumber('id')->name('juntes.update');
    Route::post('/juntes/{id}/cancelar', [JunteController::class, 'cancel'])->whereNumber('id')->name('juntes.cancel');
    Route::get('/alertas', [JunteController::class, 'alerts'])->name('alerts.index');
    Route::get('/juntes/{id}/mensajes', [ChatController::class, 'index'])->whereNumber('id')->name('chat.index');
    Route::post('/juntes/{id}/mensajes', [ChatController::class, 'store'])->whereNumber('id')->name('chat.store');
    Route::post('/juntes/{id}/mensajes/adjuntos', [ChatController::class, 'storeAttachment'])->whereNumber('id')->name('chat.attachments.store');
    Route::get('/juntes/{id}/gastos', [ExpenseController::class, 'show'])->whereNumber('id')->name('expenses.show');
    Route::put('/juntes/{id}/gastos', [ExpenseController::class, 'update'])->whereNumber('id')->name('expenses.update');
    Route::put('/juntes/{id}/gastos/pagos/{usuarioId}', [ExpenseController::class, 'updatePayment'])->whereNumber('id')->whereNumber('usuarioId')->name('expenses.payment');

    Route::prefix('amistades')->name('amistades.')->group(function (): void {
        Route::get('/', [AmistadController::class, 'index'])->name('index');
        Route::get('/buscar', [AmistadController::class, 'buscar'])->name('buscar');
        Route::post('/', [AmistadController::class, 'store'])->name('store');
        Route::put('/{id}/aceptar', [AmistadController::class, 'aceptar'])->whereNumber('id')->name('aceptar');
        Route::put('/{id}/rechazar', [AmistadController::class, 'rechazar'])->whereNumber('id')->name('rechazar');
        Route::delete('/{id}', [AmistadController::class, 'destroy'])->whereNumber('id')->name('destroy');
    });
});

// Ruta accesible mediante POST: http://tu-dominio.test
Route::post('/v1/pedidos/notificar', [PedidoController::class, 'enviarNotificacion']);
