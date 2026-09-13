<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CityController extends Controller
{
    use RespondsWithData;

    public function index(): Response
    {
        return Inertia::render('settings/Cities', [
            'cities' => City::orderBy('name_ar')->get(),
        ]);
    }

    public function store(Request $request): HttpResponse
    {
        $city = City::create($this->validated($request));

        return $this->done($request, $city, 201);
    }

    public function update(Request $request, City $city): HttpResponse
    {
        $city->update($this->validated($request));

        return $this->done($request, $city);
    }

    public function destroy(Request $request, City $city): HttpResponse
    {
        $city->delete();

        return $this->done($request, ['id' => $city->id]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'shipping_fee' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
