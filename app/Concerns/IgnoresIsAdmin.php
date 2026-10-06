<?php

namespace App\Concerns;

use Illuminate\Support\Arr;

/**
 * For API Form Requests: is_admin can only be changed on the web Users page,
 * so the API drops it from the input and the rules. It is then never
 * validated, never in validated() and never saved.
 */
trait IgnoresIsAdmin
{
    /**
     * Get the data to validate, without is_admin.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return Arr::except($this->all(), ['is_admin']);
    }

    /**
     * Get the parent Form Request's rules, without is_admin.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['is_admin']);
    }

    /**
     * Get the parent Form Request's documented body parameters, without is_admin.
     *
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return Arr::except(parent::bodyParameters(), ['is_admin']);
    }
}
