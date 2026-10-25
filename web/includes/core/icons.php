<?php
/**
 * Minimal line-icon set (24x24, stroke-based). No emojis anywhere in the UI.
 */
function icon(string $name, string $class = 'w-5 h-5'): string
{
    $common = 'fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"';

    $paths = [
        'mic' => '<path d="M12 15a3.5 3.5 0 0 0 3.5-3.5V6a3.5 3.5 0 1 0-7 0v5.5A3.5 3.5 0 0 0 12 15Z"/><path d="M6 11.5a6 6 0 0 0 12 0"/><path d="M12 17.5V21"/><path d="M8.5 21h7"/>',
        'play' => '<path d="M7 5.5v13l11-6.5-11-6.5Z" stroke-linejoin="round"/>',
        'pause' => '<rect x="6.5" y="5.5" width="3.5" height="13" rx="1"/><rect x="14" y="5.5" width="3.5" height="13" rx="1"/>',
        'stop' => '<rect x="6" y="6" width="12" height="12" rx="2"/>',
        'upload' => '<path d="M12 15V4"/><path d="M7.5 8.5 12 4l4.5 4.5"/><path d="M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5Z"/>',
        'pencil' => '<path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="M13.5 8 16 10.5"/>',
        'calendar' => '<rect x="4" y="5.5" width="16" height="15" rx="2"/><path d="M4 10h16"/><path d="M8 3.5v3M16 3.5v3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'trash' => '<path d="M5 7h14"/><path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7"/><path d="M7 7l1 12.5A2 2 0 0 0 10 21.5h4a2 2 0 0 0 2-1.5L17 7"/><path d="M10 11v6M14 11v6"/>',
        'alert' => '<path d="M12 4 3 20h18L12 4Z" stroke-linejoin="round"/><path d="M12 10v4.5"/><circle cx="12" cy="17.3" r="0.9" fill="currentColor" stroke="none"/>',
        'sparkles' => '<path d="M11 4 12.3 8 16 9.3 12.3 10.6 11 14.6 9.7 10.6 6 9.3 9.7 8 11 4Z" stroke-linejoin="round"/><path d="M18 14l0.8 2.2L21 17l-2.2 0.8L18 20l-0.8-2.2L15 17l2.2-0.8L18 14Z" stroke-linejoin="round"/>',
        'check-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.3l2.4 2.4 4.6-5.4"/>',
        'printer' => '<path d="M7 8.5V4.5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v4"/><rect x="4.5" y="8.5" width="15" height="7.5" rx="1.5"/><rect x="7.5" y="13.5" width="9" height="6" rx="1"/>',
        'download' => '<path d="M12 4v11"/><path d="M7.5 11.5 12 16l4.5-4.5"/><path d="M5 19.5h14"/>',
        'message' => '<path d="M4.5 6.5A2 2 0 0 1 6.5 4.5h11a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H9L4.5 20V6.5Z" stroke-linejoin="round"/>',
        'phone' => '<path d="M6 4.5h2.5l1.3 4-2 1.3a11 11 0 0 0 5.4 5.4l1.3-2 4 1.3V17a2 2 0 0 1-2 2A14 14 0 0 1 4 6.5a2 2 0 0 1 2-2Z" stroke-linejoin="round"/>',
        'arrow-right' => '<path d="M4.5 12h15"/><path d="M13.5 5.5 20 12l-6.5 6.5"/>',
        'chevron-down' => '<path d="M5.5 8.5 12 15l6.5-6.5"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'activity' => '<path d="M3 12h4l2-7 4 14 2-7h6"/>',
        'beaker' => '<path d="M9 3.5h6"/><path d="M10 4v6.2L4.8 18a1.5 1.5 0 0 0 1.3 2.3h11.8a1.5 1.5 0 0 0 1.3-2.3L14 10.2V4"/><path d="M7.5 15h9"/>',
        'clipboard' => '<rect x="5.5" y="5" width="13" height="16" rx="2"/><rect x="9" y="3" width="6" height="3.5" rx="1"/><path d="M8.5 11h7M8.5 14.5h7M8.5 18h4.5"/>',
        'user' => '<circle cx="12" cy="8.3" r="3.3"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 1.8"/>',
        'menu' => '<path d="M4 6.5h16M4 12h16M4 17.5h16"/>',
        'save' => '<path d="M6 4.5h9.5L18 7v12.5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V5.5a1 1 0 0 1 1-1Z" stroke-linejoin="round"/><path d="M8.5 4.5V9h6V4.5"/><path d="M8.5 20.5v-5h7v5"/>',
        'shield' => '<path d="M12 3.5 19 6.5v5c0 5-3.2 7.8-7 9-3.8-1.2-7-4-7-9v-5L12 3.5Z" stroke-linejoin="round"/><path d="M9 12l2 2 4-4.2"/>',
        'wave' => '<path d="M2 12h2.2l1-6 2 12 2-15 2 18 2-12 2 6h3.6"/>',
        'mail' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4.5 7l7.5 6 7.5-6"/>',
        'stethoscope' => '<path d="M6 4.5v4.8a4 4 0 0 0 8 0V4.5"/><path d="M4.5 4.5h3"/><path d="M12.5 4.5h3"/><path d="M10 13.2v1.3a4.5 4.5 0 0 0 9 0V13"/><circle cx="19" cy="10.8" r="2.2"/>',
        'hospital' => '<path d="M5 20.5V5.5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v15"/><path d="M3.5 20.5h17"/><path d="M9 20.5v-5h6v5"/><path d="M12 7.2v4.6M9.7 9.5h4.6"/><path d="M8 14h1.5M14.5 14H16"/>',
        'heart-pulse' => '<path d="M4.8 12.3 12 20l7.2-7.7a4.4 4.4 0 0 0-.2-6.1 4.2 4.2 0 0 0-6 0l-1 1.1-1-1.1a4.2 4.2 0 0 0-6 0 4.4 4.4 0 0 0-.2 6.1Z"/><path d="M7 12h2.3l1-2.4 2.2 5.2 1.2-2.8H17"/>',
        'bot' => '<rect x="5" y="8" width="14" height="10.5" rx="3"/><path d="M12 8V4.5"/><circle cx="12" cy="3.8" r="1"/><path d="M9 12.5h.1M15 12.5h.1"/><path d="M9.5 16h5"/>',
    ];

    $body = $paths[$name] ?? $paths['sparkles'];
    return '<svg class="' . htmlspecialchars($class) . '" viewBox="0 0 24 24" ' . $common . ' aria-hidden="true">' . $body . '</svg>';
}
