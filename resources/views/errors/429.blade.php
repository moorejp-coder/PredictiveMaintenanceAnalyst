@php
    // Laravel sends the wait in a Retry-After header when a rate limit stops a request.
    $wait = (int) (($exception->getHeaders()['Retry-After'] ?? 0));
@endphp
@extends('errors.layout')
@section('title', 'Too many requests')
@section('code', '429')
@section('heading', 'Too Many Requests')
@section('lead')
    You sent a lot of requests in a short time, so PRISM paused you.
    @if ($wait > 0)
        Try again in {{ $wait }} {{ $wait === 1 ? 'second' : 'seconds' }}.
    @else
        Wait a minute, then try again.
    @endif
@endsection
