<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExchangeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'environment' => ['required', 'string', Rule::in(['testnet', 'mainnet'])],
            'api_key' => ['required', 'string', 'max:256'],
            'api_secret' => ['required', 'string', 'max:512'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
