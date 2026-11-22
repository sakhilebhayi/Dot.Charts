<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Confirm it is your account · Dot.Charts</title>
@include('auth._skin')
</head>
<body>
<main class="wrap" id="main">
  <div class="mark"><a href="/" aria-label="Dot.Charts home"><img src="/images/logo.png" alt="Dot.Charts" width="150" height="113"></a></div>
  <h1>Confirm it is your account</h1>
  <div class="panel">
    <p>A Dot.Charts account already uses the email address you signed in with. To link it, enter the {{ $codeLength }}-digit code we sent to <strong>{{ $maskedEmail }}</strong>. It works for {{ $minutes }} minutes.</p>
    <p class="muted">Your charts, backtests, strategies and journal stay exactly as they are.</p>
    @if (session('dotsso_status'))<p class="sent" role="status">{{ session('dotsso_status') }}</p>@endif
    <form method="post" action="{{ $verifyUrl }}" novalidate>
      @csrf
      <label for="code">Confirmation code</label>
      <input id="code" name="code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="{{ $codeLength }}" required autofocus
             aria-describedby="code-error" @if ($errors->first('code')) aria-invalid="true" @endif>
      <p id="code-error" class="err" role="alert">{{ $errors->first('code') }}</p>
      <button class="btn primary" type="submit">Confirm and sign in</button>
    </form>
    <div class="row">
      <form method="post" action="{{ $resendUrl }}">@csrf<button class="btn" type="submit">Send a new code</button></form>
      <form method="post" action="{{ $cancelUrl }}">@csrf<button class="btn" type="submit">Cancel</button></form>
    </div>
    <p class="fine">Not expecting this? Cancel, and nothing changes. Without the code nobody can reach the account.</p>
  </div>
</main>
</body>
</html>
