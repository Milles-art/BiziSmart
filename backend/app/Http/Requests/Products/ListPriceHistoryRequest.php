<?php
namespace App\Http\Requests\Products;
use Illuminate\Foundation\Http\FormRequest;
class ListPriceHistoryRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage_products') ?? false; }
    public function rules(): array { return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]; }
}
