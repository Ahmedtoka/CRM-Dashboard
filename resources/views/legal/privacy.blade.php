@extends('legal.layout')

@section('body')
    @foreach ($content['sections'] as $section)
        @include('legal.partials.section', ['section' => $section])
    @endforeach
@endsection
