@extends('lead-magnets::layout')

@section('title', __('lead-magnets::public.confirm_title'))

@section('content')
    {{-- Shown for a grant whose confirmation also subscribes a mailing list.
         Opening the link changes nothing; the button does. --}}
    <h1 data-state="awaiting-button">{{ __('lead-magnets::public.confirm_title') }}</h1>
    <p>{{ __('lead-magnets::public.confirm_body', ['title' => $resource?->title ?? '']) }}</p>
    <p data-consent>{{ $consent['text'] }}</p>
    <form method="POST" action="{{ $action }}">
        @csrf
        <button type="submit" style="font: inherit; padding: .75rem 1.25rem; min-height: 44px; cursor: pointer;">{{ __('lead-magnets::public.confirm_button') }}</button>
    </form>
@endsection
