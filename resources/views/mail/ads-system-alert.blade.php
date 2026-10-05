<!DOCTYPE html>
<html lang="ar" dir="rtl">
<body style="font-family: Arial, Tahoma, sans-serif; line-height: 1.7; color: #1f2937;">
    <ul>
        @foreach ($lines as $l)
            <li>{{ __('ads.health.mail.'.$l['reason'], [], 'ar') }}@if ($l['subject'] !== '') ({{ $l['subject'] }})@endif</li>
        @endforeach
    </ul>
    <p>{{ __('ads.health.mail.open', [], 'ar') }}: <a href="{{ $link }}">{{ $link }}</a></p>
    <hr>
    <div dir="ltr" style="text-align: left;">
        <ul>
            @foreach ($lines as $l)
                <li>{{ __('ads.health.mail.'.$l['reason'], [], 'en') }}@if ($l['subject'] !== '') ({{ $l['subject'] }})@endif</li>
            @endforeach
        </ul>
        <p>{{ __('ads.health.mail.open', [], 'en') }}: <a href="{{ $link }}">{{ $link }}</a></p>
    </div>
</body>
</html>