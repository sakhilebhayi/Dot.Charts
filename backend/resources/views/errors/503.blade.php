@extends('errors._ecosystem-layout', ['code' => 503])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#facc15" stroke-width="1.75"><path d="M14.7 6.3a5 5 0 00-6.9 6.9L3 18v3h3l4.8-4.8a5 5 0 006.9-6.9L14 13l-3-3 3.7-3.7z" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('title', 'Down for maintenance')
@section('message', "Dot.Charts is briefly offline while we ship an improvement. We'll be back shortly — your backtests, strategies, and journal are safe.")

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #020617; font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Try again</a>
@endsection
