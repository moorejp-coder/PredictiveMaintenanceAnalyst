{{--
  "Start here" guide for first-time visitors and contest judges. Plain HTML, no script.
  A native details element, open by default, so a visitor hides it with one press.
  Button names below match the real buttons on the dashboard.
--}}
<style>
    .guide { margin: var(--prism-space-3) 0; }
    .guide > summary { cursor: pointer; min-height: 44px; display: flex; align-items: center; font-weight: 600; font-size: 1.0625rem; }
    .guide .guide-lead { margin: 0 0 var(--prism-space-3); color: var(--prism-muted); font-size: 0.9375rem; max-width: 75ch; }
    .guide ol { list-style: none; counter-reset: guide; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--prism-space-3); }
    .guide li { counter-increment: guide; background: var(--prism-surface-raised); border: 1px solid var(--prism-border); border-radius: var(--prism-radius-md); padding: var(--prism-space-3) var(--prism-space-4); font-size: 0.9375rem; }
    .guide li::before { content: counter(guide); display: inline-flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; margin-bottom: var(--prism-space-2); border: 2px solid var(--prism-accent); border-radius: 50%; color: var(--prism-accent); font-family: var(--prism-font-data); font-weight: 600; }
    .guide li strong { display: block; margin-bottom: var(--prism-space-1); }
    .guide li span { color: var(--prism-muted); }
    .guide .guide-foot { margin: var(--prism-space-3) 0 0; color: var(--prism-muted); font-size: 0.8125rem; }
</style>
<details class="card guide" id="judge-guide" open>
    <summary>Start here: a four step tour of PRISM</summary>
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
    <p class="guide-foot">The rules set the score. The AI only explains it. A qualified technician decides what to do.</p>
</details>
