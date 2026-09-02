@extends('errors._ecosystem-layout', ['code' => 419])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#facc15" stroke-width="1.75"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('title', 'This page expired')
@section('message', "You were away for a while and the page timed out. Refresh and pick up where you left off.")

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #020617; font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go home</a>
@endsection
