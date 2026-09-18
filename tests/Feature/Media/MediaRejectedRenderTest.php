<?php

use App\Media\MediaRejected;
use Illuminate\Support\Facades\Route;

/**
 * Task 2 wires the real upload endpoint that throws MediaRejected in
 * practice; this exercises bootstrap/app.php's render callback directly
 * against a throwaway route so a rejection never surfaces as a 500 for
 * either a JSON or a plain web/Inertia request.
 */
beforeEach(function () {
    Route::middleware('web')->get('/__test/media-rejected', function () {
        throw new MediaRejected('نوع الملف ده مش مدعوم');
    });
});

it('renders a MediaRejected as 422 json for a json request', function () {
    $this->getJson('/__test/media-rejected')
        ->assertStatus(422)
        ->assertJsonPath('message', 'نوع الملف ده مش مدعوم')
        ->assertJsonPath('errors.file.0', 'نوع الملف ده مش مدعوم');
});

it('renders a MediaRejected as a redirect back with a session error for a web request', function () {
    $response = $this->get('/__test/media-rejected');

    $response->assertRedirect();
    $response->assertSessionHasErrors(['file' => 'نوع الملف ده مش مدعوم']);
});
