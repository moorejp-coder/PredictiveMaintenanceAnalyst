{{--
  "Start here" guide for first-time visitors and contest judges.
  A native modal dialog. It opens when the dashboard loads, once per browser
  session, and the "Guide" button in the toolbar (#guide-open) opens it again.
  Button names below match the real buttons on the dashboard.
--}}
<style nonce="{{ $cspNonce ?? '' }}">
    .guide-modal { width: min(720px, calc(100vw - 32px)); max-height: calc(100vh - 48px); border-top: 3px solid var(--prism-accent); }
    .guide-inner { padding: 24px 28px 28px; }
    .guide-head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--prism-space-3); margin-bottom: var(--prism-space-2); }
    .guide-head h2 { margin: 0; font-size: 1.5rem; }
    .guide-lead { margin: 0 0 var(--prism-space-6); color: var(--prism-muted); font-size: 0.9375rem; max-width: 56ch; }
    .guide-modal ol { list-style: none; counter-reset: guide; margin: 0; padding: 0; }
    .guide-modal li { counter-increment: guide; display: grid; grid-template-columns: 2.5rem 1fr; column-gap: var(--prism-space-3); padding: var(--prism-space-4) 0; border-top: 1px solid var(--prism-border); font-size: 0.9375rem; }
    .guide-modal li::before { content: counter(guide); grid-row: 1 / span 2; font-family: var(--prism-font-data); font-size: 1.5rem; font-weight: 500; line-height: 1.2; color: var(--prism-accent); }
    .guide-modal li strong { display: block; }
    .guide-modal li span { color: var(--prism-muted); }
    .guide-foot { margin: var(--prism-space-4) 0; color: var(--prism-muted); font-size: 0.8125rem; }
    .guide-actions { display: flex; flex-wrap: wrap; gap: var(--prism-space-2); justify-content: flex-end; }
</style>
<dialog class="guide-modal" id="guide-modal" aria-labelledby="guide-title">
    <div class="guide-inner">
        <div class="guide-head">
            <h2 id="guide-title">Start here: a four step tour of PRISM</h2>
            <button type="button" class="btn" id="guide-close-x" aria-label="Close the guide">Close</button>
        </div>
        <p class="guide-lead">PRISM scores the failure risk of one milling machine. Every reading is simulated. Follow these steps to see each part work.</p>
        <ol>
            <li>
                <strong>Pick a situation</strong>
                <span>Press Worn tool, Overheating or Overload in the toolbar. Watch the score ring and the band change. Healthy stays Low.</span>
            </li>
            <li>
                <strong>Read why</strong>
                <span>"Why this score" gives the reason in plain words. The score is the highest of the six colored bars. The gray speed bar is context only.</span>
            </li>
            <li>
                <strong>Ask for an explanation</strong>
                <span>When the band reaches Elevated or High, press "Explain this reading." The label says AI-GENERATED or RULE-BASED, NO AI USED. Rate the answer Helpful or Wrong.</span>
            </li>
            <li>
                <strong>Try the safety check</strong>
                <span>On a High score, press "Keep running anyway" to record an override. Type a made-up name. Then read <a href="{{ url('/ai') }}">AI use</a> for the limits and the test results.</span>
            </li>
        </ol>
        <p class="guide-foot">The rules set the score. The AI only explains it. A qualified technician decides what to do. Press "Open the guide" beside the page title to open this tour again.</p>
        <div class="guide-actions">
            <button type="button" class="btn btn--primary" id="guide-close" autofocus>Start exploring</button>
        </div>
    </div>
</dialog>
<script src="{{ asset('js/judge-guide.js') }}?v={{ filemtime(public_path('js/judge-guide.js')) }}"></script>
