<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $headline }} · Dot.Charts</title>
@include('auth._skin')
</head>
<body>
<main class="wrap" id="main" role="alert">
  <div class="mark"><a href="/" aria-label="Dot.Charts home"><img src="/images/logo.png" alt="Dot.Charts" width="150" height="113"></a></div>
  <h1>{{ $headline }}</h1>
  <div class="panel">
    <p>{{ $body }}</p>
    <div class="actions">
      @if ($retry)<a class="btn primary" href="{{ $retryUrl }}">Try signing in again</a>@endif
      <a class="btn" href="{{ $homeUrl }}">Back to the console</a>
    </div>
    <p class="ref">Reference: {{ $code }}</p>
  </div>
</main>
</body>
</html>
