<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Approved WhatsApp templates for the mobile template picker (spec §5.6),
 * mirrored from `config('crm.whatsapp_templates')` — the same list the web
 * `TemplatePicker.vue` reads via the shared Inertia prop.
 */
class WhatsappTemplateController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => config('crm.whatsapp_templates', [])]);
    }
}
