<!DOCTYPE html>
<html lang="ar" dir="rtl">
<body style="font-family: Arial, Tahoma, sans-serif; line-height: 1.7; color: #1f2937;">
    <p>{{ __('ads.health.mail.'.$reason, [], 'ar') }}@if ($subject_ !== '') ({{ $subject_ }})@endif</p>
    <p>{{ __('ads.health.mail.open', [], 'ar') }}: <a href="{{ $link }}">{{ $link }}</a></p>
    <hr>
    <div dir="ltr" style="text-align: left;">
        <p>{{ __('ads.health.mail.'.$reason, [], 'en') }}@if ($subject_ !== '') ({{ $subject_ }})@endif</p>
        <p>{{ __('ads.health.mail.open', [], 'en') }}: <a href="{{ $link }}">{{ $link }}</a></p>
    </div>
</body>
</html>
