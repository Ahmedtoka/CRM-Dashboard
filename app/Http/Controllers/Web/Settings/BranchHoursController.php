<?php

namespace App\Http\Controllers\Web\Settings;

use App\Analytics\ActivityLogger;
use App\Enums\ActorType;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «مواعيد الفروع» (audit 2026-10-16): the admin edits what App\Bot\Flows\BranchFinder shows a
 * customer about each store — its hours, phone and whether it is listed at all. Branches are
 * not created, deleted or moved between areas here: the full branches page stays removed
 * (fresh-orders F4), new stores still come in by migration.
 */
class BranchHoursController extends Controller
{
    use RespondsWithData;

    public const HOURS_MAX = 60;

    private const FIELDS = ['hours', 'phone', 'is_active'];

    public function index(): Response
    {
        return Inertia::render('settings/BranchHours', [
            'branches' => Branch::query()->orderBy('sort')->orderBy('id')->get()->map(fn (Branch $b) => $this->row($b))->all(),
        ]);
    }

    public function update(Request $request, Branch $branch, ActivityLogger $logger): HttpResponse
    {
        $data = $request->validate([
            // 60 leaves the phone room in Messenger's 80-character card subtitle (BranchFinder::cards()).
            'hours' => ['sometimes', 'nullable', 'string', 'max:'.self::HOURS_MAX],
            // Digits (Latin or Arabic-Indic), spaces, dashes and a leading +, 9–13 digits: what OutboundCards::phone()
            // turns into a number Meta accepts (a bad call button makes Meta reject the whole carousel).
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9٠-٩][0-9٠-٩ \-]*$/u', function (string $attribute, mixed $value, \Closure $fail) {
                $digits = preg_match_all('/[0-9٠-٩]/u', (string) $value);

                if ($digits < 9 || $digits > 13) {
                    $fail('validation.digits_between')->translate(['min' => 9, 'max' => 13]);
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch->fill(array_intersect_key($data, array_flip(self::FIELDS)));
        $changed = array_values(array_intersect(self::FIELDS, array_keys($branch->getDirty())));

        if ($changed !== []) {
            $branch->save();
            $logger->log(ActorType::User, $request->user(), ActivityLogger::BRANCH_UPDATED, $branch, null, ['name' => $branch->name, 'changed' => $changed]);
        }

        return $this->done($request, $this->row($branch));
    }

    /** @return array{id:int, name:string, area_ar:string, area_en:?string, hours:?string, phone:?string, is_active:bool} */
    private function row(Branch $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'area_ar' => $b->area_ar,
            'area_en' => $b->area_en,
            'hours' => $b->hours,
            'phone' => $b->phone,
            'is_active' => (bool) $b->is_active,
        ];
    }
}
