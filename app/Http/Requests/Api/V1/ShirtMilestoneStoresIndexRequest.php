<?php

namespace App\Http\Requests\Api\V1;

/**
 * The multi-store queue: the store index's filters plus the stores to read.
 *
 * `storeIds` is required, not optional — the auth server authorizes this
 * route per store from exactly this parameter, so an empty list would mean
 * "no store to check" rather than "every store".
 */
class ShirtMilestoneStoresIndexRequest extends ShirtMilestoneIndexRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),

            // Store NUMBERS, matching every other endpoint.
            'storeIds' => ['required', 'array', 'min:1'],
            'storeIds.*' => ['required', 'string', 'max:64'],
        ];
    }
}
