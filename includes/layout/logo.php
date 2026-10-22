<?php /* Inline brand mark (same artwork as assets/images/logo.svg) */ ?>
<svg class="ns-logo-mark" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="nsMark<?= $logoId = $logoId ?? random_int(100, 999) ?>" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#0FB5A6"/><stop offset="1" stop-color="#6B4EFF"/>
        </linearGradient>
    </defs>
    <path d="M10 4h28a6 6 0 0 1 6 6v20a6 6 0 0 1-6 6H22l-9 8v-8h-3a6 6 0 0 1-6-6V10a6 6 0 0 1 6-6z" fill="url(#nsMark<?= $logoId ?>)"/>
    <path d="M24 9l2.6 7.4L34 20l-7.4 2.6L24 30l-2.6-7.4L14 20l7.4-3.6z" fill="#fff"/>
    <circle cx="35" cy="10.5" r="2.2" fill="#fff" opacity=".85"/>
</svg>
<?php unset($logoId); ?>
