<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;

/** The tag picker's options on the mobile app: every tag by name, as the web inbox gets them. */
class TagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Tag::orderBy('name')->get(['id', 'name', 'color'])
            ->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values()]);
    }
}
