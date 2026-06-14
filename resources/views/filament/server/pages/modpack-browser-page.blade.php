<x-filament-panels::page>

{{-- ═══════════════════════════════════════════════════════════════════════════
     Modpack Manager — Server Panel Page
     Fully self-contained styling (scoped under .mpm) so it renders correctly
     regardless of the host panel's compiled Tailwind build. Adapts to
     Filament light/dark themes via the `.dark` class on <html>.
     Alpine.js (bundled with Filament) drives interactivity; Livewire the data.
     ═══════════════════════════════════════════════════════════════════════════ --}}

<style>
    .mpm {
        --mpm-surface:   #ffffff;
        --mpm-surface-2: #f8fafc;
        --mpm-surface-3: #f1f5f9;
        --mpm-border:    #e5e7eb;
        --mpm-border-2:  #e2e8f0;
        --mpm-text:      #0f172a;
        --mpm-text-2:    #475569;
        --mpm-muted:     #94a3b8;
        --mpm-shadow:    0 1px 3px rgba(15,23,42,.08), 0 1px 2px rgba(15,23,42,.04);
        --mpm-shadow-lg: 0 12px 32px rgba(15,23,42,.14);
        --mpm-primary:   #6366f1;
        --mpm-cf:        #f16436;
        --mpm-mr:        #1bd96a;
        --mpm-ftb:       #d6332b;
        --mpm-atl:       #1d9bf0;
        --mpm-success:   #10b981;
        --mpm-warn:      #f59e0b;
        --mpm-danger:    #ef4444;
        --mpm-blue:      #3b82f6;
    }
    .dark .mpm {
        --mpm-surface:   #1c1f26;
        --mpm-surface-2: #23272f;
        --mpm-surface-3: #2c313a;
        --mpm-border:    rgba(255,255,255,.08);
        --mpm-border-2:  rgba(255,255,255,.12);
        --mpm-text:      #f1f5f9;
        --mpm-text-2:    #cbd5e1;
        --mpm-muted:     #8b95a5;
        --mpm-shadow:    0 1px 3px rgba(0,0,0,.4);
        --mpm-shadow-lg: 0 16px 40px rgba(0,0,0,.55);
    }

    .mpm { color: var(--mpm-text); }
    .mpm *, .mpm *::before, .mpm *::after { box-sizing: border-box; }
    .mpm [x-cloak] { display: none !important; }

    /* ── Animations ── */
    @keyframes mpm-fade   { from { opacity:0; transform:translateY(10px);} to { opacity:1; transform:none; } }
    @keyframes mpm-spin   { to { transform: rotate(360deg); } }
    @keyframes mpm-pulse  { 0%,100%{opacity:1;} 50%{opacity:.55;} }
    @keyframes mpm-shimmer{ 0%{background-position:-400px 0;} 100%{background-position:400px 0;} }
    @keyframes mpm-stripes{ from{background-position:0 0;} to{background-position:40px 0;} }
    .mpm-fade { animation: mpm-fade .4s cubic-bezier(.16,1,.3,1) both; }
    .mpm-spin { animation: mpm-spin 1s linear infinite; }

    /* ── Layout ── */
    .mpm-stack { display:flex; flex-direction:column; gap:18px; }
    .mpm-card {
        background: var(--mpm-surface);
        border: 1px solid var(--mpm-border);
        border-radius: 16px;
        box-shadow: var(--mpm-shadow);
    }
    .mpm-pad { padding: 20px; }

    /* ── Header ── */
    .mpm-head { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-start; justify-content:space-between; }
    .mpm-title { font-size:1.15rem; font-weight:700; margin:0; letter-spacing:-.01em; }
    .mpm-sub { font-size:.8rem; color:var(--mpm-text-2); margin:3px 0 0; }

    .mpm-search { position:relative; }
    .mpm-search svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--mpm-muted); pointer-events:none; }
    .mpm-input {
        width:100%; min-width:240px; height:40px;
        padding:0 14px 0 36px;
        background: var(--mpm-surface-2);
        border:1px solid var(--mpm-border);
        border-radius:10px; color:var(--mpm-text); font-size:.875rem;
        outline:none; transition:border-color .15s, box-shadow .15s;
    }
    .mpm-input::placeholder { color:var(--mpm-muted); }
    .mpm-input:focus { border-color:var(--mpm-primary); box-shadow:0 0 0 3px color-mix(in srgb, var(--mpm-primary) 20%, transparent); }

    .mpm-filters { display:flex; align-items:center; gap:8px; margin-top:10px; font-size:.75rem; color:var(--mpm-muted); }
    .mpm-chip {
        display:inline-flex; align-items:center; gap:6px;
        padding:3px 10px; border-radius:999px;
        background:var(--mpm-surface-3); border:1px solid var(--mpm-border);
        color:var(--mpm-text-2); font-weight:500;
    }

    /* ── Provider tabs ── */
    .mpm-tabs { display:flex; gap:8px; margin-top:16px; }
    .mpm-tab {
        display:inline-flex; align-items:center; gap:7px;
        padding:8px 16px; border-radius:10px; cursor:pointer;
        font-size:.85rem; font-weight:600;
        background:var(--mpm-surface-2); border:1px solid var(--mpm-border);
        color:var(--mpm-text-2); transition:all .15s;
    }
    .mpm-tab:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); }
    .mpm-tab .dot { width:8px; height:8px; border-radius:50%; }
    .mpm-tab--all .dot { background:conic-gradient(var(--mpm-cf),var(--mpm-mr),var(--mpm-atl),var(--mpm-ftb),var(--mpm-cf)); }
    .mpm-tab--all.is-active { background:var(--mpm-primary); border-color:var(--mpm-primary); color:#fff; }
    .mpm-tab--cf .dot { background:var(--mpm-cf); }
    .mpm-tab--mr .dot { background:var(--mpm-mr); }
    .mpm-tab--ftb .dot { background:var(--mpm-ftb); }
    .mpm-tab--atl .dot { background:var(--mpm-atl); }
    .mpm-tab--cf.is-active { background:var(--mpm-cf); border-color:var(--mpm-cf); color:#fff; }
    .mpm-tab--mr.is-active { background:var(--mpm-mr); border-color:var(--mpm-mr); color:#06281a; }
    .mpm-tab--ftb.is-active { background:var(--mpm-ftb); border-color:var(--mpm-ftb); color:#fff; }
    .mpm-tab--atl.is-active { background:var(--mpm-atl); border-color:var(--mpm-atl); color:#fff; }
    .mpm-tab.is-active .dot { background:currentColor; }

    /* ── Installed banner ── */
    .mpm-banner {
        display:flex; align-items:center; gap:14px; padding:16px 18px; border-radius:14px;
        background: color-mix(in srgb, var(--mpm-success) 10%, var(--mpm-surface));
        border:1px solid color-mix(in srgb, var(--mpm-success) 35%, transparent);
    }
    .mpm-banner__label { font-size:.68rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--mpm-success); }
    .mpm-banner__name  { font-weight:600; margin:2px 0 0; }
    .mpm-banner__ver   { font-size:.78rem; color:var(--mpm-text-2); }

    /* ── Grid + cards ── */
    .mpm-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(330px,1fr)); gap:16px; }
    .mpm-pack {
        display:flex; flex-direction:column; gap:12px; padding:16px;
        background:var(--mpm-surface); border:1px solid var(--mpm-border);
        border-radius:14px; box-shadow:var(--mpm-shadow);
        transition:transform .18s cubic-bezier(.16,1,.3,1), box-shadow .18s, border-color .18s;
    }
    .mpm-pack:hover { transform:translateY(-3px); box-shadow:var(--mpm-shadow-lg); border-color:color-mix(in srgb, var(--mpm-primary) 45%, var(--mpm-border)); }
    .mpm-pack__top { display:flex; gap:13px; }
    .mpm-pack__icon { width:58px; height:58px; border-radius:12px; object-fit:cover; flex-shrink:0; border:1px solid var(--mpm-border); background:var(--mpm-surface-3); }
    .mpm-pack__icon--ph { display:flex; align-items:center; justify-content:center; color:var(--mpm-muted); }
    .mpm-pack__name { font-size:.92rem; font-weight:700; line-height:1.25; margin:0; }
    .mpm-pack__author { display:inline-block; margin-top:5px; font-size:.7rem; font-weight:500; color:var(--mpm-text-2); background:var(--mpm-surface-3); border:1px solid var(--mpm-border); padding:2px 8px; border-radius:6px; }
    .mpm-pack__stats { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-top:9px; font-size:.72rem; color:var(--mpm-muted); }
    .mpm-pack__stats span { display:inline-flex; align-items:center; gap:4px; }
    .mpm-pack__stats svg { width:13px; height:13px; }
    .mpm-loaders { display:flex; flex-wrap:wrap; gap:6px; margin-top:9px; }
    .mpm-loader { display:inline-flex; align-items:center; gap:4px; font-size:.66rem; font-weight:700;
        letter-spacing:.02em; text-transform:uppercase; padding:2px 8px; border-radius:6px;
        border:1px solid color-mix(in srgb, var(--mpm-loader-c) 45%, transparent);
        color:var(--mpm-loader-c); background:color-mix(in srgb, var(--mpm-loader-c) 14%, transparent); }
    .mpm-loader::before { content:""; width:6px; height:6px; border-radius:50%; background:var(--mpm-loader-c); }
    /* Provider tag: filled with the provider colour so it reads as the source badge. */
    .mpm-ptag { color:#fff; background:var(--mpm-loader-c); border-color:var(--mpm-loader-c); }
    .mpm-ptag::before { background:rgba(255,255,255,.85); }
    .mpm-pack__desc { font-size:.8rem; color:var(--mpm-text-2); line-height:1.5; margin:0;
        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .mpm-pack__foot { margin-top:auto; padding-top:4px; }

    /* ── Buttons ── */
    .mpm-btn {
        display:inline-flex; align-items:center; justify-content:center; gap:7px;
        padding:9px 16px; border-radius:10px; border:1px solid transparent;
        font-size:.83rem; font-weight:600; cursor:pointer; transition:all .15s; text-decoration:none;
    }
    .mpm-btn svg { width:15px; height:15px; }
    .mpm-btn:disabled { opacity:.5; cursor:not-allowed; }
    .mpm-btn--install { background:var(--mpm-primary); color:#fff; width:100%; }
    .mpm-btn--install:hover:not(:disabled) { background:color-mix(in srgb, var(--mpm-primary) 85%, #000); }
    .mpm-btn--update  { background:var(--mpm-warn); color:#3a2300; }
    .mpm-btn--update:hover:not(:disabled) { background:color-mix(in srgb, var(--mpm-warn) 88%, #fff); }
    .mpm-btn--primary { background:var(--mpm-primary); color:#fff; }
    .mpm-btn--primary:hover:not(:disabled) { background:color-mix(in srgb, var(--mpm-primary) 85%, #000); }
    .mpm-btn--success { background:var(--mpm-success); color:#fff; }
    .mpm-btn--ghost { background:var(--mpm-surface-2); border-color:var(--mpm-border); color:var(--mpm-text); }
    .mpm-btn--ghost:hover { background:var(--mpm-surface-3); }
    .mpm-btn--block { width:100%; }

    /* ── Skeleton ── */
    .mpm-skel { border-radius:14px; padding:16px; background:var(--mpm-surface); border:1px solid var(--mpm-border); display:flex; flex-direction:column; gap:12px; }
    .mpm-shimmer { background:linear-gradient(90deg, var(--mpm-surface-2) 25%, var(--mpm-surface-3) 50%, var(--mpm-surface-2) 75%); background-size:800px 100%; animation:mpm-shimmer 1.4s infinite linear; border-radius:6px; }

    /* ── Empty / error ── */
    .mpm-empty { display:flex; flex-direction:column; align-items:center; text-align:center; gap:10px; padding:54px 20px; color:var(--mpm-muted); }
    .mpm-alert { display:flex; gap:12px; padding:16px; border-radius:14px;
        background:color-mix(in srgb, var(--mpm-danger) 10%, var(--mpm-surface));
        border:1px solid color-mix(in srgb, var(--mpm-danger) 35%, transparent); color:var(--mpm-danger); font-size:.85rem; }
    .mpm-alert svg { width:20px; height:20px; flex-shrink:0; }

    /* ── Install progress ── */
    .mpm-prog-head { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
    .mpm-prog-title { font-size:1.05rem; font-weight:700; margin:0; }
    .mpm-prog-eyebrow { font-size:.68rem; text-transform:uppercase; letter-spacing:.1em; color:var(--mpm-muted); margin:4px 0 0; }
    .mpm-elapsed { text-align:right; }
    .mpm-elapsed .n { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:1.3rem; font-weight:700; }

    .mpm-steps { display:flex; gap:4px; overflow-x:auto; padding-bottom:4px; }
    .mpm-step {
        flex:1; min-width:96px; padding:10px 8px 10px 16px; position:relative;
        display:flex; flex-direction:column; align-items:center; justify-content:center; gap:5px; text-align:center;
        background:var(--mpm-surface-3); color:var(--mpm-muted);
        clip-path: polygon(0 0, calc(100% - 13px) 0, 100% 50%, calc(100% - 13px) 100%, 0 100%, 13px 50%);
        transition:background .3s, color .3s;
    }
    .mpm-step:first-child { clip-path: polygon(0 0, calc(100% - 13px) 0, 100% 50%, calc(100% - 13px) 100%, 0 100%); border-radius:8px 0 0 8px; }
    .mpm-step:last-child  { clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%, 13px 50%); border-radius:0 8px 8px 0; }
    .mpm-step__label { font-size:.62rem; font-weight:600; line-height:1.2; }
    .mpm-step__icon { width:16px; height:16px; }
    .mpm-step.is-done    { background:var(--mpm-success); color:#fff; }
    .mpm-step.is-running { background:var(--mpm-blue);    color:#fff; }
    .mpm-step.is-failed  { background:var(--mpm-danger);  color:#fff; }

    .mpm-now { display:flex; align-items:center; gap:8px; font-size:.85rem; color:var(--mpm-blue); }
    .mpm-now svg { width:16px; height:16px; }
    .mpm-prog-actions { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }

    .mpm-progress { margin-top:4px; }
    .mpm-progress__top { display:flex; justify-content:space-between; font-size:.72rem; color:var(--mpm-text-2); margin-bottom:6px; }
    .mpm-progress__top b { font-family:ui-monospace,monospace; color:var(--mpm-text); }
    .mpm-progress__track { height:12px; border-radius:999px; background:var(--mpm-surface-3); overflow:hidden; }
    .mpm-progress__bar { height:100%; border-radius:999px; transition:width .7s cubic-bezier(.16,1,.3,1); background:var(--mpm-blue); }
    .mpm-progress__bar.is-running {
        background-image:linear-gradient(45deg, rgba(255,255,255,.18) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.18) 50%, rgba(255,255,255,.18) 75%, transparent 75%);
        background-size:40px 40px; animation:mpm-stripes 1s linear infinite, mpm-pulse 2s ease-in-out infinite;
    }
    .mpm-progress__bar.is-done   { background:var(--mpm-success); }
    .mpm-progress__bar.is-failed { background:var(--mpm-danger); }

    /* ── Debug log ── */
    .mpm-debug-toggle { display:inline-flex; align-items:center; gap:7px; background:none; border:none; cursor:pointer; font-size:.78rem; color:var(--mpm-text-2); padding:0; }
    .mpm-debug-toggle:hover { color:var(--mpm-text); }
    .mpm-debug-toggle .chev { width:14px; height:14px; transition:transform .2s; }
    .mpm-debug-toggle .chev.open { transform:rotate(90deg); }
    .mpm-debug-toggle .count { font-family:ui-monospace,monospace; font-size:.7rem; background:var(--mpm-surface-3); border:1px solid var(--mpm-border); border-radius:5px; padding:1px 7px; }
    .mpm-debug {
        margin-top:10px; height:200px; overflow-y:auto; border-radius:10px;
        background:#0a0c10; border:1px solid var(--mpm-border);
        padding:12px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.72rem; line-height:1.6; color:#34d399;
    }
    .mpm-debug::-webkit-scrollbar { width:7px; }
    .mpm-debug::-webkit-scrollbar-thumb { background:rgba(255,255,255,.16); border-radius:4px; }
    .mpm-debug .muted { color:#475569; }
    .mpm-debug-foot { display:flex; align-items:center; gap:8px; margin-top:8px; font-size:.72rem; color:var(--mpm-muted); }

    /* ── Modal ── */
    .mpm-overlay { position:fixed; inset:0; z-index:60; display:flex; align-items:center; justify-content:center; padding:16px;
        background:rgba(2,6,23,.62); backdrop-filter:blur(4px); }
    .mpm-modal { position:relative; width:100%; max-width:540px; max-height:90vh; overflow-y:auto;
        background:var(--mpm-surface); border:1px solid var(--mpm-border-2);
        border-radius:18px; box-shadow:var(--mpm-shadow-lg); }
    /* Instant client-side overlay shown the moment Install is pressed, so the
       Livewire round-trip to startInstall reads as a smooth transition. */
    .mpm-modal__starting { position:absolute; inset:0; z-index:5; border-radius:18px;
        flex-direction:column; align-items:center; justify-content:center; gap:14px; text-align:center;
        background:color-mix(in srgb, var(--mpm-surface) 86%, transparent); backdrop-filter:blur(3px);
        animation: mpm-fade .22s cubic-bezier(.16,1,.3,1) both; }
    .mpm-modal__starting .mpm-spin { width:36px; height:36px; color:var(--mpm-primary); }
    .mpm-modal__starting p { margin:0; font-weight:600; color:var(--mpm-text); }
    .mpm-modal__starting small { color:var(--mpm-text-2); font-size:.78rem; }
    .mpm-modal__head { display:flex; align-items:flex-start; gap:13px; padding:18px 20px; border-bottom:1px solid var(--mpm-border); }
    .mpm-modal__icon { width:48px; height:48px; border-radius:11px; object-fit:cover; border:1px solid var(--mpm-border); flex-shrink:0; }
    .mpm-modal__title { font-size:1rem; font-weight:700; margin:0; line-height:1.3; }
    .mpm-modal__close { margin-left:auto; background:none; border:none; cursor:pointer; color:var(--mpm-muted); padding:2px; flex-shrink:0; }
    .mpm-modal__close:hover { color:var(--mpm-text); }
    .mpm-modal__close svg { width:20px; height:20px; }
    .mpm-modal__body { padding:20px; display:flex; flex-direction:column; gap:18px; }

    .mpm-eyebrow { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--mpm-muted); margin:0 0 8px; }
    .mpm-badge { display:inline-flex; align-items:center; gap:7px; padding:5px 13px; border-radius:999px; font-size:.75rem; font-weight:700; }
    .mpm-badge .dot { width:8px; height:8px; border-radius:50%; }
    .mpm-badge--install { background:color-mix(in srgb, var(--mpm-primary) 16%, transparent); color:var(--mpm-primary); border:1px solid color-mix(in srgb, var(--mpm-primary) 35%, transparent); }
    .mpm-badge--install .dot { background:var(--mpm-primary); }
    .mpm-badge--update { background:color-mix(in srgb, var(--mpm-warn) 18%, transparent); color:#b45309; border:1px solid color-mix(in srgb, var(--mpm-warn) 40%, transparent); }
    .dark .mpm-badge--update { color:var(--mpm-warn); }
    .mpm-badge--update .dot { background:var(--mpm-warn); }

    .mpm-preserve { border:1px solid var(--mpm-border); background:var(--mpm-surface-2); border-radius:11px; padding:13px; }
    .mpm-preserve__head { display:flex; align-items:center; gap:8px; margin-bottom:9px; font-size:.78rem; font-weight:600; color:var(--mpm-text-2); }
    .mpm-preserve__head svg { width:15px; height:15px; color:var(--mpm-success); }
    .mpm-preserve__tags { display:flex; flex-wrap:wrap; gap:6px; }
    .mpm-tag { font-family:ui-monospace,monospace; font-size:.68rem; padding:3px 8px; border-radius:6px; background:var(--mpm-surface-3); border:1px solid var(--mpm-border); color:var(--mpm-text-2); }
    .mpm-preserve__note { font-size:.7rem; color:var(--mpm-muted); margin:9px 0 0; }

    .mpm-label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--mpm-text-2); margin-bottom:8px; }
    .mpm-select-wrap { position:relative; }
    .mpm-select {
        width:100%; height:44px; padding:0 38px 0 14px; appearance:none; cursor:pointer;
        background:var(--mpm-surface-2); border:1px solid var(--mpm-border); border-radius:10px;
        color:var(--mpm-text); font-size:.875rem; outline:none; transition:border-color .15s, box-shadow .15s;
    }
    .mpm-select:focus { border-color:var(--mpm-primary); box-shadow:0 0 0 3px color-mix(in srgb, var(--mpm-primary) 20%, transparent); }
    .mpm-select-wrap .caret { position:absolute; right:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--mpm-muted); pointer-events:none; }
    .mpm-loading-box { display:flex; align-items:center; gap:9px; height:44px; padding:0 14px; border-radius:10px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); color:var(--mpm-muted); font-size:.85rem; }
    .mpm-loading-box svg { width:16px; height:16px; }

    /* Toggle */
    .mpm-switch { display:flex; align-items:flex-start; gap:12px; cursor:pointer; }
    .mpm-switch input { position:absolute; opacity:0; width:0; height:0; }
    .mpm-switch__track { position:relative; flex-shrink:0; margin-top:1px; width:42px; height:24px; border-radius:999px; background:var(--mpm-surface-3); border:1px solid var(--mpm-border); transition:background .18s; }
    .mpm-switch__track::after { content:""; position:absolute; top:2px; left:2px; width:18px; height:18px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.3); transition:transform .18s; }
    .mpm-switch input:checked + .mpm-switch__track { background:var(--mpm-primary); border-color:var(--mpm-primary); }
    .mpm-switch input:checked + .mpm-switch__track::after { transform:translateX(18px); }
    .mpm-switch__title { font-size:.86rem; font-weight:600; }
    .mpm-switch__desc { font-size:.74rem; color:var(--mpm-muted); margin-top:1px; }

    .mpm-modal__actions { display:flex; gap:10px; }
    .mpm-modal__actions .mpm-btn--install,
    .mpm-modal__actions .mpm-btn--update { flex:1; }

    .mpm-modal__link-existing { margin-top:12px; padding-top:12px; border-top:1px solid var(--mpm-border); text-align:center; }
    .mpm-link-existing { background:none; border:none; padding:0; cursor:pointer; font-size:.85rem; font-weight:600;
        color:var(--mpm-primary); text-decoration:underline; text-underline-offset:2px; }
    .mpm-link-existing:hover:not(:disabled) { color:var(--mpm-text); }
    .mpm-link-existing:disabled { opacity:.5; cursor:not-allowed; text-decoration:none; }
    .mpm-link-existing__hint { display:block; margin-top:4px; font-size:.75rem; color:var(--mpm-muted); }

    @media (max-width:640px){ .mpm-grid { grid-template-columns:1fr; } .mpm-input { min-width:0; } }
</style>

<div
    class="mpm"
    x-data="{
        showDebug: false,
        modalOpen: @entangle('showModal'),
        autoScroll: true,
        scrollDebugToBottom() {
            if (!this.autoScroll) return;
            this.$nextTick(() => { const el = this.$refs.debugArea; if (el) el.scrollTop = el.scrollHeight; });
        }
    }"
    x-init="$watch('$wire.debugLog', () => scrollDebugToBottom())"
>
<div class="mpm-stack">

    {{-- ══════════════ INSTALLATION PROGRESS VIEW ══════════════ --}}
    @if ($isInstalling || in_array($installStatus, ['installing', 'failed']))

        <div wire:poll.2000ms="pollProgress"></div>

        <div class="mpm-card mpm-pad mpm-fade mpm-stack">

            <div class="mpm-prog-head">
                <div>
                    <h2 class="mpm-prog-title">
                        @if($installStatus === 'failed')
                            <span style="color:var(--mpm-danger)">Installation Failed</span>
                        @elseif($installStatus === 'installed')
                            <span style="color:var(--mpm-success)">Installation Complete</span>
                        @else
                            Modpack Installation Running
                        @endif
                    </h2>
                    <p class="mpm-prog-eyebrow">Installation Steps</p>
                </div>
                <div class="mpm-elapsed">
                    <p class="mpm-prog-eyebrow" style="margin:0">Elapsed</p>
                    @if($isInstalling)
                        {{-- Elapsed is derived from a fixed start timestamp, so repeated
                             Livewire polls (which re-run x-init) can't speed it up. --}}
                        <p class="n"
                           x-data="{ start: {{ $installStartedAt ?: 'Date.now()' }}, now: Date.now() }"
                           x-init="setInterval(() => { now = Date.now() }, 1000)"
                           x-text="Math.max(0, Math.floor((now - start) / 1000)) + 's'">0s</p>
                    @else
                        <p class="n">{{ $installElapsed }}s</p>
                    @endif
                </div>
            </div>

            {{-- Step arrows --}}
            @php
                $stepLabels   = $this->getInstallStepLabels();
                $stepStatuses = $steps ?: array_fill_keys(array_keys($stepLabels), 'pending');
            @endphp
            <div class="mpm-steps">
                @foreach ($stepLabels as $key => $label)
                    @php $st = $stepStatuses[$key] ?? 'pending'; @endphp
                    <div class="mpm-step {{ $st === 'done' ? 'is-done' : ($st === 'running' ? 'is-running' : ($st === 'failed' ? 'is-failed' : '')) }}">
                        @if($st === 'done')
                            <svg class="mpm-step__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        @elseif($st === 'running')
                            <svg class="mpm-step__icon mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        @elseif($st === 'failed')
                            <svg class="mpm-step__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        @else
                            <span class="mpm-step__icon" style="display:flex;align-items:center;justify-content:center;font-weight:700;">○</span>
                        @endif
                        <span class="mpm-step__label">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            {{-- Current step --}}
            @php
                $runningStep  = collect($steps ?? [])->filter(fn($s) => $s === 'running')->keys()->first();
                $runningLabel = $runningStep ? ($stepLabels[$runningStep] ?? $runningStep) : null;
            @endphp
            @if($runningLabel)
                <div class="mpm-now">
                    <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>{{ $runningLabel }}…</span>
                </div>
            @endif

            @if($installStatus === 'failed' && $installError)
                <div class="mpm-alert"><span><b>Error:</b> {{ $installError }}</span></div>
            @endif

            {{-- Progress bar --}}
            <div class="mpm-progress">
                <div class="mpm-progress__top"><span>Overall Progress</span><b>{{ $progress }}%</b></div>
                <div class="mpm-progress__track">
                    <div class="mpm-progress__bar {{ $installStatus === 'failed' ? 'is-failed' : ($installStatus === 'installed' ? 'is-done' : 'is-running') }}" style="width: {{ $progress }}%"></div>
                </div>
            </div>

            {{-- Debug log --}}
            <div>
                <button type="button" class="mpm-debug-toggle" @click="showDebug = !showDebug">
                    <svg class="chev" :class="showDebug ? 'open' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    <span x-text="showDebug ? 'Hide Debug Log' : 'Show Debug Log'">Show Debug Log</span>
                    <span class="count">{{ count($debugLog) }}</span>
                </button>
                <div x-show="showDebug" x-transition x-cloak>
                    <div class="mpm-debug" x-ref="debugArea">
                        @forelse($debugLog as $line)
                            <div>{{ $line }}</div>
                        @empty
                            <div class="muted">Waiting for log output…</div>
                        @endforelse
                    </div>
                    <label class="mpm-debug-foot">
                        <input type="checkbox" x-model="autoScroll"> Auto-scroll
                    </label>
                </div>
            </div>

            {{-- Actions --}}
            @if($installStatus === 'installed')
                <div><button type="button" wire:click="cancelInstallView" class="mpm-btn mpm-btn--success">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Done
                </button></div>
            @elseif($installStatus === 'failed')
                <div><button type="button" wire:click="cancelInstallView" class="mpm-btn mpm-btn--ghost">Back to Browser</button></div>
            @else
                {{-- Running: always allow dismissing a stuck install (no tinker needed). --}}
                <div class="mpm-prog-actions">
                    <button type="button" wire:click="cancelInstallView"
                            wire:confirm="Dismiss this installation? If it's genuinely stuck this unlocks the page. If a worker is still processing it, this just stops watching."
                            class="mpm-btn mpm-btn--ghost">
                        Dismiss / cancel
                    </button>
                    <span class="muted" style="font-size:.8rem;">Stuck? Use this to unlock the page — no need to clear the queue manually.</span>
                </div>
            @endif
        </div>

    {{-- ══════════════ MODPACK BROWSER VIEW ══════════════ --}}
    @else

        @if($installedModpack)
            <div class="mpm-banner mpm-fade">
                @if($installedModpack['iconUrl'])
                    <img src="{{ $installedModpack['iconUrl'] }}" alt="" class="mpm-pack__icon" style="width:44px;height:44px;">
                @endif
                <div style="flex:1;min-width:0;">
                    <div class="mpm-banner__label">Installed Modpack</div>
                    <div class="mpm-banner__name">{{ $installedModpack['name'] }}</div>
                    @if($installedModpack['version'])<div class="mpm-banner__ver">{{ $installedModpack['version'] }}</div>@endif
                    @if($updateAvailable && $latestVersionLabel)
                        <div class="mpm-banner__ver" style="color:var(--mpm-warn);">Latest: {{ $latestVersionLabel }}</div>
                    @endif
                </div>
                @if($updateAvailable)
                    <button type="button" wire:click="openModal('{{ $installedModpack['id'] }}', '{{ $installedModpack['provider'] ?? '' }}')" class="mpm-btn mpm-btn--update">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Update Available
                    </button>
                @else
                    <span class="mpm-badge mpm-badge--update" style="background:color-mix(in srgb, var(--mpm-success) 16%, transparent);color:var(--mpm-success);border-color:color-mix(in srgb, var(--mpm-success) 35%, transparent);">
                        <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        Up to date
                    </span>
                @endif
            </div>
        @endif

        {{-- Header --}}
        <div class="mpm-card mpm-pad mpm-fade">
            <div class="mpm-head">
                <div>
                    <h2 class="mpm-title">Modpack Browser</h2>
                    <p class="mpm-sub">Browse and install modpacks from CurseForge, Modrinth, FTB &amp; ATLauncher.</p>
                </div>
                <div>
                    <div class="mpm-search">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                        <input type="text" class="mpm-input" wire:model.lazy="search" wire:keydown.enter="searchModpacks" placeholder="Search modpacks…">
                    </div>
                    <div class="mpm-filters">
                        <span>Active filters</span>
                        <span class="mpm-chip">Provider: {{ ['all'=>'All','curseforge'=>'CurseForge','modrinth'=>'Modrinth','ftb'=>'FTB','atlauncher'=>'ATLauncher'][$provider] ?? ucfirst($provider) }}</span>
                    </div>
                </div>
            </div>

            <div class="mpm-tabs">
                <button type="button" wire:click="setProvider('all')" class="mpm-tab mpm-tab--all {{ $provider === 'all' ? 'is-active' : '' }}">
                    <span class="dot"></span> All
                </button>
                <button type="button" wire:click="setProvider('curseforge')" class="mpm-tab mpm-tab--cf {{ $provider === 'curseforge' ? 'is-active' : '' }}">
                    <span class="dot"></span> CurseForge
                </button>
                <button type="button" wire:click="setProvider('modrinth')" class="mpm-tab mpm-tab--mr {{ $provider === 'modrinth' ? 'is-active' : '' }}">
                    <span class="dot"></span> Modrinth
                </button>
                <button type="button" wire:click="setProvider('ftb')" class="mpm-tab mpm-tab--ftb {{ $provider === 'ftb' ? 'is-active' : '' }}">
                    <span class="dot"></span> FTB
                </button>
                <button type="button" wire:click="setProvider('atlauncher')" class="mpm-tab mpm-tab--atl {{ $provider === 'atlauncher' ? 'is-active' : '' }}">
                    <span class="dot"></span> ATLauncher
                </button>
            </div>
        </div>

        @if($errorMsg)
            <div class="mpm-alert mpm-fade">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div><b>Failed to load modpacks</b><div style="margin-top:4px;opacity:.85;">{{ $errorMsg }}</div></div>
            </div>
        @endif

        @if($isLoading)
            <div class="mpm-grid">
                @foreach(range(1,6) as $_)
                    <div class="mpm-skel">
                        <div style="display:flex;gap:13px;">
                            <div class="mpm-shimmer" style="width:58px;height:58px;border-radius:12px;"></div>
                            <div style="flex:1;display:flex;flex-direction:column;gap:8px;">
                                <div class="mpm-shimmer" style="height:14px;width:70%;"></div>
                                <div class="mpm-shimmer" style="height:11px;width:40%;"></div>
                            </div>
                        </div>
                        <div class="mpm-shimmer" style="height:11px;width:100%;"></div>
                        <div class="mpm-shimmer" style="height:11px;width:65%;"></div>
                    </div>
                @endforeach
            </div>

        @elseif(!empty($modpacks))
            <div class="mpm-grid">
                @foreach($modpacks as $i => $pack)
                    @php
                        $isInstalled = $installedModpack
                            && (string) $installedModpack['id'] === (string) $pack['id']
                            && ($installedModpack['provider'] ?? null) === ($pack['provider'] ?? null);
                        $dl = (int) ($pack['downloadCount'] ?? 0);
                        $dlFmt = $dl >= 1000000 ? round($dl/1000000,1).'M' : ($dl >= 1000 ? round($dl/1000).'K' : $dl);
                    @endphp
                    <div class="mpm-pack mpm-fade" style="animation-delay: {{ min($i * 35, 280) }}ms">
                        <div class="mpm-pack__top">
                            @if($pack['iconUrl'])
                                <img src="{{ $pack['iconUrl'] }}" alt="" class="mpm-pack__icon">
                            @else
                                <div class="mpm-pack__icon mpm-pack__icon--ph">
                                    <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                </div>
                            @endif
                            <div style="flex:1;min-width:0;">
                                <h3 class="mpm-pack__name">{{ $pack['name'] }}</h3>
                                <span class="mpm-pack__author">{{ $pack['author'] }}</span>
                                <div class="mpm-pack__stats">
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
                                <div class="mpm-loaders">
                                    @php
                                        $pv = $pack['provider'] ?? '';
                                        $pvLabel = ['curseforge'=>'CurseForge','modrinth'=>'Modrinth','ftb'=>'FTB','atlauncher'=>'ATLauncher'][$pv] ?? ucfirst($pv);
                                        $pvColor = ['curseforge'=>'var(--mpm-cf)','modrinth'=>'#18bf5d','ftb'=>'var(--mpm-ftb)','atlauncher'=>'var(--mpm-atl)'][$pv] ?? '#94a3b8';
                                    @endphp
                                    <span class="mpm-loader mpm-ptag" style="--mpm-loader-c:{{ $pvColor }};">{{ $pvLabel }}</span>
                                    @foreach(($pack['loaders'] ?? []) as $loader)
                                        @php
                                            $lc = match(strtolower($loader)) {
                                                'forge'      => '#d97706',
                                                'neoforge'   => '#f97316',
                                                'fabric'     => '#c9a16b',
                                                'quilt'      => '#a855f7',
                                                'liteloader' => '#38bdf8',
                                                default      => '#94a3b8',
                                            };
                                        @endphp
                                        <span class="mpm-loader" style="--mpm-loader-c:{{ $lc }};">{{ $loader }}</span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <p class="mpm-pack__desc">{{ $pack['summary'] }}</p>
                        <div class="mpm-pack__foot">
                            @if($isInstalled && $updateAvailable)
                                <button type="button" wire:click="openModal('{{ $pack['id'] }}', '{{ $pack['provider'] ?? '' }}')" class="mpm-btn mpm-btn--update">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    Update Available
                                </button>
                            @elseif($isInstalled)
                                <button type="button" wire:click="openModal('{{ $pack['id'] }}', '{{ $pack['provider'] ?? '' }}')" class="mpm-btn mpm-btn--ghost" style="width:100%;">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color:var(--mpm-success);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    Installed · Reinstall
                                </button>
                            @else
                                <button type="button" wire:click="openModal('{{ $pack['id'] }}', '{{ $pack['provider'] ?? '' }}')" class="mpm-btn mpm-btn--install">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    Install
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

        @else
            <div class="mpm-card mpm-empty mpm-fade">
                <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                <p style="font-weight:600;color:var(--mpm-text-2);margin:0;">No modpacks found</p>
                <p style="font-size:.85rem;margin:0;">Try a different search or provider.</p>
            </div>
        @endif

    @endif

    {{-- ══════════════ INSTALL / UPDATE MODAL ══════════════ --}}
    {{-- Kept inside the Livewire component root so wire: directives work.
         position:fixed escapes layout flow; high z-index overlays the panel. --}}
    <div class="mpm-overlay" x-show="modalOpen" x-cloak x-transition.opacity
         @keydown.escape.window="$wire.closeModal()" @click.self="$wire.closeModal()">
        <div class="mpm-modal" x-show="modalOpen" x-transition>
                    {{-- Fades in instantly on click (client-side), masking the startInstall round-trip. --}}
                    <div class="mpm-modal__starting" wire:loading.flex wire:target="startInstall">
                        <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <div>
                            <p>Preparing installation…</p>
                            <small>Setting things up on your server</small>
                        </div>
                    </div>
                    @if($selectedModpack)
                        @php
                            $isInstalledPack = $installedModpack
                                && (string) $installedModpack['id'] === (string) $selectedModpack['id']
                                && ($installedModpack['provider'] ?? null) === ($selectedModpack['provider'] ?? null);
                            $isUpd       = $isInstalledPack && $updateAvailable;
                            $actionLabel = $isUpd ? 'Update' : ($isInstalledPack ? 'Reinstall' : 'Install');
                        @endphp
                        <div class="mpm-modal__head">
                            @if($selectedModpack['iconUrl'])
                                <img src="{{ $selectedModpack['iconUrl'] }}" alt="" class="mpm-modal__icon">
                            @endif
                            <div style="min-width:0;">
                                <h3 class="mpm-modal__title">{{ $actionLabel }} — {{ $selectedModpack['name'] }}</h3>
                                <p class="mpm-sub" style="margin-top:3px;">{{ $selectedModpack['author'] ?? '' }}</p>
                            </div>
                            <button type="button" class="mpm-modal__close" wire:click="closeModal">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="mpm-modal__body"
                             wire:key="modal-{{ $selectedModpack['provider'] ?? '' }}-{{ $selectedModpack['id'] ?? '' }}"
                             wire:init="loadVersions">
                            <div>
                                <p class="mpm-eyebrow">Installation Type</p>
                                <span class="mpm-badge {{ $isUpd ? 'mpm-badge--update' : 'mpm-badge--install' }}">
                                    <span class="dot"></span>{{ $actionLabel }}
                                </span>
                            </div>

                            <div class="mpm-preserve">
                                <div class="mpm-preserve__head">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    These files will be preserved
                                </div>
                                <div class="mpm-preserve__tags">
                                    @foreach($this->getPreservedFilesProperty() as $file)
                                        <span class="mpm-tag">{{ $file }}</span>
                                    @endforeach
                                </div>
                                <p class="mpm-preserve__note">These files are never deleted and always restored after install.</p>
                            </div>

                            <div>
                                <label class="mpm-label">Select Version *</label>
                                @if($versionsLoading)
                                    <div class="mpm-loading-box">
                                        <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Loading versions…
                                    </div>
                                @elseif(empty($versions))
                                    <p style="color:var(--mpm-danger);font-size:.85rem;margin:0;">No versions available.</p>
                                @else
                                    <div class="mpm-select-wrap">
                                        <select class="mpm-select" wire:model="selectedVersion">
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

                            <label class="mpm-switch">
                                <input type="checkbox" wire:model="createBackup">
                                <span class="mpm-switch__track"></span>
                                <span>
                                    <span class="mpm-switch__title">Create a backup first</span>
                                    <span class="mpm-switch__desc">Makes a Pelican backup (shown in the Backups tab) before installing (recommended)</span>
                                </span>
                            </label>

                            <label class="mpm-switch">
                                <input type="checkbox" wire:model="deleteExisting">
                                <span class="mpm-switch__track"></span>
                                <span>
                                    <span class="mpm-switch__title">Delete existing server files</span>
                                    <span class="mpm-switch__desc">Removes old mods/config before install (preserved files are kept)</span>
                                </span>
                            </label>

                            <div class="mpm-modal__actions">
                                <button type="button" wire:click="startInstall" wire:loading.attr="disabled" wire:target="startInstall,linkInstalled"
                                        class="mpm-btn {{ $isUpd ? 'mpm-btn--update' : 'mpm-btn--install' }}"
                                        @if($versionsLoading || empty($versions)) disabled @endif>
                                    <span wire:loading.remove wire:target="startInstall">{{ $isUpd ? 'Submit Update' : $actionLabel }}</span>
                                    <span wire:loading wire:target="startInstall">
                                        <svg class="mpm-spin" style="width:15px;height:15px;vertical-align:-2px;margin-right:6px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Starting…
                                    </span>
                                </button>
                                <button type="button" class="mpm-btn mpm-btn--ghost" wire:click="closeModal">Cancel</button>
                            </div>

                            {{-- Non-destructive recovery: register a pack you already have installed
                                 (e.g. an install record was lost) without wiping/re-downloading files. --}}
                            <div class="mpm-modal__link-existing">
                                <button type="button" wire:click="linkInstalled" wire:loading.attr="disabled" wire:target="startInstall,linkInstalled"
                                        class="mpm-link-existing"
                                        @if($versionsLoading || empty($versions)) disabled @endif>
                                    <span wire:loading.remove wire:target="linkInstalled">Already installed? Link this version without reinstalling</span>
                                    <span wire:loading wire:target="linkInstalled">
                                        <svg class="mpm-spin" style="width:13px;height:13px;vertical-align:-2px;margin-right:6px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Linking…
                                    </span>
                                </button>
                                <span class="mpm-link-existing__hint">Marks this pack as installed for the banner &amp; update checks. No files are changed.</span>
                            </div>
                        </div>
                    @else
                        <div style="padding:40px;display:flex;justify-content:center;">
                            <svg class="mpm-spin" style="width:26px;height:26px;color:var(--mpm-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </div>
                    @endif
                </div>
            </div>

</div>
</div>

</x-filament-panels::page>
