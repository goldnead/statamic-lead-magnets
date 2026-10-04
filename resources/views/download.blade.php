@extends('lead-magnets::layout')

@section('title', $resource->title)

@section('content')
    <h1>{{ $resource->title }}</h1>
    <p>{{ __('lead-magnets::public.files_body', ['count' => collect($groups)->sum(fn ($group) => count($group['files']))]) }}</p>

    @include('lead-magnets::partials.file-groups', ['groups' => $groups])

    <p class="muted">{{ __('lead-magnets::public.files_hint') }}</p>
@endsection
