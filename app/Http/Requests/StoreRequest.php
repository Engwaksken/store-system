<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->route('store');

        return $store ? Gate::allows('update', $store) : Gate::allows('create', Store::class);
    }

    /** The form posts user_ids="" when no storekeeper is ticked, so an admin can clear all assignments. */
    protected function prepareForValidation(): void
    {
        if ($this->has('user_ids') && ! is_array($this->input('user_ids'))) {
            $this->merge(['user_ids' => []]);
        }
    }

    public function rules(): array
    {
        $storeId = $this->route('store')?->getKey();

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('stores', 'code')->ignore($storeId)],
            'name' => ['required', 'string', 'max:255', Rule::unique('stores', 'name')->ignore($storeId)],
            'type' => ['sometimes', Rule::in(array_keys(Store::TYPES))],
            'project_id' => ['nullable', 'required_if:type,site', 'integer', 'exists:projects,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', Role::Storekeeper->value)],
        ];
    }

    /** Store attributes only (assignments are handled separately). */
    public function storeData(): array
    {
        $data = collect($this->validated())->except('user_ids')->all();
        $data['type'] ??= 'central';
        $data['is_active'] = $this->boolean('is_active', true);
        if ($data['type'] === 'central') {
            $data['project_id'] = null;
        }

        return $data;
    }
}
