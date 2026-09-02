@extends('errors._ecosystem-layout', ['code' => 500])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.75"><path d="M12 3l9.5 16.5H2.5L12 3z" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 10v4" stroke-linecap="round"/><circle cx="12" cy="17" r="0.15" fill="#ef4444" stroke="none"/></svg>
@endsection

@section('title', 'Something went wrong on our end')
@section('message', "That's on us, not you — an unexpected error interrupted this page. Try again in a moment; if it keeps happening, we're already looking into it.")

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #020617; font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go home</a>
@endsection
