@extends('lead-magnets::layout')

@section('title', __('lead-magnets::public.confirmed_title'))

@section('content')
    @if ($lapsed)
        <h1 data-state="lapsed">{{ __('lead-magnets::public.lapsed_title') }}</h1>
        <p>{{ __('lead-magnets::public.lapsed_body') }}</p>
    @elseif ($grant->isActive())
        <h1 data-state="active">{{ __('lead-magnets::public.confirmed_title') }}</h1>
        <p>{{ __('lead-magnets::public.confirmed_body', ['title' => $resource?->title ?? '']) }}</p>
        <p class="muted">{{ __('lead-magnets::public.confirmed_hint', ['email' => $grant->email]) }}</p>
    @else
        {{-- Revoked, or expired after activation. Says so without explaining
             which, because this page is opened by whoever holds the link. --}}
        <h1 data-state="{{ $grant->state }}">{{ __('lead-magnets::public.unavailable_title') }}</h1>
        <p>{{ __('lead-magnets::public.unavailable_body') }}</p>
    @endif
@endsection
