<?php

use App\Confirmations\EgyptPhone;

it('normalises Egyptian mobiles to E.164', function (?string $raw, ?string $expected) {
    expect(EgyptPhone::toE164($raw))->toBe($expected);
})->with([
    'local' => ['01012345678', '+201012345678'],
    'no trunk zero' => ['1112345678', '+201112345678'],
    'country code' => ['201212345678', '+201212345678'],
    'plus country code' => ['+201512345678', '+201512345678'],
    'double zero' => ['00201012345678', '+201012345678'],
    'country code with trunk zero' => ['+20 010 1234 5678', '+201012345678'],
    'spaces and dashes' => ['010-1234 5678', '+201012345678'],
    'brackets' => ['(+20) 10 1234-5678', '+201012345678'],
    'arabic-indic digits' => ['٠١٠١٢٣٤٥٦٧٨', '+201012345678'],
    'eastern arabic digits' => ['۰۱۱۱۲۳۴۵۶۷۸', '+201112345678'],
    'null' => [null, null],
    'empty' => ['', null],
    'letters' => ['no phone', null],
    'landline' => ['0223456789', null],
    'unknown operator' => ['01312345678', null],
    'too short' => ['0101234567', null],
    'too long' => ['010123456789', null],
    'other country' => ['+966501234567', null],
    'saudi local' => ['0501234567', null],
]);
