<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Pushes report rows to a Google Sheet through a Google Apps Script web app
 * (see docs/google-sheets-sync.md for the script). Each report goes to its own
 * tab; the script updates a row when its key already exists and appends it
 * otherwise, so re-sending a day (e.g. after a late check-out or a correction)
 * never duplicates rows. Off until configured in Settings > Google Sheets.
 */
class GoogleSheetsSync
{
    /** Reports that can be synced, with the columns that identify a row. */
    public const REPORTS = [
        'attendance' => ['Attendance', ['employee_code', 'work_date']],
        'leave' => ['Leave', ['id']],
        'overtime' => ['Overtime', ['id']],
        'tasks' => ['Tasks', ['id']],
    ];

    public const LAST_RESULT_KEY = 'google_sheets.last_sync';

    public function __construct(private readonly PolicyConfigurationService $policies, private readonly ReportService $reports, private readonly AuditLogger $audit) {}

    public function settings(): array
    {
        $config = $this->policies->forEmployee(null, 'integrations.google_sheets', today()->toDateString(), []);
        $secret = null;
        if (! empty($config['secret'])) {
            try { $secret = Crypt::decryptString((string) $config['secret']); } catch (\Throwable) { $secret = null; }
        }

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'url' => (string) ($config['url'] ?? ''),
            'secret' => $secret,
            'reports' => array_values(array_intersect((array) ($config['reports'] ?? ['attendance']), array_keys(self::REPORTS))),
            'run_as' => isset($config['run_as']) ? (int) $config['run_as'] : null,
        ];
    }

    /** Saves the connection; an empty secret keeps the one already stored. */
    public function configure(bool $enabled, string $url, ?string $secret, array $reports, User $by): void
    {
        $current = $this->settings();
        $secret = $secret !== null && $secret !== '' ? $secret : $current['secret'];
        app(PeopleOperationsService::class)->setPolicy('integrations.google_sheets', 'integrations', 'Google Sheets sync', [
            'enabled' => $enabled,
            'url' => trim($url),
            'secret' => $secret ? Crypt::encryptString($secret) : null,
            'reports' => array_values(array_intersect($reports, array_keys(self::REPORTS))),
            'run_as' => $by->id,
        ], null, $by->id, today()->toDateString());
    }

    /**
     * Sends the given days (default: yesterday and today) for every enabled report.
     *
     * @return array{ok: bool, message: string, rows: int, at: string}
     */
    public function sync(?Carbon $from = null, ?Carbon $to = null, bool $force = false): array
    {
        $settings = $this->settings();
        $to ??= today();
        $from ??= $to->copy()->subDay();
        if (! $force && ! $settings['enabled']) return ['ok' => false, 'message' => 'Google Sheets sync is turned off.', 'rows' => 0, 'at' => now()->toDateTimeString()];
        if ($settings['url'] === '' || ! str_starts_with($settings['url'], 'https://')) return $this->remember(false, 'Add the https web-app URL of the Apps Script first.', 0);
        $user = $settings['run_as'] ? User::find($settings['run_as']) : null;
        if (! $user || ! $user->is_active) return $this->remember(false, 'The administrator who set up the sync is no longer active. Save the Google Sheets settings again.', 0);

        $total = 0;
        foreach ($settings['reports'] as $type) {
            if (! $this->reports->canView($user, $type)) return $this->remember(false, "{$user->name} cannot view the {$type} report, so it was not sent.", $total);
            [$sheet, $keys] = self::REPORTS[$type];
            $columns = $this->reports->columns($type);
            $rows = $this->reports->rows($user, $type, ['from' => $from->toDateString(), 'to' => $to->toDateString()])
                ->map(fn ($row) => array_map(fn ($column) => $row[$column] ?? null, $columns))->values()->all();
            try {
                $response = Http::timeout(30)->acceptJson()->post($settings['url'], [
                    'secret' => $settings['secret'], 'sheet' => $sheet, 'key_columns' => $keys, 'columns' => $columns, 'rows' => $rows,
                ]);
            } catch (\Throwable $error) {
                return $this->remember(false, "Could not reach the Apps Script: {$error->getMessage()}", $total);
            }
            $body = $response->json();
            if (! $response->successful() || ! is_array($body) || ($body['ok'] ?? false) !== true) {
                $reason = is_array($body) && isset($body['error']) ? (string) $body['error'] : 'HTTP '.$response->status();
                return $this->remember(false, "Google Sheets rejected the {$sheet} rows: {$reason}", $total);
            }
            $total += count($rows);
        }
        $this->audit->record('integrations.google_sheets_synced', null, ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $total, 'reports' => $settings['reports']]);

        return $this->remember(true, "Sent {$total} row(s) for ".$from->format('d M').($from->isSameDay($to) ? '' : ' – '.$to->format('d M Y')).'.', $total);
    }

    public function lastResult(): ?array
    {
        return Cache::get(self::LAST_RESULT_KEY);
    }

    private function remember(bool $ok, string $message, int $rows): array
    {
        $result = ['ok' => $ok, 'message' => $message, 'rows' => $rows, 'at' => now()->toDateTimeString()];
        Cache::forever(self::LAST_RESULT_KEY, $result);

        return $result;
    }
}
