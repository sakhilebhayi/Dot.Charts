{{-- Dot.Charts instrument skin for the sign-in claim and error pages. Same tokens as frontend/src/instrument.css (dark
     only, amber signal, square corners, hairlines, uppercase headings), inline so the pages need no build output. The
     font file is the Roboto the frontend ships (frontend/public/auth-fonts), with the system sans as a fallback. --}}
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<style>
  @font-face{font-family:Roboto;font-style:normal;font-weight:100 900;font-display:swap;src:url(/auth-fonts/roboto-latin-wght-normal.woff2) format("woff2")}
  :root{
    --bg:#01040d;--panel:#070d1c;--sunken:#040917;--line:rgba(148,163,184,.18);--text:#e8ebf2;--muted:#8496b0;--quiet:#6b7c99;
    --signal:#f2a70b;--on-signal:#211a06;--blue:#8ec9dc;--green:#2fd06d;--red:#ff4d55;
    --font:Roboto,system-ui,-apple-system,"Segoe UI",sans-serif;--mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
    color-scheme:dark;
  }
  *{box-sizing:border-box}
  html{background:var(--bg)}
  body{margin:0;min-height:100vh;min-height:100dvh;background:var(--bg);color:var(--text);font:15px/1.55 var(--font);-webkit-font-smoothing:antialiased}
  ::selection{background:rgba(242,167,11,.38);color:var(--text)}
  .wrap{max-width:460px;margin:0 auto;padding:24px 16px 48px}
  .mark{padding:24px 0 8px;text-align:center}
  .mark a{display:inline-block;line-height:0}
  .mark img{width:150px;height:auto;aspect-ratio:1181/886}
  h1{margin:8px 0 0;text-align:center;font-weight:700;font-size:clamp(24px,6vw,32px);letter-spacing:.01em;text-transform:uppercase;line-height:1.15;overflow-wrap:anywhere;text-wrap:balance}
  .panel{background:var(--panel);border:1px solid var(--line);padding:24px;margin-top:22px}
  .panel p{margin:0 0 14px;color:var(--text);max-width:62ch}
  .panel p.muted,.muted{color:var(--muted)}
  .sent{margin:0 0 14px;padding:10px 12px;background:rgba(47,208,109,.1);border:1px solid rgba(47,208,109,.35);color:var(--green);font-size:14px}
  label{display:block;font-weight:600;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:18px 0 7px}
  input{width:100%;min-height:56px;padding:8px 12px;background:var(--sunken);color:var(--text);border:1px solid var(--line);border-radius:0;
    font:600 26px/1 var(--mono);letter-spacing:.35em;text-align:center;font-variant-numeric:tabular-nums}
  input:focus{outline:none;border-color:var(--signal)}
  input:focus-visible{outline:2px solid var(--signal);outline-offset:1px}
  input[aria-invalid="true"]{border-color:var(--red)}
  .err{min-height:1.55em;margin:8px 0 0;color:var(--red);font-size:14px}
  .btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 22px;border-radius:0;border:1px solid var(--line);background:transparent;
    color:var(--text);font:700 14px/1 var(--font);letter-spacing:.05em;text-transform:uppercase;text-decoration:none;cursor:pointer}
  .btn:hover{border-color:var(--muted)}
  .btn:active{transform:translateY(1px)}
  .btn:focus-visible{outline:2px solid var(--signal);outline-offset:2px}
  .btn.primary{background:var(--signal);border-color:var(--signal);color:var(--on-signal);width:100%}
  .btn.primary:hover{filter:brightness(1.08)}
  .row{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}
  .row form{flex:1 1 150px;margin:0}
  .row .btn{width:100%}
  .actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}
  .actions .btn{flex:1 1 180px}
  .ref{margin:18px 0 0;font:12.5px/1.4 var(--mono);color:var(--quiet);letter-spacing:.04em}
  .panel p.fine{margin:18px 0 0;font-size:14px;color:var(--muted)}
  @media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important}}
</style>
