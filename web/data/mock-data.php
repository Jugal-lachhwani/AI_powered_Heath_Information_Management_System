<?php
/**
 * Static UI content for the AI Voice Consultation Module.
 * Patient/visit/doctor context is DB-driven (see includes/core/db.php, /consultation/workspace.php).
 */

$voice_commands = [
    ['label' => 'Start Recording', 'command' => 'start_recording'],
    ['label' => 'Stop Recording', 'command' => 'stop_recording'],
    ['label' => 'Next Section', 'command' => 'next_section'],
    ['label' => 'Print Prescription', 'command' => 'print_prescription'],
    ['label' => 'Save Consultation', 'command' => 'save_consultation'],
    ['label' => 'Open Previous Visit', 'command' => 'open_previous_visit'],
    ['label' => 'Next Patient', 'command' => 'next_patient'],
];
