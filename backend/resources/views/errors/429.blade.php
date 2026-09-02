@extends('errors._ecosystem-layout', ['code' => 429])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#facc15" stroke-width="1.75"><path d="M13 3L4 14h6l-1 7 9-11h-6l1-7z" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('title', 'Easy does it')
@section('message', "You've hit the rate limit for now — backtests and chart reads are compute-heavy, so we pace them. Give it a few minutes, or log in for higher limits.")

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #020617; font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go home</a>
    <a href="/login.html" class="press link-underline" style="padding: 12px 24px; color: var(--text); font-size: 15px; text-decoration: none;">Log in</a>
@endsection
