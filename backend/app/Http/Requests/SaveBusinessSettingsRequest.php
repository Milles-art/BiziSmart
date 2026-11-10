<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasRole('owner') ?? false; }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'address' => ['present', 'nullable', 'string', 'max:500'],
            'phone' => ['present', 'nullable', 'string', 'max:40'], 'tin' => ['present', 'nullable', 'string', 'max:40'],
            'vrn' => ['present', 'nullable', 'string', 'max:40'], 'header' => ['present', 'nullable', 'string', 'max:300'],
            'footer' => ['present', 'nullable', 'string', 'max:300'], 'paper_width' => ['required', Rule::in(['58', '80', 'A4'])],
            'business_id' => ['prohibited'], 'subdomain' => ['prohibited'], 'vat_rate' => ['prohibited'],
            'owner_phone' => ['prohibited'], 'currency' => ['prohibited'], 'timezone' => ['prohibited'], 'settings' => ['prohibited']];
    }
}
