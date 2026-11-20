<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->nombre,
            'email' => $this->correo,
            'phone' => $this->phone,
            'bio' => $this->bio,
            'avatar_url' => self::avatarUrl($this->avatar_url),
        ];
    }

    public static function avatarUrl(?string $avatarPath): ?string
    {
        if ($avatarPath === null || $avatarPath === '') {
            return null;
        }

        if (Str::startsWith($avatarPath, ['http://', 'https://'])) {
            return $avatarPath;
        }

        return Storage::disk('public')->url($avatarPath);
    }
}
