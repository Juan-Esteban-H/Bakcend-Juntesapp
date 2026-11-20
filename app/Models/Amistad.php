<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Amistad extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'usuario_id',
        'amigo_id',
        'estado',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'estado' => 'string',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function amigo()
    {
        return $this->belongsTo(User::class, 'amigo_id');
    }

    public function scopeAceptadas($query)
    {
        return $query->where('estado', 'aceptado');
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }
}
