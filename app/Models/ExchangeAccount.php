<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'environment',
        'api_key',
        'api_secret',
        'is_enabled',
    ];

    protected $hidden = [
        'api_key',
        'api_secret',
    ];

    protected $attributes = [
        'environment' => 'testnet',
        'is_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'is_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
