<?php

namespace App\Http\Requests;

use App\Models\StockTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', StockTransfer::class);
    }

    public function rules(): array
    {
        return [
            'from_store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true), 'different:to_store_id'],
            'to_store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer', 'distinct', Rule::exists('inventory_items', 'id')->where('is_active', true)],
            'lines.*.qty' => ['nullable', 'required_with:lines.*.item_id', 'numeric', 'gt:0', 'decimal:0,3'],
        ];
    }
}
