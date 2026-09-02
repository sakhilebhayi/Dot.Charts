@extends('errors._ecosystem-layout', ['code' => 403])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.75"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 018 0v3" stroke-linecap="round"/></svg>
@endsection

@section('title', "You don't have access to this")
@section('message', "This page is restricted — if you think you should have access, log in with the right account and try again.")

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #020617; font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go home</a>
    <a href="/login.html" class="press link-underline" style="padding: 12px 24px; color: var(--text); font-size: 15px; text-decoration: none;">Log in</a>
@endsection
