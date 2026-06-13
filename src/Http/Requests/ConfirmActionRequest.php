<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body of the endpoint `POST /chatbot/actions/{action}/confirm` (E16).
 *
 *  - `accept`   required bool. true = the user approves; false = rejects.
 *  - `result`   optional array. If sent with `accept=true`, it indicates that the
 *               widget already executed the primitive and reports the outcome — the row
 *               transitions directly to `executed`. Without `result` and with
 *               `accept=true`, the row stays at `confirmed` and the widget
 *               will execute later and call the endpoint again with result.
 *               If it arrives with `accept=false`, it is persisted as the
 *               rejection reason (e.g. `{reason: 'User reason'}`).
 *
 * Authorization by ownership of the parent conversation (404-not-403,
 * E10/D12 doctrine) is applied by the controller with `forUser`.
 */
class ConfirmActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Defense-in-depth: the group's `auth` middleware already guarantees
        // a user; here we confirm that the request carries it attached.
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'accept' => ['required', 'boolean'],
            'result' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
