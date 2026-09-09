<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'must_change_password', 'password_changed_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected string $guard_name = 'web';
    
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'ui_preferences' => 'array',
        ];
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($email));

        return $normalized === '' ? null : $normalized;
    }

    public static function findByEmail(string $email): ?self
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === null) {
            return null;
        }

        return static::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->first();
    }

    public static function emailIsTaken(string $email, ?int $ignoreUserId = null): bool
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === null) {
            return false;
        }

        return static::query()
            ->withTrashed()
            ->when($ignoreUserId !== null, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->exists();
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: function (mixed $value): ?string {
                if (! is_string($value)) {
                    return null;
                }

                return self::normalizeEmail($value);
            },
        );
    }

    // --- NUEVAS RELACIONES ---

    // Saber qué proyectos creó este usuario
    public function createdProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    // Saber qué proyectos actualizó este usuario
    public function updatedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'updated_by');
    }
}