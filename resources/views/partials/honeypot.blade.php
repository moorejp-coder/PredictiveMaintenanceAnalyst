{{-- Hidden from people and from screen readers. A bot that fills every field fills this one, and RejectAbuse rejects the submission. --}}
<div class="hp-field" aria-hidden="true">
    <label for="{{ \App\Support\AbuseGuard::HONEYPOT }}-{{ $hpId ?? 'form' }}">Leave this field empty</label>
    <input type="text" id="{{ \App\Support\AbuseGuard::HONEYPOT }}-{{ $hpId ?? 'form' }}" name="{{ \App\Support\AbuseGuard::HONEYPOT }}" value="" tabindex="-1" autocomplete="off">
</div>
