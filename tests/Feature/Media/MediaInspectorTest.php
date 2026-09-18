<?php

use App\Media\MediaInspector;

/**
 * ISO-BMFF ("ftyp") major-brand detection (fix round 1): the brand sits at
 * bytes 8-12, right after the 4-byte box size and the "ftyp" box type itself.
 */
function ftypBytes(string $brand): string {
    return "\x00\x00\x00\x18ftyp".$brand."\x00\x00\x00\x00".$brand.'isom';
}

function writeSample(string $bytes): string {
    $path = tempnam(sys_get_temp_dir(), 'crm-ftyp');
    file_put_contents($path, $bytes);

    return $path;
}

it('maps the quicktime major brand to video/quicktime', function () {
    $mime = app(MediaInspector::class)->sniff(writeSample(ftypBytes('qt  ')));
    expect($mime)->toBe('video/quicktime');
});

it('maps any 3gp* major brand to video/3gpp', function () {
    $mime = app(MediaInspector::class)->sniff(writeSample(ftypBytes('3gp5')));
    expect($mime)->toBe('video/3gpp');
});

it('maps the M4A major brand to audio/mp4', function () {
    $mime = app(MediaInspector::class)->sniff(writeSample(ftypBytes('M4A ')));
    expect($mime)->toBe('audio/mp4');
});

it('maps generic mp4-container major brands to video/mp4', function (string $brand) {
    $mime = app(MediaInspector::class)->sniff(writeSample(ftypBytes($brand)));
    expect($mime)->toBe('video/mp4');
})->with(['isom', 'mp41', 'mp42', 'avc1']);

it('falls through to finfo for heic/avif family major brands instead of guessing video or audio', function (string $brand) {
    $mime = app(MediaInspector::class)->sniff(writeSample(ftypBytes($brand)));
    expect($mime)->not->toBe('video/mp4')->and($mime)->not->toBe('audio/mp4');
})->with(['heic', 'heix', 'mif1', 'avif']);
