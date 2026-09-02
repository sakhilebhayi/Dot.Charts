@extends('errors._ecosystem-layout', ['code' => 404])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#22d3ee" stroke-width="1.75"><circle cx="11" cy="11" r="7" stroke-linecap="round"/><path d="M21 21l-4.35-4.35" stroke-linecap="round"/></svg>
@endsection

@section('title', "This chart doesn't exist")
@section('message', "The page you're after isn't here — it may have moved, or the link was mistyped. The markets are still open, though.")

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #020617; font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go home</a>
    <a href="/backtest.html" class="press link-underline" style="padding: 12px 24px; color: var(--text); font-size: 15px; text-decoration: none;">Run a backtest</a>
@endsection
