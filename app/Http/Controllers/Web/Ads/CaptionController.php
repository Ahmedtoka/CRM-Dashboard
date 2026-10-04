<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Captions\CaptionException;
use App\Ads\Captions\GeneratesCaptions;
use App\Http\Controllers\Controller;
use App\Models\AdMaterial;
use App\Models\AdMaterialCaption;
use App\Models\AdMaterialFile;
use App\Support\Emoji;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AI captions of a material's video: list, generate (replaces), hand edit. Open to the whole materials audience; publishing is checked elsewhere. */
class CaptionController extends Controller
{
    public function index(Request $request, AdMaterial $material): JsonResponse
    {
        $file = $this->file($request, $material);

        return response()->json(['captions' => $this->rows($material, $file)]);
    }

    public function generate(Request $request, AdMaterial $material, GeneratesCaptions $generator): JsonResponse
    {
        $file = $this->file($request, $material);

        try {
            $generator->generate($material, $file);
        } catch (CaptionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['captions' => $this->rows($material, $file), 'frames_used' => $generator->framesUsed()]);
    }

    public function update(Request $request, AdMaterialCaption $caption): JsonResponse
    {
        $request->merge([
            'headline' => Emoji::strip((string) $request->input('headline', '')),
            'primary_text' => Emoji::strip((string) $request->input('primary_text', '')),
        ]);
        $data = $request->validate([
            'headline' => ['required', 'string', 'max:'.GeneratesCaptions::MAX_HEADLINE],
            'primary_text' => ['required', 'string', 'max:'.GeneratesCaptions::MAX_TEXT],
            'cta' => ['required', Rule::in(GeneratesCaptions::CTAS)],
        ]);

        $caption->update(['headline' => trim($data['headline']), 'primary_text' => trim($data['primary_text']), 'cta' => $data['cta'], 'edited_by_id' => $request->user()->id]);

        return response()->json(['caption' => $this->row($caption->refresh())]);
    }

    /** The requested file (default: the first video) of this material, 422 when it is not one of its videos. */
    private function file(Request $request, AdMaterial $material): AdMaterialFile
    {
        $request->validate(['file_id' => ['nullable', 'integer']]);
        $query = $material->files()->where('mime', 'like', 'video/%');
        $file = $request->filled('file_id') ? $query->whereKey((int) $request->input('file_id'))->first() : $query->first();

        if ($file === null) {
            abort(response()->json(['message' => __('ads.captions.no_video')], 422));
        }

        return $file;
    }

    /** @return list<array<string, mixed>> */
    private function rows(AdMaterial $material, AdMaterialFile $file): array
    {
        return AdMaterialCaption::query()->where('ad_material_id', $material->id)->where('ad_material_file_id', $file->id)
            ->orderBy('position')->get()->map(fn (AdMaterialCaption $c) => $this->row($c))->all();
    }

    /** @return array<string, mixed> */
    private function row(AdMaterialCaption $c): array
    {
        return [
            'id' => $c->id, 'file_id' => $c->ad_material_file_id, 'position' => $c->position, 'angle' => $c->angle,
            'headline' => $c->headline, 'primary_text' => $c->primary_text, 'cta' => $c->cta,
            'edited' => $c->edited_by_id !== null, 'model' => $c->model,
        ];
    }
}
