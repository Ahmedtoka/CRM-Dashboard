<?php

namespace App\Http\Requests\Ads;

use App\Ads\Materials\MaterialFileStorage;
use App\Ads\Materials\MaterialService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/** Create a material: content users and supervisors (a media buyer takes and activates materials, never authors them). */
class StoreMaterialRequest extends FormRequest
{
    public const LINK_FIELDS = ['website_links', 'drive_links', 'ig_links'];

    public function authorize(): bool
    {
        $u = $this->user();

        return $u !== null && MaterialService::canAuthor($u);
    }

    /** Links arrive as one string (comma or newline separated) or an array: normalise to a list of trimmed URLs. */
    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (self::LINK_FIELDS as $field) {
            $raw = $this->input($field);
            $items = is_array($raw) ? $raw : preg_split('/[\r\n,]+/', (string) $raw);
            $merge[$field] = array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) $items), fn ($v) => $v !== '' && $v !== null));
        }
        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $links = ['nullable', 'array', 'max:20'];

        return [
            'title' => ['required', 'string', 'max:200'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'collection_ids' => ['nullable', 'array'],
            'collection_ids.*' => ['integer', Rule::exists('ad_material_collections', 'id')],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', Rule::in(MaterialService::TYPES)],
            'website_links' => $links,
            'drive_links' => $links,
            'ig_links' => $links,
            'website_links.*' => ['string', 'max:2000', 'url:http,https'],
            'drive_links.*' => ['string', 'max:2000', 'url:http,https'],
            'ig_links.*' => ['string', 'max:2000', 'url:http,https'],
            'content_notes' => ['nullable', 'string', 'max:5000'],
            'media_buyer_id' => ['nullable', 'integer', Rule::exists('media_buyers', 'id')],
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', function (string $attribute, mixed $value, \Closure $fail) {
                if (! $value instanceof UploadedFile) {
                    return; // the `file` rule reports it
                }
                $storage = app(MaterialFileStorage::class);
                $kind = MaterialFileStorage::kind($storage->allowedMime($value));
                if ($kind === null) {
                    $fail(__('ads.materials.file_type', ['name' => $value->getClientOriginalName()]));
                } elseif ($value->getSize() > $storage->maxBytes($kind)) {
                    $fail(__('ads.materials.file_too_big', ['name' => $value->getClientOriginalName(), 'mb' => (int) config("crm.ads.material_max_mb.{$kind}")]));
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'website_links.*.url' => __('ads.materials.bad_link'),
            'drive_links.*.url' => __('ads.materials.bad_link'),
            'ig_links.*.url' => __('ads.materials.bad_link'),
            'files.*.file' => __('ads.materials.file_upload_failed'),
        ];
    }

    /** @return list<UploadedFile> */
    public function uploads(): array
    {
        return array_values(array_filter((array) $this->file('files'), fn ($f) => $f instanceof UploadedFile));
    }
}
