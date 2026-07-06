<x-filament-panels::page>

{{-- ═══════════════════════════════════════════════════════════════════════════
     Modpack Manager — Server Panel Page  ·  "Command Deck" experience
     ────────────────────────────────────────────────────────────────────────
     A search-first, command-palette layout: one hero search, inline source
     filter pills, a dense results FEED (rows, not a card grid), and a
     right-hand slide-in DRAWER for configuring + launching an install. Install
     progress renders as a vertical timeline console. Bold "game launcher"
     visual language, fully self-contained under `.mpm` so it renders correctly
     regardless of the host panel's compiled Tailwind. Adapts to light/dark via
     the `.dark` class on <html>. Alpine drives interactivity; Livewire the data.

     Overlays (scrim + drawer + log modal) are teleported to <body> so they escape
     the host panel's transformed/clipped content wrapper and can dim the whole
     viewport (incl. the panel header/footer). They keep this component's Alpine +
     Livewire scope via <template x-teleport>; loading states use Alpine flags since
     wire:loading is re-queried against the component subtree and won't cross the
     teleport. The drawer slide is a pure-CSS class toggle so it never depends on
     the host build shipping any particular utility class.
     ═══════════════════════════════════════════════════════════════════════════ --}}

<style>
    .mpm {
        --mpm-accent:    #7c5cff;
        --mpm-accent-2:  #22d3ee;
        --mpm-accent-lo: color-mix(in srgb, var(--mpm-accent) 14%, transparent);
        --mpm-glow:      color-mix(in srgb, var(--mpm-accent) 55%, transparent);

        --mpm-surface:   #ffffff;
        --mpm-surface-2: #f5f6fc;
        --mpm-surface-3: #ecedf6;
        --mpm-border:    #e6e7f2;
        --mpm-border-2:  #d9dbec;
        --mpm-text:      #16151f;
        --mpm-text-2:    #55536b;
        --mpm-muted:     #8b8aa3;

        --mpm-shadow:    0 1px 2px rgba(24,22,45,.06), 0 4px 14px rgba(24,22,45,.05);
        --mpm-shadow-lg: 0 24px 60px rgba(24,22,45,.22);

        --mpm-cf:  #f16436;
        --mpm-mr:  #18bf5d;
        --mpm-ftb: #e0403a;
        --mpm-atl: #1d9bf0;
        --mpm-success: #22c55e;
        --mpm-warn:    #f59e0b;
        --mpm-danger:  #ef4444;
        --mpm-blue:    #3b82f6;
    }
    .dark .mpm {
        /* Card fill matches the Modpacks page body background so there is no
           lightness step between a card and the surrounding gutters/sidebar — the whole UI
           reads as one tone. Cards are defined by borders; dark shadows are avoided because
           they spill into the empty page background and create horizontal tone bands. */
        --mpm-page-bg:   #050506;
        --mpm-surface:   var(--mpm-page-bg);
        --mpm-surface-2: #18181b;
        --mpm-surface-3: #23232a;
        --mpm-border:    rgba(255,255,255,.10);
        --mpm-border-2:  rgba(255,255,255,.16);
        --mpm-text:      #fafafa;
        --mpm-text-2:    #a1a1aa;
        --mpm-muted:     #71717a;

        --mpm-shadow:    none;
        --mpm-shadow-lg: 0 30px 70px rgba(0,0,0,.7);
    }

    .mpm { color: var(--mpm-text); }
    .mpm *, .mpm *::before, .mpm *::after { box-sizing: border-box; }
    .mpm [x-cloak] { display: none !important; }
    html.fi.dark,
    .dark body.fi-panel-server.fi-body,
    .dark .fi-layout,
    .dark .fi-main-ctn,
    .dark .fi-main,
    .dark .fi-page,
    .dark .fi-sidebar,
    .dark .fi-sidebar-nav {
        background: #050506 !important;
        background-color: #050506 !important;
    }
    .dark .mpm,
    .dark .mpm-shell {
        background:#050506;
    }
    .dark .mpm .mpm-hero__grid { display:none; }
    .dark .mpm .mpm-loadout__art,
    .dark .mpm .mpm-row__art,
    .dark .mpm .mpm-pill,
    .dark .mpm .mpm-pill .dot,
    .dark .mpm .mpm-pill.is-active,
    .dark .mpm .mpm-chip--src,
    .dark .mpm .mpm-cta--install,
    .dark .mpm .mpm-cta--update,
    .dark .mpm .mpm-omni__go,
    .dark .mpm .mpm-btn--primary,
    .dark .mpm .mpm-btn--update,
    .dark .mpm .mpm-btn--success {
        box-shadow:none !important;
    }
    .dark .mpm .mpm-loadout__label .live {
        animation:none;
        box-shadow:none;
    }
    .dark .mpm .mpm-row:hover,
    .dark .mpm .mpm-omni__field:focus-within {
        box-shadow:none;
    }
    .dark .mpm .mpm-omni__go:hover,
    .dark .mpm .mpm-row:hover .mpm-cta--install,
    .dark .mpm .mpm-btn--primary:hover:not(:disabled),
    .dark .mpm .mpm-btn--update:hover:not(:disabled) {
        filter:none;
    }

    /* ── Animations ── */
    @keyframes mpm-rise   { from { opacity:0; transform:translateY(14px);} to { opacity:1; transform:none; } }
    @keyframes mpm-spin   { to { transform: rotate(360deg); } }
    @keyframes mpm-shimmer{ 0%{background-position:-500px 0;} 100%{background-position:500px 0;} }
    @keyframes mpm-livepulse { 0%,100%{ filter:brightness(1); } 50%{ filter:brightness(1.22); } }
    @keyframes mpm-glowpulse { 0%,100%{ box-shadow:0 0 0 0 var(--mpm-glow);} 50%{ box-shadow:0 0 0 6px transparent;} }
    .mpm-rise { animation: mpm-rise .45s cubic-bezier(.16,1,.3,1) both; }
    .mpm-spin { animation: mpm-spin 1s linear infinite; }

    /* ── Shell ── */
    .mpm-shell { display:flex; flex-direction:column; gap:20px; }

    /* ══════════ LOADOUT (installed pack) hero strip ══════════ */
    .mpm-loadout {
        position:relative; overflow:hidden;
        display:flex; align-items:center; gap:18px;
        padding:18px 20px; border-radius:20px;
        background: var(--mpm-surface);
        border:1px solid color-mix(in srgb, var(--mpm-success) 34%, var(--mpm-border));
        box-shadow: var(--mpm-shadow);
    }
    .mpm-loadout__art { width:60px; height:60px; border-radius:15px; object-fit:cover; flex-shrink:0;
        border:1px solid var(--mpm-border-2); box-shadow:0 6px 18px rgba(0,0,0,.25); }
    .mpm-loadout__label { display:inline-flex; align-items:center; gap:7px; font-size:.64rem; font-weight:800;
        letter-spacing:.14em; text-transform:uppercase; color:var(--mpm-success); }
    .mpm-loadout__label .live { width:7px; height:7px; border-radius:50%; background:var(--mpm-success);
        box-shadow:0 0 0 0 var(--mpm-success); animation:mpm-glowpulse 2s infinite; }
    .mpm-loadout__name { font-size:1.05rem; font-weight:800; margin:4px 0 1px; letter-spacing:-.01em; }
    .mpm-loadout__ver  { font-size:.8rem; color:var(--mpm-text-2); }

    /* ══════════ Hero search (command palette) ══════════ */
    .mpm-hero {
        position:relative; overflow:hidden; border-radius:22px; padding:26px 26px 22px;
        background: var(--mpm-surface);
        border:1px solid var(--mpm-border);
        box-shadow:var(--mpm-shadow);
    }
    .mpm-hero__grid { position:absolute; inset:0; opacity:.5; pointer-events:none;
        background-image:linear-gradient(color-mix(in srgb, var(--mpm-accent) 8%, transparent) 1px, transparent 1px),
                         linear-gradient(90deg, color-mix(in srgb, var(--mpm-accent) 8%, transparent) 1px, transparent 1px);
        background-size:34px 34px; -webkit-mask-image:radial-gradient(80% 80% at 50% 0%, #000, transparent 75%);
        mask-image:radial-gradient(80% 80% at 50% 0%, #000, transparent 75%); }
    .mpm-hero__eyebrow { position:relative; display:inline-flex; align-items:center; gap:8px;
        font-size:.66rem; font-weight:800; letter-spacing:.16em; text-transform:uppercase; color:var(--mpm-accent); margin:0 0 6px; }
    .mpm-hero__eyebrow svg { width:15px; height:15px; }
    .mpm-hero__title { position:relative; font-size:1.5rem; font-weight:800; letter-spacing:-.02em; margin:0 0 2px; }
    .mpm-hero__sub { position:relative; font-size:.84rem; color:var(--mpm-text-2); margin:0 0 18px; }

    .mpm-omni { position:relative; }
    .mpm-omni__field { position:relative; display:flex; align-items:center;
        background:var(--mpm-surface-2); border:1.5px solid var(--mpm-border-2); border-radius:15px;
        transition:border-color .18s, box-shadow .18s, background .18s; }
    .mpm-omni__field:focus-within { border-color:var(--mpm-accent); background:var(--mpm-surface);
        box-shadow:0 0 0 4px var(--mpm-accent-lo), 0 8px 26px color-mix(in srgb, var(--mpm-accent) 16%, transparent); }
    .mpm-omni__ico { display:flex; padding-left:18px; color:var(--mpm-accent); }
    .mpm-omni__ico svg { width:20px; height:20px; }
    .mpm-omni input { flex:1; width:100%; height:56px; padding:0 16px; border:0; outline:0; background:transparent;
        color:var(--mpm-text); font-size:1.02rem; font-weight:500; }
    .mpm-omni input::placeholder { color:var(--mpm-muted); font-weight:400; }
    .mpm-omni__kbd { display:flex; align-items:center; gap:5px; padding-right:14px; }
    .mpm-omni__kbd kbd { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.66rem; font-weight:700;
        color:var(--mpm-text-2); background:var(--mpm-surface-3); border:1px solid var(--mpm-border-2);
        border-bottom-width:2px; border-radius:6px; padding:3px 7px; }
    .mpm-omni__go { flex-shrink:0; margin:6px; height:44px; padding:0 20px; border:0; cursor:pointer;
        display:inline-flex; align-items:center; gap:7px; border-radius:11px; font-weight:700; font-size:.88rem;
        color:#fff; background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 60%, var(--mpm-accent-2)));
        box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-accent) 40%, transparent); transition:transform .12s, filter .15s; }
    .mpm-omni__go:hover { filter:brightness(1.08); transform:translateY(-1px); }
    .mpm-omni__go svg { width:16px; height:16px; }

    /* ── Source pills ── */
    .mpm-pills { position:relative; display:flex; flex-wrap:wrap; gap:9px; margin-top:16px; }
    .mpm-pill { display:inline-flex; align-items:center; gap:8px; padding:8px 15px; border-radius:12px; cursor:pointer;
        font-size:.82rem; font-weight:700; letter-spacing:.01em; color:var(--mpm-text-2);
        background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:all .16s; }
    .mpm-pill:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); transform:translateY(-1px); }
    .mpm-pill .dot { width:9px; height:9px; border-radius:50%; box-shadow:0 0 6px 0 currentColor; }
    .mpm-pill--all .dot { background:conic-gradient(from 210deg, var(--mpm-cf), var(--mpm-mr), var(--mpm-atl), var(--mpm-ftb), var(--mpm-cf)); box-shadow:none; }
    .mpm-pill--cf  .dot { background:var(--mpm-cf);  color:var(--mpm-cf); }
    .mpm-pill--mr  .dot { background:var(--mpm-mr);  color:var(--mpm-mr); }
    .mpm-pill--ftb .dot { background:var(--mpm-ftb); color:var(--mpm-ftb); }
    .mpm-pill--atl .dot { background:var(--mpm-atl); color:var(--mpm-atl); }
    .mpm-pill.is-active { color:#fff; border-color:transparent;
        background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2)));
        box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-accent) 34%, transparent); }
    .mpm-pill--cf.is-active  { background:linear-gradient(135deg, var(--mpm-cf),  #c8471f); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-cf) 40%, transparent); }
    .mpm-pill--mr.is-active  { background:linear-gradient(135deg, var(--mpm-mr),  #0f9c48); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-mr) 40%, transparent); }
    .mpm-pill--ftb.is-active { background:linear-gradient(135deg, var(--mpm-ftb), #b52a25); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-ftb) 40%, transparent); }
    .mpm-pill--atl.is-active { background:linear-gradient(135deg, var(--mpm-atl), #1273c4); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-atl) 40%, transparent); }
    .mpm-pill.is-active .dot { background:rgba(255,255,255,.9); box-shadow:none; }

    /* subtle secondary action (last log) */
    .mpm-loglink { display:inline-flex; align-items:center; gap:8px; margin-top:16px; padding:8px 14px; border-radius:11px; cursor:pointer;
        font-size:.8rem; font-weight:700; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:all .15s; }
    .mpm-loglink:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); }
    .mpm-loglink svg { width:15px; height:15px; }

    /* ── Results header ── */
    .mpm-feed-head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; padding:0 4px; }
    .mpm-feed-head h3 { font-size:.95rem; font-weight:800; margin:0; letter-spacing:-.01em; }
    .mpm-feed-head .count { font-size:.75rem; color:var(--mpm-muted); font-weight:600; }

    /* ══════════ Results feed (rows) ══════════ */
    .mpm-feed { display:flex; flex-direction:column; gap:10px; }
    .mpm-row {
        --row-c: var(--mpm-accent);
        position:relative; display:flex; align-items:center; gap:16px; width:100%; text-align:left;
        padding:14px 16px; border-radius:16px; cursor:pointer;
        background:var(--mpm-surface); border:1px solid var(--mpm-border); box-shadow:var(--mpm-shadow);
        transition:transform .16s cubic-bezier(.16,1,.3,1), box-shadow .16s, border-color .16s, background .16s;
    }
    .mpm-row::before { content:""; position:absolute; left:0; top:12px; bottom:12px; width:3px; border-radius:3px;
        background:var(--row-c); opacity:0; transition:opacity .16s; }
    .mpm-row:hover { transform:translateX(3px); border-color:color-mix(in srgb, var(--row-c) 45%, var(--mpm-border));
        box-shadow:0 10px 28px color-mix(in srgb, var(--row-c) 16%, transparent); }
    .mpm-row:hover::before { opacity:1; }
    .mpm-row:focus-visible { outline:2px solid var(--mpm-accent); outline-offset:2px; }
    .mpm-row.is-installed { border-color:color-mix(in srgb, var(--mpm-success) 42%, var(--mpm-border)); }

    .mpm-row__art { position:relative; width:56px; height:56px; border-radius:13px; object-fit:cover; flex-shrink:0;
        border:1px solid var(--mpm-border-2); background:var(--mpm-surface-3); box-shadow:0 4px 12px rgba(0,0,0,.18); }
    .mpm-row__art--ph { display:flex; align-items:center; justify-content:center; color:var(--mpm-muted); }
    .mpm-row__body { flex:1; min-width:0; }
    .mpm-row__title { display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
    .mpm-row__name { font-size:.98rem; font-weight:800; margin:0; letter-spacing:-.01em; line-height:1.2;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
    .mpm-row__desc { font-size:.79rem; color:var(--mpm-text-2); line-height:1.45; margin:5px 0 0;
        display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; }
    .mpm-row__meta { display:flex; flex-wrap:wrap; align-items:center; gap:14px; margin-top:8px; font-size:.72rem; color:var(--mpm-muted); }
    .mpm-row__meta span { display:inline-flex; align-items:center; gap:5px; }
    .mpm-row__meta svg { width:13px; height:13px; }
    .mpm-row__meta .author { color:var(--mpm-text-2); font-weight:600; }

    /* provider + loader chips */
    .mpm-chip { display:inline-flex; align-items:center; gap:5px; font-size:.63rem; font-weight:800; letter-spacing:.03em;
        text-transform:uppercase; padding:3px 9px; border-radius:7px; --c:#8b8aa3;
        color:var(--c); background:color-mix(in srgb, var(--c) 14%, transparent);
        border:1px solid color-mix(in srgb, var(--c) 38%, transparent); }
    .mpm-chip::before { content:""; width:6px; height:6px; border-radius:50%; background:var(--c); }
    .mpm-chip--src { color:#fff; background:var(--c); border-color:var(--c); box-shadow:0 2px 8px color-mix(in srgb, var(--c) 45%, transparent); }
    .mpm-chip--src::before { background:rgba(255,255,255,.9); }

    /* row CTA */
    .mpm-row__cta { flex-shrink:0; display:flex; align-items:center; gap:12px; }
    .mpm-cta-state { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:11px;
        font-size:.82rem; font-weight:700; white-space:nowrap; transition:all .15s; }
    .mpm-cta-state svg { width:15px; height:15px; }
    .mpm-cta--install { color:#fff; background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2)));
        box-shadow:0 6px 16px color-mix(in srgb, var(--mpm-accent) 32%, transparent); }
    .mpm-row:hover .mpm-cta--install { filter:brightness(1.08); }
    .mpm-cta--update { color:#3a2400; background:linear-gradient(135deg, var(--mpm-warn), #f7b64c); box-shadow:0 6px 16px color-mix(in srgb, var(--mpm-warn) 34%, transparent); }
    .mpm-cta--installed { color:var(--mpm-success); background:color-mix(in srgb, var(--mpm-success) 14%, transparent);
        border:1px solid color-mix(in srgb, var(--mpm-success) 36%, transparent); }
    .mpm-row__chev { color:var(--mpm-muted); transition:transform .16s, color .16s; display:flex; }
    .mpm-row__chev svg { width:18px; height:18px; }
    .mpm-row:hover .mpm-row__chev { transform:translateX(3px); color:var(--mpm-accent); }

    /* ── Skeleton rows ── */
    .mpm-skel-row { display:flex; align-items:center; gap:16px; padding:14px 16px; border-radius:16px;
        background:var(--mpm-surface); border:1px solid var(--mpm-border); }
    .mpm-shimmer { background:linear-gradient(90deg, var(--mpm-surface-2) 25%, var(--mpm-surface-3) 50%, var(--mpm-surface-2) 75%);
        background-size:900px 100%; animation:mpm-shimmer 1.5s infinite linear; border-radius:7px; }

    /* ── Empty / error ── */
    .mpm-empty { display:flex; flex-direction:column; align-items:center; text-align:center; gap:12px; padding:64px 20px;
        border-radius:20px; background:var(--mpm-surface); border:1px dashed var(--mpm-border-2); color:var(--mpm-muted); }
    .mpm-empty__orb { width:72px; height:72px; border-radius:20px; display:flex; align-items:center; justify-content:center;
        color:var(--mpm-accent); background:var(--mpm-accent-lo); border:1px solid color-mix(in srgb, var(--mpm-accent) 30%, transparent); }
    .mpm-alert { display:flex; gap:13px; padding:16px 18px; border-radius:16px; align-items:flex-start;
        background:color-mix(in srgb, var(--mpm-danger) 10%, var(--mpm-surface));
        border:1px solid color-mix(in srgb, var(--mpm-danger) 34%, transparent); color:var(--mpm-danger); font-size:.85rem; }
    .mpm-alert svg { width:20px; height:20px; flex-shrink:0; margin-top:1px; }

    /* ══════════ INSTALL CONSOLE (vertical timeline) ══════════ */
    .mpm-console { position:relative; overflow:hidden; border-radius:22px; border:1px solid var(--mpm-border);
        background:var(--mpm-surface);
        box-shadow:var(--mpm-shadow); }
    .mpm-console__head { display:flex; align-items:center; justify-content:space-between; gap:18px; flex-wrap:wrap;
        padding:22px 24px; border-bottom:1px solid var(--mpm-border); }
    .mpm-console__eyebrow { font-size:.64rem; text-transform:uppercase; letter-spacing:.16em; font-weight:800; color:var(--mpm-accent); margin:0 0 5px; }
    .mpm-console__title { font-size:1.25rem; font-weight:800; margin:0; letter-spacing:-.01em; }
    .mpm-console__title.ok  { color:var(--mpm-success); }
    .mpm-console__title.err { color:var(--mpm-danger); }
    .mpm-clock { text-align:right; }
    .mpm-clock__k { font-size:.62rem; text-transform:uppercase; letter-spacing:.14em; color:var(--mpm-muted); font-weight:700; margin:0 0 2px; }
    .mpm-clock__n { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:1.6rem; font-weight:800; line-height:1;
        background:linear-gradient(135deg, var(--mpm-accent), var(--mpm-accent-2)); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }

    .mpm-console__body { padding:22px 24px; display:flex; flex-direction:column; gap:22px; }

    /* progress bar */
    .mpm-bar__top { display:flex; justify-content:space-between; align-items:center; font-size:.74rem; color:var(--mpm-text-2); margin-bottom:8px; }
    .mpm-bar__top b { font-family:ui-monospace,monospace; font-size:1rem; color:var(--mpm-text); }
    .mpm-bar__track { height:14px; border-radius:999px; background:var(--mpm-surface-3); overflow:hidden; border:1px solid var(--mpm-border); }
    .mpm-bar__fill { height:100%; border-radius:999px; transition:width .7s cubic-bezier(.16,1,.3,1);
        background:linear-gradient(135deg, var(--mpm-accent), var(--mpm-accent-2)); }
    .mpm-bar__fill.is-running { animation:mpm-livepulse 1.8s ease-in-out infinite; }
    .mpm-bar__fill.is-done   { background:linear-gradient(135deg, var(--mpm-success), #4ade80); }
    .mpm-bar__fill.is-failed { background:linear-gradient(135deg, var(--mpm-danger), #f87171); }

    /* vertical timeline */
    .mpm-timeline { display:flex; flex-direction:column; gap:2px; }
    .mpm-tl { position:relative; display:flex; align-items:center; gap:14px; padding:11px 0 11px 4px; }
    .mpm-tl__rail { position:relative; flex-shrink:0; width:34px; display:flex; justify-content:center; }
    .mpm-tl__node { position:relative; z-index:1; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center;
        background:var(--mpm-surface-3); color:var(--mpm-muted); border:2px solid var(--mpm-border-2); transition:all .3s; }
    .mpm-tl__node svg { width:15px; height:15px; }
    .mpm-tl:not(:last-child) .mpm-tl__rail::after { content:""; position:absolute; top:26px; bottom:-16px; left:50%; transform:translateX(-50%);
        width:2px; background:var(--mpm-border-2); transition:background .3s; }
    .mpm-tl__label { font-size:.9rem; font-weight:600; color:var(--mpm-text-2); transition:color .3s; }
    .mpm-tl.is-done  .mpm-tl__node { background:var(--mpm-success); border-color:var(--mpm-success); color:#fff; }
    .mpm-tl.is-done  .mpm-tl__rail::after { background:var(--mpm-success); }
    .mpm-tl.is-done  .mpm-tl__label { color:var(--mpm-text); }
    .mpm-tl.is-running .mpm-tl__node { background:var(--mpm-accent); border-color:var(--mpm-accent); color:#fff;
        box-shadow:0 0 0 5px var(--mpm-accent-lo); }
    .mpm-tl.is-running .mpm-tl__label { color:var(--mpm-text); font-weight:800; }
    .mpm-tl.is-failed .mpm-tl__node { background:var(--mpm-danger); border-color:var(--mpm-danger); color:#fff; }
    .mpm-tl.is-failed .mpm-tl__label { color:var(--mpm-danger); }

    /* terminal / debug log */
    .mpm-term { border-radius:14px; overflow:hidden; border:1px solid var(--mpm-border-2); background:#0a0b10; }
    .mpm-term__bar { display:flex; align-items:center; gap:9px; padding:9px 14px; background:#11131b; border-bottom:1px solid rgba(255,255,255,.06); }
    .mpm-term__dots { display:flex; gap:6px; }
    .mpm-term__dots i { width:11px; height:11px; border-radius:50%; display:block; }
    .mpm-term__dots i:nth-child(1){ background:#ff5f57; } .mpm-term__dots i:nth-child(2){ background:#febc2e; } .mpm-term__dots i:nth-child(3){ background:#28c840; }
    .mpm-term__title { font-family:ui-monospace,monospace; font-size:.72rem; color:#8b95a5; }
    .mpm-term__count { margin-left:auto; font-family:ui-monospace,monospace; font-size:.68rem; color:#5b6472;
        background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08); border-radius:6px; padding:2px 8px; }
    .mpm-term__body { height:210px; overflow-y:auto; padding:14px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
        font-size:.73rem; line-height:1.65; color:#4ade80; }
    .mpm-term__body::-webkit-scrollbar { width:8px; } .mpm-term__body::-webkit-scrollbar-thumb { background:rgba(255,255,255,.14); border-radius:4px; }
    .mpm-term__body .muted { color:#475569; }
    .mpm-term__foot { display:flex; align-items:center; gap:8px; padding:9px 14px; border-top:1px solid rgba(255,255,255,.06);
        font-size:.72rem; color:#8b95a5; }
    .mpm-term-toggle { display:inline-flex; align-items:center; gap:8px; background:none; border:0; cursor:pointer; font-size:.82rem; font-weight:600; color:var(--mpm-text-2); padding:0; }
    .mpm-term-toggle:hover { color:var(--mpm-text); }
    .mpm-term-toggle .chev { width:15px; height:15px; transition:transform .2s; } .mpm-term-toggle .chev.open { transform:rotate(90deg); }

    .mpm-now { display:inline-flex; align-items:center; gap:9px; font-size:.88rem; font-weight:700; color:var(--mpm-accent); }
    .mpm-now svg { width:17px; height:17px; }
    .mpm-console__foot { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:18px 24px; border-top:1px solid var(--mpm-border); }

    /* ── Buttons ── */
    .mpm-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:11px 20px; border-radius:12px;
        border:1px solid transparent; font-size:.86rem; font-weight:700; cursor:pointer; transition:all .15s; text-decoration:none; }
    .mpm-btn svg { width:16px; height:16px; }
    .mpm-btn:disabled { opacity:.5; cursor:not-allowed; }
    .mpm-btn--primary { color:#fff; background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2)));
        box-shadow:0 8px 22px color-mix(in srgb, var(--mpm-accent) 32%, transparent); }
    .mpm-btn--primary:hover:not(:disabled) { filter:brightness(1.08); transform:translateY(-1px); }
    .mpm-btn--update  { color:#3a2400; background:linear-gradient(135deg, var(--mpm-warn), #f7b64c); box-shadow:0 8px 22px color-mix(in srgb, var(--mpm-warn) 32%, transparent); }
    .mpm-btn--update:hover:not(:disabled) { filter:brightness(1.05); transform:translateY(-1px); }
    .mpm-btn--success { color:#fff; background:linear-gradient(135deg, var(--mpm-success), #4ade80); box-shadow:0 8px 22px color-mix(in srgb, var(--mpm-success) 30%, transparent); }
    .mpm-btn--ghost { background:var(--mpm-surface-2); border-color:var(--mpm-border-2); color:var(--mpm-text); }
    .mpm-btn--ghost:hover { background:var(--mpm-surface-3); }
    .mpm-btn--block { width:100%; }

    /* ══════════ Install DRAWER (slide-in from right) ══════════ */
    /* These overlays are teleported to <body> (see markup), escaping Filament's transformed/
       clipped content wrapper, so inset:0 is truly viewport-relative here. High z-index keeps
       them above the panel's own header/footer chrome. */
    .mpm-scrim { position:fixed; inset:0; z-index:9990; background:rgba(8,6,22,.92); backdrop-filter:blur(8px); }
    .mpm-ilbl { display:inline-flex; align-items:center; gap:8px; }
    .mpm-drawer { position:fixed; top:0; right:0; bottom:0; z-index:9991; width:100%; max-width:480px; display:flex; flex-direction:column;
        background:var(--mpm-surface); border-left:1px solid var(--mpm-border-2); box-shadow:var(--mpm-shadow-lg);
        transform:translateX(100%); visibility:hidden;
        transition:transform .32s cubic-bezier(.16,1,.3,1), visibility 0s linear .32s; }
    .mpm-drawer.is-open { transform:translateX(0); visibility:visible; transition:transform .32s cubic-bezier(.16,1,.3,1); }
    .mpm-drawer__starting { position:absolute; inset:0; z-index:5; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:16px; text-align:center;
        background:color-mix(in srgb, var(--mpm-surface) 88%, transparent); backdrop-filter:blur(4px); animation:mpm-rise .22s ease both; }
    .mpm-drawer__starting .mpm-spin { width:40px; height:40px; color:var(--mpm-accent); }
    .mpm-drawer__starting p { margin:0; font-weight:800; font-size:1rem; }
    .mpm-drawer__starting small { color:var(--mpm-text-2); }

    /* drawer hero (artwork banner) */
    .mpm-dhero { position:relative; overflow:hidden; padding:22px 22px 20px; flex-shrink:0; border-bottom:1px solid var(--mpm-border); }
    .mpm-dhero__bg { position:absolute; inset:0; background-size:cover; background-position:center; filter:blur(26px) saturate(1.4); opacity:.5; transform:scale(1.3); }
    .mpm-dhero__scrim { position:absolute; inset:0; background:linear-gradient(180deg, color-mix(in srgb, var(--mpm-surface) 40%, transparent), var(--mpm-surface)); }
    .mpm-dhero__row { position:relative; display:flex; align-items:flex-start; gap:15px; }
    .mpm-dhero__art { width:64px; height:64px; border-radius:15px; object-fit:cover; flex-shrink:0; border:1px solid var(--mpm-border-2); box-shadow:0 8px 22px rgba(0,0,0,.35); }
    .mpm-dhero__name { font-size:1.12rem; font-weight:800; margin:0; letter-spacing:-.01em; line-height:1.25; }
    .mpm-dhero__author { font-size:.8rem; color:var(--mpm-text-2); margin:4px 0 0; }
    .mpm-dhero__close { position:relative; margin-left:auto; flex-shrink:0; width:34px; height:34px; display:flex; align-items:center; justify-content:center;
        border-radius:10px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); color:var(--mpm-muted); cursor:pointer; transition:all .15s; }
    .mpm-dhero__close:hover { color:var(--mpm-text); background:var(--mpm-surface-3); }
    .mpm-dhero__close svg { width:18px; height:18px; }
    .mpm-dbadge { position:relative; display:inline-flex; align-items:center; gap:7px; margin-top:14px; padding:6px 14px; border-radius:999px; font-size:.72rem; font-weight:800; letter-spacing:.02em; }
    .mpm-dbadge .dot { width:8px; height:8px; border-radius:50%; }
    .mpm-dbadge--install { color:var(--mpm-accent); background:var(--mpm-accent-lo); border:1px solid color-mix(in srgb, var(--mpm-accent) 34%, transparent); }
    .mpm-dbadge--install .dot { background:var(--mpm-accent); }
    .mpm-dbadge--update { color:#b45309; background:color-mix(in srgb, var(--mpm-warn) 16%, transparent); border:1px solid color-mix(in srgb, var(--mpm-warn) 38%, transparent); }
    .dark .mpm-dbadge--update { color:var(--mpm-warn); }
    .mpm-dbadge--update .dot { background:var(--mpm-warn); }

    .mpm-drawer__body { flex:1; overflow-y:auto; padding:22px; display:flex; flex-direction:column; gap:20px; }
    .mpm-label { display:block; font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:var(--mpm-text-2); margin-bottom:9px; }

    .mpm-select-wrap { position:relative; }
    .mpm-select { width:100%; height:48px; padding:0 40px 0 15px; appearance:none; cursor:pointer;
        background:var(--mpm-surface-2); border:1.5px solid var(--mpm-border-2); border-radius:12px;
        color:var(--mpm-text); font-size:.9rem; font-weight:600; outline:none; transition:border-color .15s, box-shadow .15s; }
    .mpm-select:focus { border-color:var(--mpm-accent); box-shadow:0 0 0 4px var(--mpm-accent-lo); }
    .mpm-select-wrap .caret { position:absolute; right:14px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--mpm-muted); pointer-events:none; }
    .mpm-loadbox { display:flex; align-items:center; gap:10px; height:48px; padding:0 15px; border-radius:12px;
        background:var(--mpm-surface-2); border:1.5px solid var(--mpm-border-2); color:var(--mpm-muted); font-size:.88rem; }
    .mpm-loadbox svg { width:17px; height:17px; }

    /* preserve panel */
    .mpm-preserve { border:1px solid var(--mpm-border); background:var(--mpm-surface-2); border-radius:14px; padding:15px; }
    .mpm-preserve__head { display:flex; align-items:center; gap:9px; margin-bottom:11px; font-size:.8rem; font-weight:700; color:var(--mpm-text-2); }
    .mpm-preserve__head svg { width:16px; height:16px; color:var(--mpm-success); }
    .mpm-preserve__tags { display:flex; flex-wrap:wrap; gap:7px; }
    .mpm-tag { font-family:ui-monospace,monospace; font-size:.68rem; padding:4px 9px; border-radius:7px;
        background:var(--mpm-surface-3); border:1px solid var(--mpm-border); color:var(--mpm-text-2); }
    .mpm-preserve__note { font-size:.72rem; color:var(--mpm-muted); margin:11px 0 0; }

    /* option toggles */
    .mpm-opt { display:flex; align-items:flex-start; gap:13px; cursor:pointer; padding:13px 14px; border-radius:13px;
        background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:border-color .15s, background .15s; }
    .mpm-opt:hover { border-color:var(--mpm-border-2); }
    .mpm-opt input { position:absolute; opacity:0; width:0; height:0; }
    .mpm-opt__track { position:relative; flex-shrink:0; margin-top:1px; width:44px; height:25px; border-radius:999px;
        background:var(--mpm-surface-3); border:1px solid var(--mpm-border-2); transition:all .18s; }
    .mpm-opt__track::after { content:""; position:absolute; top:2px; left:2px; width:19px; height:19px; border-radius:50%; background:#fff;
        box-shadow:0 1px 3px rgba(0,0,0,.35); transition:transform .18s; }
    .mpm-opt input:checked + .mpm-opt__track { background:linear-gradient(135deg, var(--mpm-accent), var(--mpm-accent-2)); border-color:transparent; }
    .mpm-opt input:checked + .mpm-opt__track::after { transform:translateX(19px); }
    .mpm-opt__title { font-size:.87rem; font-weight:700; }
    .mpm-opt__desc { font-size:.75rem; color:var(--mpm-muted); margin-top:2px; line-height:1.4; }

    .mpm-drawer__foot { flex-shrink:0; padding:18px 22px; border-top:1px solid var(--mpm-border); background:var(--mpm-surface); display:flex; flex-direction:column; gap:12px; }
    .mpm-drawer__actions { display:flex; gap:11px; }
    .mpm-drawer__actions .mpm-btn--primary, .mpm-drawer__actions .mpm-btn--update { flex:1; }

    .mpm-link-existing { background:none; border:0; padding:0; cursor:pointer; font-size:.83rem; font-weight:700; color:var(--mpm-accent);
        text-decoration:underline; text-underline-offset:3px; }
    .mpm-link-existing:hover:not(:disabled) { color:var(--mpm-text); }
    .mpm-link-existing:disabled { opacity:.5; cursor:not-allowed; text-decoration:none; }
    .mpm-link-existing__hint { display:block; margin-top:5px; font-size:.72rem; color:var(--mpm-muted); }
    .mpm-link-wrap { text-align:center; padding-top:2px; }

    /* ══════════ Last-install-log modal (centered) ══════════ */
    .mpm-logscrim { position:fixed; inset:0; z-index:9992; display:flex; align-items:center; justify-content:center; padding:18px;
        background:rgba(6,4,20,.6); backdrop-filter:blur(5px); }
    .mpm-logmodal { width:100%; max-width:660px; max-height:86vh; display:flex; flex-direction:column; overflow:hidden;
        border-radius:20px; background:var(--mpm-surface); border:1px solid var(--mpm-border-2); box-shadow:var(--mpm-shadow-lg); }
    .mpm-logmodal__head { display:flex; align-items:flex-start; gap:12px; padding:20px 22px; border-bottom:1px solid var(--mpm-border); }
    .mpm-logmodal__title { font-size:1.05rem; font-weight:800; margin:0; letter-spacing:-.01em; }
    .mpm-logmodal__meta { font-size:.8rem; color:var(--mpm-text-2); margin:4px 0 0; }
    .mpm-logmodal__close { margin-left:auto; flex-shrink:0; width:34px; height:34px; display:flex; align-items:center; justify-content:center;
        border-radius:10px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); color:var(--mpm-muted); cursor:pointer; transition:all .15s; }
    .mpm-logmodal__close:hover { color:var(--mpm-text); background:var(--mpm-surface-3); }
    .mpm-logmodal__close svg { width:18px; height:18px; }
    .mpm-logmodal__body { padding:20px 22px; display:flex; }

    @media (max-width:860px){
        .mpm-row { flex-wrap:wrap; }
        .mpm-row__cta { width:100%; justify-content:space-between; margin-top:4px; }
        .mpm-cta-state { flex:1; justify-content:center; }
    }
    @media (max-width:560px){
        .mpm-hero__title { font-size:1.25rem; }
        .mpm-omni__kbd { display:none; }
        .mpm-drawer { max-width:100%; }
    }
</style>

<div
    class="mpm"
    x-data="{
        showDebug: false,
        drawerOpen: @entangle('showModal'),
        lastLogOpen: @entangle('showLastLog'),
        starting: false,
        linking: false,
        autoScroll: true,
        scrollDebugToBottom() {
            if (!this.autoScroll) return;
            this.$nextTick(() => { const el = this.$refs.termBody; if (el) el.scrollTop = el.scrollHeight; });
        }
    }"
    x-init="
        document.documentElement.style.setProperty('background', '#050506', 'important');
        document.documentElement.style.setProperty('background-color', '#050506', 'important');
        document.body.style.setProperty('background', '#050506', 'important');
        document.body.style.setProperty('background-color', '#050506', 'important');
        $watch('$wire.debugLog', () => scrollDebugToBottom());
    "
>
<div class="mpm-shell">

    {{-- ══════════════ INSTALL CONSOLE (progress) ══════════════ --}}
    @if ($isInstalling || in_array($installStatus, ['installing', 'failed']))

        <div wire:poll.2000ms="pollProgress"></div>

        <div class="mpm-console mpm-rise">
            <div class="mpm-console__head">
                <div>
                    <p class="mpm-console__eyebrow">
                        @if($installStatus === 'failed') Deployment halted
                        @elseif($installStatus === 'installed') Deployment complete
                        @else Deploying to server @endif
                    </p>
                    <h2 class="mpm-console__title {{ $installStatus === 'failed' ? 'err' : ($installStatus === 'installed' ? 'ok' : '') }}">
                        @if($installStatus === 'failed') Installation Failed
                        @elseif($installStatus === 'installed') Installation Complete
                        @else Building your modpack… @endif
                    </h2>
                </div>
                <div class="mpm-clock">
                    <p class="mpm-clock__k">Elapsed</p>
                    @if($isInstalling)
                        {{-- Elapsed derives from a fixed start timestamp so repeated Livewire
                             polls (which re-run x-init) can't speed the counter up. --}}
                        <p class="mpm-clock__n"
                           x-data="{ start: {{ $installStartedAt ?: 'Date.now()' }}, now: Date.now() }"
                           x-init="setInterval(() => { now = Date.now() }, 1000)"
                           x-text="Math.max(0, Math.floor((now - start) / 1000)) + 's'">0s</p>
                    @else
                        <p class="mpm-clock__n">{{ $installElapsed }}s</p>
                    @endif
                </div>
            </div>

            <div class="mpm-console__body">

                {{-- Progress bar --}}
                <div>
                    <div class="mpm-bar__top"><span>Overall progress</span><b>{{ $progress }}%</b></div>
                    <div class="mpm-bar__track">
                        <div class="mpm-bar__fill {{ $installStatus === 'failed' ? 'is-failed' : ($installStatus === 'installed' ? 'is-done' : 'is-running') }}" style="width: {{ $progress }}%"></div>
                    </div>
                </div>

                @if($installStatus === 'failed' && $installError)
                    <div class="mpm-alert">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <div><b>Error:</b> {{ $installError }}</div>
                    </div>
                @endif

                {{-- Vertical timeline --}}
                @php
                    $stepLabels   = $this->getInstallStepLabels();
                    $stepStatuses = $steps ?: array_fill_keys(array_keys($stepLabels), 'pending');
                    $runningStep  = collect($steps ?? [])->filter(fn($s) => $s === 'running')->keys()->first();
                    $runningLabel = $runningStep ? ($stepLabels[$runningStep] ?? $runningStep) : null;
                @endphp
                <div class="mpm-timeline">
                    @foreach ($stepLabels as $key => $label)
                        @php $st = $stepStatuses[$key] ?? 'pending'; @endphp
                        <div class="mpm-tl {{ $st === 'done' ? 'is-done' : ($st === 'running' ? 'is-running' : ($st === 'failed' ? 'is-failed' : '')) }}">
                            <div class="mpm-tl__rail">
                                <div class="mpm-tl__node">
                                    @if($st === 'done')
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    @elseif($st === 'running')
                                        <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    @elseif($st === 'failed')
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                    @endif
                                </div>
                            </div>
                            <span class="mpm-tl__label">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>

                @if($runningLabel)
                    <div class="mpm-now">
                        <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <span>{{ $runningLabel }}…</span>
                    </div>
                @endif

                {{-- Terminal / debug log --}}
                <div>
                    <button type="button" class="mpm-term-toggle" @click="showDebug = !showDebug">
                        <svg class="chev" :class="showDebug ? 'open' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span x-text="showDebug ? 'Hide install log' : 'Show install log'">Show install log</span>
                    </button>
                    <div x-show="showDebug" x-transition x-cloak style="margin-top:12px;">
                        <div class="mpm-term">
                            <div class="mpm-term__bar">
                                <span class="mpm-term__dots"><i></i><i></i><i></i></span>
                                <span class="mpm-term__title">modpack-manager · install.log</span>
                                <span class="mpm-term__count">{{ count($debugLog) }} lines</span>
                            </div>
                            <div class="mpm-term__body" x-ref="termBody">
                                @forelse($debugLog as $line)
                                    <div>{{ $line }}</div>
                                @empty
                                    <div class="muted">Waiting for log output…</div>
                                @endforelse
                            </div>
                            <label class="mpm-term__foot">
                                <input type="checkbox" x-model="autoScroll"> Auto-scroll
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="mpm-console__foot">
                @if($installStatus === 'installed')
                    <button type="button" wire:click="cancelInstallView" class="mpm-btn mpm-btn--success">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg> Done
                    </button>
                @elseif($installStatus === 'failed')
                    <button type="button" wire:click="cancelInstallView" class="mpm-btn mpm-btn--ghost">Back to library</button>
                @else
                    <button type="button" wire:click="cancelInstallView"
                            wire:confirm="Dismiss this installation? If it's genuinely stuck this unlocks the page. If a worker is still processing it, this just stops watching."
                            class="mpm-btn mpm-btn--ghost">
                        Dismiss / cancel
                    </button>
                    <span style="font-size:.78rem; color:var(--mpm-muted);">Stuck? Dismiss to unlock the page — no need to clear the queue manually.</span>
                @endif
            </div>
        </div>

    {{-- ══════════════ MODPACK LIBRARY (browse) ══════════════ --}}
    @else

        @if($installedModpack)
            <div class="mpm-loadout mpm-rise">
                @if($installedModpack['iconUrl'])
                    <img src="{{ $installedModpack['iconUrl'] }}" alt="" class="mpm-loadout__art">
                @endif
                <div style="flex:1; min-width:0;">
                    <div class="mpm-loadout__label"><span class="live"></span> Active modpack</div>
                    <div class="mpm-loadout__name">{{ $installedModpack['name'] }}</div>
                    @if($installedModpack['version'])<div class="mpm-loadout__ver">{{ $installedModpack['version'] }}</div>@endif
                    @if($updateAvailable && $latestVersionLabel)
                        <div class="mpm-loadout__ver" style="color:var(--mpm-warn); font-weight:600;">New version available: {{ $latestVersionLabel }}</div>
                    @endif
                </div>
                @if($updateAvailable)
                    <button type="button" wire:click="openModal('{{ $installedModpack['id'] }}', '{{ $installedModpack['provider'] ?? '' }}')" class="mpm-btn mpm-btn--update">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Update available
                    </button>
                @else
                    <span class="mpm-dbadge" style="color:var(--mpm-success); background:color-mix(in srgb, var(--mpm-success) 14%, transparent); border:1px solid color-mix(in srgb, var(--mpm-success) 36%, transparent);">
                        <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        Up to date
                    </span>
                @endif
            </div>
        @endif

        {{-- Hero search --}}
        <div class="mpm-hero mpm-rise">
            <div class="mpm-hero__grid"></div>
            <p class="mpm-hero__eyebrow">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Modpacks
            </p>
            <h1 class="mpm-hero__title">Deploy a modpack to your server</h1>
            <p class="mpm-hero__sub">Search CurseForge, Modrinth, FTB &amp; ATLauncher — pick a pack and we build &amp; launch it for you.</p>

            <div class="mpm-omni">
                <div class="mpm-omni__field">
                    <span class="mpm-omni__ico">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                    </span>
                    <input type="text" wire:model.lazy="search" wire:keydown.enter="searchModpacks"
                           placeholder="Search modpacks — try “All the Mods”, “Create”, “RLCraft”…">
                    <span class="mpm-omni__kbd"><kbd>Enter</kbd></span>
                    <button type="button" class="mpm-omni__go" wire:click="searchModpacks">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        Search
                    </button>
                </div>
            </div>

            <div class="mpm-pills">
                <button type="button" wire:click="setProvider('all')" class="mpm-pill mpm-pill--all {{ $provider === 'all' ? 'is-active' : '' }}"><span class="dot"></span> All sources</button>
                <button type="button" wire:click="setProvider('curseforge')" class="mpm-pill mpm-pill--cf {{ $provider === 'curseforge' ? 'is-active' : '' }}"><span class="dot"></span> CurseForge</button>
                <button type="button" wire:click="setProvider('modrinth')" class="mpm-pill mpm-pill--mr {{ $provider === 'modrinth' ? 'is-active' : '' }}"><span class="dot"></span> Modrinth</button>
                <button type="button" wire:click="setProvider('ftb')" class="mpm-pill mpm-pill--ftb {{ $provider === 'ftb' ? 'is-active' : '' }}"><span class="dot"></span> FTB</button>
                <button type="button" wire:click="setProvider('atlauncher')" class="mpm-pill mpm-pill--atl {{ $provider === 'atlauncher' ? 'is-active' : '' }}"><span class="dot"></span> ATLauncher</button>
            </div>

            @if($hasLastLog)
                <div>
                    <button type="button" wire:click="showLastInstallLog" class="mpm-loglink">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Show last install log
                    </button>
                </div>
            @endif
        </div>

        @if($errorMsg)
            <div class="mpm-alert mpm-rise">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div><b>Failed to load modpacks</b><div style="margin-top:4px;opacity:.85;">{{ $errorMsg }}</div></div>
            </div>
        @endif

        {{-- Results feed --}}
        @if($isLoading)
            <div class="mpm-feed">
                @foreach(range(1,6) as $_)
                    <div class="mpm-skel-row">
                        <div class="mpm-shimmer" style="width:56px;height:56px;border-radius:13px;flex-shrink:0;"></div>
                        <div style="flex:1;display:flex;flex-direction:column;gap:9px;">
                            <div class="mpm-shimmer" style="height:15px;width:38%;"></div>
                            <div class="mpm-shimmer" style="height:11px;width:65%;"></div>
                            <div class="mpm-shimmer" style="height:10px;width:45%;"></div>
                        </div>
                        <div class="mpm-shimmer" style="width:96px;height:38px;border-radius:11px;flex-shrink:0;"></div>
                    </div>
                @endforeach
            </div>

        @elseif(!empty($modpacks))
            <div class="mpm-feed-head">
                <h3>{{ $search !== '' ? 'Results' : 'Popular modpacks' }}</h3>
                <span class="count">{{ count($modpacks) }} {{ \Illuminate\Support\Str::plural('pack', count($modpacks)) }}</span>
            </div>
            <div class="mpm-feed">
                @foreach($modpacks as $i => $pack)
                    @php
                        $isInstalled = $installedModpack
                            && (string) $installedModpack['id'] === (string) $pack['id']
                            && ($installedModpack['provider'] ?? null) === ($pack['provider'] ?? null);
                        $dl = (int) ($pack['downloadCount'] ?? 0);
                        $dlFmt = $dl >= 1000000 ? round($dl/1000000,1).'M' : ($dl >= 1000 ? round($dl/1000).'K' : $dl);
                        $pv = $pack['provider'] ?? '';
                        $pvLabel = ['curseforge'=>'CurseForge','modrinth'=>'Modrinth','ftb'=>'FTB','atlauncher'=>'ATLauncher'][$pv] ?? ucfirst($pv);
                        $pvColor = ['curseforge'=>'var(--mpm-cf)','modrinth'=>'var(--mpm-mr)','ftb'=>'var(--mpm-ftb)','atlauncher'=>'var(--mpm-atl)'][$pv] ?? '#8b8aa3';
                    @endphp
                    <div class="mpm-row mpm-rise {{ $isInstalled ? 'is-installed' : '' }}" style="--row-c:{{ $pvColor }}; animation-delay: {{ min($i * 40, 320) }}ms"
                         role="button" tabindex="0"
                         wire:click="openModal('{{ $pack['id'] }}', '{{ $pack['provider'] ?? '' }}')"
                         wire:key="row-{{ $pv }}-{{ $pack['id'] }}"
                         @keydown.enter="$wire.openModal('{{ $pack['id'] }}', '{{ $pack['provider'] ?? '' }}')">
                        @if($pack['iconUrl'])
                            <img src="{{ $pack['iconUrl'] }}" alt="" class="mpm-row__art">
                        @else
                            <div class="mpm-row__art mpm-row__art--ph">
                                <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </div>
                        @endif

                        <div class="mpm-row__body">
                            <div class="mpm-row__title">
                                <h3 class="mpm-row__name">{{ $pack['name'] }}</h3>
                                <span class="mpm-chip mpm-chip--src" style="--c:{{ $pvColor }};">{{ $pvLabel }}</span>
                                @foreach(array_slice($pack['loaders'] ?? [], 0, 3) as $loader)
                                    @php $lc = match(strtolower($loader)) {
                                        'forge'=>'#d97706','neoforge'=>'#f97316','fabric'=>'#c9a16b','quilt'=>'#a855f7','liteloader'=>'#38bdf8', default=>'#8b8aa3',
                                    }; @endphp
                                    <span class="mpm-chip" style="--c:{{ $lc }};">{{ $loader }}</span>
                                @endforeach
                            </div>
                            @if(!empty($pack['summary']))
                                <p class="mpm-row__desc">{{ $pack['summary'] }}</p>
                            @endif
                            <div class="mpm-row__meta">
                                @if(!empty($pack['author']))<span class="author">{{ $pack['author'] }}</span>@endif
                                <span title="Downloads">
                                    <svg fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16A8 8 0 0010 2zm-1 11H7v-6h2v6zm4 0h-2v-6h2v6z"/></svg>
                                    {{ $dlFmt }}
                                </span>
                                @if(!empty($pack['gameVersions']))<span>{{ $pack['gameVersions'] }}</span>@endif
                                @if(!empty($pack['dateModified']))
                                    <span>
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0"/></svg>
                                        {{ \Carbon\Carbon::parse($pack['dateModified'])->diffForHumans(null, true) }} ago
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="mpm-row__cta">
                            @if($isInstalled && $updateAvailable)
                                <span class="mpm-cta-state mpm-cta--update">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    Update
                                </span>
                            @elseif($isInstalled)
                                <span class="mpm-cta-state mpm-cta--installed">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    Installed
                                </span>
                            @else
                                <span class="mpm-cta-state mpm-cta--install">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    Install
                                </span>
                            @endif
                            <span class="mpm-row__chev">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

        @else
            <div class="mpm-empty mpm-rise">
                <div class="mpm-empty__orb">
                    <svg width="34" height="34" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <p style="font-weight:800;color:var(--mpm-text);margin:0;font-size:1rem;">No modpacks found</p>
                <p style="font-size:.86rem;margin:0;">Try a different search term or switch source above.</p>
            </div>
        @endif

    @endif

    {{-- ══════════════ OVERLAYS ══════════════
         Teleported to <body> so they escape Filament's transformed/clipped content wrapper and
         can dim the ENTIRE viewport (including the panel's own header/footer), instead of being
         trapped inside the page's box. The <template x-teleport> keeps them in this component's
         Alpine + Livewire scope (wire:model / wire:click / wire:init travel with the nodes). The
         wrapper carries `.mpm` so the scoped CSS variables resolve at the new DOM location.
         wire:loading is NOT reliable across teleport (Livewire re-queries the component subtree),
         so the loading states are driven by Alpine `starting` / `linking` flags instead. --}}
    <template x-teleport="body">
    <div class="mpm">

    <div class="mpm-scrim" x-show="drawerOpen" x-cloak x-transition.opacity
         @keydown.escape.window="$wire.closeModal()" @click="$wire.closeModal()"></div>

    <div class="mpm-drawer" :class="{ 'is-open': drawerOpen }" x-cloak>

        {{-- Fades in the instant Install is pressed, masking the startInstall round-trip. --}}
        <div class="mpm-drawer__starting" x-show="starting" x-cloak x-transition.opacity>
            <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <div>
                <p>Preparing deployment…</p>
                <small>Setting things up on your server</small>
            </div>
        </div>

        @if($selectedModpack)
            @php
                $isInstalledPack = $installedModpack
                    && (string) $installedModpack['id'] === (string) $selectedModpack['id']
                    && ($installedModpack['provider'] ?? null) === ($selectedModpack['provider'] ?? null);
                $isUpd       = $isInstalledPack && $updateAvailable;
            @endphp

            {{-- Drawer hero --}}
            <div class="mpm-dhero">
                @if($selectedModpack['iconUrl'])
                    <div class="mpm-dhero__bg" style="background-image:url('{{ $selectedModpack['iconUrl'] }}')"></div>
                @endif
                <div class="mpm-dhero__scrim"></div>
                <div class="mpm-dhero__row">
                    @if($selectedModpack['iconUrl'])
                        <img src="{{ $selectedModpack['iconUrl'] }}" alt="" class="mpm-dhero__art">
                    @endif
                    <div style="min-width:0;">
                        <h3 class="mpm-dhero__name">{{ $selectedModpack['name'] }}</h3>
                        @if(!empty($selectedModpack['author']))<p class="mpm-dhero__author">by {{ $selectedModpack['author'] }}</p>@endif
                    </div>
                    <button type="button" class="mpm-dhero__close" @click="$wire.closeModal()">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <span class="mpm-dbadge {{ $isUpd ? 'mpm-dbadge--update' : 'mpm-dbadge--install' }}">
                    <span class="dot"></span>{{ $isUpd ? 'Update deployment' : ($isInstalledPack ? 'Reinstall pack' : 'New install') }}
                </span>
            </div>

            {{-- Drawer body --}}
            <div class="mpm-drawer__body"
                 wire:key="drawer-{{ $selectedModpack['provider'] ?? '' }}-{{ $selectedModpack['id'] ?? '' }}"
                 x-init="$wire.loadVersions()">

                <div>
                    <label class="mpm-label">Version</label>
                    @if($versionsLoading)
                        <div class="mpm-loadbox">
                            <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Loading versions…
                        </div>
                    @elseif(empty($versions))
                        <p style="color:var(--mpm-danger);font-size:.85rem;margin:0;">No versions available.</p>
                    @else
                        <div class="mpm-select-wrap">
                            <select class="mpm-select" x-model="$wire.selectedVersion">
                                @foreach($versions as $ver)
                                    @php
                                        $label = $ver['displayName'] ?? $ver['versionNumber'] ?? $ver['name'] ?? $ver['fileName'] ?? $ver['id'];
                                        $date = isset($ver['datePublished']) ? \Carbon\Carbon::parse($ver['datePublished'])->format('M j, Y')
                                              : (isset($ver['fileDate']) ? \Carbon\Carbon::parse($ver['fileDate'])->format('M j, Y') : '');
                                    @endphp
                                    <option value="{{ $ver['id'] }}">{{ $label }}{{ $date ? ' • '.$date : '' }}</option>
                                @endforeach
                            </select>
                            <svg class="caret" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    @endif
                </div>

                <div class="mpm-preserve">
                    <div class="mpm-preserve__head">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        These files are always kept
                    </div>
                    <div class="mpm-preserve__tags">
                        @foreach($this->getPreservedFilesProperty() as $file)
                            <span class="mpm-tag">{{ $file }}</span>
                        @endforeach
                    </div>
                    <p class="mpm-preserve__note">Never deleted and always restored after install.</p>
                </div>

                <label class="mpm-opt">
                    <input type="checkbox" x-model="$wire.createBackup">
                    <span class="mpm-opt__track"></span>
                    <span>
                        <span class="mpm-opt__title">Create a backup first</span>
                        <span class="mpm-opt__desc">Makes a Pelican backup (shown in the Backups tab) before installing — recommended.</span>
                    </span>
                </label>

                <label class="mpm-opt">
                    <input type="checkbox" x-model="$wire.deleteExisting">
                    <span class="mpm-opt__track"></span>
                    <span>
                        <span class="mpm-opt__title">Wipe existing server files</span>
                        <span class="mpm-opt__desc">Removes old mods/config before install (preserved files above are kept).</span>
                    </span>
                </label>
            </div>

            {{-- Drawer footer (sticky) --}}
            <div class="mpm-drawer__foot">
                <div class="mpm-drawer__actions">
                    <button type="button"
                            @click="starting = true; $wire.startInstall().finally(() => starting = false)"
                            class="mpm-btn {{ $isUpd ? 'mpm-btn--update' : 'mpm-btn--primary' }}"
                            :disabled="starting || linking"
                            @if($versionsLoading || empty($versions)) disabled @endif>
                        <span class="mpm-ilbl" x-show="!starting">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            {{ $isUpd ? 'Update' : ($isInstalledPack ? 'Reinstall' : 'Install') }}
                        </span>
                        <span class="mpm-ilbl" x-show="starting" x-cloak>
                            <svg class="mpm-spin" style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Starting…
                        </span>
                    </button>
                    <button type="button" class="mpm-btn mpm-btn--ghost" @click="$wire.closeModal()">Cancel</button>
                </div>

                {{-- Non-destructive recovery: register a pack you already have without wiping/re-downloading. --}}
                <div class="mpm-link-wrap">
                    <button type="button"
                            @click="linking = true; $wire.linkInstalled().finally(() => linking = false)"
                            class="mpm-link-existing"
                            :disabled="starting || linking"
                            @if($versionsLoading || empty($versions)) disabled @endif>
                        <span x-show="!linking">Already installed? Link this version without reinstalling</span>
                        <span class="mpm-ilbl" style="justify-content:center;" x-show="linking" x-cloak>
                            <svg class="mpm-spin" style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Linking…
                        </span>
                    </button>
                    <span class="mpm-link-existing__hint">Marks this pack as installed for the active-modpack banner &amp; update checks. No files are changed.</span>
                </div>
            </div>
        @else
            <div style="flex:1; display:flex; align-items:center; justify-content:center; padding:40px;">
                <svg class="mpm-spin" style="width:28px;height:28px;color:var(--mpm-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </div>
        @endif
    </div>

    {{-- ══════════════ LAST INSTALL LOG MODAL ══════════════ --}}
    <div class="mpm-logscrim" x-show="lastLogOpen" x-cloak x-transition.opacity
         @keydown.escape.window="$wire.hideLastInstallLog()" @click.self="$wire.hideLastInstallLog()">
        <div class="mpm-logmodal" x-show="lastLogOpen" x-transition>
            <div class="mpm-logmodal__head">
                <div style="min-width:0;">
                    <h3 class="mpm-logmodal__title">Last install log</h3>
                    @if(!empty($lastLogMeta))
                        @php
                            $llStatus = $lastLogMeta['status'] ?? '';
                            $llColor  = $llStatus === 'failed' ? 'var(--mpm-danger)' : 'var(--mpm-success)';
                        @endphp
                        <p class="mpm-logmodal__meta">
                            {{ $lastLogMeta['name'] ?? 'Modpack' }}@if(!empty($lastLogMeta['version'])) · {{ $lastLogMeta['version'] }}@endif
                            — <span style="color:{{ $llColor }};font-weight:700;">{{ ucfirst($llStatus) }}</span>@if(!empty($lastLogMeta['time'])) · {{ $lastLogMeta['time'] }}@endif
                        </p>
                    @endif
                </div>
                <button type="button" class="mpm-logmodal__close" @click="$wire.hideLastInstallLog()">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="mpm-logmodal__body">
                <div class="mpm-term" style="width:100%;">
                    <div class="mpm-term__bar">
                        <span class="mpm-term__dots"><i></i><i></i><i></i></span>
                        <span class="mpm-term__title">modpack-manager · install.log</span>
                        <span class="mpm-term__count">{{ count($lastLog) }} lines</span>
                    </div>
                    <div class="mpm-term__body" style="height:46vh;">
                        @forelse($lastLog as $line)
                            <div>{{ $line }}</div>
                        @empty
                            <div class="muted">No log output was recorded for the last install.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    </div>{{-- /.mpm teleport wrapper --}}
    </template>

</div>
</div>

</x-filament-panels::page>
