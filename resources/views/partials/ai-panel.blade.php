{{--
  "Explain this reading" panel. Two small AI calls, run only on a button press.
  Needs window.prismLatest set by the dashboard script to the latest /api/readings
  response (an object with scenario and tick). If the AI is slow or off, the
  server sends back the built-in explanation, so the panel always has an answer.
--}}
<section class="card ai" id="ai-panel" aria-labelledby="ai-title"
         data-diagnosis="{{ url('/api/diagnosis') }}" data-order="{{ url('/api/work-order') }}"
         data-feedback="{{ url('/api/ai-feedback') }}">
    <style nonce="{{ $cspNonce ?? '' }}">
        .ai { margin-top: 0; border-left: 3px solid var(--prism-accent); }
        .ai-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 24px; }
        .ai-head h2 { margin: 0; font-size: 1.25rem; color: var(--prism-text); }
        .ai-head p { margin: 4px 0 0; max-width: 52ch; }
        .ai-cta { font-size: 1rem; min-height: 48px; padding-left: 24px; padding-right: 24px; }
        @media (max-width: 480px) { .ai-cta { width: 100%; } }
        .ai h3 { font-size: 0.9375rem; margin: 18px 0 4px; }
        .ai ul, .ai ol { margin: 6px 0 0; padding-left: 20px; }
        .ai p { margin: 6px 0 0; }
        .ai .ai-note, .ai-modal .ai-note { color: var(--prism-muted); font-size: 0.875rem; }
        .ai [hidden] { display: none; }
        .ai-modal { width: min(1080px, calc(100vw - 32px)); max-height: calc(100vh - 48px); border-top: 3px solid var(--prism-accent); }
        /* Wide pop-up: the cause and the work order sit side by side on a wide screen, so a long answer needs little or no scrolling. */
        .ai-body { display: grid; gap: 0 40px; }
        .ai-body > .ai-note { grid-column: 1 / -1; }
        @media (min-width: 900px) {
            .ai-body { grid-template-columns: 1fr 1fr; align-items: start; }
            .ai-body > .ai-col-right:has(> :not([hidden])) { border-left: 1px solid var(--prism-border); padding-left: 40px; }
        }
        .ai-modal-inner { padding: 20px 28px 22px; }
        .ai-modal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .ai-modal-head h2 { margin: 0; font-size: 1.375rem; }
        .ai-modal-head .ai-note { margin: 2px 0 0; }
        .ai-close { min-height: 44px; min-width: 44px; }
        .ai-label { display: inline-block; margin: 10px 0 0; padding: 0 8px; border: 1px solid var(--prism-accent); border-radius: var(--prism-radius-sm); color: var(--prism-accent); font-size: 0.8125rem; font-weight: 600; line-height: 1.6; }
        .ai-warn { margin: 10px 0 0; padding: 8px 12px; border-left: 3px solid var(--prism-risk-elevated); background: var(--prism-surface-raised); border-radius: 0 var(--prism-radius-md) var(--prism-radius-md) 0; font-size: 0.875rem; }
        .ai-feedback { margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--prism-border); }
        .ai-feedback p { margin: 0 0 8px; font-size: 0.9375rem; }
        .ai-feedback .ai-fb-row { display: flex; flex-wrap: wrap; gap: 8px; }
        .ai-feedback .btn { min-height: 44px; }
        /* The thank-you text sits to the right of the buttons, so rating an answer never adds a line and never causes scrolling. */
        .ai-feedback .ai-fb-line { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; }
        .ai-feedback .ai-fb-line .ai-note { margin: 0; }
    </style>
    <div class="ai-head">
        <div>
            <h2 id="ai-title">Explain this reading</h2>
            <p class="ai-note">Get the likely cause and a drafted work order for the current reading. If the AI is slow or unavailable, PRISM shows its built-in explanation instead. <a href="{{ url('/ai') }}">How PRISM uses AI</a></p>
        </div>
        <button type="button" class="btn btn--primary ai-cta" id="ai-run">Explain this reading</button>
    </div>
    <dialog class="ai-modal" id="ai-modal" aria-labelledby="ai-modal-title">
        <div class="ai-modal-inner">
            <div class="ai-modal-head">
                <div>
                    <h2 id="ai-modal-title">Explain this reading</h2>
                    <p class="ai-note">The simulated feed is paused while this pop-up is open.</p>
                </div>
                <button type="button" class="btn ai-close" id="ai-close" aria-label="Close">Close</button>
            </div>
            <span class="ai-label" id="ai-label" hidden></span>
            <p class="ai-warn" id="ai-warn" hidden></p>
            <div class="ai-body" aria-live="polite">
                <p class="ai-note" id="ai-status"></p>
                <div class="ai-col ai-col-left">
                    <div id="ai-diagnosis" hidden>
                        <h3>Likely cause</h3>
                        <p id="ai-cause"></p>
                        <ul id="ai-checks"></ul>
                    </div>
                </div>
                <div class="ai-col ai-col-right">
                    <div id="ai-order" hidden>
                        <h3 id="ai-order-title"></h3>
                        <p id="ai-priority"></p>
                        <ol id="ai-steps"></ol>
                    </div>
                    <div id="ai-tested" hidden>
                        <h3>Tested on a copy</h3>
                        <p id="ai-tested-text"></p>
                    </div>
                    <div id="ai-trace" hidden>
                        <h3>What the AI looked at</h3>
                        <ul id="ai-trace-list"></ul>
                    </div>
                </div>
                <p class="ai-note" id="ai-meta"></p>
            </div>
            <div class="ai-feedback" id="ai-feedback" hidden>
                <p id="ai-feedback-q">Was this answer correct and useful?</p>
                <div class="ai-fb-line">
                    <div class="ai-fb-row" role="group" aria-labelledby="ai-feedback-q">
                        <button type="button" class="btn" id="ai-fb-helpful">Helpful</button>
                        <button type="button" class="btn" id="ai-fb-wrong">Wrong</button>
                    </div>
                    <p class="ai-note" id="ai-fb-status" role="status"></p>
                </div>
            </div>
        </div>
    </dialog>
    <script src="{{ asset('js/ai-panel.js') }}?v={{ filemtime(public_path('js/ai-panel.js')) }}"></script>
</section>
