<?php

namespace App\Http\Requests\Ads;

/** Update a material: same fields as create, plus the ids of files to remove. */
class UpdateMaterialRequest extends StoreMaterialRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'remove_file_ids' => ['nullable', 'array'],
            'remove_file_ids.*' => ['integer'],
        ];
    }

    /** @return list<int> */
    public function removeFileIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('remove_file_ids', [])));
    }
}
