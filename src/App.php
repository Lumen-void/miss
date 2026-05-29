<?php

declare(strict_types=1);

namespace MisTool;

use MisTool\Services\ExcelExporter;
use MisTool\Services\ApiIntegrationService;
use MisTool\Services\AutoImportService;
use MisTool\Services\Importer;
use MisTool\Services\InventoryService;
use MisTool\Services\MasterSeeder;
use MisTool\Services\MisCalculator;
use RuntimeException;
use Throwable;

final class App
{
    private Database $db;
    private string $root;
    private array $flash = [];

    public function __construct(string $root)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        $this->root = $root;
        $storage = $root . '/storage';
        $uploads = $storage . '/uploads';
        foreach ([$storage, $uploads, $storage . '/exports'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Unable to create {$dir}");
            }
            @chmod($dir, 0777);
        }

        $this->db = new Database($storage . '/mis.sqlite');
        $this->db->migrate();
        new AutoImportService($this->db, $this->root);

        $sample = '/Users/deepanshujain/Downloads/Naturesum Feb\'26 MIS Report.xlsx';
        (new MasterSeeder($this->db, $sample))->seedIfEmpty();
    }

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->handlePost($path);
                return;
            }

            match ($path) {
                '/', '/index.php' => $this->dashboard(),
                '/dashboard/close' => $this->dashboard('close'),
                '/dashboard/trends' => $this->dashboard('trends'),
                '/auto-import' => $this->autoImport(),
                '/auto-import/status' => $this->autoImportStatus(),
                '/integrations' => $this->integrations(),
                '/portal/connect' => $this->portalConnect(),
                '/portal/connect-all' => $this->portalConnectAll(),
                '/portal/status' => $this->portalStatus(),
                '/imports/new' => $this->importsNew(),
                '/imports/sources' => $this->autoImport('sources'),
                '/imports/activity' => $this->autoImport('activity'),
                '/imports/manual' => $this->importsNew('manual'),
                '/sales' => $this->sales(),
                '/sales/charts' => $this->sales('charts'),
                '/sales/platforms' => $this->sales('platforms'),
                '/sales/records' => $this->sales('records'),
                '/inventory' => $this->inventory(),
                '/inventory/stock' => $this->inventory('stock'),
                '/inventory/movements' => $this->inventory('movements'),
                '/inventory/setup' => $this->inventory('setup'),
                '/validation' => $this->validation(),
                '/adjustments' => $this->adjustments(),
                '/reports' => $this->reports(),
                '/reports/executive' => $this->reports('executive'),
                '/reports/pnl' => $this->reports('pnl'),
                '/reports/loss-watch' => $this->reports('loss-watch'),
                '/masters' => $this->masters(),
                '/mis/preview' => $this->preview(),
                '/mis/charts' => $this->preview('charts'),
                '/mis/profit-bridge' => $this->preview('profit-bridge'),
                '/mis/platforms' => $this->preview('platforms'),
                '/mis/categories' => $this->preview('categories'),
                '/mis/audit' => $this->preview('audit'),
                '/mis/export' => $this->export(),
                default => $this->notFound($path),
            };
        } catch (Throwable $e) {
            http_response_code(500);
            $this->render('Error', function () use ($e): void {
                echo '<section class="panel danger"><h1>Something went wrong</h1>';
                echo '<p>' . e($e->getMessage()) . '</p></section>';
            });
        }
    }

    private function handlePost(string $path): void
    {
        match ($path) {
            '/runs/create' => $this->createRun(),
            '/runs/finalize' => $this->finalizeRun(),
            '/runs/unlock' => $this->unlockRun(),
            '/auto-import/run' => $this->runAutoImport(),
            '/auto-import/stop' => $this->stopAutoImport(),
            '/auto-import/cleanup-temp' => $this->cleanupAutoImportTemp(),
            '/auto-import/schedule' => $this->saveAutoImportSchedule(),
            '/integrations/save' => $this->saveIntegration(),
            '/integrations/disconnect' => $this->disconnectIntegration(),
            '/integrations/import' => $this->runApiImport(),
            '/portal/verify' => $this->verifyPortalConnection(),
            '/imports/upload' => $this->uploadImport(),
            '/inventory/item' => $this->saveInventoryItem(),
            '/inventory/warehouse' => $this->saveWarehouse(),
            '/inventory/movement' => $this->saveInventoryMovement(),
            '/inventory/transfer' => $this->transferInventory(),
            '/inventory/sync-sales' => $this->syncInventorySales(),
            '/validation/map' => $this->mapValidationProduct(),
            '/adjustments/save' => $this->saveAdjustment(),
            '/adjustments/delete' => $this->deleteAdjustment(),
            '/masters/save' => $this->saveMasters(),
            '/mis/recalculate' => $this->recalculate(),
            default => $this->notFound($path),
        };
    }

    private function dashboard(string $view = 'overview'): void
    {
        $runs = $this->db->fetchAll('SELECT * FROM monthly_runs ORDER BY month DESC, id DESC');
        $selectedRunId = (int) ($_GET['run_id'] ?? 0);
        $latest = $selectedRunId > 0
            ? $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$selectedRunId])
            : $this->defaultRun();
        $latest ??= $runs[0] ?? null;
        $stats = $latest ? $this->runStats((int) $latest['id']) : ['rows' => 0, 'net' => 0, 'returns' => 0, 'issues' => 0];
        $platforms = $latest ? $this->platformCards((int) $latest['id']) : [];
        $categories = $latest ? $this->categoryPerformance((int) $latest['id']) : [];
        $expenses = $latest ? $this->expenseBreakdown((int) $latest['id']) : [];
        $readiness = $latest ? $this->runReadiness($latest) : null;
        $trend = $latest ? $this->monthTrend($latest) : [];
        $overview = $latest ? $this->db->fetchAll('SELECT * FROM mis_overview_lines WHERE run_id = ? ORDER BY sort_order', [$latest['id']]) : [];
        $overviewByLine = [];
        foreach ($overview as $line) {
            $overviewByLine[$line['line_item']] = $line;
        }

        $this->render('MIS Tool', function () use ($runs, $latest, $stats, $platforms, $categories, $expenses, $overviewByLine, $readiness, $trend, $view): void {
            $runParams = $latest ? ['run_id' => $latest['id']] : [];
            $netSurplus = (float) ($overviewByLine['Net surplus / burn']['amount'] ?? 0);
            $grossMargin = (float) ($overviewByLine['Gross margin after COGS']['amount'] ?? 0);
            $netSales = (float) ($overviewByLine['Net sales after tax']['amount'] ?? $stats['net']);
            $health = $stats['issues'] > 0 ? 'Needs review' : (($latest && (int) ($latest['locked'] ?? 0) === 1) ? 'Finalized' : 'Ready to review');

            echo '<section class="hero control-hero">';
            echo '<div class="hero-copy"><p class="eyebrow">Monthly Income Statement</p><h1>MIS command center</h1>';
            echo '<p>One place to bring in sales exports, map Profit and Loss rows, sync inventory, validate totals, and review the final MIS visually.</p>';
            echo '<div class="hero-actions"><a class="button light" href="' . e(route_url('/mis/preview', $runParams)) . '">Open MIS Preview</a><a class="ghost light" href="' . e(route_url('/auto-import', $runParams)) . '">Import Data</a></div></div>';
            echo '<form class="quick-run" method="post" action="' . e(route_url('/runs/create')) . '">';
            echo '<span class="form-title">Open a month</span><label>Month<input type="month" name="month" value="' . e($latest['month'] ?? date('Y-m')) . '" required></label>';
            echo '<button>Create / Open Run</button>';
            echo '<div class="run-snapshot"><span>Current run</span><strong>' . e($latest['month'] ?? 'Not created') . '</strong><small>' . e($health) . '</small></div></form></section>';
            $this->subNav([
                ['Overview', '/', $runParams],
                ['Close Board', '/dashboard/close', $runParams],
                ['Trends', '/dashboard/trends', $runParams],
            ]);

            echo '<section class="stats dashboard-stats">';
            echo '<div class="metric-card"><span>Latest run</span><strong>' . e($latest['month'] ?? '-') . '</strong><small>' . e($latest['status'] ?? 'draft') . '</small></div>';
            echo '<div class="metric-card"><span>Imported rows</span><strong>' . e($stats['rows']) . '</strong><small>Sales and returns</small></div>';
            echo '<div class="metric-card positive"><span>Net revenue</span><strong>₹' . money($stats['net']) . '</strong><small>After tax</small></div>';
            echo '<div class="metric-card"><span>Gross margin</span><strong>₹' . money($grossMargin) . '</strong><small>After COGS</small></div>';
            echo '<div class="metric-card ' . ($netSurplus < 0 ? 'negative' : 'positive') . '"><span>Net result</span><strong>₹' . money($netSurplus) . '</strong><small>Surplus / burn</small></div>';
            echo '<div class="metric-card ' . ($stats['issues'] > 0 ? 'negative' : 'positive') . '"><span>Open issues</span><strong>' . e($stats['issues']) . '</strong><small>' . e($health) . '</small></div>';
            echo '</section>';

            $this->dashboardInsightPanel($platforms, $categories, $expenses, $trend, $stats, $netSurplus, $netSales, $runParams);

            if ($readiness) {
                $this->readinessPanel($readiness, $runParams);
            }

            if ($view !== 'close' && $trend) {
                echo '<section class="panel trend-panel"><div class="section-title"><div><h2>Month trend</h2><p class="muted">Current month compared with the previous available MIS run.</p></div></div><div class="trend-grid">';
                foreach ($trend as $row) {
                    $delta = (float) $row['delta'];
                    echo '<div class="trend-card ' . ($delta < 0 ? 'negative' : 'positive') . '"><span>' . e($row['label']) . '</span><strong>₹' . money($row['current']) . '</strong><small>Previous ₹' . money($row['previous']) . '</small><b>' . ($delta >= 0 ? '+' : '') . '₹' . money($delta) . '</b></div>';
                }
                echo '</div></section>';
            }

            if ($view === 'close') {
                $this->closeStatusPanel($latest, $stats, $expenses, $grossMargin, $netSales);
                return;
            }

            if ($view === 'trends') {
                echo '<section class="panel visual-panel"><div class="section-title"><div><h2>Trend focus</h2><p class="muted">Movement between the current and previous available MIS runs.</p></div></div>';
                if (!$trend) {
                    echo '<p class="muted">No previous run is available for comparison yet.</p>';
                }
                echo '</section>';
                return;
            }

            echo '<section class="workflow command-workflow">';
            foreach ([
                ['API Connect', 'Use authenticated APIs for automatic sales import.', '/integrations'],
                ['Import', 'Use browser fallback, P&L and COGS workbook data.', '/auto-import'],
                ['Sales', 'Check platform orders, returns, tax and net revenue.', '/sales'],
                ['Inventory', 'Apply COGS and keep stock movement visible.', '/inventory'],
                ['Validate', 'Fix unmapped SKUs and open calculation issues.', '/validation'],
                ['Adjust', 'Add controlled monthly additions or deductions.', '/adjustments'],
                ['Review', 'Read the graphical MIS before final lock.', '/mis/preview'],
                ['Export', 'Download the final Excel report only when needed.', '/mis/export'],
            ] as $index => $step) {
                echo '<a class="step-card" href="' . e(route_url($step[2], $runParams)) . '"><span>' . ($index + 1) . '</span><strong>' . e($step[0]) . '</strong><small>' . e($step[1]) . '</small></a>';
            }
            echo '</section>';

            if ($latest) {
                echo '<section class="panel visual-panel dashboard-visual"><div class="section-title"><div><p class="eyebrow">Run ' . e($latest['month']) . '</p><h2>Visual business snapshot</h2><p class="muted">The same backend MIS data, summarized for quick review before opening the full report.</p></div><div class="actions"><a class="ghost" href="' . e(route_url('/reports', $runParams)) . '">Open Reports</a><a class="button" href="' . e(route_url('/mis/preview', $runParams)) . '">Review MIS</a></div></div>';
                echo '<div class="chart-grid">';
                $this->donutChart('Expense mix', $this->chartSegments($expenses, 'pnl_category', 'amount', true));
                $this->barChart('Platform net sales', $platforms, 'platform', 'net', 'gross_profit', 'Gross profit');
                $this->barChart('Category gross profit', $categories, 'category', 'gross_profit', 'revenue', 'Revenue');
                echo '</div></section>';

                echo '<section class="dashboard-grid">';
                echo '<div class="panel"><div class="section-title"><div><h2>Monthly close status</h2><p class="muted">Use this list as the operating flow for the month.</p></div></div><div class="status-list">';
                foreach ([
                    ['Sales imported', $stats['rows'] > 0, $stats['rows'] . ' rows available'],
                    ['P&L mapping ready', count($expenses) > 0, count($expenses) . ' mapped categories'],
                    ['COGS calculated', abs($grossMargin) > 0.00001 || abs($netSales) > 0.00001, 'Margin output is visible'],
                    ['Validation clear', $stats['issues'] === 0, $stats['issues'] . ' open issue(s)'],
                    ['Run finalized', (int) ($latest['locked'] ?? 0) === 1, e($latest['status'] ?? 'draft')],
                ] as [$label, $done, $note]) {
                    echo '<div class="status-row ' . ($done ? 'done' : 'pending') . '"><b>' . ($done ? 'Done' : 'Check') . '</b><span><strong>' . e($label) . '</strong><small>' . e($note) . '</small></span></div>';
                }
                echo '</div></div>';

                echo '<div class="panel"><div class="section-title"><div><h2>Top P&L mappings</h2><p class="muted">From Profit and loss column C.</p></div></div>';
                if (!$expenses) {
                    echo '<p class="muted">No mapped expenses yet.</p>';
                } else {
                    echo '<div class="map-list compact-map">';
                    foreach (array_slice($expenses, 0, 6) as $row) {
                        echo '<div><span>' . e($row['pnl_category']) . '<small>' . e($row['rows_count']) . ' lines</small></span><b>₹' . money($row['amount']) . '</b></div>';
                    }
                    echo '</div>';
                }
                echo '</div></section>';
            }

            echo '<section class="panel"><div class="section-title"><div><h2>Monthly runs</h2><p class="muted">Open any month, continue imports, review reports, or export the workbook.</p></div><a class="ghost" href="' . e(route_url('/masters')) . '">Edit masters</a></div>';
            if (!$runs) {
                echo '<p class="muted">No runs yet. Create a month to start importing data.</p>';
            } else {
                echo '<div class="table-wrap"><table><thead><tr><th>Month</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
                foreach ($runs as $run) {
                    echo '<tr><td>' . e($run['month']) . '</td><td><span class="pill">' . e($run['status']) . '</span></td><td>' . e($run['created_at']) . '</td><td class="actions">';
                    echo '<a href="' . e(route_url('/imports/new', ['month' => $run['month'], 'run_id' => $run['id']])) . '">Import</a>';
                    echo '<a href="' . e(route_url('/auto-import', ['run_id' => $run['id']])) . '">Auto Import</a>';
                    echo '<a href="' . e(route_url('/sales', ['run_id' => $run['id']])) . '">Sales</a>';
                    echo '<a href="' . e(route_url('/inventory', ['run_id' => $run['id']])) . '">Inventory</a>';
                    echo '<a href="' . e(route_url('/validation', ['run_id' => $run['id']])) . '">Validate</a>';
                    echo '<a href="' . e(route_url('/adjustments', ['run_id' => $run['id']])) . '">Adjust</a>';
                    echo '<a href="' . e(route_url('/mis/preview', ['run_id' => $run['id']])) . '">Preview</a>';
                    echo '<a href="' . e(route_url('/mis/export', ['run_id' => $run['id']])) . '">Export</a>';
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</section>';
        });
    }

    private function autoImport(string $view = 'sources'): void
    {
        $run = $this->getRun();
        $service = new AutoImportService($this->db, $this->root);
        $apiService = new ApiIntegrationService($this->db, $this->root);
        $jobId = (int) ($_GET['job_id'] ?? 0);
        if ($jobId) {
            $service->syncEventFile($jobId);
        }
        $sources = $service->portalSources();
        $apiConnections = $apiService->connections();
        $jobs = $this->db->fetchAll('SELECT * FROM auto_import_jobs WHERE run_id = ? ORDER BY id DESC LIMIT 12', [$run['id']]);
        $schedule = $this->db->fetch('SELECT * FROM auto_import_schedules WHERE run_id = ?', [$run['id']]) ?: [
            'enabled' => 0,
            'frequency' => 'daily',
            'run_time' => '09:00',
            'import_mode' => 'replace',
            'sources_json' => json_encode(array_keys($sources)),
            'next_run_at' => null,
        ];
        $selectedJob = $jobId ? $this->db->fetch('SELECT * FROM auto_import_jobs WHERE id = ? AND run_id = ?', [$jobId, $run['id']]) : ($jobs[0] ?? null);
        $events = $selectedJob ? $this->db->fetchAll('SELECT * FROM auto_import_events WHERE job_id = ? ORDER BY id DESC LIMIT 120', [$selectedJob['id']]) : [];
        $scheduleSources = json_decode((string) ($schedule['sources_json'] ?? '[]'), true) ?: array_keys($sources);
        $connections = $this->portalConnectionMap();
        $readiness = $this->runReadiness($run);
        $latestEvents = $selectedJob ? $this->friendlyJobEvents($events) : [];

        $this->render('Auto Import', function () use ($run, $sources, $jobs, $schedule, $scheduleSources, $selectedJob, $events, $connections, $readiness, $latestEvents, $view, $apiConnections): void {
            echo '<section class="panel auto-panel"><div class="section-title"><div><p class="eyebrow">Run ' . e($run['month']) . '</p><h1>Guided import</h1><p class="muted">Use API Connect first for fully authenticated sales import. Browser portal capture stays available as fallback when a marketplace API is not approved.</p></div><div class="actions">';
            echo '<a class="button" href="' . e(route_url('/integrations', ['run_id' => $run['id']])) . '">API Connect</a>';
            echo '<a class="button" href="' . e(route_url('/portal/connect-all', ['run_id' => $run['id']])) . '">Connect all portals</a>';
            echo '<a class="ghost" href="' . e(route_url('/imports/new', ['run_id' => $run['id']])) . '">Manual Import</a>';
            echo '<a class="ghost" href="' . e(route_url('/mis/preview', ['run_id' => $run['id']])) . '">Preview MIS</a></div></div>';
            $this->subNav([
                ['Sources', '/auto-import', ['run_id' => $run['id']]],
                ['API Connect', '/integrations', ['run_id' => $run['id']]],
                ['Activity', '/imports/activity', ['run_id' => $run['id']]],
                ['Manual Upload', '/imports/manual', ['run_id' => $run['id']]],
            ]);
            $this->readinessPanel($readiness, ['run_id' => $run['id']]);
            echo '<div class="guided-flow"><div><b>1</b><span><strong>API first</strong><small>Connect approved APIs or a secured sales export endpoint.</small></span></div><div><b>2</b><span><strong>Fallback capture</strong><small>Use browser portal capture only where APIs are unavailable.</small></span></div><div><b>3</b><span><strong>Review</strong><small>Imported rows update Sales, Inventory, and MIS Preview automatically.</small></span></div></div>';
            $apiReady = count(array_filter($apiConnections, fn(array $connection): bool => in_array((string) ($connection['status'] ?? ''), ['configured', 'connected'], true) && (int) ($connection['enabled'] ?? 0) === 1));
            echo '<div class="automation-strip api-first-strip"><div><span>Fully authenticated path</span><strong>' . e($apiReady) . ' API connector' . ($apiReady === 1 ? '' : 's') . ' ready</strong><small>API imports can run without portal login after tokens/endpoints are configured.</small></div><a class="ghost" href="' . e(route_url('/integrations', ['run_id' => $run['id']])) . '">Manage API Connect</a></div>';
            $connectedCount = count(array_filter($sources, fn(array $source, string $sourceType): bool => (($connections[$sourceType]['status'] ?? '') === 'connected'), ARRAY_FILTER_USE_BOTH));
            echo '<div class="automation-strip"><div><span>Portal automation</span><strong>' . e($connectedCount) . '/' . e(count($sources)) . ' connected</strong><small>Login and OTP stay manual. Report capture and import run inside app storage.</small></div><a class="ghost" href="' . e(route_url('/portal/connect-all', ['run_id' => $run['id']])) . '">Check sessions</a></div>';

            if ($view === 'activity') {
                echo '</section>';
                $this->autoImportActivityView($run, $jobs, $selectedJob, $events, $latestEvents);
                return;
            }

            if ((int) ($run['locked'] ?? 0) === 1) {
                echo '<p class="locked-note">This run is finalized and locked. Unlock it before running browser auto-import.</p>';
            } else {
                echo '<form class="auto-run-form" method="post" action="' . e(route_url('/auto-import/run')) . '">';
                echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '">';
                echo '<div class="auto-source-grid">';
                foreach ($sources as $sourceType => $source) {
                    $connection = $connections[$sourceType] ?? ['status' => 'not_connected', 'message' => 'Not connected'];
                    $connected = ($connection['status'] ?? '') === 'connected';
                    $sourceStatus = $readiness['sources'][$sourceType] ?? ['done' => false, 'note' => 'Not imported'];
                    $status = (string) ($connection['status'] ?? 'not_connected');
                    $statusText = $this->portalStatusLabel($status);
                    $message = trim((string) ($connection['message'] ?? ''));
                    echo '<div class="source-check">';
                    echo '<div class="source-card-top"><label class="source-pick"><input type="checkbox" name="sources[]" value="' . e($sourceType) . '"' . ($connected ? ' checked' : '') . '><span><strong>' . e($source['label']) . '</strong><small>' . e(parse_url($source['url'], PHP_URL_HOST) ?: $source['url']) . '</small></span></label>';
                    echo '<span class="connection-badge ' . e($this->portalStatusClass($status)) . '">' . e($statusText) . '</span></div>';
                    if (!empty($source['report_hint'])) {
                        echo '<small class="source-hint">' . e($source['report_hint']) . '</small>';
                    }
                    if ($message !== '') {
                        echo '<small class="source-message">' . e($message) . '</small>';
                    }
                    echo '<div class="source-next-action"><span>Next action</span><strong>' . e($this->portalNextAction($status, !empty($sourceStatus['done']))) . '</strong></div>';
                    echo '<div class="source-progress ' . (!empty($sourceStatus['done']) ? 'done' : 'pending') . '"><b>' . (!empty($sourceStatus['done']) ? 'Imported' : 'Missing') . '</b><span>' . e($sourceStatus['note'] ?? '') . '</span></div>';
                    echo '<div class="source-actions">';
                    echo '<a class="ghost connect-link" href="' . e(route_url('/portal/connect', ['run_id' => $run['id'], 'source' => $sourceType])) . '" target="_blank" rel="noreferrer">' . ($connected ? 'Open / refresh login' : 'Open / login') . '</a>';
                    if (!$connected) {
                        echo '<a class="ghost connect-link" href="' . e(route_url('/portal/connect-all', ['run_id' => $run['id']])) . '">Continue after login</a>';
                    }
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';
                echo '<div class="auto-controls"><label>Import mode<select name="import_mode"><option value="replace">Replace each selected source</option><option value="append">Append captured rows</option></select></label><button>Fetch selected reports</button></div>';
                echo '</form>';
            }
            echo '</section>';

            echo '<section class="grid two">';
            echo '<div class="panel"><div class="section-title"><div><h2>Schedule</h2><p class="muted">Optional background import; leave off for manual control.</p></div></div><form class="schedule-form" method="post" action="' . e(route_url('/auto-import/schedule')) . '">';
            echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '">';
            echo '<label class="toggle-row"><input type="checkbox" name="enabled" value="1"' . ((int) ($schedule['enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable scheduled auto-import</label>';
            echo '<div class="auto-controls"><label>Frequency<select name="frequency"><option value="daily"' . (($schedule['frequency'] ?? '') === 'daily' ? ' selected' : '') . '>Daily</option><option value="monthly"' . (($schedule['frequency'] ?? '') === 'monthly' ? ' selected' : '') . '>Monthly</option></select></label>';
            echo '<label>Time<input type="time" name="run_time" value="' . e($schedule['run_time'] ?? '09:00') . '"></label>';
            echo '<label>Mode<select name="import_mode"><option value="replace"' . (($schedule['import_mode'] ?? '') === 'replace' ? ' selected' : '') . '>Replace</option><option value="append"' . (($schedule['import_mode'] ?? '') === 'append' ? ' selected' : '') . '>Append</option></select></label></div>';
            echo '<div class="mini-checks">';
            foreach ($sources as $sourceType => $source) {
                $checked = in_array($sourceType, $scheduleSources, true) ? ' checked' : '';
                echo '<label><input type="checkbox" name="sources[]" value="' . e($sourceType) . '"' . $checked . '> ' . e($source['label']) . '</label>';
            }
            echo '</div><button>Save schedule</button>';
            if (!empty($schedule['next_run_at'])) {
                echo '<p class="muted">Next run: ' . e($schedule['next_run_at']) . '. Cron/launchd command: <code>/Applications/XAMPP/xamppfiles/bin/php ' . e($this->root) . '/bin/run_auto_import.php</code></p>';
            }
            echo '<p class="muted">To install the macOS background checker, run <code>' . e($this->root) . '/bin/install_launchd_schedule.sh</code>. It checks due jobs every 15 minutes and uses the schedule saved here.</p>';
            echo '</form></div>';

            echo '<div class="panel"><div class="section-title"><div><h2>Recent activity</h2><p class="muted">Simple status first; details are below only when needed.</p></div></div>';
            if ($selectedJob) {
                echo '<div class="job-summary ' . e((string) $selectedJob['status']) . '"><strong>' . e($this->friendlyJobStatus((string) $selectedJob['status'])) . '</strong><span>Job #' . e($selectedJob['id']) . ' · ' . e($selectedJob['created_at']) . '</span></div>';
                if ($latestEvents) {
                    echo '<div class="friendly-events">';
                    foreach ($latestEvents as $event) {
                        echo '<div class="' . e($event['class']) . '"><b>' . e($event['title']) . '</b><span>' . e($event['message']) . '</span></div>';
                    }
                    echo '</div>';
                }
            }
            if (!$jobs) {
                echo '<p class="muted">No browser auto-import jobs yet.</p>';
            } else {
                echo '<div class="job-list">';
                foreach ($jobs as $job) {
                    $active = $selectedJob && (int) $selectedJob['id'] === (int) $job['id'] ? ' active' : '';
                    echo '<a class="job-card' . $active . '" href="' . e(route_url('/auto-import', ['run_id' => $run['id'], 'job_id' => $job['id']])) . '"><strong>#' . e($job['id']) . ' ' . e($job['status']) . '</strong><span>' . e($job['created_at']) . '</span></a>';
                }
                echo '</div>';
            }
            echo '</div></section>';

            echo '<details class="panel review-details" id="auto-job-log"' . ($selectedJob ? ' data-job-id="' . e($selectedJob['id']) . '"' : '') . '><summary>Technical job log</summary><div class="review-body"><div class="section-title"><div><h2>Job log</h2>';
            if ($selectedJob) {
                echo '<p class="muted">Job #' . e($selectedJob['id']) . ' status: <strong>' . e($selectedJob['status']) . '</strong></p>';
            } else {
                echo '<p class="muted">Start a browser auto-import job to see live events.</p>';
            }
            echo '</div>';
            if ($selectedJob && in_array((string) $selectedJob['status'], ['queued', 'running', 'importing'], true)) {
                echo '<form method="post" action="' . e(route_url('/auto-import/stop')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="job_id" value="' . e($selectedJob['id']) . '"><button class="ghost">Stop job</button></form>';
            }
            echo '</div><div class="issue-list auto-events">';
            if (!$events) {
                echo '<p class="muted">No events yet.</p>';
            } else {
                foreach ($events as $event) {
                    echo $this->autoImportEventHtml($event);
                }
            }
            echo '</div></div></details>';
        });
    }

    private function autoImportStatus(): void
    {
        $jobId = (int) ($_GET['job_id'] ?? 0);
        $service = new AutoImportService($this->db, $this->root);
        if ($jobId) {
            $service->syncEventFile($jobId);
        }
        $job = $this->db->fetch('SELECT * FROM auto_import_jobs WHERE id = ?', [$jobId]);
        $events = $job ? $this->db->fetchAll('SELECT * FROM auto_import_events WHERE job_id = ? ORDER BY id DESC LIMIT 120', [$jobId]) : [];
        header('Content-Type: application/json');
        echo json_encode([
            'job' => $job,
            'events_html' => implode('', array_map(fn($event) => $this->autoImportEventHtml($event), $events)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function integrations(): void
    {
        $run = $this->getRun();
        $service = new ApiIntegrationService($this->db, $this->root);
        $connectors = $service->connectors();
        $connections = $service->connections();
        $selectedProvider = (string) ($_GET['provider'] ?? 'website_api');
        if (!isset($connectors[$selectedProvider])) {
            $selectedProvider = array_key_first($connectors) ?: 'website_api';
        }

        $this->render('API Connect', function () use ($run, $service, $connectors, $connections, $selectedProvider): void {
            $readyCount = count(array_filter($connections, fn(array $connection): bool => (int) ($connection['enabled'] ?? 0) === 1 && in_array((string) ($connection['status'] ?? ''), ['configured', 'connected'], true)));
            echo '<section class="panel integration-hero"><div class="section-title"><div><p class="eyebrow">Run ' . e($run['month']) . '</p><h1>Connect sales APIs</h1><p class="muted">Pick a source, paste its endpoint and token once, then import sales without portal login. Advanced fields stay hidden unless the provider needs them.</p></div><div class="actions"><a class="button" href="#api-setup">Connect API</a><a class="ghost" href="' . e(route_url('/auto-import', ['run_id' => $run['id']])) . '">Browser fallback</a><a class="ghost" href="' . e(route_url('/imports/manual', ['run_id' => $run['id']])) . '">Manual upload</a></div></div>';
            $this->subNav([
                ['Sources', '/auto-import', ['run_id' => $run['id']]],
                ['API Connect', '/integrations', ['run_id' => $run['id']]],
                ['Activity', '/imports/activity', ['run_id' => $run['id']]],
                ['Manual Upload', '/imports/manual', ['run_id' => $run['id']]],
            ]);
            echo '<div class="stats compact"><div><span>API ready</span><strong>' . e($readyCount) . '</strong></div><div><span>Connectors</span><strong>' . e(count($connectors)) . '</strong></div><div><span>Import mode</span><strong>API first</strong></div><div><span>Fallback</span><strong>Browser</strong></div></div>';
            echo '<div class="api-flow"><div><b>1</b><span><strong>Choose source</strong><small>Select website, Shopify, WooCommerce, Amazon, or Zoho P&L.</small></span></div><div><b>2</b><span><strong>Paste access</strong><small>Add endpoint and token. Secrets are encrypted before saving.</small></span></div><div><b>3</b><span><strong>Import</strong><small>Click Test & Import; the data flows into Sales and MIS.</small></span></div></div>';
            echo '</section>';

            echo '<section class="api-workbench">';
            echo '<div class="api-provider-rail"><div class="rail-title"><p class="eyebrow">Available connectors</p><h2>Pick one source</h2><p class="muted">Only the selected connector opens for setup, so the page stays simple.</p></div>';
            foreach ($connectors as $provider => $connector) {
                $connection = $connections[$provider] ?? null;
                $ready = $connection && (int) ($connection['enabled'] ?? 0) === 1 && in_array((string) ($connection['status'] ?? ''), ['configured', 'connected'], true);
                $status = $connection ? $service->connectionHealth($connection) : 'Not configured';
                $statusClass = $ready ? 'connected' : (($connection['status'] ?? '') === 'failed' ? 'pending' : 'checking');
                $mode = (string) $connector['mode'];
                $isSelected = $provider === $selectedProvider;
                echo '<article class="integration-card provider-card' . ($isSelected ? ' selected' : '') . '">';
                echo '<div class="integration-top"><div><span class="api-logo">' . e(strtoupper(substr((string) $provider, 0, 2))) . '</span><h2>' . e($connector['label']) . '</h2><p>' . e($connector['summary']) . '</p></div><span class="connection-badge ' . e($statusClass) . '">' . e($status) . '</span></div>';
                echo '<div class="integration-meta"><span>' . e(str_replace('_', ' ', $mode)) . '</span><span>Imports as ' . e($connector['source_type']) . '</span></div>';
                if ($connection && !empty($connection['last_message'])) {
                    echo '<p class="source-message">' . e($connection['last_message']) . '</p>';
                }
                echo '<div class="integration-actions compact-actions">';
                echo '<a class="' . ($isSelected ? 'button' : 'ghost') . '" href="' . e(route_url('/integrations', ['run_id' => $run['id'], 'provider' => $provider])) . '#api-setup">' . ($ready ? 'Manage' : 'Connect') . '</a>';
                if ($ready) {
                    echo '<form method="post" action="' . e(route_url('/integrations/import')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="provider" value="' . e($provider) . '"><select name="import_mode"><option value="replace">Replace source rows</option><option value="append">Append rows</option></select><button>Test & Import</button></form>';
                } else {
                    echo '<small class="connect-note">Save endpoint and token first.</small>';
                }
                if ($connection) {
                    echo '<form method="post" action="' . e(route_url('/integrations/disconnect')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="provider" value="' . e($provider) . '"><button class="ghost">Disconnect</button></form>';
                }
                echo '</div></article>';
            }
            echo '</div>';

            $connector = $connectors[$selectedProvider];
            $connection = $connections[$selectedProvider] ?? null;
            $authOptions = (array) $connector['auth'];
            $authType = (string) ($connection['auth_type'] ?? $authOptions[0]);
            $extra = $connection ? (json_decode((string) ($connection['extra_json'] ?? '{}'), true) ?: []) : [];
            $selectedReady = $connection && (int) ($connection['enabled'] ?? 0) === 1 && in_array((string) ($connection['status'] ?? ''), ['configured', 'connected'], true);
            echo '<section class="panel api-setup-panel" id="api-setup"><div class="section-title"><div><p class="eyebrow">Connection setup</p><h2>' . e($connector['label']) . '</h2><p class="muted">' . e($connector['setup']) . '</p></div><span class="connection-badge ' . ($selectedReady ? 'connected' : 'checking') . '">' . ($selectedReady ? 'Ready to import' : 'Needs setup') . '</span></div>';
            echo '<div class="api-setup-guide"><div><strong>1. Endpoint</strong><span>Paste the report or sales export URL.</span></div><div><strong>2. Access</strong><span>Select token, API key, basic auth, or no auth.</span></div><div><strong>3. Import</strong><span>Save once, then use Test & Import from the connector card.</span></div></div>';
            echo '<form class="integration-form api-connect-form" method="post" action="' . e(route_url('/integrations/save')) . '">';
            echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="provider" value="' . e($selectedProvider) . '">';
            echo '<label class="span-2">API endpoint URL<input name="base_url" value="' . e($connection['base_url'] ?? '') . '" placeholder="https://example.com/export/sales?month={month}" required><small>Use {month} in the URL or keep Month parameter below.</small></label>';
            echo '<label>Access method<select name="auth_type">';
            foreach ($authOptions as $option) {
                $label = match ((string) $option) {
                    'bearer', 'oauth' => 'Token / OAuth',
                    'api_key' => 'API key header',
                    'basic' => 'Username + secret',
                    'none' => 'No auth',
                    default => strtoupper(str_replace('_', ' ', (string) $option)),
                };
                echo '<option value="' . e($option) . '"' . ($authType === $option ? ' selected' : '') . '>' . e($label) . '</option>';
            }
            echo '</select><small>Choose the method your provider gives you.</small></label>';
            echo '<label>Token, API key, or secret<input type="password" name="access_token" autocomplete="new-password" placeholder="' . ($connection && (!empty($connection['access_token_enc']) || !empty($connection['api_key_enc'])) ? 'Saved. Leave blank to keep.' : 'Paste credential here') . '"><small>One field works for token, API key, and Basic password.</small></label>';
            echo '<details class="advanced-api-settings span-2"><summary>Advanced setup</summary><div class="advanced-api-grid">';
            echo '<label>Display name<input name="label" value="' . e($connection['label'] ?? $connector['label']) . '"></label>';
            echo '<label>Import source<select name="source_type">';
            foreach (['website_sales' => 'Website sales', 'website_mcf_returns' => 'Website returns', 'amazon_b2c' => 'Amazon B2C', 'amazon_b2b' => 'Amazon B2B', 'blinkit' => 'Blinkit', 'flipkart' => 'Flipkart', 'sample_workbook' => 'P&L / workbook mapping'] as $sourceType => $label) {
                $selected = (($connection['source_type'] ?? $connector['source_type']) === $sourceType) ? ' selected' : '';
                echo '<option value="' . e($sourceType) . '"' . $selected . '>' . e($label) . '</option>';
            }
            echo '</select></label>';
            echo '<label>API key / secret<input type="password" name="api_key" autocomplete="new-password" placeholder="' . ($connection && !empty($connection['api_key_enc']) ? 'Saved. Leave blank to keep.' : 'API key or Basic secret') . '"></label>';
            echo '<label>Refresh token<input type="password" name="refresh_token" autocomplete="new-password" placeholder="' . ($connection && !empty($connection['refresh_token_enc']) ? 'Saved. Leave blank to keep.' : 'Optional') . '"></label>';
            echo '<label>Header name<input name="header_name" value="' . e($extra['header_name'] ?? 'X-API-Key') . '"></label>';
            echo '<label>Basic username<input name="username" value="' . e($extra['username'] ?? '') . '"></label>';
            echo '<label>Month parameter<input name="date_param" value="' . e($extra['date_param'] ?? 'month') . '" placeholder="month"></label>';
            echo '<label>Notes<input name="notes" value="' . e($extra['notes'] ?? '') . '" placeholder="Approval notes, report type, account id"></label>';
            echo '</div></details>';
            echo '<div class="api-form-actions span-2"><button>Save API connection</button><a class="ghost" href="' . e(route_url('/auto-import', ['run_id' => $run['id']])) . '">Use browser fallback</a></div>';
            echo '</form>';
            echo '<div class="api-result-map"><div><span>Accepted formats</span><strong>CSV / JSON</strong><small>Rows can be an array or inside rows, items, or data.</small></div><div><span>After import</span><strong>Sales + MIS</strong><small>Rows are loaded into Sales and calculations refresh automatically.</small></div><div><span>Storage</span><strong>Inside app</strong><small>Temporary API files are removed after import.</small></div></div>';
            echo '</section></section>';
        });
    }

    private function portalConnect(): void
    {
        $run = $this->getRun();
        $sourceType = (string) ($_GET['source'] ?? '');
        $sources = (new AutoImportService($this->db, $this->root))->portalSources();
        if (!isset($sources[$sourceType])) {
            throw new RuntimeException('Unknown portal source.');
        }

        $source = $sources[$sourceType];
        $profileDir = $this->root . '/storage/browser-visible-profile';
        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0777, true);
        }
        @chmod($profileDir, 0777);
        $this->savePortalConnection($sourceType, 'checking', null, '', 'Opening the linked app Chrome profile and checking the saved session.');
        $opened = $this->openPortalInAutomationChrome($profileDir, (string) $source['url']);
        if (!$opened) {
            $this->savePortalConnection($sourceType, 'browser_closed', null, '', 'Could not open the linked Chrome profile. Check Chrome is installed and try again.');
        }

        $this->render('Connect ' . $source['label'], function () use ($run, $sourceType, $source): void {
            echo '<section class="panel connect-panel" id="portal-connect" data-source="' . e($sourceType) . '" data-run-id="' . e($run['id']) . '" data-status-url="' . e(route_url('/portal/status', ['source' => $sourceType])) . '"><div class="section-title"><div><p class="eyebrow">Connect portal</p><h1>' . e($source['label']) . '</h1><p class="muted">The seller portal has been opened in the same Chrome profile used by MIS auto-import. Complete login or OTP there; this page will keep checking and mark it connected when the session is valid.</p></div><div class="actions">';
            echo '<a class="ghost" href="' . e(route_url('/auto-import', ['run_id' => $run['id']])) . '">Back to Auto Import</a>';
            echo '<a class="ghost" href="' . e(route_url('/portal/connect-all', ['run_id' => $run['id']])) . '">Connect all</a>';
            echo '<a class="ghost" href="' . e(route_url('/portal/connect', ['run_id' => $run['id'], 'source' => $sourceType])) . '">Reopen app Chrome</a></div></div>';
            echo '<div class="connect-assist"><div><b>1</b><span><strong>Finish login or OTP</strong><small>Use the Chrome portal window that opened from this app.</small></span></div><div><b>2</b><span><strong>Wait for Connected</strong><small>This page checks the saved browser profile automatically.</small></span></div><div><b>3</b><span><strong>Fetch report</strong><small>Once connected, the app can capture downloads into app storage.</small></span></div></div>';
            echo '<div class="connect-status"><span class="connection-badge pending" data-connect-badge>Checking</span><strong data-connect-title>Checking saved Chrome session...</strong><p class="muted" data-connect-message>Login in the Chrome window if the portal asks for it. This page will update automatically.</p></div>';
            echo '<form class="auto-run-form" data-connect-fetch-form method="post" action="' . e(route_url('/auto-import/run')) . '">';
            echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '">';
            echo '<input type="hidden" name="sources[]" value="' . e($sourceType) . '">';
            echo '<div class="auto-controls"><label>Import mode<select name="import_mode"><option value="replace">Replace this source</option><option value="append">Append captured rows</option></select></label><button data-connect-fetch disabled>Fetch ' . e($source['label']) . '</button></div>';
            echo '</form>';
            echo '<form class="inline-actions" method="post" action="' . e(route_url('/portal/verify')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="source" value="' . e($sourceType) . '"><button class="ghost" type="submit">Verify connection now</button></form>';
            echo '<p class="muted">Once the badge says Connected, future fetches will reuse the same browser session until the portal expires the login.</p>';
            echo '</section>';
        });
    }

    private function portalConnectAll(): void
    {
        $run = $this->getRun();
        $service = new AutoImportService($this->db, $this->root);
        $sources = $service->portalSources();
        $profileDir = $this->root . '/storage/browser-visible-profile';
        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0777, true);
        }
        @chmod($profileDir, 0777);

        foreach ($sources as $sourceType => $source) {
            $this->savePortalConnection($sourceType, 'checking', null, '', 'Opening the linked app Chrome profile and checking the saved session.');
            if (!$this->openPortalInAutomationChrome($profileDir, (string) $source['url'])) {
                $this->savePortalConnection($sourceType, 'browser_closed', null, '', 'Could not open the linked Chrome profile. Open the portal again from this app.');
            }
            usleep(200000);
        }
        $connections = $this->portalConnectionMap();

        $this->render('Connect Portals', function () use ($run, $sources, $connections): void {
            echo '<section class="panel connect-panel portal-connect-board" id="portal-connect-all"><div class="section-title"><div><p class="eyebrow">Automation setup</p><h1>Connect all portals</h1><p class="muted">Chrome has opened each seller portal using the app browser profile. Complete login or OTP where needed; connected portals are selected for fetch automatically.</p></div><div class="actions"><a class="ghost" href="' . e(route_url('/auto-import', ['run_id' => $run['id']])) . '">Back to Import</a><button class="ghost" type="button" data-verify-all>Verify all again</button></div></div>';
            echo '<div class="connect-assist"><div><b>1</b><span><strong>Login once</strong><small>Complete password, OTP, or verification only inside the opened Chrome portal windows.</small></span></div><div><b>2</b><span><strong>Session saved</strong><small>The app stores only the browser session profile, not passwords.</small></span></div><div><b>3</b><span><strong>Auto fetch</strong><small>Connected sources are selected below for report capture.</small></span></div></div>';
            echo '<form class="auto-run-form" data-connect-all-fetch method="post" action="' . e(route_url('/auto-import/run')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><div class="portal-status-grid">';
            foreach ($sources as $sourceType => $source) {
                $connection = $connections[$sourceType] ?? ['status' => 'checking', 'message' => 'Checking saved browser session.'];
                $status = (string) ($connection['status'] ?? 'checking');
                $connected = $status === 'connected';
                echo '<article class="portal-status-card" data-portal-card data-source="' . e($sourceType) . '" data-status-url="' . e(route_url('/portal/status', ['source' => $sourceType])) . '">';
                echo '<label class="source-pick"><input type="checkbox" name="sources[]" value="' . e($sourceType) . '"' . ($connected ? ' checked' : '') . ' data-source-checkbox><span><strong>' . e($source['label']) . '</strong><small>' . e(parse_url($source['url'], PHP_URL_HOST) ?: $source['url']) . '</small></span></label>';
                echo '<span class="connection-badge ' . e($this->portalStatusClass($status)) . '" data-status-badge>' . e($this->portalStatusLabel($status)) . '</span>';
                echo '<p class="muted" data-status-message>' . e((string) ($connection['message'] ?? 'Checking saved browser session.')) . '</p>';
                echo '<div class="source-actions"><a class="ghost connect-link" href="' . e(route_url('/portal/connect', ['run_id' => $run['id'], 'source' => $sourceType])) . '" target="_blank" rel="noreferrer">Open portal</a></div>';
                echo '</article>';
            }
            echo '</div><div class="auto-controls"><label>Import mode<select name="import_mode"><option value="replace">Replace each selected source</option><option value="append">Append captured rows</option></select></label><button data-fetch-connected disabled>Fetch connected portals</button></div></form>';
            echo '<p class="muted">If a portal still says login or OTP required, complete it in Chrome and leave this page open. The app will keep checking and enable fetch when the session is ready.</p>';
            echo '</section>';
        });
    }

    private function portalStatus(): void
    {
        $sourceType = (string) ($_GET['source'] ?? '');
        $verify = (int) ($_GET['verify'] ?? 0) === 1;
        $sources = (new AutoImportService($this->db, $this->root))->portalSources();
        if (!isset($sources[$sourceType])) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'not_found', 'message' => 'Unknown portal source.']);
            return;
        }

        if ($verify) {
            $result = $this->checkPortalConnection((string) $sources[$sourceType]['url'], true);
            $status = $this->portalStatusFromResult($result);
            $this->savePortalConnection(
                $sourceType,
                $status,
                $result['url'] ?? null,
                (string) ($result['title'] ?? ''),
                (string) ($result['message'] ?? ($status === 'connected' ? 'Connected.' : 'Login required.'))
            );
        }

        $connection = $this->db->fetch('SELECT * FROM portal_connections WHERE source_type = ?', [$sourceType])
            ?: ['source_type' => $sourceType, 'status' => 'not_connected', 'message' => 'Not connected yet.'];
        $connection['status_label'] = $this->portalStatusLabel((string) ($connection['status'] ?? 'not_connected'));
        $connection['status_class'] = $this->portalStatusClass((string) ($connection['status'] ?? 'not_connected'));
        header('Content-Type: application/json');
        echo json_encode($connection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function verifyPortalConnection(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $sourceType = (string) ($_POST['source'] ?? '');
        $sources = (new AutoImportService($this->db, $this->root))->portalSources();
        if (!isset($sources[$sourceType])) {
            throw new RuntimeException('Unknown portal source.');
        }

        $result = $this->checkPortalConnection((string) $sources[$sourceType]['url']);
        $status = $this->portalStatusFromResult($result);
        $message = !empty($result['connected']) ? 'Connected through saved Chrome session.' : 'Login required in Chrome.';
        $this->savePortalConnection(
            $sourceType,
            $status,
            $result['url'] ?? null,
            (string) ($result['title'] ?? ''),
            (string) ($result['message'] ?? $message)
        );
        $this->redirect('/auto-import', ['run_id' => $runId]);
    }

    private function openPortalInAutomationChrome(string $profileDir, string $url): bool
    {
        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0777, true);
        }
        @chmod($profileDir, 0777);
        $script = $this->root . '/bin/open_portal_window.js';
        if (is_file($script)) {
            $command = [
                $this->nodeBinary(),
                $script,
                '--profile',
                $profileDir,
                '--url',
                $url,
                '--port',
                (string) $this->automationDebugPort(),
            ];
            $cmd = implode(' ', array_map('escapeshellarg', $command)) . ' >/dev/null 2>&1 &';
            exec($cmd);
            return true;
        }
        $chrome = $this->chromeBinary();
        if ($chrome === null) {
            return false;
        }
        $args = [
            $chrome,
            '--remote-debugging-port=' . $this->automationDebugPort(),
            '--user-data-dir=' . $profileDir,
            '--no-first-run',
            '--no-default-browser-check',
            '--new-window',
            '--window-position=60,80',
            '--window-size=1440,920',
            $url,
        ];
        shell_exec(implode(' ', array_map('escapeshellarg', $args)) . ' >/dev/null 2>&1 &');
        return true;
    }

    private function portalStatusFromResult(array $result): string
    {
        if (!empty($result['connected'])) {
            return 'connected';
        }
        $message = strtolower((string) ($result['message'] ?? ''));
        $state = is_array($result['state'] ?? null) ? $result['state'] : [];
        if ((int) ($state['otpFields'] ?? 0) > 0 || str_contains($message, 'otp') || str_contains($message, 'verification')) {
            return 'otp_required';
        }
        if (str_contains($message, 'not open') || str_contains($message, 'browser is not open')) {
            return 'browser_closed';
        }
        return 'login_required';
    }

    private function portalStatusLabel(string $status): string
    {
        return match ($status) {
            'connected' => 'Connected',
            'otp_required' => 'OTP required',
            'login_required' => 'Login required',
            'browser_closed' => 'Open browser',
            'opened', 'checking' => 'Checking',
            default => 'Not connected',
        };
    }

    private function portalStatusClass(string $status): string
    {
        return match ($status) {
            'connected' => 'connected',
            'otp_required' => 'otp',
            'login_required', 'browser_closed' => 'pending',
            'opened', 'checking' => 'checking',
            default => 'pending',
        };
    }

    private function checkPortalConnection(string $url, bool $openIfMissing = false): array
    {
        $script = $this->root . '/bin/check_portal_connection.js';
        $profileDir = $this->root . '/storage/browser-visible-profile';
        $command = [$this->nodeBinary(), $script, '--profile', $profileDir, '--url', $url, '--port', (string) $this->automationDebugPort()];
        if ($openIfMissing) {
            $command[] = '--open-if-missing';
        }
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, $this->root);
        if (!is_resource($process)) {
            return ['connected' => false, 'message' => 'Connection checker could not start.'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $started = time();
        $output = '';
        $error = '';
        $timedOut = false;
        while (true) {
            $status = proc_get_status($process);
            $output .= stream_get_contents($pipes[1]) ?: '';
            $error .= stream_get_contents($pipes[2]) ?: '';
            if (!$status['running']) {
                break;
            }
            if (time() - $started >= 18) {
                $timedOut = true;
                proc_terminate($process);
                usleep(250000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(200000);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        if ($timedOut) {
            return ['connected' => false, 'message' => 'Connection check timed out. Finish login in Chrome, then verify again.'];
        }
        $json = json_decode(trim($output), true);
        if (!is_array($json)) {
            return ['connected' => false, 'message' => 'Connection check failed.', 'raw' => trim($output . "\n" . $error)];
        }
        return $json;
    }

    private function automationDebugPort(): int
    {
        $configured = (int) (getenv('MIS_CHROME_DEBUG_PORT') ?: 9333);
        return $configured > 0 ? $configured : 9333;
    }

    private function nodeBinary(): string
    {
        $configured = getenv('MIS_NODE_BIN') ?: '';
        if ($configured !== '' && is_file($configured) && is_executable($configured)) {
            return $configured;
        }
        foreach (['/opt/homebrew/bin/node', '/usr/local/bin/node', '/usr/bin/node'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return 'node';
    }

    private function chromeBinary(): ?string
    {
        $candidates = array_filter([
            getenv('MIS_CHROME_BIN') ?: null,
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function savePortalConnection(string $sourceType, string $status, ?string $url, string $title, string $message): void
    {
        $connectedAt = $status === 'connected' ? 'NOW()' : 'connected_at';
        $this->db->execute(
            "INSERT INTO portal_connections (source_type, status, connected_at, last_checked_at, last_url, last_title, message, created_at, updated_at)
             VALUES (?, ?, " . ($status === 'connected' ? 'NOW()' : 'NULL') . ", NOW(), ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), connected_at = {$connectedAt}, last_checked_at = NOW(), last_url = VALUES(last_url), last_title = VALUES(last_title), message = VALUES(message), updated_at = NOW()",
            [$sourceType, $status, $url, $title, $message]
        );
    }

    private function portalConnectionMap(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM portal_connections');
        $map = [];
        foreach ($rows as $row) {
            $map[$row['source_type']] = $row;
        }
        return $map;
    }

    private function autoImportEventHtml(array $event): string
    {
        $level = preg_replace('/[^a-z0-9_-]+/i', '', (string) ($event['level'] ?? 'info')) ?: 'info';
        $source = trim((string) ($event['source_type'] ?? ''));
        $label = $source !== '' ? strtoupper($source) . ' · ' : '';
        $context = json_decode((string) ($event['context_json'] ?? '{}'), true);
        $detail = '';
        if (is_array($context)) {
            $parts = [];
            foreach (['error', 'output', 'file', 'rows_imported', 'rows'] as $key) {
                if (isset($context[$key]) && $context[$key] !== '') {
                    $value = is_scalar($context[$key]) ? (string) $context[$key] : json_encode($context[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $parts[] = $key . ': ' . $value;
                }
            }
            if ($parts) {
                $detail = '<code>' . e(implode(' | ', $parts)) . '</code>';
            }
        }
        return '<div class="issue ' . e($level) . '"><strong>' . e($label . ($event['level'] ?? 'info')) . '</strong><span>' . e($event['message'] ?? '') . '</span>' . $detail . '<small>' . e($event['created_at'] ?? '') . '</small></div>';
    }

    private function autoImportActivityView(array $run, array $jobs, ?array $selectedJob, array $events, array $latestEvents): void
    {
        echo '<section class="grid two">';
        echo '<div class="panel"><div class="section-title"><div><h2>Recent activity</h2><p class="muted">Human-readable status first; technical logs remain available below.</p></div></div>';
        if ($selectedJob) {
            echo '<div class="job-summary ' . e((string) $selectedJob['status']) . '"><strong>' . e($this->friendlyJobStatus((string) $selectedJob['status'])) . '</strong><span>Job #' . e($selectedJob['id']) . ' · ' . e($selectedJob['created_at']) . '</span></div>';
            if ($latestEvents) {
                echo '<div class="friendly-events">';
                foreach ($latestEvents as $event) {
                    echo '<div class="' . e($event['class']) . '"><b>' . e($event['title']) . '</b><span>' . e($event['message']) . '</span></div>';
                }
                echo '</div>';
            }
        }
        if (!$jobs) {
            echo '<p class="muted">No browser auto-import jobs yet.</p>';
        } else {
            echo '<div class="job-list">';
            foreach ($jobs as $job) {
                $active = $selectedJob && (int) $selectedJob['id'] === (int) $job['id'] ? ' active' : '';
                echo '<a class="job-card' . $active . '" href="' . e(route_url('/imports/activity', ['run_id' => $run['id'], 'job_id' => $job['id']])) . '"><strong>#' . e($job['id']) . ' ' . e($job['status']) . '</strong><span>' . e($job['created_at']) . '</span></a>';
            }
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="panel"><div class="section-title"><div><h2>Automation health</h2><p class="muted">What the app can do without leaving local app storage.</p></div></div>';
        echo '<div class="quality-grid compact-quality">';
        foreach ([
            ['Browser profile', 'Saved locally', 'Portal sessions are reused after manual OTP/login.', 'ready'],
            ['Report capture', 'In app', 'Captured exports are imported from app storage.', 'ready'],
            ['Manual action', 'Login/OTP', 'Credentials and OTP stay user-controlled in Chrome.', 'warn'],
        ] as [$label, $value, $note, $class]) {
            echo '<div class="quality-card ' . e($class) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong><small>' . e($note) . '</small></div>';
        }
        echo '</div></div></section>';

        echo '<details class="panel review-details" open id="auto-job-log"' . ($selectedJob ? ' data-job-id="' . e($selectedJob['id']) . '"' : '') . '><summary>Technical job log</summary><div class="review-body"><div class="section-title"><div><h2>Job log</h2>';
        if ($selectedJob) {
            echo '<p class="muted">Job #' . e($selectedJob['id']) . ' status: <strong>' . e($selectedJob['status']) . '</strong></p>';
        } else {
            echo '<p class="muted">Start a browser auto-import job to see live events.</p>';
        }
        echo '</div>';
        if ($selectedJob && in_array((string) $selectedJob['status'], ['queued', 'running', 'importing'], true)) {
            echo '<form method="post" action="' . e(route_url('/auto-import/stop')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="job_id" value="' . e($selectedJob['id']) . '"><button class="ghost">Stop job</button></form>';
        }
        echo '</div><div class="issue-list auto-events">';
        if (!$events) {
            echo '<p class="muted">No events yet.</p>';
        } else {
            foreach ($events as $event) {
                echo $this->autoImportEventHtml($event);
            }
        }
        echo '</div></div></details>';
    }

    private function importsNew(string $view = 'manual'): void
    {
        $run = $this->getRun();
        $files = $this->db->fetchAll('SELECT * FROM source_files WHERE run_id = ? ORDER BY uploaded_at DESC', [$run['id']]);
        $issues = $this->db->fetchAll('SELECT * FROM validation_issues WHERE run_id = ? AND COALESCE(status, "open") = "open" ORDER BY FIELD(severity, "error", "warning", "notice"), id DESC LIMIT 80', [$run['id']]);
        $readiness = $this->runReadiness($run);
        $this->render('Import Data', function () use ($run, $files, $issues, $readiness, $view): void {
            echo '<section class="panel"><div class="section-title"><div><p class="eyebrow">Run ' . e($run['month']) . '</p><h1>Import source reports</h1><p class="muted">Upload reports directly or use guided portal import. The checklist shows what is still missing for this month.</p></div><div class="actions">';
            echo '<a class="ghost" href="' . e(route_url('/auto-import', ['run_id' => $run['id']])) . '">Auto Import</a>';
            echo '<a class="ghost" href="' . e(route_url('/validation', ['run_id' => $run['id']])) . '">Validate</a>';
            echo '<a class="ghost" href="' . e(route_url('/mis/preview', ['run_id' => $run['id']])) . '">Preview MIS</a></div></div>';
            $this->subNav([
                ['Sources', '/auto-import', ['run_id' => $run['id']]],
                ['Activity', '/imports/activity', ['run_id' => $run['id']]],
                ['Manual Upload', '/imports/manual', ['run_id' => $run['id']]],
            ]);
            $this->readinessPanel($readiness, ['run_id' => $run['id']]);
            echo '<div class="source-matrix">';
            foreach ($readiness['sources'] as $key => $status) {
                echo '<div class="' . (!empty($status['done']) ? 'done' : 'pending') . '"><b>' . (!empty($status['done']) ? 'Imported' : 'Missing') . '</b><span><strong>' . e($status['label']) . '</strong><small>' . e($status['note']) . '</small></span></div>';
            }
            echo '</div></section>';

            echo '<section class="panel"><div class="section-title"><div><h2>Manual upload</h2><p class="muted">Files are stored in app storage and imported into the selected run.</p></div></div>';
            if ((int) ($run['locked'] ?? 0) === 1) {
                echo '<p class="locked-note">This run is finalized and locked. Unlock it before importing new data.</p>';
            } else {
                echo '<div class="import-grid">';
                foreach ($this->sourceTypes() as $key => $label) {
                    echo '<form class="upload-card" method="post" enctype="multipart/form-data" action="' . e(route_url('/imports/upload')) . '">';
                    echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="source_type" value="' . e($key) . '">';
                    echo '<h3>' . e($label) . '</h3><p>' . e($this->requiredColumns($key)) . '</p>';
                    echo '<label>Import mode<select name="import_mode"><option value="replace">Replace this source</option><option value="append">Append rows</option></select></label>';
                    echo '<label>Report file<input type="file" name="report" accept=".xlsx,.xls,.csv,.pdf,.doc,.docx,.zip,.txt,.html,.htm" required></label><button>Upload</button></form>';
                }
                echo '</div>';
            }
            echo '</section>';

            echo '<section class="grid two">';
            echo '<div class="panel"><h2>Uploaded files</h2>';
            if (!$files) {
                echo '<p class="muted">No source files uploaded yet.</p>';
            } else {
                echo '<table><thead><tr><th>Source</th><th>Rows</th><th>Mode</th><th>File</th><th>Uploaded</th></tr></thead><tbody>';
                foreach ($files as $file) {
                    echo '<tr><td>' . e($file['source_type']) . '</td><td>' . e($file['rows_imported']) . '</td><td>' . e($file['import_mode'] ?? 'replace') . '</td><td>' . e($file['original_name']) . '</td><td>' . e($file['uploaded_at']) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';

            echo '<div class="panel"><h2>Validation issues</h2>';
            if (!$issues) {
                echo '<p class="ok">No validation issues for this run.</p>';
            } else {
                echo '<div class="issue-list">';
                foreach ($issues as $issue) {
                    echo '<div class="issue ' . e($issue['severity']) . '"><strong>' . e($issue['severity']) . '</strong><span>' . e($issue['message']) . '</span></div>';
                }
                echo '</div>';
            }
            echo '</div></section>';
        });
    }

    private function validation(): void
    {
        $run = $this->getRun();
        $issues = $this->db->fetchAll('SELECT * FROM validation_issues WHERE run_id = ? AND COALESCE(status, "open") = "open" ORDER BY FIELD(severity, "error", "warning", "notice"), id DESC', [$run['id']]);
        $issueCounts = $this->db->fetchAll('SELECT severity, COUNT(*) AS count FROM validation_issues WHERE run_id = ? AND COALESCE(status, "open") = "open" GROUP BY severity', [$run['id']]);
        $criticalIssues = 0;
        $noticeIssues = 0;
        foreach ($issueCounts as $countRow) {
            if (in_array($countRow['severity'], ['error', 'warning'], true)) {
                $criticalIssues += (int) $countRow['count'];
            } else {
                $noticeIssues += (int) $countRow['count'];
            }
        }
        $unmapped = $this->db->fetchAll("SELECT product_name, COUNT(*) AS row_count FROM import_rows WHERE run_id = ? AND product_name <> '' AND (category = 'Unmapped' OR cogs_sku = '') GROUP BY product_name ORDER BY row_count DESC LIMIT 100", [$run['id']]);
        $blankProducts = $this->db->fetchAll("SELECT source_type, platform, COUNT(*) AS row_count, SUM(net_revenue) AS revenue FROM import_rows WHERE run_id = ? AND product_name = '' GROUP BY source_type, platform ORDER BY row_count DESC", [$run['id']]);
        $categories = $this->db->fetchAll('SELECT DISTINCT category FROM product_costs ORDER BY category');
        $this->render('Validation', function () use ($run, $issues, $criticalIssues, $noticeIssues, $unmapped, $blankProducts, $categories): void {
            echo '<section class="panel"><div class="section-title"><div><p class="eyebrow">Run ' . e($run['month']) . '</p><h1>Validation dashboard</h1></div><div class="actions"><form method="post" action="' . e(route_url('/mis/recalculate')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><button>Refresh validation</button></form><a class="ghost" href="' . e(route_url('/imports/new', ['run_id' => $run['id']])) . '">Imports</a></div></div>';
            echo '<div class="stats compact"><div><span>Needs fixing</span><strong>' . e($criticalIssues) . '</strong></div><div><span>Audit notices</span><strong>' . e($noticeIssues) . '</strong></div><div><span>Unmapped products</span><strong>' . count($unmapped) . '</strong></div><div><span>Blank product groups</span><strong>' . count($blankProducts) . '</strong></div><div><span>Status</span><strong>' . e($run['status']) . '</strong></div></div>';
            echo '<div class="quality-grid">';
            foreach ([
                ['Calculation blockers', $criticalIssues, $criticalIssues === 0 ? 'Clear' : 'Fix before lock', $criticalIssues === 0 ? 'ready' : 'risk'],
                ['Mapping coverage', count($unmapped), count($unmapped) === 0 ? 'All products mapped' : 'Products need category', count($unmapped) === 0 ? 'ready' : 'risk'],
                ['Blank product audit', count($blankProducts), count($blankProducts) === 0 ? 'Clean row names' : 'Review import source', count($blankProducts) === 0 ? 'ready' : 'warn'],
            ] as [$label, $value, $note, $class]) {
                echo '<div class="quality-card ' . e($class) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong><small>' . e($note) . '</small></div>';
            }
            echo '</div></section>';

            echo '<section class="grid two"><div class="panel"><h2>Issues</h2>';
            if (!$issues) {
                echo '<p class="ok">No validation issues. This run is ready for MIS review.</p>';
            } else {
                echo '<div class="issue-list">';
                foreach ($issues as $issue) {
                    echo '<div class="issue ' . e($issue['severity']) . '"><strong>' . e($issue['severity']) . '</strong><span>' . e($issue['message']) . '</span></div>';
                }
                echo '</div>';
            }
            echo '</div><div class="panel"><h2>Fix unmapped products</h2>';
            if (!$unmapped) {
                echo '<p class="ok">No unmapped products found.</p>';
            } else {
                echo '<div class="table-wrap tall"><table><thead><tr><th>Product</th><th>Rows</th><th>Map to category</th></tr></thead><tbody>';
                foreach ($unmapped as $row) {
                    echo '<tr><td>' . e($row['product_name'] ?: 'blank product') . '</td><td>' . e($row['row_count']) . '</td><td><form class="inline-form" method="post" action="' . e(route_url('/validation/map')) . '">';
                    echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="product_name" value="' . e($row['product_name']) . '"><select name="category" required><option value="">Choose</option>';
                    foreach ($categories as $category) {
                        echo '<option value="' . e($category['category']) . '">' . e($category['category']) . '</option>';
                    }
                    echo '</select><button>Map</button></form></td></tr>';
                }
                echo '</tbody></table></div>';
            }
            if ($blankProducts) {
                echo '<h2>Rows still missing product names</h2><div class="map-list">';
                foreach ($blankProducts as $row) {
                    echo '<div><span>' . e($row['platform']) . '<small>' . e($row['source_type']) . ' · ' . e($row['row_count']) . ' rows</small></span><b>₹' . money($row['revenue']) . '</b></div>';
                }
                echo '</div>';
            }
            echo '</div></section>';
        });
    }

    private function sales(string $view = 'overview'): void
    {
        $run = $this->getRun();
        $review = $this->reviewData((int) $run['id']);
        $platforms = $this->db->fetchAll('SELECT platform, COUNT(*) AS row_count, SUM(quantity) AS quantity, SUM(net_revenue) AS revenue FROM import_rows WHERE run_id = ? GROUP BY platform ORDER BY revenue DESC', [$run['id']]);
        $platformCards = $this->platformCards((int) $run['id']);
        $categories = $this->db->fetchAll('SELECT category, SUM(quantity) AS quantity, SUM(net_revenue) AS revenue FROM import_rows WHERE run_id = ? GROUP BY category ORDER BY revenue DESC LIMIT 8', [$run['id']]);
        $this->render('Sales Data', function () use ($run, $review, $platforms, $platformCards, $categories, $view): void {
            echo '<section class="panel"><div class="section-title"><div><p class="eyebrow">Run ' . e($run['month']) . '</p><h1>Sales data manager</h1><p class="muted">Review every imported sale, return, SKU, platform, tax and revenue row before inventory sync and MIS export.</p></div><div class="actions">';
            echo '<a class="ghost" href="' . e(route_url('/auto-import', ['run_id' => $run['id']])) . '">Extract from portals</a>';
            echo '<a class="ghost" href="' . e(route_url('/inventory', ['run_id' => $run['id']])) . '">Inventory</a>';
            echo '<a class="ghost" href="' . e(route_url('/mis/preview', ['run_id' => $run['id']])) . '">MIS Preview</a></div></div>';
            $this->subNav([
                ['Overview', '/sales', ['run_id' => $run['id']]],
                ['Charts', '/sales/charts', ['run_id' => $run['id']]],
                ['Platforms', '/sales/platforms', ['run_id' => $run['id']]],
                ['Records', '/sales/records', ['run_id' => $run['id']]],
            ]);
            $totals = $review['totals'] ?? [];
            echo '<div class="stats compact"><div><span>Rows</span><strong>' . e($totals['rows_count'] ?? 0) . '</strong></div><div><span>Qty</span><strong>' . number_fmt($totals['quantity'] ?? 0) . '</strong></div><div><span>Gross</span><strong>₹' . money($totals['gross'] ?? 0) . '</strong></div><div><span>Tax</span><strong>₹' . money($totals['tax'] ?? 0) . '</strong></div><div><span>Net</span><strong>₹' . money($totals['net'] ?? 0) . '</strong></div></div></section>';

            if ($view === 'records') {
                $this->salesReviewTable($run, $review, true);
                return;
            }

            echo '<section class="panel visual-panel"><div class="section-title"><div><h2>Sales snapshot</h2><p class="muted">Charts first, row audit second.</p></div></div><div class="chart-grid">';
            $this->donutChart('Platform revenue mix', $this->chartSegments($platforms, 'platform', 'revenue', true));
            $this->barChart('Platform net sales', $platformCards, 'platform', 'net', 'gross_profit', 'Gross profit');
            $this->barChart('Category revenue', $categories, 'category', 'revenue', 'quantity', 'Qty', false);
            echo '</div></section>';

            if ($view === 'charts') {
                return;
            }

            $this->summaryTable('Platform Sales Summary', $platforms, ['platform' => 'Platform', 'row_count' => 'Rows', 'quantity' => 'Qty', 'revenue' => 'Net Revenue']);
            if ($view === 'platforms') {
                return;
            }
            $this->salesReviewTable($run, $review);
        });
    }

    private function salesReviewTable(array $run, array $review, bool $open = false): void
    {
        $filters = $review['filters'];
        echo '<details class="panel review-details"' . ($open ? ' open' : '') . '><summary>Imported sales rows and filters</summary><div class="review-body"><div class="section-title"><h2>Imported sales rows</h2><span class="muted">Showing latest 250 matching rows.</span></div>';
        echo '<form class="filters" method="get" action="' . e(route_url('/sales')) . '">';
        echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '">';
        echo '<label>Platform<select name="platform"><option value="">All platforms</option>';
        foreach ($review['platforms'] as $row) {
            $selected = $filters['platform'] === $row['platform'] ? ' selected' : '';
            echo '<option value="' . e($row['platform']) . '"' . $selected . '>' . e($row['platform']) . '</option>';
        }
        echo '</select></label><label>Category<select name="category"><option value="">All categories</option>';
        foreach ($review['categories'] as $row) {
            $selected = $filters['category'] === $row['category'] ? ' selected' : '';
            echo '<option value="' . e($row['category']) . '"' . $selected . '>' . e($row['category']) . '</option>';
        }
        echo '</select></label><label>Search<input name="q" value="' . e($filters['q']) . '" placeholder="Order, product, SKU"></label><button>Apply filters</button><a class="ghost" href="' . e(route_url('/sales', ['run_id' => $run['id']])) . '">Clear</a></form>';
        echo '<div class="table-wrap"><table><thead><tr><th>Platform</th><th>Date</th><th>Order</th><th>Product</th><th>Category</th><th>Qty</th><th>Gross</th><th>Tax</th><th>Net</th><th>Type</th></tr></thead><tbody>';
        foreach ($review['rows'] as $row) {
            echo '<tr><td>' . e($row['platform']) . '</td><td>' . e($row['order_date']) . '</td><td>' . e($row['order_id']) . '</td><td>' . e($row['product_name']) . '</td><td>' . e($row['category']) . '</td><td>' . number_fmt($row['quantity']) . '</td><td>' . money($row['gross_amount']) . '</td><td>' . money($row['tax_amount']) . '</td><td>' . money($row['net_revenue']) . '</td><td>' . e($row['transaction_type']) . '</td></tr>';
        }
        echo '</tbody></table></div></div></details>';
    }

    private function inventory(string $view = 'overview'): void
    {
        $run = $this->getRun();
        $service = new InventoryService($this->db);
        $service->seedItemsFromMasters();
        $stock = $service->stockSummary();
        $lowStock = $service->lowStock();
        $ledger = $service->monthlyLedger((string) $run['month']);
        $items = $service->items();
        $warehouses = $service->warehouses();
        $movements = $service->recentMovements();
        $stats = [
            'items' => count($stock),
            'qty' => array_sum(array_map(fn($row) => (float) $row['stock_qty'], $stock)),
            'low' => count(array_filter($stock, fn($row) => (float) $row['reorder_level'] > 0 && (float) $row['stock_qty'] <= (float) $row['reorder_level'])),
            'sales_rows' => (int) (($this->db->fetch('SELECT COUNT(*) AS count FROM import_rows WHERE run_id = ?', [$run['id']]) ?: [])['count'] ?? 0),
        ];
        $stockByCategory = [];
        foreach ($stock as $row) {
            $category = trim((string) ($row['category'] ?? '')) ?: 'Unmapped';
            $stockByCategory[$category] ??= ['category' => $category, 'stock_qty' => 0, 'stock_value' => 0];
            $stockByCategory[$category]['stock_qty'] += (float) ($row['stock_qty'] ?? 0);
            $stockByCategory[$category]['stock_value'] += (float) ($row['stock_value'] ?? 0);
        }
        usort($stockByCategory, fn(array $a, array $b): int => abs((float) $b['stock_qty']) <=> abs((float) $a['stock_qty']));

        $this->render('Inventory', function () use ($run, $stock, $lowStock, $ledger, $items, $warehouses, $movements, $stats, $stockByCategory, $view): void {
            echo '<section class="panel"><div class="section-title"><div><p class="eyebrow">Warehouse inventory</p><h1>Stock and sales control</h1><p class="muted">Manage item masters, stock movements, and imported sales deductions from marketplace reports.</p></div><div class="actions">';
            echo '<a class="ghost" href="' . e(route_url('/auto-import', ['run_id' => $run['id']])) . '">Auto Import Sales</a>';
            echo '<a class="ghost" href="' . e(route_url('/mis/preview', ['run_id' => $run['id']])) . '">MIS Preview</a></div></div>';
            $this->subNav([
                ['Overview', '/inventory', ['run_id' => $run['id']]],
                ['Stock', '/inventory/stock', ['run_id' => $run['id']]],
                ['Movements', '/inventory/movements', ['run_id' => $run['id']]],
                ['Setup', '/inventory/setup', ['run_id' => $run['id']]],
            ]);
            echo '<div class="stats compact"><div><span>Items</span><strong>' . e($stats['items']) . '</strong></div><div><span>Total stock qty</span><strong>' . number_fmt($stats['qty']) . '</strong></div><div><span>Low stock</span><strong>' . e($stats['low']) . '</strong></div><div><span>Sales rows this run</span><strong>' . e($stats['sales_rows']) . '</strong></div></div>';
            if ($warehouses) {
                echo '<form method="post" action="' . e(route_url('/inventory/sync-sales')) . '" class="inline-actions"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><label>Warehouse<select name="warehouse_id">';
                foreach ($warehouses as $warehouse) {
                    echo '<option value="' . e($warehouse['id']) . '">' . e($warehouse['name']) . '</option>';
                }
                echo '</select></label><button>Sync imported sales to stock</button></form>';
            }
            echo '</section>';

            echo '<section class="panel visual-panel"><div class="section-title"><div><h2>Inventory snapshot</h2><p class="muted">Stock visibility before table-level movement review.</p></div></div><div class="chart-grid">';
            $this->donutChart('Stock quantity mix', $this->chartSegments($stockByCategory, 'category', 'stock_qty', true));
            $this->barChart('Stock by category', $stockByCategory, 'category', 'stock_qty', 'stock_value', 'Stock value');
            echo '<div class="chart-card wide"><h3>Low stock focus</h3>';
            if (!$lowStock) {
                echo '<p class="ok">No item is below reorder level.</p>';
            } else {
                echo '<div class="map-list">';
                foreach (array_slice($lowStock, 0, 8) as $row) {
                    echo '<div><span>' . e($row['item_name']) . '<small>' . e($row['sku']) . '</small></span><b>' . number_fmt($row['stock_qty']) . ' left</b></div>';
                }
                echo '</div>';
            }
            echo '</div></div></section>';

            echo '<section class="grid two"><div class="panel"><h2>Warehouse</h2><form class="inventory-form" method="post" action="' . e(route_url('/inventory/warehouse')) . '">';
            echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="id" value="0">';
            echo '<label>Name<input name="name" required placeholder="Main Warehouse"></label><label>Location<input name="location" placeholder="City / address"></label><button>Save warehouse</button></form>';
            echo '<div class="mini-list">';
            foreach ($warehouses as $warehouse) {
                echo '<span><strong>' . e($warehouse['name']) . '</strong><small>' . e($warehouse['location']) . '</small></span>';
            }
            echo '</div></div>';

            echo '<div class="panel"><h2>Add / edit item</h2><form class="inventory-form" method="post" action="' . e(route_url('/inventory/item')) . '">';
            echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="id" value="0">';
            echo '<label>SKU<input name="sku" required></label><label>Item name<input name="item_name" required></label><label>Category<input name="category"></label><label>Reorder level<input type="number" step="0.01" name="reorder_level" value="0"></label><button>Save item</button></form></div></section>';

            echo '<section class="grid two"><div class="panel"><h2>Add stock movement</h2><form class="inventory-form" method="post" action="' . e(route_url('/inventory/movement')) . '">';
            echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '"><label>Item<select name="item_id" required><option value="">Choose item</option>';
            foreach ($items as $item) {
                echo '<option value="' . e($item['id']) . '">' . e($item['sku'] . ' · ' . $item['item_name']) . '</option>';
            }
            echo '</select></label><label>Warehouse<select name="warehouse_id" required>';
            foreach ($warehouses as $warehouse) {
                echo '<option value="' . e($warehouse['id']) . '">' . e($warehouse['name']) . '</option>';
            }
            echo '</select></label><label>Type<select name="movement_type"><option value="opening">Opening</option><option value="purchase">Purchase</option><option value="adjustment">Adjustment</option><option value="damage">Damaged stock</option><option value="expired">Expired stock</option><option value="sale">Sale</option><option value="return">Return</option></select></label>';
            echo '<label>Quantity<input type="number" step="0.01" name="quantity" required></label><label>Unit cost<input type="number" step="0.01" name="unit_cost" value="0"></label><label>Reference<input name="reference"></label><label>Notes<input name="notes"></label><button>Add movement</button></form></div>';

            echo '<div class="panel"><h2>Transfer stock</h2><form class="inventory-form" method="post" action="' . e(route_url('/inventory/transfer')) . '">';
            echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '"><label>Item<select name="item_id" required><option value="">Choose item</option>';
            foreach ($items as $item) {
                echo '<option value="' . e($item['id']) . '">' . e($item['sku'] . ' · ' . $item['item_name']) . '</option>';
            }
            echo '</select></label><label>From warehouse<select name="from_warehouse_id" required>';
            foreach ($warehouses as $warehouse) {
                echo '<option value="' . e($warehouse['id']) . '">' . e($warehouse['name']) . '</option>';
            }
            echo '</select></label><label>To warehouse<select name="to_warehouse_id" required>';
            foreach ($warehouses as $warehouse) {
                echo '<option value="' . e($warehouse['id']) . '">' . e($warehouse['name']) . '</option>';
            }
            echo '</select></label><label>Quantity<input type="number" step="0.01" name="quantity" required></label><label>Reference<input name="reference"></label><label>Notes<input name="notes"></label><button>Transfer</button></form></div></section>';

            echo '<section class="grid two"><div class="panel"><div class="section-title"><h2>Low-stock alerts</h2><span class="muted">' . e(count($lowStock)) . ' item(s)</span></div>';
            if (!$lowStock) {
                echo '<p class="ok">No item is below reorder level.</p>';
            } else {
                echo '<div class="table-wrap"><table><thead><tr><th>SKU</th><th>Item</th><th>Stock</th><th>Reorder</th></tr></thead><tbody>';
                foreach ($lowStock as $row) {
                    echo '<tr><td>' . e($row['sku']) . '</td><td>' . e($row['item_name']) . '</td><td>' . number_fmt($row['stock_qty']) . '</td><td>' . number_fmt($row['reorder_level']) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div>';

            echo '<details class="panel review-details"' . ($view === 'movements' ? ' open' : '') . '><summary>Monthly stock ledger</summary><div class="review-body"><div class="section-title"><h2>Monthly stock ledger</h2><span class="muted">' . e($run['month']) . '</span></div><div class="table-wrap"><table><thead><tr><th>SKU</th><th>Warehouse</th><th>Opening</th><th>In</th><th>Out</th><th>Transfer In</th><th>Transfer Out</th><th>Adj.</th><th>Closing</th></tr></thead><tbody>';
            foreach ($ledger as $row) {
                echo '<tr><td>' . e($row['sku']) . '</td><td>' . e($row['warehouse_name']) . '</td><td>' . number_fmt($row['opening_qty']) . '</td><td>' . number_fmt($row['inward_qty']) . '</td><td>' . number_fmt($row['outward_qty']) . '</td><td>' . number_fmt($row['transfer_in_qty']) . '</td><td>' . number_fmt($row['transfer_out_qty']) . '</td><td>' . number_fmt($row['adjustment_qty']) . '</td><td>' . number_fmt($row['closing_qty']) . '</td></tr>';
            }
            echo '</tbody></table></div></div></details></section>';

            echo '<details class="panel review-details"' . ($view === 'stock' ? ' open' : '') . '><summary>Current stock table</summary><div class="review-body"><div class="section-title"><h2>Current stock</h2><span class="muted">Sales sync creates negative sale movements and return rows add stock back.</span></div><div class="table-wrap tall"><table><thead><tr><th>SKU</th><th>Item</th><th>Category</th><th>Stock Qty</th><th>Reorder</th><th>Status</th></tr></thead><tbody>';
            foreach ($stock as $row) {
                $low = (float) $row['reorder_level'] > 0 && (float) $row['stock_qty'] <= (float) $row['reorder_level'];
                echo '<tr><td>' . e($row['sku']) . '</td><td>' . e($row['item_name']) . '</td><td>' . e($row['category']) . '</td><td>' . number_fmt($row['stock_qty']) . '</td><td>' . number_fmt($row['reorder_level']) . '</td><td><span class="pill ' . ($low ? 'low' : '') . '">' . ($low ? 'Low stock' : 'OK') . '</span></td></tr>';
            }
            echo '</tbody></table></div></div></details>';

            echo '<details class="panel review-details"' . ($view === 'movements' ? ' open' : '') . '><summary>Recent inventory movements</summary><div class="review-body"><h2>Recent movements</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Warehouse</th><th>SKU</th><th>Item</th><th>Qty</th><th>Reference</th><th>Notes</th></tr></thead><tbody>';
            foreach ($movements as $row) {
                echo '<tr><td>' . e($row['moved_at']) . '</td><td>' . e($row['movement_type']) . '</td><td>' . e($row['warehouse_name']) . '</td><td>' . e($row['sku']) . '</td><td>' . e($row['item_name']) . '</td><td>' . number_fmt($row['quantity']) . '</td><td>' . e($row['reference']) . '</td><td>' . e($row['notes']) . '</td></tr>';
            }
            echo '</tbody></table></div></div></details>';
        });
    }

    private function adjustments(): void
    {
        $run = $this->getRun();
        $adjustments = $this->db->fetchAll('SELECT * FROM monthly_adjustments WHERE run_id = ? ORDER BY created_at DESC, id DESC', [$run['id']]);
        $totals = ['addition' => 0.0, 'deduction' => 0.0];
        foreach ($adjustments as $adjustment) {
            $type = (string) ($adjustment['adjustment_type'] ?? 'deduction');
            $totals[$type] = ($totals[$type] ?? 0.0) + (float) ($adjustment['amount'] ?? 0);
        }
        $this->render('Adjustments', function () use ($run, $adjustments, $totals): void {
            echo '<section class="panel"><div class="section-title"><div><p class="eyebrow">Run ' . e($run['month']) . '</p><h1>Monthly adjustments</h1></div><a class="ghost" href="' . e(route_url('/mis/preview', ['run_id' => $run['id']])) . '">Review MIS</a></div>';
            echo '<div class="stats compact"><div><span>Total entries</span><strong>' . e(count($adjustments)) . '</strong></div><div><span>Additions</span><strong>₹' . money($totals['addition'] ?? 0) . '</strong></div><div><span>Deductions</span><strong>₹' . money($totals['deduction'] ?? 0) . '</strong></div><div><span>Net effect</span><strong>₹' . money(($totals['addition'] ?? 0) - ($totals['deduction'] ?? 0)) . '</strong></div></div>';
            if ((int) ($run['locked'] ?? 0) === 1) {
                echo '<p class="locked-note">This run is locked. Unlock before editing adjustments.</p>';
            } else {
                echo '<form class="adjust-form" method="post" action="' . e(route_url('/adjustments/save')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '">';
                echo '<label>Type<select name="adjustment_type"><option value="addition">Addition</option><option value="deduction">Deduction</option></select></label>';
                echo '<label>Platform<input name="platform" placeholder="Optional"></label><label>Category<input name="category" placeholder="Optional"></label>';
                echo '<label>Description<input name="description" required placeholder="Commission, logistics, storage, damages"></label><label>Amount<input type="number" step="0.01" name="amount" required></label><button>Add adjustment</button></form>';
            }
            echo '</section><section class="panel"><h2>Adjustment audit trail</h2>';
            if (!$adjustments) {
                echo '<p class="muted">No manual additions or deductions yet.</p>';
            } else {
                echo '<div class="table-wrap"><table><thead><tr><th>Type</th><th>Platform</th><th>Category</th><th>Description</th><th>Amount</th><th>Created</th><th></th></tr></thead><tbody>';
                foreach ($adjustments as $row) {
                    echo '<tr><td>' . e($row['adjustment_type']) . '</td><td>' . e($row['platform']) . '</td><td>' . e($row['category']) . '</td><td>' . e($row['description']) . '</td><td>₹' . money($row['amount']) . '</td><td>' . e($row['created_at']) . '</td><td>';
                    if ((int) ($run['locked'] ?? 0) !== 1) {
                        echo '<form method="post" action="' . e(route_url('/adjustments/delete')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><input type="hidden" name="id" value="' . e($row['id']) . '"><button class="ghost">Delete</button></form>';
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</section>';
        });
    }

    private function reports(string $view = 'overview'): void
    {
        $run = $this->getRun();
        $top = $this->db->fetchAll('SELECT category, SUM(quantity) AS quantity, SUM(net_revenue) AS revenue FROM import_rows WHERE run_id = ? GROUP BY category ORDER BY revenue DESC', [$run['id']]);
        $loss = $this->db->fetchAll('SELECT category, platform, quantity, revenue, gross_profit FROM mis_sku_summary WHERE run_id = ? AND gross_profit < 0 ORDER BY gross_profit ASC', [$run['id']]);
        $pnl = $this->db->fetchAll('SELECT pnl_category, product_category, COUNT(*) AS rows_count, SUM(amount) AS amount FROM profit_loss_entries WHERE run_id = ? GROUP BY pnl_category, product_category ORDER BY pnl_category, product_category', [$run['id']]);
        $platforms = $this->platformCards((int) $run['id']);
        $expenses = $this->expenseBreakdown((int) $run['id']);
        $readiness = $this->runReadiness($run);
        $overview = $this->db->fetchAll('SELECT * FROM mis_overview_lines WHERE run_id = ? ORDER BY sort_order', [$run['id']]);
        $overviewByLine = [];
        foreach ($overview as $line) {
            $overviewByLine[$line['line_item']] = $line;
        }
        $this->render('Reports', function () use ($run, $top, $loss, $pnl, $platforms, $expenses, $readiness, $view, $overviewByLine): void {
            echo '<section class="panel"><div class="section-title"><div><p class="eyebrow">Run ' . e($run['month']) . '</p><h1>Management reports</h1><p class="muted">Printable and review-ready summaries before exporting the final Excel workbook.</p></div><div class="actions"><a class="ghost" href="' . e(route_url('/mis/preview', ['run_id' => $run['id']])) . '">MIS Preview</a><a class="button" href="' . e(route_url('/mis/export', ['run_id' => $run['id']])) . '">' . ($readiness['ready'] ? 'Export Excel' : 'Export with warnings') . '</a></div></div>';
            $this->subNav([
                ['Overview', '/reports', ['run_id' => $run['id']]],
                ['Executive', '/reports/executive', ['run_id' => $run['id']]],
                ['P&L', '/reports/pnl', ['run_id' => $run['id']]],
                ['Loss Watch', '/reports/loss-watch', ['run_id' => $run['id']]],
            ]);
            $this->readinessPanel($readiness, ['run_id' => $run['id']]);
            echo '</section>';
            if ($view === 'executive') {
                $this->executiveReportView($overviewByLine, $platforms, $top, $expenses, $loss, $readiness, $run);
            }
            echo '<section class="panel visual-panel"><div class="section-title"><div><h2>Report charts</h2><p class="muted">Fast visual review for platform contribution, expenses, and category revenue.</p></div></div><div class="chart-grid">';
            $this->barChart('Platform net sales', $platforms, 'platform', 'net', 'gross_profit', 'Gross profit');
            $this->donutChart('Expense split', array_map(fn(array $row, int $index): array => [$row['pnl_category'], abs((float) $row['amount']), ['#4f46e5', '#047857', '#ea580c', '#7c3aed', '#b42318', '#d9a303'][$index % 6]], $expenses, array_keys($expenses)));
            $this->barChart('Category revenue', $top, 'category', 'revenue', 'quantity', 'Qty', false);
            echo '<div class="chart-card wide"><h3>Loss watch</h3>';
            if (!$loss) {
                echo '<p class="ok">No negative gross-profit lines found.</p>';
            } else {
                echo '<div class="map-list">';
                foreach (array_slice($loss, 0, 8) as $row) {
                    echo '<div><span>' . e($row['category']) . '<small>' . e($row['platform']) . '</small></span><b>₹' . money($row['gross_profit']) . '</b></div>';
                }
                echo '</div>';
            }
            echo '</div></div></section>';
            if ($view === 'executive') {
                return;
            }
            if ($view === 'loss-watch') {
                $this->summaryTable('Loss-making / Negative Lines', $loss, ['category' => 'Category', 'platform' => 'Platform', 'quantity' => 'Qty', 'revenue' => 'Revenue', 'gross_profit' => 'Gross Profit']);
                return;
            }
            $this->summaryTable('Profit & Loss Mappings', $pnl, ['pnl_category' => 'P&L Mapping', 'product_category' => 'Product Mapping', 'rows_count' => 'Rows', 'amount' => 'Amount']);
            if ($view === 'pnl') {
                return;
            }
            $this->summaryTable('Category Revenue', $top, ['category' => 'Category', 'quantity' => 'Qty', 'revenue' => 'Revenue']);
            $this->summaryTable('Loss-making / Negative Lines', $loss, ['category' => 'Category', 'platform' => 'Platform', 'quantity' => 'Qty', 'revenue' => 'Revenue', 'gross_profit' => 'Gross Profit']);
        });
    }

    private function masters(): void
    {
        $mappings = $this->db->fetchAll('SELECT * FROM sku_mappings ORDER BY product_name LIMIT 400');
        $costs = $this->db->fetchAll('SELECT * FROM product_costs ORDER BY item_name LIMIT 400');
        $this->render('Masters', function () use ($mappings, $costs): void {
            echo '<section class="panel"><div class="section-title"><div><p class="eyebrow">Editable master</p><h1>SKU mappings and COGS</h1></div><a class="ghost" href="' . e(route_url('/')) . '">Dashboard</a></div>';
            echo '<div class="stats compact"><div><span>SKU mappings</span><strong>' . e(count($mappings)) . '</strong></div><div><span>COGS items</span><strong>' . e(count($costs)) . '</strong></div><div><span>Workflow</span><strong>Backend</strong></div><div><span>Scope</span><strong>Masters</strong></div></div>';
            echo '<form method="post" action="' . e(route_url('/masters/save')) . '">';
            echo '<h2>SKU mappings</h2><div class="table-wrap tall"><table><thead><tr><th>Product name</th><th>COGS SKU</th><th>MIS SKU</th><th>Category</th></tr></thead><tbody>';
            for ($i = 0; $i < max(20, count($mappings) + 5); $i++) {
                $row = $mappings[$i] ?? ['id' => '', 'product_name' => '', 'cogs_sku' => '', 'mis_sku' => '', 'category' => ''];
                echo '<tr><td><input type="hidden" name="mapping_id[]" value="' . e($row['id']) . '"><input name="product_name[]" value="' . e($row['product_name']) . '"></td>';
                echo '<td><input name="cogs_sku[]" value="' . e($row['cogs_sku']) . '"></td><td><input name="mis_sku[]" value="' . e($row['mis_sku']) . '"></td><td><input name="category[]" value="' . e($row['category']) . '"></td></tr>';
            }
            echo '</tbody></table></div>';

            echo '<h2>COGS and packaging</h2><div class="table-wrap tall"><table><thead><tr><th>Item</th><th>Category</th><th>Multiplier</th><th>Purchase price</th><th>Packaging rate</th></tr></thead><tbody>';
            for ($i = 0; $i < max(20, count($costs) + 5); $i++) {
                $row = $costs[$i] ?? ['id' => '', 'item_name' => '', 'category' => '', 'multiplier' => 1, 'purchase_price' => 0, 'packaging_rate' => 0];
                echo '<tr><td><input type="hidden" name="cost_id[]" value="' . e($row['id']) . '"><input name="item_name[]" value="' . e($row['item_name']) . '"></td>';
                echo '<td><input name="cost_category[]" value="' . e($row['category']) . '"></td><td><input type="number" step="0.0001" name="multiplier[]" value="' . e($row['multiplier']) . '"></td>';
                echo '<td><input type="number" step="0.01" name="purchase_price[]" value="' . e($row['purchase_price']) . '"></td><td><input type="number" step="0.01" name="packaging_rate[]" value="' . e($row['packaging_rate']) . '"></td></tr>';
            }
            echo '</tbody></table></div><button>Save masters</button></form></section>';
        });
    }

    private function executiveReportView(array $overviewByLine, array $platforms, array $categories, array $expenses, array $loss, array $readiness, array $run): void
    {
        $netSales = (float) ($overviewByLine['Net sales after tax']['amount'] ?? 0);
        $grossMargin = (float) ($overviewByLine['Gross margin after COGS']['amount'] ?? 0);
        $netSurplus = (float) ($overviewByLine['Net surplus / burn']['amount'] ?? 0);
        $marketing = abs((float) ($overviewByLine['Marketing spend']['amount'] ?? 0));
        $bestPlatform = $this->topBy($platforms, 'gross_profit');
        $bestCategory = $this->topBy($categories, 'revenue');
        $largestExpense = $this->topBy($expenses, 'amount', true);
        echo '<section class="panel executive-report" data-reveal><div class="section-title"><div><p class="eyebrow">Executive packet</p><h2>Month-end board summary</h2><p class="muted">A printable management view with result, drivers, risks, and next action.</p></div><div class="actions"><button class="ghost" type="button" onclick="window.print()">Print</button><a class="button" href="' . e(route_url('/mis/export', ['run_id' => $run['id']])) . '">Export Excel</a></div></div>';
        echo '<div class="executive-grid">';
        foreach ([
            ['Net sales', '₹' . money($netSales), 'Revenue after tax and returns', 'positive'],
            ['Gross margin', '₹' . money($grossMargin), 'After product and marketing costs', $grossMargin < 0 ? 'negative' : 'positive'],
            ['Net result', '₹' . money($netSurplus), $netSurplus < 0 ? 'Burn' : 'Surplus', $netSurplus < 0 ? 'negative' : 'positive'],
            ['Marketing load', abs($netSales) > 0.00001 ? number_fmt($marketing / abs($netSales) * 100) . '%' : '0.00%', 'Spend against net sales', 'neutral'],
        ] as [$label, $value, $note, $class]) {
            echo '<div class="executive-kpi ' . e($class) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong><small>' . e($note) . '</small></div>';
        }
        echo '</div><div class="executive-brief">';
        foreach ([
            ['Best platform', $bestPlatform['platform'] ?? '-', '₹' . money($bestPlatform['gross_profit'] ?? 0), 'Gross profit'],
            ['Largest revenue category', $bestCategory['category'] ?? '-', '₹' . money($bestCategory['revenue'] ?? 0), 'Revenue'],
            ['Biggest expense bucket', $largestExpense['pnl_category'] ?? '-', '₹' . money(abs((float) ($largestExpense['amount'] ?? 0))), 'P&L mapping'],
            ['Loss watch', count($loss) . ' line(s)', count($loss) > 0 ? 'Review negative gross-profit rows' : 'No negative rows', 'Risk'],
            ['Close status', $readiness['status'], $readiness['score'] . '/' . $readiness['total'] . ' checks', 'Readiness'],
        ] as [$label, $title, $value, $note]) {
            echo '<article><span>' . e($label) . '</span><strong>' . e($title) . '</strong><b>' . e($value) . '</b><small>' . e($note) . '</small></article>';
        }
        echo '</div></section>';
    }

    private function preview(string $view = 'overview'): void
    {
        $run = $this->getRun();
        $overview = $this->db->fetchAll('SELECT * FROM mis_overview_lines WHERE run_id = ? ORDER BY sort_order', [$run['id']]);
        $overviewByLine = [];
        foreach ($overview as $line) {
            $overviewByLine[$line['line_item']] = $line;
        }
        $platforms = $this->platformCards((int) $run['id']);
        $categories = $this->categoryPerformance((int) $run['id']);
        $expenseBreakdown = $this->expenseBreakdown((int) $run['id']);
        $marketingAllocation = $this->marketingAllocation((int) $run['id']);
        $review = $this->reviewData((int) $run['id']);
        $readiness = $this->runReadiness($run);
        $this->render('MIS Preview', function () use ($run, $overview, $overviewByLine, $platforms, $categories, $expenseBreakdown, $marketingAllocation, $review, $readiness, $view): void {
            $netSurplus = (float) ($overviewByLine['Net surplus / burn']['amount'] ?? 0);
            $resultLabel = $netSurplus < 0 ? 'Burn' : 'Surplus';
            $netSales = (float) ($overviewByLine['Net sales after tax']['amount'] ?? 0);
            $grossMargin = (float) ($overviewByLine['Gross margin after COGS']['amount'] ?? 0);
            $grossSales = (float) ($overviewByLine['Sales including GST']['amount'] ?? 0);
            $marketing = abs((float) ($overviewByLine['Marketing spend']['amount'] ?? 0));
            $profitRatio = abs($netSales) > 0.00001 ? $netSurplus / abs($netSales) * 100 : 0;
            $bestPlatform = $this->topBy($platforms, 'gross_profit');
            $bestCategory = $this->topBy($categories, 'gross_profit');
            $largestExpense = $this->topBy($expenseBreakdown, 'amount', true);
            $reviewRows = (int) (($review['totals']['rows_count'] ?? 0));

            echo '<div class="mis-page">';
            echo '<section class="mis-hero">';
            echo '<div class="mis-hero-main"><p class="eyebrow">Run ' . e($run['month']) . '</p><h1>MIS Review</h1><p>A management-ready view of sales, COGS, P&L mappings, platform performance and final result.</p>';
            echo '<div class="mis-hero-result"><span>Final result</span><strong class="' . ($netSurplus < 0 ? 'negative-text' : 'positive-text') . '">₹' . money($netSurplus) . '</strong><small>' . e($resultLabel) . ' · ' . number_fmt($profitRatio) . '% of net sales</small></div></div>';
            echo '<div class="mis-action-panel"><span>' . e($readiness['status']) . '</span><strong>' . e($readiness['score']) . '/' . e($readiness['total']) . ' checks</strong><small>' . e($readiness['summary']) . '</small><div class="mis-actions">';
            echo '<form method="post" action="' . e(route_url('/mis/recalculate')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><button class="ghost">Recalculate</button></form>';
            if ((int) ($run['locked'] ?? 0) === 1) {
                echo '<form method="post" action="' . e(route_url('/runs/unlock')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><button class="ghost">Unlock</button></form>';
            } else {
                echo '<form method="post" action="' . e(route_url('/runs/finalize')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><button>Finalize & Lock</button></form>';
            }
            echo '<a class="button" href="' . e(route_url('/mis/export', ['run_id' => $run['id']])) . '">' . ($readiness['ready'] ? 'Export Excel' : 'Export with warnings') . '</a></div></div>';
            echo '</section>';
            if ((int) ($run['locked'] ?? 0) === 1) {
                echo '<p class="locked-note">Finalized on ' . e($run['finalized_at'] ?? '') . '. Imports and adjustments are protected until unlocked.</p>';
            }

            $this->subNav([
                ['Overview', '/mis/preview', ['run_id' => $run['id']]],
                ['Charts', '/mis/charts', ['run_id' => $run['id']]],
                ['Profit Bridge', '/mis/profit-bridge', ['run_id' => $run['id']]],
                ['Platforms', '/mis/platforms', ['run_id' => $run['id']]],
                ['Categories', '/mis/categories', ['run_id' => $run['id']]],
                ['Audit', '/mis/audit', ['run_id' => $run['id']]],
            ]);
            $this->stickyReportActions($run, $readiness, $view);

            if ($view === 'charts') {
                $this->subpageIntro('Graphical analysis', 'Revenue use, cost pressure, platform performance, and category profit in chart-first format.', 'Use this page for fast visual review before opening the audit rows.');
                $this->visualDashboard($overviewByLine, $platforms, $categories, $expenseBreakdown);
                echo '</div>';
                return;
            }
            if ($view === 'profit-bridge') {
                $this->subpageIntro('Profit bridge', 'A step-by-step backend calculation from sales through COGS, marketing, expenses, and final result.', 'Use this to explain why the month ended in surplus or burn.');
                $this->profitBridge($overview);
                echo '</div>';
                return;
            }
            if ($view === 'platforms') {
                $this->subpageIntro('Platform performance', 'Marketplace and website contribution after returns, tax, product cost, and packaging.', 'Use this to see which channel is creating or consuming margin.');
                $this->platformCardsView($platforms);
                echo '</div>';
                return;
            }
            if ($view === 'categories') {
                $this->subpageIntro('Category performance', 'Product-category revenue, cost, gross profit, and margin from COGS-backed calculations.', 'Use this to compare product families before inventory decisions.');
                $this->categoryPerformanceView($categories);
                echo '</div>';
                return;
            }
            if ($view === 'audit') {
                $this->subpageIntro('Audit table', 'Raw imported rows remain available here for verification, filters, and row-level traceability.', 'Use this only when a number needs proof from the underlying sales data.');
                $this->reviewTable($run, $review, true);
                echo '</div>';
                return;
            }

            echo '<section class="panel mis-command-panel" id="mis-overview">';
            echo '<div class="section-title mis-overview-title"><div><h2>Executive summary</h2><p class="muted">The main month-end numbers and the checks needed before locking the report.</p></div></div>';
            echo '<div class="mis-command-grid">';
            foreach ([
                ['Gross sales', '₹' . money($grossSales), 'Before returns and tax', 'neutral'],
                ['Net sales', '₹' . money($netSales), $reviewRows . ' imported rows', 'positive'],
                ['Gross margin', '₹' . money($grossMargin), 'After product and marketing costs', $grossMargin < 0 ? 'negative' : 'positive'],
                ['Marketing spend', '₹' . money($marketing), abs($netSales) > 0.00001 ? number_fmt($marketing / abs($netSales) * 100) . '% of net sales' : 'No sales base', 'neutral'],
                ['Close status', $readiness['ready'] ? 'Ready' : (string) max(0, $readiness['total'] - $readiness['score']) . ' open', $readiness['status'], $readiness['ready'] ? 'positive' : 'negative'],
            ] as [$label, $value, $note, $class]) {
                echo '<div class="mis-command-card ' . e($class) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong><small>' . e($note) . '</small></div>';
            }
            echo '</div>';
            echo '<div class="mis-driver-title"><span>Key drivers</span><small>Highest impact areas from the current MIS run</small></div>';
            echo '<div class="mis-driver-grid">';
            foreach ([
                ['Best platform', $bestPlatform['platform'] ?? '-', '₹' . money($bestPlatform['gross_profit'] ?? 0), 'Gross profit'],
                ['Best category', $bestCategory['category'] ?? '-', '₹' . money($bestCategory['gross_profit'] ?? 0), 'Gross profit'],
                ['Largest expense', $largestExpense['pnl_category'] ?? '-', '₹' . money(abs((float) ($largestExpense['amount'] ?? 0))), 'P&L column C'],
            ] as [$label, $title, $value, $note]) {
                echo '<div class="mis-driver-card"><span>' . e($label) . '</span><strong>' . e($title) . '</strong><b>' . e($value) . '</b><small>' . e($note) . '</small></div>';
            }
            echo '</div>';
            $this->readinessPanel($readiness, ['run_id' => $run['id']]);
            echo '</section>';

            echo '<div id="mis-charts">';
            $this->visualDashboard($overviewByLine, $platforms, $categories, $expenseBreakdown);
            echo '</div>';
            echo '<div id="mis-costs">';
            $this->expenseBreakdownView($expenseBreakdown, $marketingAllocation);
            echo '</div><div id="mis-bridge">';
            $this->profitBridge($overview);
            echo '</div><div id="mis-platforms">';
            $this->platformCardsView($platforms);
            echo '</div><div id="mis-categories">';
            $this->categoryPerformanceView($categories);
            echo '</div><div id="mis-audit">';
            $this->reviewTable($run, $review);
            echo '</div></div>';
        });
    }

    private function topBy(array $rows, string $key, bool $absolute = false): array
    {
        $best = [];
        $bestValue = null;
        foreach ($rows as $row) {
            $value = (float) ($row[$key] ?? 0);
            $score = $absolute ? abs($value) : $value;
            if ($bestValue === null || $score > $bestValue) {
                $bestValue = $score;
                $best = $row;
            }
        }
        return $best;
    }

    private function chartSegments(array $rows, string $labelKey, string $valueKey, bool $absolute = false): array
    {
        $palette = ['#4f46e5', '#047857', '#ea580c', '#7c3aed', '#0f766e', '#b42318', '#d9a303', '#64748b', '#0284c7'];
        $segments = [];
        foreach (array_values($rows) as $index => $row) {
            $value = (float) ($row[$valueKey] ?? 0);
            $amount = $absolute ? abs($value) : $value;
            if ($amount <= 0.00001) {
                continue;
            }
            $label = trim((string) ($row[$labelKey] ?? '')) ?: 'Unmapped';
            $segments[] = [$label, $amount, $palette[$index % count($palette)]];
        }
        return $segments;
    }

    private function subNav(array $links): void
    {
        $currentPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $scriptBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        if ($scriptBase !== '' && $scriptBase !== '.' && $scriptBase !== '/' && str_starts_with($currentPath, $scriptBase)) {
            $currentPath = substr($currentPath, strlen($scriptBase)) ?: '/';
        }
        echo '<nav class="subnav" data-subnav>';
        foreach ($links as $link) {
            [$label, $path, $params] = [$link[0], $link[1], $link[2] ?? []];
            $active = $currentPath === $path || ($path === '/' && $currentPath === '/index.php');
            echo '<a class="' . ($active ? 'active' : '') . '" href="' . e(route_url($path, $params)) . '">' . e($label) . '</a>';
        }
        echo '</nav>';
    }

    private function closeStatusPanel(?array $run, array $stats, array $expenses, float $grossMargin, float $netSales): void
    {
        echo '<section class="dashboard-grid">';
        echo '<div class="panel"><div class="section-title"><div><h2>Monthly close board</h2><p class="muted">A focused checklist for month-end approval.</p></div></div><div class="status-list">';
        foreach ([
            ['Sales imported', $stats['rows'] > 0, $stats['rows'] . ' rows available'],
            ['P&L mapping ready', count($expenses) > 0, count($expenses) . ' mapped categories'],
            ['COGS calculated', abs($grossMargin) > 0.00001 || abs($netSales) > 0.00001, 'Margin output is visible'],
            ['Validation clear', $stats['issues'] === 0, $stats['issues'] . ' open issue(s)'],
            ['Run finalized', $run && (int) ($run['locked'] ?? 0) === 1, e($run['status'] ?? 'draft')],
        ] as [$label, $done, $note]) {
            echo '<div class="status-row ' . ($done ? 'done' : 'pending') . '"><b>' . ($done ? 'Done' : 'Check') . '</b><span><strong>' . e($label) . '</strong><small>' . e($note) . '</small></span></div>';
        }
        echo '</div></div>';

        echo '<div class="panel"><div class="section-title"><div><h2>P&L mapping pressure</h2><p class="muted">Largest mapped categories from the Profit and loss sheet.</p></div></div>';
        if (!$expenses) {
            echo '<p class="muted">No mapped expenses yet.</p>';
        } else {
            echo '<div class="map-list compact-map">';
            foreach (array_slice($expenses, 0, 10) as $row) {
                echo '<div><span>' . e($row['pnl_category']) . '<small>' . e($row['rows_count']) . ' lines</small></span><b>₹' . money($row['amount']) . '</b></div>';
            }
            echo '</div>';
        }
        echo '</div></section>';
    }

    private function dashboardInsightPanel(array $platforms, array $categories, array $expenses, array $trend, array $stats, float $netSurplus, float $netSales, array $runParams): void
    {
        $bestPlatform = $this->topBy($platforms, 'gross_profit');
        $bestCategory = $this->topBy($categories, 'gross_profit');
        $largestExpense = $this->topBy($expenses, 'amount', true);
        $largestMove = [];
        foreach ($trend as $row) {
            if (!$largestMove || abs((float) $row['delta']) > abs((float) ($largestMove['delta'] ?? 0))) {
                $largestMove = $row;
            }
        }
        $margin = abs($netSales) > 0.00001 ? $netSurplus / abs($netSales) * 100 : 0;
        $riskLabel = $stats['issues'] > 0 ? 'Validation issues' : ($netSurplus < 0 ? 'Net burn' : 'No blockers');
        $riskNote = $stats['issues'] > 0 ? $stats['issues'] . ' issue(s) need fixing' : ($netSurplus < 0 ? 'Review costs before lock' : 'Ready for business review');
        $actionPath = $stats['issues'] > 0 ? '/validation' : '/mis/preview';
        echo '<section class="insight-grid" data-reveal>';
        foreach ([
            ['What changed', $largestMove ? ($largestMove['label'] ?? 'Trend') : 'No prior run', $largestMove ? (($largestMove['delta'] ?? 0) >= 0 ? '+' : '') . '₹' . money($largestMove['delta'] ?? 0) : 'Import another month', 'Month-on-month movement', 'trend'],
            ['Top platform', $bestPlatform['platform'] ?? '-', '₹' . money($bestPlatform['gross_profit'] ?? 0), 'Gross profit leader', 'positive'],
            ['Top category', $bestCategory['category'] ?? '-', '₹' . money($bestCategory['gross_profit'] ?? 0), 'Category contribution', 'positive'],
            ['Risk area', $riskLabel, $riskNote, number_fmt($margin) . '% net result ratio', $stats['issues'] > 0 || $netSurplus < 0 ? 'negative' : 'positive'],
            ['Biggest cost', $largestExpense['pnl_category'] ?? '-', '₹' . money(abs((float) ($largestExpense['amount'] ?? 0))), 'Largest P&L column C bucket', 'neutral'],
        ] as [$label, $title, $value, $note, $class]) {
            echo '<article class="insight-card ' . e($class) . '"><span>' . e($label) . '</span><strong>' . e($title) . '</strong><b>' . e($value) . '</b><small>' . e($note) . '</small></article>';
        }
        echo '<a class="insight-card action" href="' . e(route_url($actionPath, $runParams)) . '"><span>Action required</span><strong>' . e($riskLabel) . '</strong><b>Open next step</b><small>' . e($riskNote) . '</small></a>';
        echo '</section>';
    }

    private function portalNextAction(string $status, bool $imported): string
    {
        return match ($status) {
            'connected' => $imported ? 'Refresh only if portal data changed' : 'Fetch selected report now',
            'otp_required' => 'Enter OTP in the Chrome portal window',
            'login_required' => 'Complete portal login in Chrome',
            'browser_closed' => 'Open the portal browser again',
            'opened', 'checking' => 'Wait for session check',
            default => 'Open portal and connect',
        };
    }

    private function misKpis(array $overviewByLine): void
    {
        $cards = [
            ['Gross sales', $overviewByLine['Sales including GST']['amount'] ?? 0, 'Before returns and tax'],
            ['Net sales', $overviewByLine['Net sales after tax']['amount'] ?? 0, 'After returns and tax'],
            ['Gross margin', $overviewByLine['Gross margin after COGS']['amount'] ?? 0, 'After marketing and product costs'],
            ['Net surplus / burn', $overviewByLine['Net surplus / burn']['amount'] ?? 0, 'Final MIS result'],
        ];
        echo '<div class="mis-kpi-grid">';
        foreach ($cards as [$label, $amount, $note]) {
            $class = (float) $amount < 0 ? 'negative' : 'positive';
            echo '<div class="mis-kpi ' . $class . '"><span>' . e($label) . '</span><strong>₹' . money($amount) . '</strong><small>' . e($note) . '</small></div>';
        }
        echo '</div>';
    }

    private function visualDashboard(array $overviewByLine, array $platforms, array $categories, array $expenseBreakdown): void
    {
        $palette = ['#4f46e5', '#047857', '#ea580c', '#7c3aed', '#b42318', '#d9a303', '#0f766e', '#64748b', '#0284c7'];
        $costSplit = [];
        foreach ($expenseBreakdown as $index => $row) {
            $costSplit[] = [$row['pnl_category'], abs((float) $row['amount']), $palette[$index % count($palette)]];
        }
        $resultSplit = [
            ['Net surplus', max(0, (float) ($overviewByLine['Net surplus / burn']['amount'] ?? 0)), '#047857'],
            ['Platform costs', abs((float) ($overviewByLine['Net sales after tax']['amount'] ?? 0) - (float) ($overviewByLine['Net proceeds']['amount'] ?? 0)), '#4f46e5'],
            ['Marketing', abs((float) ($overviewByLine['Marketing spend']['amount'] ?? 0)), '#ea580c'],
            ['Product costs', abs((float) ($overviewByLine['COGS - raw material']['amount'] ?? 0)) + abs((float) ($overviewByLine['Packaging cost']['amount'] ?? 0)) + abs((float) ($overviewByLine['Extra COGS from P&L']['amount'] ?? 0)), '#b42318'],
            ['Admin costs', abs((float) ($overviewByLine['Agency / consultant fees']['amount'] ?? 0)) + abs((float) ($overviewByLine['General and professional expenses']['amount'] ?? 0)), '#7c3aed'],
        ];

        $netSales = (float) ($overviewByLine['Net sales after tax']['amount'] ?? 0);
        $marketing = abs((float) ($overviewByLine['Marketing spend']['amount'] ?? 0));
        $netSurplus = (float) ($overviewByLine['Net surplus / burn']['amount'] ?? 0);
        $grossMargin = (float) ($overviewByLine['Gross margin after COGS']['amount'] ?? 0);
        $productCosts = abs((float) ($overviewByLine['COGS - raw material']['amount'] ?? 0)) + abs((float) ($overviewByLine['Packaging cost']['amount'] ?? 0)) + abs((float) ($overviewByLine['Extra COGS from P&L']['amount'] ?? 0));
        $marketingRatio = abs($netSales) > 0.00001 ? $marketing / abs($netSales) * 100 : 0;
        $productCostRatio = abs($netSales) > 0.00001 ? $productCosts / abs($netSales) * 100 : 0;
        $netRatio = abs($netSales) > 0.00001 ? $netSurplus / abs($netSales) * 100 : 0;

        echo '<section class="panel visual-panel"><div class="section-title"><div><h2>Graphical MIS dashboard</h2><p class="muted">A cleaner visual summary of revenue, cost pressure, marketing spend, and final surplus.</p></div></div>';
        echo '<div class="visual-summary-grid">';
        foreach ([
            ['Net sales', '₹' . money($netSales), 'Revenue after returns and tax', 'positive'],
            ['Final result', '₹' . money($netSurplus), number_fmt($netRatio) . '% of net sales', $netSurplus < 0 ? 'negative' : 'positive'],
            ['Marketing load', number_fmt($marketingRatio) . '%', '₹' . money($marketing) . ' spend', 'neutral'],
            ['Product cost load', number_fmt($productCostRatio) . '%', '₹' . money($productCosts) . ' COGS + packaging', 'neutral'],
            ['Gross margin', '₹' . money($grossMargin), 'After marketing and product cost', $grossMargin < 0 ? 'negative' : 'positive'],
        ] as [$label, $value, $note, $class]) {
            echo '<div class="visual-summary-card ' . e($class) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong><small>' . e($note) . '</small></div>';
        }
        echo '</div>';
        echo '<div class="chart-grid">';
        $this->donutChart('Cost split', $costSplit);
        $this->donutChart('Sales usage', $resultSplit);
        $this->barChart('Platform net sales', $platforms, 'platform', 'net', 'gross_profit', 'Gross profit');
        $this->barChart('Category gross profit', $categories, 'category', 'gross_profit', 'revenue', 'Revenue');
        echo '</div></section>';
    }

    private function expenseBreakdown(int $runId): array
    {
        return $this->db->fetchAll(
            "SELECT pnl_category, COUNT(*) AS rows_count, SUM(amount) AS amount
             FROM profit_loss_entries
             WHERE run_id = ? AND pnl_category <> ''
             GROUP BY pnl_category
             ORDER BY FIELD(
                pnl_category,
                'Logistics',
                'Marketing',
                'G & A expenses',
                'Seller Fee',
                'Storage Charges',
                'Cold Storage Charges',
                'Packing',
                'Other support services',
                'Transactions Charges',
                'COGS',
                'Code incentive',
                'Muskaan jain',
                'Snell Business collective LLP'
             ), pnl_category",
            [$runId]
        );
    }

    private function marketingAllocation(int $runId): array
    {
        return $this->db->fetchAll(
            "SELECT COALESCE(NULLIF(product_category, ''), 'Unallocated Expenses') AS product_category, SUM(amount) AS amount, COUNT(*) AS rows_count
             FROM profit_loss_entries
             WHERE run_id = ? AND pnl_category = 'Marketing'
             GROUP BY COALESCE(NULLIF(product_category, ''), 'Unallocated Expenses')
             ORDER BY amount DESC",
            [$runId]
        );
    }

    private function expenseBreakdownView(array $expenses, array $marketingAllocation): void
    {
        echo '<section class="panel"><div class="section-title"><div><h2>Expense mapping view</h2><p class="muted">Built from Profit and loss column C. Product allocation comes from column D.</p></div></div>';
        echo '<div class="expense-map-grid">';
        echo '<div><h3>MIS categories</h3>';
        if (!$expenses) {
            echo '<p class="muted">No mapped expenses yet.</p>';
        } else {
            echo '<div class="map-list">';
            foreach ($expenses as $row) {
                echo '<div><span>' . e($row['pnl_category']) . '<small>' . e($row['rows_count']) . ' lines</small></span><b>₹' . money($row['amount']) . '</b></div>';
            }
            echo '</div>';
        }
        echo '</div>';

        echo '<div><h3>Marketing by product</h3>';
        if (!$marketingAllocation) {
            echo '<p class="muted">No product-level marketing allocation yet.</p>';
        } else {
            echo '<div class="map-list">';
            foreach ($marketingAllocation as $row) {
                echo '<div><span>' . e($row['product_category']) . '<small>' . e($row['rows_count']) . ' lines</small></span><b>₹' . money($row['amount']) . '</b></div>';
            }
            echo '</div>';
        }
        echo '</div></div></section>';
    }

    private function donutChart(string $title, array $segments): void
    {
        $segments = array_values(array_filter($segments, fn(array $segment): bool => (float) $segment[1] > 0));
        usort($segments, fn(array $a, array $b): int => (float) $b[1] <=> (float) $a[1]);
        if (count($segments) > 8) {
            $visible = array_slice($segments, 0, 7);
            $other = array_slice($segments, 7);
            $visible[] = [
                'Other',
                array_sum(array_map(fn(array $segment): float => (float) $segment[1], $other)),
                '#5c677d',
            ];
            $segments = $visible;
        }
        $total = array_sum(array_map(fn(array $segment): float => (float) $segment[1], $segments));
        echo '<div class="chart-card donut-card" data-chart="donut" data-reveal><div class="chart-card-head"><h3>' . e($title) . '</h3><span>Top drivers</span></div>';
        if ($total <= 0) {
            echo '<div class="empty-state compact"><strong>No chart data</strong><span>Import data or recalculate the run.</span></div></div>';
            return;
        }

        $radius = 44;
        $circumference = 2 * M_PI * $radius;
        $offset = 0.0;
        echo '<div class="donut-wrap"><svg class="donut" viewBox="0 0 120 120" role="img" aria-label="' . e($title) . '">';
        echo '<circle cx="60" cy="60" r="' . e((string) $radius) . '" fill="none" stroke="#edf2f7" stroke-width="18"></circle>';
        foreach ($segments as $segment) {
            [$label, $value, $color] = $segment;
            $length = ((float) $value / $total) * $circumference;
            echo '<circle data-chart-segment cx="60" cy="60" r="' . e((string) $radius) . '" fill="none" stroke="' . e($color) . '" stroke-width="18" stroke-linecap="butt" stroke-dasharray="' . e(number_format($length, 3, '.', '')) . ' ' . e(number_format($circumference - $length, 3, '.', '')) . '" stroke-dashoffset="' . e(number_format(-$offset, 3, '.', '')) . '"></circle>';
            $offset += $length;
        }
        echo '<text x="60" y="56" text-anchor="middle">₹' . e($this->shortMoney($total)) . '</text><text x="60" y="72" text-anchor="middle">total</text></svg>';
        echo '<div class="chart-legend">';
        foreach ($segments as $segment) {
            [$label, $value, $color] = $segment;
            $percentage = $total > 0 ? ((float) $value / $total) * 100 : 0;
            echo '<div><i style="background:' . e($color) . '"></i><span>' . e($label) . '</span><b>₹' . money($value) . '</b><em>' . number_fmt($percentage) . '%</em></div>';
        }
        echo '</div></div></div>';
    }

    private function barChart(string $title, array $rows, string $labelKey, string $amountKey, string $subAmountKey, string $subLabel = 'Secondary', bool $subMoney = true): void
    {
        $rows = array_values(array_slice($rows, 0, 8));
        $max = 0.0;
        foreach ($rows as $row) {
            $max = max($max, abs((float) ($row[$amountKey] ?? 0)));
        }
        echo '<div class="chart-card wide" data-chart="bar" data-reveal><h3>' . e($title) . '</h3>';
        if ($max <= 0) {
            echo '<div class="empty-state compact"><strong>No chart data</strong><span>Import data or recalculate the run.</span></div></div>';
            return;
        }
        echo '<div class="modern-bars">';
        foreach ($rows as $row) {
            $amount = (float) ($row[$amountKey] ?? 0);
            $sub = (float) ($row[$subAmountKey] ?? 0);
            $width = min(100, abs($amount) / $max * 100);
            $class = $amount < 0 ? 'negative' : 'positive';
            $subDisplay = $subMoney ? '₹' . money($sub) : number_fmt($sub);
            echo '<div class="modern-bar-row"><div class="bar-label"><strong>' . e($row[$labelKey] ?? '') . '</strong><small>' . e($subLabel) . ': ' . e($subDisplay) . '</small></div>';
            echo '<div class="bar-track"><i class="' . $class . '" data-bar-fill style="--target-width:' . e(number_format($width, 2, '.', '')) . '%;width:' . e(number_format($width, 2, '.', '')) . '%"></i></div>';
            echo '<b>₹' . money($amount) . '</b></div>';
        }
        echo '</div></div>';
    }

    private function shortMoney(float $value): string
    {
        $abs = abs($value);
        if ($abs >= 10000000) {
            return number_fmt($value / 10000000) . 'Cr';
        }
        if ($abs >= 100000) {
            return number_fmt($value / 100000) . 'L';
        }
        if ($abs >= 1000) {
            return number_fmt($value / 1000) . 'K';
        }
        return number_fmt($value);
    }

    private function profitBridge(array $overview): void
    {
        echo '<section class="panel"><div class="section-title"><div><h2>Simple profit bridge</h2><p class="muted">Calculated in the backend from sales tabs, Profit and loss mappings, and COGS Cal.</p></div></div>';
        if (!$overview) {
            echo '<p class="muted">No MIS bridge yet. Recalculate after importing the workbook.</p></section>';
            return;
        }
        $sections = [];
        foreach ($overview as $line) {
            $sections[$line['section']][] = $line;
        }
        echo '<div class="bridge-grid">';
        foreach ($sections as $section => $lines) {
            echo '<div class="bridge-section"><h3>' . e($section) . '</h3>';
            foreach ($lines as $line) {
                $amount = (float) $line['amount'];
                $ratio = $line['ratio'] === null ? '' : number_fmt((float) $line['ratio'] * 100) . '%';
                echo '<div class="bridge-row ' . ($amount < 0 ? 'cost' : 'income') . '"><span><strong>' . e($line['line_item']) . '</strong>';
                if (($line['note'] ?? '') !== '') {
                    echo '<small>' . e($line['note']) . '</small>';
                }
                echo '</span><b>₹' . money($amount) . '</b><em>' . e($ratio) . '</em></div>';
            }
            echo '</div>';
        }
        echo '</div></section>';
    }

    private function platformCards(int $runId): array
    {
        $platforms = $this->db->fetchAll(
            "SELECT platform,
                    COUNT(DISTINCT NULLIF(order_id, '')) AS orders,
                    SUM(quantity) AS quantity,
                    SUM(CASE WHEN quantity > 0 THEN gross_amount ELSE 0 END) AS sales,
                    SUM(CASE WHEN quantity < 0 THEN -ABS(gross_amount) ELSE 0 END) AS returns,
                    SUM(tax_amount) AS tax,
                    SUM(net_revenue) AS net
             FROM import_rows
             WHERE run_id = ?
             GROUP BY platform
             ORDER BY net DESC",
            [$runId]
        );
        $profits = [];
        foreach ($this->db->fetchAll('SELECT platform, SUM(cogs + packaging) AS product_cost, SUM(gross_profit) AS gross_profit FROM mis_sku_summary WHERE run_id = ? GROUP BY platform', [$runId]) as $row) {
            $profits[$row['platform']] = $row;
        }
        foreach ($platforms as &$platform) {
            $profit = $profits[$platform['platform']] ?? ['product_cost' => 0, 'gross_profit' => 0];
            $platform['product_cost'] = (float) ($profit['product_cost'] ?? 0);
            $platform['gross_profit'] = (float) ($profit['gross_profit'] ?? 0);
            $net = (float) ($platform['net'] ?? 0);
            $platform['margin'] = abs($net) > 0.00001 ? (float) $platform['gross_profit'] / $net : 0;
        }
        unset($platform);
        return $platforms;
    }

    private function platformCardsView(array $platforms): void
    {
        echo '<section class="panel"><div class="section-title"><div><h2>Platform view</h2><p class="muted">Each card is the platform result after returns, tax, COGS and packaging.</p></div></div>';
        if (!$platforms) {
            echo '<p class="muted">No platform data yet.</p></section>';
            return;
        }
        echo '<div class="platform-card-grid">';
        foreach ($platforms as $row) {
            echo '<article class="platform-card-mini"><div><h3>' . e($row['platform']) . '</h3><span>' . e($row['orders']) . ' orders</span></div>';
            echo '<strong>₹' . money($row['net']) . '</strong>';
            echo '<dl><dt>Returns</dt><dd>₹' . money($row['returns']) . '</dd><dt>Product cost</dt><dd>₹' . money($row['product_cost']) . '</dd><dt>Gross profit</dt><dd>₹' . money($row['gross_profit']) . '</dd><dt>Margin</dt><dd>' . number_fmt((float) $row['margin'] * 100) . '%</dd></dl>';
            echo '</article>';
        }
        echo '</div></section>';
    }

    private function categoryPerformance(int $runId): array
    {
        return $this->db->fetchAll(
            'SELECT category,
                    SUM(quantity) AS quantity,
                    SUM(revenue) AS revenue,
                    SUM(cogs + packaging) AS product_cost,
                    SUM(gross_profit) AS gross_profit,
                    CASE WHEN ABS(SUM(revenue)) > 0.00001 THEN SUM(gross_profit) / SUM(revenue) ELSE 0 END AS margin
             FROM mis_sku_summary
             WHERE run_id = ?
             GROUP BY category
             ORDER BY revenue DESC',
            [$runId]
        );
    }

    private function categoryPerformanceView(array $categories): void
    {
        echo '<section class="panel"><div class="section-title"><div><h2>Product category view</h2><p class="muted">Backend COGS and packaging are already applied here.</p></div></div>';
        if (!$categories) {
            echo '<p class="muted">No category data yet.</p></section>';
            return;
        }
        echo '<div class="table-wrap"><table><thead><tr><th>Category</th><th>Qty</th><th>Revenue</th><th>COGS + Packaging</th><th>Gross Profit</th><th>Margin</th></tr></thead><tbody>';
        foreach ($categories as $row) {
            echo '<tr><td>' . e($row['category']) . '</td><td>' . number_fmt($row['quantity']) . '</td><td>₹' . money($row['revenue']) . '</td><td>₹' . money($row['product_cost']) . '</td><td>₹' . money($row['gross_profit']) . '</td><td>' . number_fmt((float) $row['margin'] * 100) . '%</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function reviewData(int $runId): array
    {
        $platform = trim((string) ($_GET['platform'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));
        $where = ['run_id = ?'];
        $params = [$runId];
        if ($platform !== '') {
            $where[] = 'platform = ?';
            $params[] = $platform;
        }
        if ($category !== '') {
            $where[] = 'category = ?';
            $params[] = $category;
        }
        if ($search !== '') {
            $where[] = '(product_name LIKE ? OR order_id LIKE ? OR cogs_sku LIKE ? OR mis_sku LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);

        return [
            'filters' => ['platform' => $platform, 'category' => $category, 'q' => $search],
            'platforms' => $this->db->fetchAll('SELECT DISTINCT platform FROM import_rows WHERE run_id = ? ORDER BY platform', [$runId]),
            'categories' => $this->db->fetchAll('SELECT DISTINCT category FROM import_rows WHERE run_id = ? ORDER BY category', [$runId]),
            'totals' => $this->db->fetch("SELECT COUNT(*) AS rows_count, SUM(quantity) AS quantity, SUM(gross_amount) AS gross, SUM(taxable_amount) AS taxable, SUM(tax_amount) AS tax, SUM(net_revenue) AS net FROM import_rows WHERE {$sqlWhere}", $params),
            'rows' => $this->db->fetchAll("SELECT platform, order_date, order_id, product_name, category, quantity, gross_amount, tax_amount, net_revenue, transaction_type FROM import_rows WHERE {$sqlWhere} ORDER BY id DESC LIMIT 250", $params),
        ];
    }

    private function reviewTable(array $run, array $review, bool $open = false): void
    {
        $filters = $review['filters'];
        echo '<details class="panel review-details"' . ($open ? ' open' : '') . '><summary>Detailed sales rows and filters</summary><div class="review-body"><p class="muted">Use this only when you need to audit individual imported rows.</p>';
        echo '<form class="filters" method="get" action="' . e(route_url('/mis/preview')) . '">';
        echo '<input type="hidden" name="run_id" value="' . e($run['id']) . '">';
        echo '<label>Platform<select name="platform"><option value="">All platforms</option>';
        foreach ($review['platforms'] as $row) {
            $selected = $filters['platform'] === $row['platform'] ? ' selected' : '';
            echo '<option value="' . e($row['platform']) . '"' . $selected . '>' . e($row['platform']) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Category<select name="category"><option value="">All categories</option>';
        foreach ($review['categories'] as $row) {
            $selected = $filters['category'] === $row['category'] ? ' selected' : '';
            echo '<option value="' . e($row['category']) . '"' . $selected . '>' . e($row['category']) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Search<input name="q" value="' . e($filters['q']) . '" placeholder="Order, product, SKU"></label><button>Apply filters</button><a class="ghost" href="' . e(route_url('/mis/preview', ['run_id' => $run['id']])) . '">Clear</a></form>';

        $totals = $review['totals'] ?? [];
        echo '<div class="stats compact"><div><span>Filtered Rows</span><strong>' . e($totals['rows_count'] ?? 0) . '</strong></div><div><span>Gross</span><strong>₹' . money($totals['gross'] ?? 0) . '</strong></div><div><span>Net</span><strong>₹' . money($totals['net'] ?? 0) . '</strong></div></div>';
        echo '<div class="table-wrap"><table><thead><tr><th>Platform</th><th>Date</th><th>Order</th><th>Product</th><th>Category</th><th>Qty</th><th>Gross</th><th>Tax</th><th>Net</th><th>Type</th></tr></thead><tbody>';
        foreach ($review['rows'] as $row) {
            echo '<tr><td>' . e($row['platform']) . '</td><td>' . e($row['order_date']) . '</td><td>' . e($row['order_id']) . '</td><td>' . e($row['product_name']) . '</td><td>' . e($row['category']) . '</td><td>' . number_fmt($row['quantity']) . '</td><td>' . money($row['gross_amount']) . '</td><td>' . money($row['tax_amount']) . '</td><td>' . money($row['net_revenue']) . '</td><td>' . e($row['transaction_type']) . '</td></tr>';
        }
        echo '</tbody></table></div><p class="muted">Showing latest 250 matching rows. Excel export includes all imported rows.</p></div></details>';
    }

    private function subpageIntro(string $title, string $summary, string $note): void
    {
        echo '<section class="subpage-intro panel" data-reveal><div><span>Focused view</span><h2>' . e($title) . '</h2><p>' . e($summary) . '</p></div><strong>' . e($note) . '</strong></section>';
    }

    private function stickyReportActions(array $run, array $readiness, string $view): void
    {
        echo '<div class="sticky-action-bar"><div><span>MIS action bar</span><strong>' . e(ucwords(str_replace('-', ' ', $view))) . '</strong><small>' . e($readiness['summary']) . '</small></div><div class="actions">';
        echo '<form method="post" action="' . e(route_url('/mis/recalculate')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><button class="ghost tiny">Recalculate</button></form>';
        if ((int) ($run['locked'] ?? 0) === 1) {
            echo '<form method="post" action="' . e(route_url('/runs/unlock')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><button class="ghost tiny">Unlock</button></form>';
        } else {
            echo '<form method="post" action="' . e(route_url('/runs/finalize')) . '"><input type="hidden" name="run_id" value="' . e($run['id']) . '"><button class="tiny">Finalize & Lock</button></form>';
        }
        echo '<a class="button tiny" href="' . e(route_url('/mis/export', ['run_id' => $run['id']])) . '">' . ($readiness['ready'] ? 'Export Excel' : 'Export with warnings') . '</a></div></div>';
    }

    private function runReadiness(array $run): array
    {
        $runId = (int) $run['id'];
        $stats = $this->runStats($runId);
        $files = $this->db->fetchAll('SELECT source_type, COUNT(*) AS files_count, SUM(rows_imported) AS rows_count, MAX(uploaded_at) AS last_imported FROM source_files WHERE run_id = ? GROUP BY source_type', [$runId]);
        $fileMap = [];
        foreach ($files as $file) {
            $fileMap[$file['source_type']] = $file;
        }
        $pnlRows = (int) (($this->db->fetch('SELECT COUNT(*) AS count FROM profit_loss_entries WHERE run_id = ?', [$runId]) ?: [])['count'] ?? 0);
        $cogsRows = (int) (($this->db->fetch('SELECT COUNT(*) AS count FROM product_costs') ?: [])['count'] ?? 0);
        $salesRows = (int) (($this->db->fetch('SELECT COUNT(*) AS count FROM import_rows WHERE run_id = ?', [$runId]) ?: [])['count'] ?? 0);

        $sources = [];
        foreach ($this->sourceTypes() as $key => $label) {
            if ($key === 'sample_workbook') {
                continue;
            }
            $file = $fileMap[$key] ?? null;
            $rows = (int) ($file['rows_count'] ?? 0);
            $sources[$key] = [
                'label' => $label,
                'done' => $rows > 0,
                'note' => $rows > 0 ? number_fmt($rows) . ' rows imported' : 'No rows imported yet',
                'last_imported' => $file['last_imported'] ?? '',
            ];
        }
        $sources['profit_loss'] = [
            'label' => 'Profit and loss mapping',
            'done' => $pnlRows > 0,
            'note' => $pnlRows > 0 ? number_fmt($pnlRows) . ' mapped rows from column C' : 'Upload full MIS workbook or P&L sheet',
            'last_imported' => '',
        ];
        $sources['cogs'] = [
            'label' => 'COGS / inventory cost sheet',
            'done' => $cogsRows > 0,
            'note' => $cogsRows > 0 ? number_fmt($cogsRows) . ' cost rows available' : 'Upload COGS Cal. sheet',
            'last_imported' => '',
        ];

        $checks = [
            ['label' => 'Sales reports imported', 'done' => $salesRows > 0, 'note' => $salesRows > 0 ? number_fmt($salesRows) . ' sales rows' : 'Import marketplace reports first', 'action' => '/auto-import'],
            ['label' => 'P&L column C mapped', 'done' => $pnlRows > 0, 'note' => $pnlRows > 0 ? number_fmt($pnlRows) . ' rows mapped' : 'Upload workbook with Profit and loss sheet', 'action' => '/imports/new'],
            ['label' => 'COGS sheet ready', 'done' => $cogsRows > 0, 'note' => $cogsRows > 0 ? number_fmt($cogsRows) . ' COGS rows' : 'Upload COGS Cal. sheet', 'action' => '/imports/new'],
            ['label' => 'Validation clear', 'done' => $stats['issues'] === 0, 'note' => $stats['issues'] . ' open issue(s)', 'action' => '/validation'],
            ['label' => 'Run finalized', 'done' => (int) ($run['locked'] ?? 0) === 1, 'note' => (string) ($run['status'] ?? 'draft'), 'action' => '/mis/preview'],
        ];
        $ready = $salesRows > 0 && $pnlRows > 0 && $cogsRows > 0 && $stats['issues'] === 0;
        $missing = array_values(array_filter($sources, fn(array $source): bool => empty($source['done'])));
        $openChecks = count(array_filter($checks, fn(array $check): bool => empty($check['done'])));

        $locked = (int) ($run['locked'] ?? 0) === 1;
        return [
            'ready' => $ready,
            'score' => count(array_filter($checks, fn(array $check): bool => !empty($check['done']))),
            'total' => count($checks),
            'status' => $ready ? ($locked ? 'Ready to export' : 'Ready to finalize') : 'Export has warnings',
            'summary' => $ready ? ($locked ? 'All required data is in place.' : 'Data checks are clear. Review and finalize when approved.') : $openChecks . ' close step(s) still need attention.',
            'checks' => $checks,
            'sources' => $sources,
            'missing' => $missing,
        ];
    }

    private function readinessPanel(array $readiness, array $runParams): void
    {
        echo '<section class="readiness-panel ' . ($readiness['ready'] ? 'ready' : 'warning') . '"><div class="readiness-head"><div><span>Readiness</span><strong>' . e($readiness['status']) . '</strong><small>' . e($readiness['summary']) . '</small></div><b>' . e($readiness['score']) . '/' . e($readiness['total']) . '</b></div>';
        echo '<div class="readiness-checks">';
        foreach ($readiness['checks'] as $check) {
            $href = route_url((string) $check['action'], $runParams);
            echo '<a class="' . (!empty($check['done']) ? 'done' : 'pending') . '" href="' . e($href) . '"><b>' . (!empty($check['done']) ? 'Done' : 'Action') . '</b><span><strong>' . e($check['label']) . '</strong><small>' . e($check['note']) . '</small></span></a>';
        }
        echo '</div></section>';
    }

    private function monthTrend(array $run): array
    {
        $previous = $this->db->fetch('SELECT * FROM monthly_runs WHERE month < ? ORDER BY month DESC, id DESC LIMIT 1', [$run['month']]);
        if (!$previous) {
            return [];
        }
        $current = $this->overviewAmounts((int) $run['id']);
        $prior = $this->overviewAmounts((int) $previous['id']);
        $rows = [];
        foreach ([
            'Net sales after tax' => 'Net sales',
            'Gross margin after COGS' => 'Gross margin',
            'Net surplus / burn' => 'Net result',
        ] as $line => $label) {
            $currentValue = (float) ($current[$line] ?? 0);
            $previousValue = (float) ($prior[$line] ?? 0);
            $rows[] = [
                'label' => $label,
                'current' => $currentValue,
                'previous' => $previousValue,
                'delta' => $currentValue - $previousValue,
            ];
        }
        return $rows;
    }

    private function overviewAmounts(int $runId): array
    {
        $amounts = [];
        foreach ($this->db->fetchAll('SELECT line_item, amount FROM mis_overview_lines WHERE run_id = ?', [$runId]) as $row) {
            $amounts[$row['line_item']] = (float) $row['amount'];
        }
        return $amounts;
    }

    private function friendlyJobStatus(string $status): string
    {
        return match ($status) {
            'queued' => 'Waiting to start',
            'running' => 'Fetching reports',
            'importing' => 'Importing captured files',
            'completed' => 'Import completed',
            'needs_attention' => 'Needs action',
            'failed' => 'Could not complete',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function friendlyJobEvents(array $events): array
    {
        $friendly = [];
        foreach (array_slice($events, 0, 5) as $event) {
            $message = (string) ($event['message'] ?? '');
            $level = (string) ($event['level'] ?? 'info');
            $title = match (true) {
                str_contains($message, 'Login') || str_contains($message, 'OTP') => 'Login needed',
                str_contains($message, 'Imported') => 'Imported',
                str_contains($message, 'Downloaded') || str_contains($message, 'captured') => 'Report captured',
                str_contains($message, 'No files') || str_contains($message, 'No report') => 'Needs action',
                $level === 'error' => 'Could not complete',
                default => ucfirst($level),
            };
            $simple = $message;
            if (str_contains($message, 'Node runner exited')) {
                $simple = 'Automation could not start. Retry after setup is available.';
            } elseif (str_contains($message, 'Browser runner failed') || str_contains($message, 'Browser runner crashed')) {
                $simple = 'Chrome automation stopped before a report was captured.';
            } elseif (str_contains($message, 'No reliable export button')) {
                $simple = 'Use the portal export button in Chrome; the app will capture the file.';
            }
            $friendly[] = [
                'class' => in_array($level, ['error', 'warning'], true) ? 'pending' : 'done',
                'title' => $title,
                'message' => $simple,
            ];
        }
        return $friendly;
    }

    private function summaryTable(string $title, array $rows, array $columns): void
    {
        echo '<section class="panel"><h2>' . e($title) . '</h2>';
        if (!$rows) {
            echo '<div class="empty-state"><strong>No data yet</strong><span>Upload reports or recalculate this run to populate this section.</span></div></section>';
            return;
        }
        echo '<div class="table-wrap"><table><thead><tr>';
        foreach ($columns as $label) {
            echo '<th>' . e($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $key => $label) {
                $value = $row[$key] ?? '';
                echo '<td>' . (is_numeric($value) ? e(number_fmt($value)) : e($value)) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function createRun(): void
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $_POST['month'] ?? '') ? $_POST['month'] : date('Y-m');
        $this->db->execute('INSERT INTO monthly_runs (month, status, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()', [$month, 'draft']);
        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE month = ?', [$month]);
        $this->redirect('/imports/new', ['month' => $month, 'run_id' => $run['id']]);
    }

    private function runAutoImport(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$runId]);
        if (!$run) {
            throw new RuntimeException('Monthly run not found.');
        }
        if ((int) ($run['locked'] ?? 0) === 1) {
            throw new RuntimeException('This run is finalized and locked. Unlock it before auto-importing.');
        }
        $activeJob = $this->db->fetch(
            'SELECT id FROM auto_import_jobs WHERE run_id = ? AND status IN ("queued", "running", "importing") ORDER BY id DESC LIMIT 1',
            [$runId]
        );
        if ($activeJob) {
            $this->redirect('/auto-import', ['run_id' => $runId, 'job_id' => $activeJob['id']]);
            return;
        }
        $service = new AutoImportService($this->db, $this->root);
        $jobId = $service->createJob($runId, $_POST['sources'] ?? [], (string) ($_POST['import_mode'] ?? 'replace'));
        $service->startJobInBackground($jobId);
        $this->redirect('/auto-import', ['run_id' => $runId, 'job_id' => $jobId]);
    }

    private function saveIntegration(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $provider = (string) ($_POST['provider'] ?? '');
        (new ApiIntegrationService($this->db, $this->root))->save($_POST);
        $this->redirect('/integrations', ['run_id' => $runId, 'provider' => $provider]);
    }

    private function disconnectIntegration(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $provider = (string) ($_POST['provider'] ?? '');
        (new ApiIntegrationService($this->db, $this->root))->disconnect($provider);
        $this->redirect('/integrations', ['run_id' => $runId, 'provider' => $provider]);
    }

    private function runApiImport(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$runId]);
        if (!$run) {
            throw new RuntimeException('Monthly run not found.');
        }
        if ((int) ($run['locked'] ?? 0) === 1) {
            throw new RuntimeException('This run is finalized and locked. Unlock it before importing sales.');
        }
        $provider = (string) ($_POST['provider'] ?? '');
        (new ApiIntegrationService($this->db, $this->root))->import($runId, $provider, (string) ($_POST['import_mode'] ?? 'replace'));
        $this->redirect('/sales', ['run_id' => $runId]);
    }

    private function stopAutoImport(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId > 0) {
            (new AutoImportService($this->db, $this->root))->stopJob($jobId);
        }
        $this->redirect('/auto-import', ['run_id' => $runId, 'job_id' => $jobId]);
    }

    private function cleanupAutoImportTemp(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        (new AutoImportService($this->db, $this->root))->cleanupTempReports();
        $this->redirect('/auto-import', ['run_id' => $runId]);
    }

    private function saveAutoImportSchedule(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$runId]);
        if (!$run) {
            throw new RuntimeException('Monthly run not found.');
        }
        $service = new AutoImportService($this->db, $this->root);
        $service->saveSchedule(
            $runId,
            isset($_POST['enabled']),
            (string) ($_POST['frequency'] ?? 'daily'),
            (string) ($_POST['run_time'] ?? '09:00'),
            $_POST['sources'] ?? [],
            (string) ($_POST['import_mode'] ?? 'replace')
        );
        $this->redirect('/auto-import', ['run_id' => $runId]);
    }

    private function uploadImport(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$runId]);
        if ($run && (int) ($run['locked'] ?? 0) === 1) {
            throw new RuntimeException('This run is finalized and locked. Unlock it before importing.');
        }
        $sourceType = (string) ($_POST['source_type'] ?? '');
        $importMode = (string) ($_POST['import_mode'] ?? 'replace');
        if (!isset($this->sourceTypes()[$sourceType])) {
            throw new RuntimeException('Invalid source type.');
        }
        if (!isset($_FILES['report']) || ($_FILES['report']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed.');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $_FILES['report']['name']);
        $destDir = $this->root . '/storage/uploads/' . $runId;
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            throw new RuntimeException('Unable to create upload folder.');
        }
        $dest = $destDir . '/' . date('Ymd_His') . '_' . $safeName;
        if (!move_uploaded_file($_FILES['report']['tmp_name'], $dest)) {
            throw new RuntimeException('Could not save uploaded report.');
        }
        @chmod($dest, 0666);

        $rows = (new Importer($this->db))->import($runId, $sourceType, $dest, $_FILES['report']['name'], $importMode);
        (new MisCalculator($this->db))->calculate($runId);
        $this->redirect('/imports/new', ['run_id' => $runId, 'imported' => $rows]);
    }

    private function saveInventoryItem(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        (new InventoryService($this->db))->saveItem(
            (int) ($_POST['id'] ?? 0),
            (string) ($_POST['sku'] ?? ''),
            (string) ($_POST['item_name'] ?? ''),
            (string) ($_POST['category'] ?? ''),
            (float) ($_POST['reorder_level'] ?? 0)
        );
        $this->redirect('/inventory', ['run_id' => $runId]);
    }

    private function saveWarehouse(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        (new InventoryService($this->db))->saveWarehouse(
            (int) ($_POST['id'] ?? 0),
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['location'] ?? '')
        );
        $this->redirect('/inventory', ['run_id' => $runId]);
    }

    private function saveInventoryMovement(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        (new InventoryService($this->db))->addMovement(
            (int) ($_POST['item_id'] ?? 0),
            (int) ($_POST['warehouse_id'] ?? 0),
            (string) ($_POST['movement_type'] ?? 'adjustment'),
            (float) ($_POST['quantity'] ?? 0),
            (float) ($_POST['unit_cost'] ?? 0),
            trim((string) ($_POST['reference'] ?? '')),
            trim((string) ($_POST['notes'] ?? ''))
        );
        $this->redirect('/inventory', ['run_id' => $runId]);
    }

    private function transferInventory(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        (new InventoryService($this->db))->transferStock(
            (int) ($_POST['item_id'] ?? 0),
            (int) ($_POST['from_warehouse_id'] ?? 0),
            (int) ($_POST['to_warehouse_id'] ?? 0),
            (float) ($_POST['quantity'] ?? 0),
            trim((string) ($_POST['reference'] ?? '')),
            trim((string) ($_POST['notes'] ?? ''))
        );
        $this->redirect('/inventory', ['run_id' => $runId]);
    }

    private function syncInventorySales(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $count = (new InventoryService($this->db))->syncSalesRun($runId, (int) ($_POST['warehouse_id'] ?? 0));
        $this->redirect('/inventory', ['run_id' => $runId, 'synced' => $count]);
    }

    private function mapValidationProduct(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $product = trim((string) ($_POST['product_name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        if ($product === '' || $category === '') {
            throw new RuntimeException('Product and category are required.');
        }
        $cost = $this->db->fetch('SELECT * FROM product_costs WHERE category = ? LIMIT 1', [$category]);
        $cogs = $cost['item_name'] ?? $category;
        $this->db->execute(
            'INSERT INTO sku_mappings (product_name, cogs_sku, mis_sku, category) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE cogs_sku = VALUES(cogs_sku), mis_sku = VALUES(mis_sku), category = VALUES(category)',
            [$product, $cogs, $category, $category]
        );
        $this->db->execute('UPDATE import_rows SET cogs_sku = ?, mis_sku = ?, category = ? WHERE run_id = ? AND product_name = ?', [$cogs, $category, $category, $runId, $product]);
        (new MisCalculator($this->db))->calculate($runId);
        $this->redirect('/validation', ['run_id' => $runId]);
    }

    private function saveAdjustment(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$runId]);
        if ($run && (int) ($run['locked'] ?? 0) === 1) {
            throw new RuntimeException('This run is locked.');
        }
        $type = in_array($_POST['adjustment_type'] ?? '', ['addition', 'deduction'], true) ? $_POST['adjustment_type'] : 'addition';
        $amount = abs((float) ($_POST['amount'] ?? 0));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($description === '' || $amount <= 0) {
            throw new RuntimeException('Adjustment description and positive amount are required.');
        }
        $this->db->execute(
            'INSERT INTO monthly_adjustments (run_id, adjustment_type, platform, category, description, amount, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$runId, $type, trim((string) ($_POST['platform'] ?? '')), trim((string) ($_POST['category'] ?? '')), $description, $amount]
        );
        (new MisCalculator($this->db))->calculate($runId);
        $this->redirect('/adjustments', ['run_id' => $runId]);
    }

    private function deleteAdjustment(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $id = (int) ($_POST['id'] ?? 0);
        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$runId]);
        if ($run && (int) ($run['locked'] ?? 0) === 1) {
            throw new RuntimeException('This run is locked.');
        }
        $this->db->execute('DELETE FROM monthly_adjustments WHERE run_id = ? AND id = ?', [$runId, $id]);
        (new MisCalculator($this->db))->calculate($runId);
        $this->redirect('/adjustments', ['run_id' => $runId]);
    }

    private function saveMasters(): void
    {
        $this->db->begin();
        try {
            $count = count($_POST['product_name'] ?? []);
            for ($i = 0; $i < $count; $i++) {
                $name = trim((string) ($_POST['product_name'][$i] ?? ''));
                if ($name === '') {
                    continue;
                }
                $id = (int) ($_POST['mapping_id'][$i] ?? 0);
                $params = [$name, trim((string) $_POST['cogs_sku'][$i]), trim((string) $_POST['mis_sku'][$i]), trim((string) $_POST['category'][$i])];
                if ($id) {
                    $this->db->execute('UPDATE sku_mappings SET product_name=?, cogs_sku=?, mis_sku=?, category=? WHERE id=?', [...$params, $id]);
                } else {
                    $this->db->execute('INSERT INTO sku_mappings (product_name, cogs_sku, mis_sku, category) VALUES (?, ?, ?, ?)', $params);
                }
            }

            $count = count($_POST['item_name'] ?? []);
            for ($i = 0; $i < $count; $i++) {
                $name = trim((string) ($_POST['item_name'][$i] ?? ''));
                if ($name === '') {
                    continue;
                }
                $id = (int) ($_POST['cost_id'][$i] ?? 0);
                $params = [$name, trim((string) $_POST['cost_category'][$i]), (float) $_POST['multiplier'][$i], (float) $_POST['purchase_price'][$i], (float) $_POST['packaging_rate'][$i]];
                if ($id) {
                    $this->db->execute('UPDATE product_costs SET item_name=?, category=?, multiplier=?, purchase_price=?, packaging_rate=? WHERE id=?', [...$params, $id]);
                } else {
                    $this->db->execute('INSERT INTO product_costs (item_name, category, multiplier, purchase_price, packaging_rate) VALUES (?, ?, ?, ?, ?)', $params);
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->redirect('/masters');
    }

    private function recalculate(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        (new MisCalculator($this->db))->calculate($runId);
        $this->redirect('/mis/preview', ['run_id' => $runId]);
    }

    private function finalizeRun(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        (new MisCalculator($this->db))->calculate($runId);
        $this->db->execute('UPDATE monthly_runs SET status = ?, locked = 1, finalized_at = NOW(), updated_at = NOW() WHERE id = ?', ['finalized', $runId]);
        $this->redirect('/mis/preview', ['run_id' => $runId]);
    }

    private function unlockRun(): void
    {
        $runId = (int) ($_POST['run_id'] ?? 0);
        $this->db->execute('UPDATE monthly_runs SET status = ?, locked = 0, updated_at = NOW() WHERE id = ?', ['calculated', $runId]);
        $this->redirect('/mis/preview', ['run_id' => $runId]);
    }

    private function export(): void
    {
        $run = $this->getRun();
        $path = (new ExcelExporter($this->db, $this->root . '/storage/exports'))->export((int) $run['id']);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    private function getRun(): array
    {
        $runId = (int) ($_GET['run_id'] ?? $_POST['run_id'] ?? 0);
        if ($runId) {
            $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$runId]);
            if ($run) {
                return $run;
            }
        }
        if (!isset($_GET['month']) && !isset($_POST['month'])) {
            $run = $this->defaultRun();
            if ($run) {
                return $run;
            }
        }
        $month = (string) ($_GET['month'] ?? $_POST['month'] ?? date('Y-m'));
        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE month = ?', [$month]);
        if (!$run) {
            $this->db->execute('INSERT INTO monthly_runs (month, status, created_at, updated_at) VALUES (?, ?, NOW(), NOW())', [$month, 'draft']);
            $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE month = ?', [$month]);
        }
        return $run;
    }

    private function defaultRun(): ?array
    {
        $run = $this->db->fetch(
            'SELECT mr.*
             FROM monthly_runs mr
             WHERE EXISTS (SELECT 1 FROM import_rows ir WHERE ir.run_id = mr.id)
             ORDER BY mr.month DESC, mr.id DESC
             LIMIT 1'
        );
        if ($run) {
            return $run;
        }
        return $this->db->fetch('SELECT * FROM monthly_runs ORDER BY month DESC, id DESC LIMIT 1');
    }

    private function navRunParams(): array
    {
        $runId = (int) ($_GET['run_id'] ?? $_POST['run_id'] ?? 0);
        if ($runId > 0) {
            return ['run_id' => $runId];
        }
        $run = $this->defaultRun();
        return $run ? ['run_id' => $run['id']] : [];
    }

    private function render(string $title, callable $body): void
    {
        $navRun = $this->navRunParams();
        $currentRun = isset($navRun['run_id'])
            ? $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$navRun['run_id']])
            : null;
        $currentRun ??= $this->defaultRun();
        $currentPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $scriptBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        if ($scriptBase !== '' && $scriptBase !== '.' && $scriptBase !== '/' && str_starts_with($currentPath, $scriptBase)) {
            $currentPath = substr($currentPath, strlen($scriptBase)) ?: '/';
        }
        $navLink = static function (string $path, string $label, array $params = [], array $also = []) use ($currentPath): string {
            $paths = array_merge([$path], $also);
            $active = false;
            foreach ($paths as $candidate) {
                if ($candidate === '/' ? in_array($currentPath, ['/', '/index.php'], true) : str_starts_with($currentPath, $candidate)) {
                    $active = true;
                    break;
                }
            }
            return '<a class="sidebar-link ' . ($active ? 'active' : '') . '" href="' . e(route_url($path, $params)) . '"><span>' . e($label) . '</span></a>';
        };
        $runParams = $currentRun ? ['run_id' => $currentRun['id']] : $navRun;
        $runStatus = $currentRun ? ((int) ($currentRun['locked'] ?? 0) === 1 ? 'Locked' : ucfirst((string) ($currentRun['status'] ?? 'draft'))) : 'No run';
        $shellTitle = $title === 'MIS Tool' ? 'Command Center' : $title;

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . e($title) . '</title><link rel="stylesheet" href="' . e(route_url('/assets/styles.css')) . '"></head><body class="app-body">';
        echo '<button class="mobile-nav-toggle" type="button" data-nav-toggle>Menu</button><div class="sidebar-scrim" data-nav-close></div>';
        echo '<div class="app-shell"><aside class="app-sidebar" data-sidebar>';
        echo '<a class="sidebar-brand" href="' . e(route_url('/')) . '"><span class="brand-mark">MIS</span><span><strong>MIS Tool</strong><small>Finance operations workspace</small></span></a>';
        echo '<div class="sidebar-run"><span>Active run</span><strong>' . e($currentRun['month'] ?? 'Create run') . '</strong><small>' . e($runStatus) . '</small></div>';
        echo '<nav class="sidebar-nav">';
        echo '<div class="sidebar-group"><span>Command Center</span>' . $navLink('/', 'Dashboard', $runParams, ['/dashboard']) . $navLink('/dashboard/close', 'Close Board', $runParams) . $navLink('/dashboard/trends', 'Trends', $runParams) . '</div>';
        echo '<div class="sidebar-group"><span>Import</span>' . $navLink('/auto-import', 'Sources', $runParams, ['/imports/sources']) . $navLink('/integrations', 'API Connect', $runParams) . $navLink('/imports/activity', 'Activity', $runParams) . $navLink('/imports/manual', 'Manual Upload', $runParams, ['/imports/new']) . '</div>';
        echo '<div class="sidebar-group"><span>Operations</span>' . $navLink('/sales', 'Sales', $runParams) . $navLink('/inventory', 'Inventory', $runParams) . $navLink('/mis/preview', 'MIS Report', $runParams, ['/mis/charts', '/mis/profit-bridge', '/mis/platforms', '/mis/categories', '/mis/audit']) . '</div>';
        echo '<div class="sidebar-group"><span>Reports</span>' . $navLink('/reports', 'Reports', $runParams) . $navLink('/reports/executive', 'Executive', $runParams) . $navLink('/reports/loss-watch', 'Loss Watch', $runParams) . '</div>';
        echo '<div class="sidebar-group"><span>Settings</span>' . $navLink('/validation', 'Validation', $runParams) . $navLink('/adjustments', 'Adjustments', $runParams) . $navLink('/masters', 'Masters') . '</div>';
        echo '</nav></aside><div class="app-main"><header class="workspace-topbar"><div><span>Workspace</span><strong>' . e($shellTitle) . '</strong></div><div class="workspace-actions">';
        if ($currentRun) {
            echo '<form method="post" action="' . e(route_url('/mis/recalculate')) . '"><input type="hidden" name="run_id" value="' . e($currentRun['id']) . '"><button class="ghost tiny">Recalculate</button></form>';
            echo '<a class="button tiny" href="' . e(route_url('/mis/preview', ['run_id' => $currentRun['id']])) . '">Review MIS</a>';
        }
        echo '</div></header><main class="workspace-content">';
        $body();
        echo '</main></div></div><script src="' . e(route_url('/assets/app.js')) . '"></script></body></html>';
    }

    private function redirect(string $path, array $params = []): void
    {
        header('Location: ' . route_url($path, $params));
        exit;
    }

    private function notFound(string $path): void
    {
        http_response_code(404);
        $this->render('Not found', fn() => print '<section class="panel"><h1>Not found</h1><p>' . e($path) . '</p></section>');
    }

    private function sourceTypes(): array
    {
        return [
            'sample_workbook' => 'Full MIS workbook',
            'flipkart' => 'Flipkart report',
            'blinkit' => 'Blinkit billing report',
            'easecommerce' => 'EaseCommerce sales workbook',
            'amazon_b2c' => 'Amazon MTR B2C',
            'amazon_b2b' => 'Amazon MTR B2B',
            'amazon_str' => 'Amazon STR',
            'mcf_sales' => 'MCF Sales',
            'website_sales' => 'Website Sales',
            'website_mcf_returns' => 'Website MCF Returns',
        ];
    }

    private function requiredColumns(string $sourceType): string
    {
        return match ($sourceType) {
            'flipkart' => 'Needs Order ID, Product Title/Description, Item Quantity, invoice amount columns.',
            'blinkit' => 'Needs Order ID, Product Name, Quantity, Selling Price columns.',
            'amazon_b2c', 'amazon_b2b' => 'Needs Order Id, Item Description, Quantity, Invoice Amount.',
            'amazon_str' => 'Needs Order/Transaction ID, item/SKU, amount or tax fields.',
            'mcf_sales' => 'Needs Order Id, Item name, Quantity, Taxable Value.',
            'website_sales' => 'Needs Order Number, Product Name, Quantity, Taxable Amount.',
            'website_mcf_returns' => 'Needs Credit Note Number, Item Name, Quantity, Item Total.',
            default => 'Imports sales tabs plus Profit and loss column C mappings and COGS Cal. inventory costs.',
        };
    }

    private function runStats(int $runId): array
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS row_count, SUM(net_revenue) AS net, SUM(CASE WHEN quantity < 0 THEN gross_amount ELSE 0 END) AS returns FROM import_rows WHERE run_id = ?",
            [$runId]
        ) ?: [];
        $issues = $this->db->fetch('SELECT COUNT(*) AS count FROM validation_issues WHERE run_id = ? AND status = "open" AND severity IN ("error", "warning")', [$runId]) ?: [];
        return [
            'rows' => (int) ($row['row_count'] ?? 0),
            'net' => (float) ($row['net'] ?? 0),
            'returns' => (float) ($row['returns'] ?? 0),
            'issues' => (int) ($issues['count'] ?? 0),
        ];
    }

    private function portalLinks(): array
    {
        return [
            'Flipkart Report Centre' => 'https://seller.flipkart.com/index.html#dashboard/metrics/report-centre?query=%7B%22one_time_request%22%3A%7B%22reportGroup%22%3Anull%2C%22reportName%22%3Anull%2C%22enable%22%3Atrue%2C%22status%22%3Anull%7D%2C%22repeat_request%22%3A%7B%22repeat_report_group_name%22%3Anull%2C%22repeat_report_name%22%3Anull%2C%22repeat_enable%22%3Atrue%7D%2C%22pagination%22%3A%7B%22page_size%22%3A10%2C%22starting_page%22%3A1%7D%2C%22request_report%22%3A%7B%22create_request%22%3Afalse%2C%22report_type%22%3Anull%2C%22report_subtype%22%3Anull%2C%22repeat_report%22%3Afalse%7D%7D',
            'Blinkit Payout Details' => 'https://seller.blinkit.com/dashboard/billing?billing=payout_details',
            'EaseCommerce Sales Report' => 'https://easecommerce.in/app/employee/reports/sales-report',
        ];
    }
}
