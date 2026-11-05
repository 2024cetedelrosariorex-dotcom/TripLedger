<?php
function auditSummary($json): string
{
    if (!is_string($json) || $json === '') {
        return '—';
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        return '—';
    }

    // Only these fields may be displayed.
    // Passwords, hashes, and tokens are excluded.
    $fields = [
        'enabled' => 'Gemini enabled',
        'explanations_enabled' => 'Explanations enabled',
        'analysis_enabled' => 'Analysis enabled',
        'model' => 'Model',
        'target_user_id' => 'Staff ID',
        'username' => 'Username',
        'full_name' => 'Name',
        'role' => 'Role',
        'is_active' => 'Active',
        'ticket_number' => 'Ticket',
        'transaction_date' => 'Transaction date',
        'plate_number' => 'Plate',
        'driver_name' => 'Driver',
        'return_id' => 'Return ID',
        'return_date' => 'Return date',
        'remarks' => 'Remarks',
        'status' => 'Status',
        'reason' => 'Reason',
        'recorded_by' => 'Recorded by user ID',
        'reversed_by' => 'Reversed by user ID'
    ];

    $parts = [];

    foreach ($fields as $key => $label) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $value = $data[$key];

        if ($value === null) {
            $value = '—';
        } elseif (!is_scalar($value)) {
            continue;
        }

        if ($key === 'is_active') {
            $value = (int) $value === 1 ? 'Yes' : 'No';
        }

        if ($value === '') {
            $value = '(empty)';
        }

        $parts[] = $label . ': ' . (string) $value;
    }

    return $parts ? implode("\n", $parts) : '—';
}

