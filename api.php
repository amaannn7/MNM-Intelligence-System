<?php
ob_start();
error_reporting(E_ALL & ~E_DEPRECATED);
/**
 * M&M Sales CRM — Backend API
 * Militzer & Münch Sales CRM for freight forwarding teams
 *
 * Run: php -S localhost:8000
 */

// ── Database layer ────────────────────────────────────────────────────────────
// Switch to db.local.php for local JSON-based testing; db.php for production.
define('LOCAL_MODE', file_exists(__DIR__ . '/local-data'));
require_once __DIR__ . (LOCAL_MODE ? '/db.local.php' : '/db.php');
// ─────────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json');
$_env = is_file(__DIR__ . '/.env.php') ? require __DIR__ . '/.env.php' : ['allowed_origins' => ['http://localhost:8000']];
$_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($_origin, $_env['allowed_origins'], true) ? $_origin : $_env['allowed_origins'][0]));
header('Vary: Origin');
header('Access-Control-Allow-Headers: Content-Type, X-User-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('DATA_DIR', __DIR__ . '/data/'); // kept for uploads subfolder only
// JSON file constants are kept so any leftover references don't break at parse time,
// but all actual reads/writes now go through PostgreSQL via db.php.
define('USERS_FILE',     DATA_DIR . 'users.json');
define('ADMIN_FILE',     DATA_DIR . 'admin.json');
define('LEADS_FILE',     DATA_DIR . 'leads.json');
define('EMAILS_FILE',    DATA_DIR . 'emails.json');
define('MEETINGS_FILE',  DATA_DIR . 'meetings.json');
define('TEMPLATES_FILE', DATA_DIR . 'templates.json');
define('AUDIT_FILE',     DATA_DIR . 'audit_log.json');
define('MS_TOKENS_FILE', DATA_DIR . 'ms_tokens.json');
define('TARGETS_FILE',   DATA_DIR . 'targets.json');

// Ensure uploads directory exists (files are still stored on disk)
if (!is_dir(DATA_DIR . 'uploads')) @mkdir(DATA_DIR . 'uploads', 0755, true);

const ACTIVE_STATUSES = ['new','qualified','researched','contacted','meeting_booked','proposal_sent','nurture'];
const SALES_STATUSES = ['new','qualified','researched','contacted','meeting_booked','proposal_sent','won','lost','nurture'];
const ALLOWED_ROLES = ['super_admin','admin','sales_rep'];
const TARGET_METRICS = ['meetings_weekly','won_value_monthly','proposals_monthly','overdue_hygiene'];

// ===== HELPERS =====
function respond($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }
function generateToken() { return bin2hex(random_bytes(32)); }
function generateId($prefix = '') { return $prefix . bin2hex(random_bytes(8)); }
function generatePassword() { $c = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'; $p = ''; for ($i = 0; $i < 10; $i++) $p .= $c[random_int(0, strlen($c) - 1)]; return $p; }

// ── Storage layer: maps old file-path constants to PostgreSQL tables ──────────
// Map from DATA_DIR/*.json paths to db table names.
// The file-path constants are kept above so parse-time references don't break,
// but no actual file I/O happens for these tables anymore.
function _fileToTable(string $file): ?string {
    static $map = null;
    if ($map === null) {
        $map = [
            'users.json'    => 'users',
            'leads.json'    => 'leads',
            'meetings.json' => 'meetings',
            'emails.json'   => 'emails',
            'templates.json'=> 'templates',
            'audit_log.json'=> 'audit_log',
            'ms_tokens.json'=> 'ms_tokens',
            'targets.json'  => 'targets',
        ];
    }
    $base = basename($file);
    return $map[$base] ?? null;
}

function loadJson(string $file): array {
    $table = _fileToTable($file);
    if ($table !== null) return dbLoadAll($table);
    // Fallback for any unmapped file (should not occur in normal use)
    if (!file_exists($file)) return [];
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : [];
}

function saveJson(string $file, array $data): void {
    $table = _fileToTable($file);
    if ($table !== null) { dbSaveAll($table, $data); return; }
    // Fallback for unmapped files
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Aliases so all existing call sites continue to work unchanged
function readJson($file): array { return loadJson($file); }
function writeJson($file, array $data): void { saveJson($file, $data); }

function normalizeRole(string $role): string {
    if ($role === 'manager') return 'admin';
    return in_array($role, ALLOWED_ROLES, true) ? $role : 'sales_rep';
}

function normalizeStatus(string $status): string {
    if ($status === 'negotiating') return 'nurture'; // stage removed — folded into Nurture
    return in_array($status, SALES_STATUSES, true) ? $status : 'new';
}

function isArchived(array $record): bool {
    return !empty($record['archived_at']);
}

function isActiveLead(array $lead): bool {
    return !isArchived($lead) && in_array(normalizeStatus($lead['status'] ?? 'new'), ACTIVE_STATUSES, true);
}

function normalizeAttendees($attendees): array {
    if (is_array($attendees)) {
        $items = $attendees;
    } else {
        $items = array_map('trim', explode(',', (string)$attendees));
    }
    $out = [];
    foreach ($items as $item) {
        $email = is_array($item) ? trim($item['email'] ?? '') : trim((string)$item);
        $name = is_array($item) ? trim($item['name'] ?? '') : '';
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[] = ['email' => strtolower($email), 'name' => $name ?: strtolower($email)];
        }
    }
    return array_values(array_unique($out, SORT_REGULAR));
}

function attendeeString($attendees): string {
    return implode(', ', array_map(fn($a) => $a['email'] ?? '', normalizeAttendees($attendees)));
}

function defaultNextAction(array $lead): array {
    $status = normalizeStatus($lead['status'] ?? 'new');
    $map = [
        'new' => ['research', 'Research company and contact'],
        'qualified' => ['research', 'Complete freight-specific research'],
        'researched' => ['email', 'Send first personalized outreach'],
        'contacted' => ['call', 'Call and confirm logistics fit'],
        'meeting_booked' => ['meeting', 'Prepare for scheduled meeting'],
        'proposal_sent' => ['follow_up', 'Follow up on proposal'],
        'nurture' => ['follow_up', 'Check whether timing has changed'],
    ];
    [$type, $note] = $map[$status] ?? ['follow_up', 'Set next step'];
    return ['type' => $type, 'note' => $note];
}

function normalizeLead(array $lead): array {
    $lead['status'] = normalizeStatus($lead['status'] ?? 'new');
    if (($lead['owner_id'] ?? '') === '') $lead['owner_id'] = '';
    if (!isset($lead['next_action_type']) || !isset($lead['next_action_due']) || !isset($lead['next_action_note'])) {
        $next = defaultNextAction($lead);
        $lead['next_action_type'] = $lead['next_action_type'] ?? ($lead['followup_date'] ? 'follow_up' : $next['type']);
        $lead['next_action_due'] = $lead['next_action_due'] ?? ($lead['followup_date'] ?? '');
        $lead['next_action_note'] = $lead['next_action_note'] ?? $next['note'];
    }
    $lead['stage_entered_at'] = $lead['stage_entered_at'] ?? ($lead['updated_at'] ?? ($lead['created_at'] ?? date('c')));
    $lead['proposal_value'] = $lead['proposal_value'] ?? ($lead['won_details']['value'] ?? '');
    $lead['proposal_sent_at'] = $lead['proposal_sent_at'] ?? '';
    $lead['nurture_until'] = $lead['nurture_until'] ?? '';
    $lead['freight_profile'] = $lead['freight_profile'] ?? ['trade_lanes'=>[],'cargo_types'=>[],'services_needed'=>[],'volume_estimate'=>'','incoterms'=>'','current_forwarder'=>''];
    $lead['meetings'] = $lead['meetings'] ?? [];
    $lead['call_logs'] = $lead['call_logs'] ?? [];
    $lead['email_history'] = $lead['email_history'] ?? [];
    $lead['requisites'] = $lead['requisites'] ?? [];
    $lead['notes_history'] = is_array($lead['notes_history'] ?? null) ? $lead['notes_history'] : [];
    // One-time migration: the old per-stage notes feature stored notes
    // keyed by stage in stage_notes; folded into the new running history
    // (each stage's note becomes one dated entry) so nothing already
    // written by a rep is lost when this field is retired.
    if (!empty($lead['stage_notes']) && is_array($lead['stage_notes'])) {
        foreach ($lead['stage_notes'] as $stage => $text) {
            if (trim((string)$text) === '') continue;
            $lead['notes_history'][] = [
                'id' => 'note_migrated_' . substr(md5($lead['id'] . $stage), 0, 10),
                'text' => $text . " (migrated from \"" . ($stage) . "\" stage notes)",
                'author' => 'System',
                'created_at' => $lead['updated_at'] ?? date('c'),
            ];
        }
        unset($lead['stage_notes']);
    }
    $lead['archived_at'] = $lead['archived_at'] ?? '';
    $lead['archived_by'] = $lead['archived_by'] ?? '';
    $lead['archive_reason'] = $lead['archive_reason'] ?? '';
    return $lead;
}

function normalizeMeeting(array $meeting): array {
    $meeting['attendees'] = normalizeAttendees($meeting['attendees'] ?? []);
    $meeting['client_email'] = $meeting['client_email'] ?? ($meeting['attendees'][0]['email'] ?? '');
    $meeting['calendar_event_id'] = $meeting['calendar_event_id'] ?? '';
    $meeting['calendar_web_link'] = $meeting['calendar_web_link'] ?? '';
    $meeting['teams_join_url'] = $meeting['teams_join_url'] ?? ($meeting['teams_link'] ?? '');
    $meeting['teams_link'] = $meeting['teams_link'] ?? ($meeting['teams_join_url'] ?? '');
    $meeting['calendar_sync_status'] = $meeting['calendar_sync_status'] ?? 'not_connected';
    $meeting['calendar_sync_error'] = $meeting['calendar_sync_error'] ?? '';
    $meeting['invite_sent_at'] = $meeting['invite_sent_at'] ?? '';
    $meeting['archived_at'] = $meeting['archived_at'] ?? '';
    $meeting['archived_by'] = $meeting['archived_by'] ?? '';
    $meeting['archive_reason'] = $meeting['archive_reason'] ?? '';
    return $meeting;
}

function normalizeUser(array $u): array {
    $u['role'] = normalizeRole($u['role'] ?? 'sales_rep');
    $u['title'] = $u['title'] ?? '';
    $u['username'] = strtolower(trim($u['username'] ?? ''));
    if ($u['username'] === '' && !empty($u['email'])) {
        $u['username'] = strtolower(strtok($u['email'], '@') ?: '');
    }
    $u['archived_at'] = $u['archived_at'] ?? '';
    $u['archived_by'] = $u['archived_by'] ?? '';
    $u['archive_reason'] = $u['archive_reason'] ?? '';
    $u['is_active'] = $u['is_active'] ?? true;
    return $u;
}

// Per-request users cache — avoids repeated DB round-trips within a single API call.
$_usersCache = null;

function getUsers(): array {
    global $_usersCache;
    if ($_usersCache !== null) return $_usersCache;
    $users = readJson(USERS_FILE);
    $changed = false;
    foreach ($users as &$u) {
        $before = $u;
        $u = normalizeUser($u);
        if ($u !== $before) $changed = true;
    }
    if ($changed) writeJson(USERS_FILE, $users);
    $_usersCache = $users;
    return $_usersCache;
}
function saveUsers(array $users): void {
    global $_usersCache;
    foreach ($users as &$u) $u = normalizeUser($u);
    writeJson(USERS_FILE, $users);
    $_usersCache = $users; // keep cache in sync so same request sees updated data
}
function getAdmin() {
    $a = kvGet('config', 'admin', []);
    if (empty($a) || !isset($a['default_provider'])) {
        $a = ['default_provider' => 'groq', 'groq_key' => '', 'gemini_key' => '', 'anthropic_key' => '',
               'sender_name' => '', 'sender_title' => '', 'sender_company' => 'Militzer & Münch',
               'company_description' => 'global freight forwarding and logistics services',
               'value_proposition' => 'Over 140 years of logistics expertise across Europe, Middle East, Central Asia, and Far East',
               'social_proof' => 'Trusted by companies across 30+ countries for cross-border freight solutions',
               'mm_footer' => "Militzer & Münch — Your global logistics partner since 1880.\nhttps://www.militzer-munch.com",
               'feature_flags' => ['ai_research' => true, 'ai_email' => true, 'csv_import' => true, 'sop_guides' => true, 'call_scripts' => true],
               'requisite_fields' => [
                   ['id' => 'budget', 'label' => 'Annual Freight Budget', 'type' => 'single', 'options' => ['<$50K', '$50K-$200K', '$200K-$500K', '$500K-$1M', '$1M+']],
                   ['id' => 'decision_timeline', 'label' => 'Decision Timeline', 'type' => 'single', 'options' => ['Immediate', '1-3 months', '3-6 months', '6-12 months', '12+ months']],
                   ['id' => 'shipping_frequency', 'label' => 'Shipping Frequency', 'type' => 'single', 'options' => ['Daily', 'Weekly', 'Bi-weekly', 'Monthly', 'Quarterly', 'Ad-hoc']],
                   ['id' => 'decision_makers', 'label' => 'Decision Makers Involved', 'type' => 'multi', 'options' => ['Logistics Manager', 'Procurement', 'CFO/Finance', 'CEO/MD', 'Operations Director', 'Supply Chain VP']],
                   ['id' => 'pain_areas', 'label' => 'Key Pain Areas', 'type' => 'multi', 'options' => ['Cost Reduction', 'Transit Times', 'Visibility/Tracking', 'Customs Compliance', 'Warehousing', 'Documentation', 'Insurance', 'Damage/Loss']],
                   ['id' => 'current_provider_satisfaction', 'label' => 'Current Provider Satisfaction', 'type' => 'single', 'options' => ['Very Dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very Satisfied']],
                   ['id' => 'contract_status', 'label' => 'Current Contract Status', 'type' => 'single', 'options' => ['No Contract', 'Month-to-month', 'Expiring Soon', 'Locked In (<1yr)', 'Locked In (1yr+)']],
                   ['id' => 'services_interested', 'label' => 'Services Interested In', 'type' => 'multi', 'options' => ['FCL', 'LCL', 'Air Freight', 'Road Transport', 'Rail', 'Customs Brokerage', 'Warehousing', 'Project Cargo', 'Dangerous Goods']],
               ],
               'ms_calendar' => ['enabled' => false, 'tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'redirect_uri' => '']];
    }
    // Ensure new keys exist with defaults
    if (!isset($a['requisite_fields'])) {
        $a['requisite_fields'] = [
            ['id' => 'budget', 'label' => 'Annual Freight Budget', 'type' => 'single', 'options' => ['<$50K', '$50K-$200K', '$200K-$500K', '$500K-$1M', '$1M+']],
            ['id' => 'decision_timeline', 'label' => 'Decision Timeline', 'type' => 'single', 'options' => ['Immediate', '1-3 months', '3-6 months', '6-12 months', '12+ months']],
            ['id' => 'shipping_frequency', 'label' => 'Shipping Frequency', 'type' => 'single', 'options' => ['Daily', 'Weekly', 'Bi-weekly', 'Monthly', 'Quarterly', 'Ad-hoc']],
            ['id' => 'decision_makers', 'label' => 'Decision Makers Involved', 'type' => 'multi', 'options' => ['Logistics Manager', 'Procurement', 'CFO/Finance', 'CEO/MD', 'Operations Director', 'Supply Chain VP']],
            ['id' => 'pain_areas', 'label' => 'Key Pain Areas', 'type' => 'multi', 'options' => ['Cost Reduction', 'Transit Times', 'Visibility/Tracking', 'Customs Compliance', 'Warehousing', 'Documentation', 'Insurance', 'Damage/Loss']],
            ['id' => 'current_provider_satisfaction', 'label' => 'Current Provider Satisfaction', 'type' => 'single', 'options' => ['Very Dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very Satisfied']],
            ['id' => 'contract_status', 'label' => 'Current Contract Status', 'type' => 'single', 'options' => ['No Contract', 'Month-to-month', 'Expiring Soon', 'Locked In (<1yr)', 'Locked In (1yr+)']],
            ['id' => 'services_interested', 'label' => 'Services Interested In', 'type' => 'multi', 'options' => ['FCL', 'LCL', 'Air Freight', 'Road Transport', 'Rail', 'Customs Brokerage', 'Warehousing', 'Project Cargo', 'Dangerous Goods']],
        ];
    }
    if (!isset($a['ms_calendar'])) {
        $a['ms_calendar'] = ['enabled' => false, 'tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'redirect_uri' => ''];
    } else {
        $a['ms_calendar'] = array_merge(['enabled' => false, 'tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'redirect_uri' => ''], $a['ms_calendar']);
    }
    return $a;
}
function saveAdmin($admin) { kvSet('config', 'admin', $admin); }
function getLeads() {
    $leads = readJson(LEADS_FILE);
    $normalized = array_map('normalizeLead', $leads);
    if ($normalized !== $leads) writeJson(LEADS_FILE, $normalized);
    return $normalized;
}
function saveLeads($leads) { writeJson(LEADS_FILE, array_map('normalizeLead', $leads)); }
function getEmails() { return readJson(EMAILS_FILE); }
function saveEmails($emails) { writeJson(EMAILS_FILE, $emails); }
function getMeetings() {
    $meetings = readJson(MEETINGS_FILE);
    $normalized = array_map('normalizeMeeting', $meetings);
    if ($normalized !== $meetings) writeJson(MEETINGS_FILE, $normalized);
    return $normalized;
}
function saveMeetings($meetings) { writeJson(MEETINGS_FILE, array_map('normalizeMeeting', $meetings)); }
function getMsTokens(): array { return kvGet('config', 'ms_tokens', []); }
function saveMsTokens($tokens): void { kvSet('config', 'ms_tokens', $tokens); }
function getTargets() { return readJson(TARGETS_FILE); }
function saveTargets(array $targets): void { writeJson(TARGETS_FILE, $targets); }
function getTemplates() {
    $t = readJson(TEMPLATES_FILE);
    if (empty($t)) {
        $t = getDefaultTemplates();
        writeJson(TEMPLATES_FILE, $t);
    }
    return $t;
}
function saveTemplates($templates) { writeJson(TEMPLATES_FILE, $templates); }

function targetMetricLabel(string $metric): string {
    return [
        'meetings_weekly' => 'Weekly meetings booked',
        'won_value_monthly' => 'Monthly won value',
        'proposals_monthly' => 'Monthly proposals sent',
        'overdue_hygiene' => 'Overdue follow-up limit',
    ][$metric] ?? $metric;
}

function defaultTargetValue(string $metric): int {
    return [
        'meetings_weekly' => 5,
        'won_value_monthly' => 50000,
        'proposals_monthly' => 8,
        'overdue_hygiene' => 0,
    ][$metric] ?? 0;
}

function targetPeriod(string $metric): string {
    return $metric === 'meetings_weekly' ? date('o-\WW') : date('Y-m');
}

function moneyValue($raw): float {
    if (is_numeric($raw)) return floatval($raw);
    $clean = preg_replace('/[^0-9.]/', '', (string)$raw);
    return $clean === '' ? 0.0 : floatval($clean);
}

function monthStart(): string { return date('Y-m-01'); }
function monthEnd(): string { return date('Y-m-t'); }
function weekStart(): string { return date('Y-m-d', strtotime('monday this week')); }
function weekEnd(): string { return date('Y-m-d', strtotime('sunday this week')); }

function upsertTarget(string $userId, string $metric, $value, array $actor): array {
    if (!in_array($metric, TARGET_METRICS, true)) {
        respond(['success' => false, 'error' => 'Invalid target metric'], 400);
    }
    $targets = getTargets();
    $period = targetPeriod($metric);
    $now = date('c');
    foreach ($targets as &$t) {
        if (($t['user_id'] ?? '') === $userId && ($t['metric'] ?? '') === $metric && ($t['period'] ?? '') === $period) {
            $t['target_value'] = max(0, intval($value));
            $t['updated_at'] = $now;
            $t['updated_by'] = $actor['id'] ?? '';
            saveTargets($targets);
            return $t;
        }
    }
    $target = [
        'id' => generateId('tgt_'),
        'user_id' => $userId,
        'metric' => $metric,
        'period' => $period,
        'target_value' => max(0, intval($value)),
        'created_at' => $now,
        'created_by' => $actor['id'] ?? '',
        'updated_at' => $now,
        'updated_by' => $actor['id'] ?? '',
    ];
    $targets[] = $target;
    saveTargets($targets);
    return $target;
}

function targetsForUsers(array $userIds): array {
    $stored = getTargets();
    $out = [];
    foreach ($userIds as $uid) {
        foreach (TARGET_METRICS as $metric) {
            $period = targetPeriod($metric);
            $found = null;
            foreach ($stored as $t) {
                if (($t['user_id'] ?? '') === $uid && ($t['metric'] ?? '') === $metric && ($t['period'] ?? '') === $period) {
                    $found = $t;
                    break;
                }
            }
            $out[] = $found ?: [
                'id' => '',
                'user_id' => $uid,
                'metric' => $metric,
                'period' => $period,
                'target_value' => defaultTargetValue($metric),
                'is_default' => true,
            ];
        }
    }
    return $out;
}

function targetProgressForUser(string $userId, array $leads, array $meetings, array $targets, bool $teamWideWonValue = false): array {
    $today = date('Y-m-d');
    $wStart = weekStart();
    $wEnd = weekEnd();
    $mStart = monthStart();
    $mEnd = monthEnd();
    $ownedLeads = array_values(array_filter($leads, fn($l) => ($l['owner_id'] ?? '') === $userId));
    $ownedMeetings = array_values(array_filter($meetings, fn($m) => ($m['user_id'] ?? '') === $userId && ($m['status'] ?? '') !== 'cancelled'));
    $wonValueOf = fn($ls) => array_reduce($ls, fn($sum, $l) => $sum + (($l['status'] ?? '') === 'won' && substr(($l['updated_at'] ?? $today), 0, 10) >= $mStart && substr(($l['updated_at'] ?? $today), 0, 10) <= $mEnd ? moneyValue($l['proposal_value'] ?: ($l['won_details']['value'] ?? 0)) : 0), 0);
    $actuals = [
        'meetings_weekly' => count(array_filter($ownedMeetings, fn($m) => !empty($m['start_time']) && substr($m['start_time'], 0, 10) >= $wStart && substr($m['start_time'], 0, 10) <= $wEnd)),
        // Admin/super_admin see the company-wide won total on this card
        // instead of just their own deals, since "Monthly won value" reads
        // as a team number to them, not a personal quota.
        'won_value_monthly' => $wonValueOf($teamWideWonValue ? $leads : $ownedLeads),
        'proposals_monthly' => count(array_filter($ownedLeads, fn($l) => ($l['status'] ?? '') === 'proposal_sent' && substr(($l['proposal_sent_at'] ?: ($l['updated_at'] ?? $today)), 0, 10) >= $mStart && substr(($l['proposal_sent_at'] ?: ($l['updated_at'] ?? $today)), 0, 10) <= $mEnd)),
        'overdue_hygiene' => count(array_filter($ownedLeads, fn($l) => isActiveLead($l) && !empty($l['next_action_due']) && $l['next_action_due'] <= $today)),
    ];
    $byMetric = [];
    foreach ($targets as $target) {
        if (($target['user_id'] ?? '') !== $userId) continue;
        $metric = $target['metric'] ?? '';
        if (!in_array($metric, TARGET_METRICS, true)) continue;
        $targetValue = intval($target['target_value'] ?? defaultTargetValue($metric));
        $actual = $actuals[$metric] ?? 0;
        $isLimit = $metric === 'overdue_hygiene';
        // Team-wide won value is allowed to visibly exceed 100% (e.g. $100k
        // actual vs $50k target reads as 200%) so over-achievement is
        // visible instead of capping at a full bar that looks the same as
        // exactly hitting target.
        $allowOverflow = $teamWideWonValue && $metric === 'won_value_monthly';
        $pct = $isLimit
            ? ($actual <= $targetValue ? 100 : max(0, 100 - (($actual - $targetValue) * 25)))
            : ($targetValue > 0 ? round(($actual / $targetValue) * 100) : 100);
        if (!$isLimit && !$allowOverflow) $pct = min(100, $pct);
        $byMetric[$metric] = [
            'metric' => $metric,
            'label' => targetMetricLabel($metric),
            'period' => $target['period'] ?? targetPeriod($metric),
            'target_value' => $targetValue,
            'actual' => $actual,
            'percent' => $pct,
            'at_risk' => $isLimit ? $actual > $targetValue : $pct < 60,
            'is_limit' => $isLimit,
            'is_default' => !empty($target['is_default']),
        ];
    }
    return array_values($byMetric);
}

// ===== AUTH =====
function getCurrentUser() {
    $token = $_SERVER['HTTP_X_USER_TOKEN'] ?? '';
    if (empty($token)) return null;
    foreach (getUsers() as $u) { if (($u['token'] ?? '') === $token && !isArchived($u) && ($u['is_active'] ?? true)) return $u; }
    return null;
}
function requireAuth() {
    $token = $_SERVER['HTTP_X_USER_TOKEN'] ?? $_GET['token'] ?? '';
    if (empty($token)) respond(['success' => false, 'error' => 'Authentication required'], 401);
    foreach (getUsers() as $u) { if (($u['token'] ?? '') === $token && !isArchived($u) && ($u['is_active'] ?? true)) return $u; }
    respond(['success' => false, 'error' => 'Authentication required'], 401);
}
function requireAdmin() {
    $u = requireAuth();
    if (!in_array($u['role'] ?? '', ['admin', 'super_admin'], true)) {
        respond(['success' => false, 'error' => 'Admin access required'], 403);
    }
    return $u;
}

function requireSuperAdmin() {
    $u = requireAuth();
    if (($u['role'] ?? '') !== 'super_admin') {
        respond(['success' => false, 'error' => 'Superadmin access required'], 403);
    }
    return $u;
}

// ===== PERMISSIONS =====
function hasPermission(string $role, string $action): bool {
    $role = normalizeRole($role);
    $matrix = [
        'view_all_leads'        => ['admin', 'super_admin'],
        'delete_lead'           => ['super_admin'],
        'reassign_lead'         => ['admin', 'super_admin'],
        'manage_users'          => ['admin', 'super_admin'],
        'delete_user'           => ['super_admin'],
        'view_audit_log'        => ['admin', 'super_admin'],
        'manage_admin_settings' => ['admin', 'super_admin'],
        'manage_global_settings'=> ['super_admin'],
        'export_leads'          => ['admin', 'super_admin'],
        'view_team_stats'       => ['admin', 'super_admin'],
        'view_super_dashboard'  => ['super_admin'],
    ];
    return in_array($role, $matrix[$action] ?? [], true);
}

// Role-based visibility hierarchy (not a per-manager reporting line — just
// three flat tiers): super_admin sees everyone; admin sees their own leads
// plus every sales_rep's leads, but NOT super_admin-owned leads; sales_rep
// sees only their own. Applied everywhere leads are filtered by ownership
// (list, stats, exports, chat #client refs) so visibility is consistent.
function visibleLeadsFor(array $user, array $allLeads): array {
    $role = normalizeRole($user['role'] ?? 'sales_rep');
    if ($role === 'super_admin') return $allLeads;
    if ($role === 'sales_rep') {
        return array_values(array_filter($allLeads, fn($l) => ($l['owner_id'] ?? '') === $user['id']));
    }
    // admin
    static $ownerRoles = null;
    if ($ownerRoles === null) {
        $ownerRoles = [];
        foreach (getUsers() as $u) $ownerRoles[$u['id']] = normalizeRole($u['role'] ?? 'sales_rep');
    }
    return array_values(array_filter($allLeads, function($l) use ($user, $ownerRoles) {
        $ownerId = $l['owner_id'] ?? '';
        if ($ownerId === $user['id']) return true;
        return ($ownerRoles[$ownerId] ?? 'sales_rep') !== 'super_admin';
    }));
}

// Can $user open/act on a specific lead, per the same hierarchy? Used for
// single-lead reads (GET lead, chat #client-ref) where fetching the whole
// visible set first would be wasteful.
function canViewLead(array $user, array $lead): bool {
    $role = normalizeRole($user['role'] ?? 'sales_rep');
    if ($role === 'super_admin') return true;
    $ownerId = $lead['owner_id'] ?? '';
    if ($ownerId === $user['id']) return true;
    if ($role === 'sales_rep') return false;
    // admin: visible unless the owner is a super_admin
    foreach (getUsers() as $u) {
        if ($u['id'] === $ownerId) return normalizeRole($u['role'] ?? 'sales_rep') !== 'super_admin';
    }
    return true; // owner not found (e.g. unassigned) — don't hide from admin
}

// Same hierarchy, for meetings (keyed by user_id instead of owner_id).
function visibleMeetingsFor(array $user, array $allMeetings): array {
    $role = normalizeRole($user['role'] ?? 'sales_rep');
    if ($role === 'super_admin') return $allMeetings;
    if ($role === 'sales_rep') {
        return array_values(array_filter($allMeetings, fn($m) => ($m['user_id'] ?? '') === $user['id']));
    }
    static $ownerRoles = null;
    if ($ownerRoles === null) {
        $ownerRoles = [];
        foreach (getUsers() as $u) $ownerRoles[$u['id']] = normalizeRole($u['role'] ?? 'sales_rep');
    }
    return array_values(array_filter($allMeetings, function($m) use ($user, $ownerRoles) {
        $uid = $m['user_id'] ?? '';
        if ($uid === $user['id']) return true;
        return ($ownerRoles[$uid] ?? 'sales_rep') !== 'super_admin';
    }));
}

// ===== AUDIT LOGGING =====
function logActivity(array $user, string $action, array $ctx = []): void {
    // Direct INSERT rather than load-all + save-all to keep audit writes O(1).
    $entry = [
        'id'        => generateId('log_'),
        'timestamp' => date('c'),
        'user_id'   => $user['id']   ?? 'unknown',
        'user_name' => $user['name'] ?? 'Unknown',
        'user_role' => $user['role'] ?? 'unknown',
        'action'    => $action,
        'context'   => $ctx,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ];
    // Insert directly; prune old entries to keep the table under 5000 rows.
    db()->prepare("
        INSERT INTO audit_log (id, data, updated_at)
        VALUES (:id, :data::jsonb, now())
        ON CONFLICT (id) DO NOTHING
    ")->execute([':id' => $entry['id'], ':data' => json_encode($entry, JSON_UNESCAPED_UNICODE)]);
    db()->exec("
        DELETE FROM audit_log
        WHERE id NOT IN (
            SELECT id FROM audit_log ORDER BY updated_at DESC LIMIT 5000
        )
    ");
}

// ===== RATE LIMITING =====
function checkRateLimit(string $key, int $max, int $windowSecs): void {
    $file = sys_get_temp_dir() . '/mmcrm_rl_' . md5($key) . '.json';
    $d    = is_file($file) ? json_decode(file_get_contents($file), true) : ['c' => 0, 't' => time()];
    if (!is_array($d)) $d = ['c' => 0, 't' => time()];
    if (time() - ($d['t'] ?? 0) > $windowSecs) $d = ['c' => 0, 't' => time()];
    $d['c']++;
    file_put_contents($file, json_encode($d));
    if ($d['c'] > $max) respond(['success' => false, 'error' => 'Too many requests. Please wait.'], 429);
}

// ===== INPUT VALIDATION =====
function validateRequired(array $input, array $fields): void {
    $missing = array_filter($fields, fn($f) => !isset($input[$f]) || $input[$f] === '');
    if (!empty($missing)) respond(['success' => false, 'error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
}

// ===== SEED DEFAULT ADMIN =====
function seedDefaultAdmin() {
    $users = getUsers();
    if (empty($users)) {
        $users[] = [
            'id' => generateId('user_'),
            'name' => 'Admin',
            'email' => 'admin@militzer-munch.com',
            'password' => password_hash('admin123', PASSWORD_DEFAULT),
            'role' => 'admin',
            'office' => 'Dubai',
            'token' => generateToken(),
            'is_active' => true,
            'created_at' => date('c')
        ];
        saveUsers($users);
    }
}
seedDefaultAdmin();

// ===== DEFAULT TEMPLATES =====
function getDefaultTemplates() {
    return [
        ['id' => generateId('tpl_'), 'name' => 'Cold Introduction', 'category' => 'introduction',
         'subject' => 'Logistics partnership for {company}',
         'body' => "Hi {first_name},\n\nI'm reaching out from Militzer & Münch regarding potential collaboration on freight forwarding services.\n\nWith over 140 years in global logistics, M&M specializes in the trade lanes most relevant to {company}. We've helped companies in {industry} optimize cross-border shipments and reduce transit times.\n\nWould you have 30 minutes this week for a brief call?\n\nBest regards,\n{sender_name}",
         'is_global' => true, 'created_by' => 'system'],
        ['id' => generateId('tpl_'), 'name' => 'Follow-up #1', 'category' => 'followup',
         'subject' => 'Re: Logistics partnership for {company}',
         'body' => "Hi {first_name},\n\nI wanted to follow up on my previous email. I understand you're busy, so I'll keep this brief.\n\nWe recently helped a {industry} company reduce their freight costs by 15% on similar trade lanes. I thought this might be relevant to {company}.\n\nWould a quick 15-minute call make sense?\n\nBest,\n{sender_name}",
         'is_global' => true, 'created_by' => 'system'],
        ['id' => generateId('tpl_'), 'name' => 'Follow-up #2', 'category' => 'followup',
         'subject' => 'Quick question about {company} logistics',
         'body' => "Hi {first_name},\n\nI know your inbox is busy, so I'll be direct.\n\nM&M handles freight forwarding across Europe, Middle East, and Central Asia — exactly the corridors that matter for {industry} companies.\n\nIf now isn't the right time, no worries. But if logistics is on your radar, I'd welcome a brief conversation.\n\nBest,\n{sender_name}",
         'is_global' => true, 'created_by' => 'system'],
        ['id' => generateId('tpl_'), 'name' => 'Meeting Request', 'category' => 'meeting',
         'subject' => 'Meeting request — M&M x {company}',
         'body' => "Hi {first_name},\n\nI'd like to suggest a short meeting to discuss how M&M's freight forwarding services could benefit {company}.\n\nWould any of the following work?\n- [Day 1] at [Time]\n- [Day 2] at [Time]\n\nHappy to adjust to your schedule.\n\nBest regards,\n{sender_name}",
         'is_global' => true, 'created_by' => 'system'],
        ['id' => generateId('tpl_'), 'name' => 'Post-Meeting Follow-up', 'category' => 'post_meeting',
         'subject' => 'Great speaking with you, {first_name}',
         'body' => "Hi {first_name},\n\nThank you for taking the time to meet today. I enjoyed learning more about {company}'s logistics needs.\n\nAs discussed, I'll put together a proposal covering the trade lanes and services we talked about. You can expect it by [date].\n\nIn the meantime, please don't hesitate to reach out with any questions.\n\nBest regards,\n{sender_name}",
         'is_global' => true, 'created_by' => 'system'],
        ['id' => generateId('tpl_'), 'name' => 'Quote Follow-up', 'category' => 'proposal',
         'subject' => 'Following up on our proposal — {company}',
         'body' => "Hi {first_name},\n\nI wanted to check in on the proposal we sent over. Have you had a chance to review the rates for the discussed trade lanes?\n\nI'm happy to walk through any questions or adjust the scope if needed.\n\nBest,\n{sender_name}",
         'is_global' => true, 'created_by' => 'system'],
        ['id' => generateId('tpl_'), 'name' => 'Breakup Email', 'category' => 'breakup',
         'subject' => 'Closing the loop — {company}',
         'body' => "Hi {first_name},\n\nI've reached out a few times without hearing back, and I completely understand — timing matters.\n\nI'll close this thread for now, but if freight forwarding or logistics support comes up in the future, feel free to reach out. We'd be happy to help.\n\nWishing you and {company} continued success.\n\nBest,\n{sender_name}",
         'is_global' => true, 'created_by' => 'system']
    ];
}

// ===== DEFAULT SOP TEMPLATES =====
function getDefaultSOPs() {
    return [
        ['id' => 'sop_new', 'name' => 'New Lead Qualification', 'trigger_status' => 'new', 'steps' => [
            'Verify contact information (email, phone)',
            'Research the company using AI Research tool',
            'Check for duplicates across the team',
            'Classify lead type (importer/exporter/manufacturer)',
            'Determine relevant shipping lanes',
            'Add notes with initial assessment'
        ]],
        ['id' => 'sop_researched', 'name' => 'First Contact Outreach', 'trigger_status' => 'researched', 'steps' => [
            'Review AI research findings',
            'Select appropriate email template',
            'Personalize the email using research data',
            'Send email via Outlook',
            'Set follow-up reminder for 3-5 business days'
        ]],
        ['id' => 'sop_meeting', 'name' => 'Meeting Preparation', 'trigger_status' => 'meeting_booked', 'steps' => [
            'Review all communication history',
            'Prepare rate/quote if applicable',
            'Identify 3 key pain points to discuss',
            'Have M&M service overview ready',
            'Confirm meeting 24 hours before'
        ]],
        ['id' => 'sop_post_meeting', 'name' => 'Post-Meeting Actions', 'trigger_status' => 'proposal_sent', 'steps' => [
            'Log meeting outcome within 1 hour',
            'Send follow-up email same day',
            'Update lead status',
            'Create proposal if requested',
            'Set next follow-up date'
        ]]
    ];
}

function getDefaultSopFields() {
    return [
        ['id' => 'sop_f1', 'label' => 'Customer Name',       'type' => 'text',   'required' => true,  'options' => []],
        ['id' => 'sop_f2', 'label' => 'Scope of Work',       'type' => 'select', 'required' => true,  'options' => ['FCL Import','FCL Export','LCL Import','LCL Export','Air Freight Import','Air Freight Export','Customs Brokerage','Road Transport','Warehousing','Multimodal','Project Logistics','Other']],
        ['id' => 'sop_f3', 'label' => 'Delivery Address / Special Instructions / ATF / Client Code', 'type' => 'textarea', 'required' => false, 'options' => []],
        ['id' => 'sop_f4', 'label' => 'Payment Terms',       'type' => 'select', 'required' => false, 'options' => ['7 Days','14 Days','30 Days','45 Days','60 Days','Cash on Delivery','Prepaid','Credit Approved']],
        ['id' => 'sop_f5', 'label' => 'Operational Contact', 'type' => 'text',   'required' => false, 'options' => []],
        ['id' => 'sop_f6', 'label' => 'Finance Contact',     'type' => 'text',   'required' => false, 'options' => []],
        ['id' => 'sop_f7', 'label' => 'Special Cargo Handling Instructions', 'type' => 'select', 'required' => false, 'options' => ['Fragile','Temperature Controlled','Hazardous (DG)','Oversized / Out of Gauge','High Value','Perishable','Live Animals','No Stacking','Palletised Only','None']],
    ];
}

// ===== LLM FUNCTIONS =====
function callLLM($provider, $apiKey, $prompt) {
    switch ($provider) {
        case 'groq': return callGroq($apiKey, $prompt);
        case 'gemini': return callGemini($apiKey, $prompt);
        case 'anthropic': return callAnthropic($apiKey, $prompt);
        default: return ['success' => false, 'error' => 'Unknown AI provider: ' . $provider];
    }
}

function callGroq($apiKey, $prompt) {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 3000, 'temperature' => 0.7
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch); $error = curl_error($ch); curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error];
    $r = json_decode($response, true);
    return isset($r['choices'][0]['message']['content'])
        ? ['success' => true, 'content' => $r['choices'][0]['message']['content']]
        : ['success' => false, 'error' => $r['error']['message'] ?? 'Groq API error'];
}

function callGemini($apiKey, $prompt) {
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch); $error = curl_error($ch); curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error];
    $r = json_decode($response, true);
    return isset($r['candidates'][0]['content']['parts'][0]['text'])
        ? ['success' => true, 'content' => $r['candidates'][0]['content']['parts'][0]['text']]
        : ['success' => false, 'error' => $r['error']['message'] ?? 'Gemini API error'];
}

function callAnthropic($apiKey, $prompt) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-sonnet-4-20250514', 'max_tokens' => 3000,
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch); $error = curl_error($ch); curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error];
    $r = json_decode($response, true);
    return isset($r['content'][0]['text'])
        ? ['success' => true, 'content' => $r['content'][0]['text']]
        : ['success' => false, 'error' => $r['error']['message'] ?? 'Anthropic API error'];
}

// ===== RESEARCH PROMPT =====
function buildResearchPrompt($lead) {
    $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $company = $lead['company'] ?? 'Unknown';
    $title = $lead['title'] ?? 'Unknown';
    $industry = $lead['industry'] ?? 'Unknown';
    $country = $lead['country'] ?? 'Unknown';
    $website = $lead['website'] ?? '';

    return "You are a B2B sales intelligence analyst. Research this prospect for a freight forwarding / logistics sales team at Militzer & Münch (M&M).

PROSPECT:
- Name: {$name}
- Title: {$title}
- Company: {$company}
- Industry: {$industry}
- Country: {$country}
- Website: {$website}

IMPORTANT: Return ONLY valid JSON. No markdown code blocks. No backticks. Just the raw JSON object.

{
  \"summary\": {
    \"headline\": \"One-sentence sales reason this account is worth attention\",
    \"why_now\": \"Why this company may have a logistics need now\",
    \"next_best_action\": \"The next practical sales action\",
    \"rep_priority\": \"High/Medium/Low\"
  },
  \"fit\": {
    \"grade\": \"A/B/C/D\",
    \"confidence\": \"High/Medium/Low\",
    \"icp_match\": \"Strong/Moderate/Weak\",
    \"reasons\": [\"Specific reason this fits M&M\", \"Specific reason to qualify further\"]
  },
  \"research_score\": {
    \"score\": 75,
    \"quality\": \"Good\",
    \"factors\": [\"Has company website\", \"Known industry\", \"Clear job title\"]
  },
  \"why_now\": {
    \"signals\": [\"Trigger or business signal\", \"Operational signal\"],
    \"urgency\": \"High/Medium/Low\",
    \"evidence\": \"Brief evidence for timing\"
  },
  \"company_profile\": {
    \"description\": \"2-3 sentences describing what this company does\",
    \"key_products_services\": [\"Service 1\", \"Service 2\", \"Service 3\"],
    \"market_position\": \"Their market position\",
    \"growth_stage\": \"Startup/Growth/Mature/Enterprise\"
  },
  \"decision_context\": {
    \"likely_decision_role\": \"Decision maker / influencer / evaluator\",
    \"stakeholders\": [\"Operations\", \"Procurement\", \"Finance\"],
    \"buying_triggers\": [\"Trigger 1\", \"Trigger 2\"],
    \"qualification_gaps\": [\"Unknown to confirm\", \"Unknown to confirm\"]
  },
  \"industry_intelligence\": {
    \"top_challenges\": [
      {\"challenge\": \"Logistics challenge\", \"impact\": \"Business impact\"},
      {\"challenge\": \"Supply chain pain\", \"impact\": \"Why it matters\"},
      {\"challenge\": \"Operational challenge\", \"impact\": \"Cost/time impact\"}
    ],
    \"trends\": [\"Trend 1\", \"Trend 2\"],
    \"competitive_pressures\": \"What competitors are doing\"
  },
  \"prospect_analysis\": {
    \"pain_points\": [
      {\"pain\": \"Specific pain for this role\", \"evidence\": \"Why they have this pain\"},
      {\"pain\": \"Another pain point\", \"evidence\": \"Evidence\"}
    ],
    \"responsibilities\": [\"Responsibility 1\", \"Responsibility 2\"],
    \"success_metrics\": [\"KPI 1\", \"KPI 2\"],
    \"buying_power\": \"Decision Maker / Influencer / Evaluator\"
  },
  \"sales_strategy\": {
    \"opening_hooks\": [
      {\"hook\": \"Attention-grabbing opener\", \"why\": \"Why this works\"},
      {\"hook\": \"Another opener\", \"why\": \"Why this resonates\"}
    ],
    \"value_angles\": [
      {\"angle\": \"How M&M freight services help them\", \"connects_to\": \"Which pain\"},
      {\"angle\": \"Secondary angle\", \"connects_to\": \"Related pain\"}
    ],
    \"discovery_questions\": [
      \"Question about their shipping volumes?\",
      \"Question about current freight partners?\",
      \"Question about trade lanes?\",
      \"Question about supply chain challenges?\"
    ],
    \"objections\": [{\"objection\": \"Likely objection\", \"response\": \"How to handle\"}],
    \"avoid\": [\"Thing NOT to say\", \"Another thing to avoid\"]
  },
  \"talk_track\": {
    \"opening_line\": \"Plain spoken opener for a call or email\",
    \"credibility_points\": [\"Proof point or relevant capability\", \"Another point\"],
    \"discovery_questions\": [\"Question 1\", \"Question 2\", \"Question 3\"],
    \"avoid\": [\"Thing NOT to say\"]
  },
  \"risks\": {
    \"sales_risks\": [\"Risk or blocker\"],
    \"data_gaps\": [\"Missing data to verify\"],
    \"avoid\": [\"Thing NOT to say\", \"Another thing to avoid\"]
  },
  \"freight_fit\": {
    \"suggested_trade_lanes\": [
      {\"origin\": \"Country/City\", \"destination\": \"Country/City\", \"rationale\": \"Why this lane matters for them\"}
    ],
    \"likely_cargo_types\": [\"FCL\", \"LCL\", \"Air Freight\"],
    \"recommended_services\": [\"Door-to-Door\", \"Customs Brokerage\", \"Warehousing\"],
    \"competitor_landscape\": \"Which forwarders they likely use and why M&M is better\",
    \"volume_potential\": \"Low/Medium/High with reasoning\"
  },
  \"sources\": [
    {\"title\": \"Company website\", \"url\": \"https://example.com\", \"publisher\": \"Company\", \"evidence_for\": \"Company profile and services\"}
  ],
  \"data_quality\": {
    \"confidence\": \"High/Medium/Low\",
    \"last_checked_basis\": \"Website, supplied CRM fields, industry inference\",
    \"needs_verification\": [\"Items the rep should verify manually\"]
  }
}

Focus on FREIGHT FORWARDING and LOGISTICS context. M&M offers: international freight forwarding, customs brokerage, project logistics, warehousing, and supply chain management across Europe, Middle East, Central Asia, and Far East.

Generate specific, actionable insights. The freight_fit section is critical — analyze their industry and geography to suggest realistic trade lanes, cargo types, and services M&M can offer them. Include source links whenever you can identify credible public sources. If you cannot verify a source URL, keep sources empty and state the gap in data_quality.needs_verification:";
}

// ===== EMAIL GENERATION =====
function generateEmailContent($provider, $apiKey, $lead, $emailType, $customInstructions, $settings) {
    $firstName = $lead['first_name'] ?? 'there';
    $company = $lead['company'] ?? '';
    $industry = $lead['industry'] ?? '';
    $senderName = $settings['sender_name'] ?? 'Sales Team';
    $senderTitle = $settings['sender_title'] ?? '';
    $senderCompany = $settings['sender_company'] ?? 'Militzer & Münch';
    $valueProp = $settings['value_proposition'] ?? 'Over 140 years of logistics expertise';

    $research = [];
    if (!empty($lead['enrichment'])) {
        $parsed = json_decode($lead['enrichment'], true);
        if ($parsed) $research = $parsed;
    }

    $companyInfo = $research['company_profile']['description'] ?? '';
    $challenges = $research['industry_intelligence']['top_challenges'] ?? [];
    $painPoints = $research['prospect_analysis']['pain_points'] ?? [];
    $hooks = $research['sales_strategy']['opening_hooks'] ?? [];
    $valueAngles = $research['sales_strategy']['value_angles'] ?? [];

    $context = "PROSPECT: {$firstName} {$lead['last_name']}, {$lead['title']} at {$company} ({$industry})
COMPANY INFO: {$companyInfo}
CHALLENGES: " . ($challenges[0]['challenge'] ?? 'logistics optimization') . "
PAIN POINTS: " . ($painPoints[0]['pain'] ?? 'freight cost management') . "
HOOK: " . ($hooks[0]['hook'] ?? '') . "
VALUE: " . ($valueAngles[0]['angle'] ?? $valueProp) . "
SENDER: {$senderName}, {$senderTitle} at {$senderCompany} (Militzer & Münch)
WHAT WE DO: Global freight forwarding, customs brokerage, project logistics, warehousing — covering Europe, Middle East, Central Asia, Far East
SOCIAL PROOF: Trusted by companies across 30+ countries since 1880";
    if ($customInstructions) $context .= "\nSPECIAL INSTRUCTIONS: {$customInstructions}";

    $langRules = "
WRITING STYLE:
- Simple, clear English. Short sentences.
- No jargon, buzzwords, or corporate-speak
- Conversational, like a colleague
- No exclamation marks
- Proper capitalization only";

    // Freight profile context
    $freightCtx = '';
    $fp = $lead['freight_profile'] ?? [];
    if (!empty($fp['trade_lanes'])) $freightCtx .= "\nTRADE LANES: " . implode(', ', $fp['trade_lanes']);
    if (!empty($fp['cargo_types'])) $freightCtx .= "\nCARGO TYPES: " . implode(', ', $fp['cargo_types']);
    if (!empty($fp['services_needed'])) $freightCtx .= "\nSERVICES: " . implode(', ', $fp['services_needed']);
    if (!empty($fp['volume_estimate'])) $freightCtx .= "\nVOLUME: " . $fp['volume_estimate'];
    if ($freightCtx) $context .= "\n\nFREIGHT PROFILE:{$freightCtx}";

    $prompts = [
        'introduction' => "{$context}\n{$langRules}\n\nGenerate 4 parts for an INTRODUCTION email about freight forwarding services. Reference their specific cargo types or trade lanes if known:\n\n1. SUBJECT (4-6 words, specific to their logistics needs)\n2. OPENER (1-2 sentences referencing their industry and likely shipping needs)\n3. PROBLEM_BRIDGE (2-3 sentences: name a logistics challenge they face, show how M&M solves it on relevant corridors)\n4. CTA (1 simple question about their current freight setup)\n\nReturn ONLY:\nSUBJECT: [subject]\nOPENER: [opener]\nPROBLEM_BRIDGE: [bridge]\nCTA: [question]",
        'value_add' => "{$context}\n{$langRules}\n\nGenerate 3 parts for a VALUE ADD email (share a relevant insight):\n\n1. SUBJECT (industry insight angle, 4-6 words)\n2. INSIGHT_BODY (3-4 sentences: share a relevant freight/logistics trend or case study specific to their industry. Mention how M&M helps companies navigate this.)\n3. CTA (1 question connecting the insight to their business)\n\nReturn ONLY:\nSUBJECT: [subject]\nINSIGHT_BODY: [body]\nCTA: [question]",
        'rate_offer' => "{$context}\n{$langRules}\n\nGenerate 3 parts for a RATE OFFER email (offering competitive freight rates):\n\n1. SUBJECT (rate/quote angle, 4-6 words)\n2. RATE_BODY (2-3 sentences: mention specific trade lanes or corridors relevant to them, offer to share competitive rates, mention M&M's volume advantages)\n3. CTA (1 direct question about sharing a quote)\n\nReturn ONLY:\nSUBJECT: [subject]\nRATE_BODY: [body]\nCTA: [question]",
        'meeting_request' => "{$context}\n{$langRules}\n\nGenerate 3 parts for a MEETING REQUEST email:\n\n1. SUBJECT (meeting request, 4-6 words)\n2. MEETING_BODY (2-3 sentences: reference previous context, explain what a 20-min call would cover — their routes, volumes, timeline)\n3. CTA (1-2 sentences: propose a brief call with specific agenda)\n\nReturn ONLY:\nSUBJECT: [subject]\nMEETING_BODY: [body]\nCTA: [question]",
        'breakup' => "{$context}\n{$langRules}\n\nGenerate 2 parts for a BREAKUP email:\n\n1. SUBJECT (\"closing the loop\" style)\n2. BODY (3-4 sentences: acknowledge no response, briefly restate M&M's value for their trade lanes, leave door open)\n\nReturn ONLY:\nSUBJECT: [subject]\nBODY: [body]"
    ];

    $prompt = $prompts[$emailType] ?? $prompts['introduction'];
    $res = callLLM($provider, $apiKey, $prompt);
    if (!$res['success']) return $res;

    $out = $res['content'];
    $parts = [];
    foreach (['SUBJECT','OPENER','PROBLEM_BRIDGE','CTA','INSIGHT_BODY','RATE_BODY','MEETING_BODY','BODY'] as $key) {
        if (preg_match('/' . $key . ':\s*(.+?)(?=\n[A-Z_]+:|$)/s', $out, $m)) $parts[strtolower($key)] = trim($m[1]);
    }

    $subject = $parts['subject'] ?? 'Quick question about logistics';
    $sig = "\n\n{$senderName}";
    if ($senderTitle) $sig .= "\n{$senderTitle}";
    $sig .= "\n{$senderCompany}";

    switch ($emailType) {
        case 'introduction':
            $body = "Hi {$firstName},\n\n" . ($parts['opener'] ?? '') . "\n\n" . ($parts['problem_bridge'] ?? '') . "\n\n" . ($parts['cta'] ?? 'Would this be worth a conversation?') . $sig;
            break;
        case 'value_add':
            $body = "Hi {$firstName},\n\n" . ($parts['insight_body'] ?? 'I came across something relevant to your business.') . "\n\n" . ($parts['cta'] ?? 'Is this something you\'re seeing as well?') . $sig;
            break;
        case 'rate_offer':
            $body = "Hi {$firstName},\n\n" . ($parts['rate_body'] ?? 'I wanted to share some competitive rates for your key trade lanes.') . "\n\n" . ($parts['cta'] ?? 'Would it help if I put together a quick quote?') . $sig;
            break;
        case 'meeting_request':
            $body = "Hi {$firstName},\n\n" . ($parts['meeting_body'] ?? 'I\'d like to suggest a brief call to discuss your freight needs.') . "\n\n" . ($parts['cta'] ?? 'Would 20 minutes this week work?') . $sig;
            break;
        case 'breakup':
            $body = "Hi {$firstName},\n\n" . ($parts['body'] ?? "I've reached out a few times. Should I close your file?") . $sig;
            break;
        default:
            $body = "Hi {$firstName},\n\n{$out}" . $sig;
    }

    // Append M&M footer
    $mmFooter = $settings['mm_footer'] ?? "Militzer & Münch — Your global logistics partner since 1880.\nhttps://www.militzer-munch.com";
    $body .= "\n\n---\n{$mmFooter}";

    return ['success' => true, 'subject' => $subject, 'body' => $body, 'raw_parts' => $parts];
}

// ===== CALL PITCH GENERATION =====
function generateCallPitch($provider, $apiKey, $lead, $pitchType, $settings) {
    $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $company = $lead['company'] ?? '';
    $industry = $lead['industry'] ?? '';
    $title = $lead['title'] ?? '';
    $senderName = $settings['sender_name'] ?? 'Sales Team';
    $senderTitle = $settings['sender_title'] ?? 'Sales Representative';
    $emailSent = in_array($pitchType, ['cold_with_email', 'email_sent']);

    $context = "PROSPECT: {$name}, {$title} at {$company} ({$industry})
CALLER: {$senderName}, {$senderTitle} at Militzer & Münch
WHAT WE DO: Global freight forwarding, customs brokerage, project logistics, warehousing
EMAIL ALREADY SENT: " . ($emailSent ? 'Yes' : 'No');

    if (!empty($lead['enrichment'])) {
        $research = json_decode($lead['enrichment'], true);
        if ($research && !empty($research['prospect_analysis']['pain_points'])) {
            $pains = array_map(fn($p) => $p['pain'], array_slice($research['prospect_analysis']['pain_points'], 0, 3));
            $context .= "\nKNOWN PAIN POINTS: " . implode(', ', $pains);
        }
    }

    $prompt = "{$context}

Generate a natural, conversational call script for a freight forwarding sales call. Include:

1. OPENING — Brief intro: \"Hey {$name}, it's {$senderName} from Militzer & Münch...\"
2. REASON FOR CALL — " . ($emailSent ? "Reference the email sent earlier" : "Brief cold intro about why calling") . "
3. IF THEY SAY NO — A 30-second pitch ask
4. WHO WE ARE — Brief M&M description (freight forwarding, 140+ years, Europe/ME/Asia)
5. PAIN POINTS — 3-4 pain points specific to {$industry} logistics
6. THE ASK — Request a 20-30 min discovery session
7. IF YES — Book it on the spot
8. IF NO — Graceful exit, leave door open

Use simple conversational language. Include decision branches (if yes → do X, if no → do Y).
Format with clear section headers and quoted speech that can be read out loud.";

    $res = callLLM($provider, $apiKey, $prompt);
    if (!$res['success']) return $res;
    return ['success' => true, 'title' => $emailSent ? 'Call Script (Email Sent)' : 'Cold Call Script', 'pitch' => $res['content']];
}

// ===== DUPLICATE DETECTION =====
function checkDuplicates($lead, $excludeId = null) {
    $leads = getLeads();
    $dupes = [];
    $email = strtolower(trim($lead['email'] ?? ''));
    $phone = preg_replace('/[^0-9+]/', '', $lead['phone'] ?? '');
    $name = strtolower(trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')));
    $company = strtolower(trim($lead['company'] ?? ''));

    foreach ($leads as $existing) {
        if ($excludeId && $existing['id'] === $excludeId) continue;

        // Email match
        if ($email && strtolower(trim($existing['email'] ?? '')) === $email) {
            $dupes[] = ['lead' => $existing, 'match_type' => 'email', 'confidence' => 'high'];
            continue;
        }
        // Phone match
        $existPhone = preg_replace('/[^0-9+]/', '', $existing['phone'] ?? '');
        if ($phone && $existPhone && $existPhone === $phone) {
            $dupes[] = ['lead' => $existing, 'match_type' => 'phone', 'confidence' => 'high'];
            continue;
        }
        // Name + company fuzzy match
        $existName = strtolower(trim(($existing['first_name'] ?? '') . ' ' . ($existing['last_name'] ?? '')));
        $existCompany = strtolower(trim($existing['company'] ?? ''));
        if ($name && $company && $existCompany === $company && similar_text($name, $existName) > (strlen($name) * 0.7)) {
            $dupes[] = ['lead' => $existing, 'match_type' => 'name_company', 'confidence' => 'medium'];
        }
    }
    return $dupes;
}

// ===== MICROSOFT GRAPH =====
function baseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
    return $scheme . '://' . $host;
}

function msRedirectUri(?array $admin = null): string {
    $admin = $admin ?? getAdmin();
    $configured = trim($admin['ms_calendar']['redirect_uri'] ?? '');
    return $configured ?: baseUrl() . '/api.php?action=ms-auth-callback';
}

function msConfig(): array {
    $admin = getAdmin();
    $cfg = $admin['ms_calendar'] ?? [];
    return array_merge(['enabled' => false, 'tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'redirect_uri' => msRedirectUri($admin)], $cfg);
}

function encryptionKey(): string {
    global $_env;
    $secret = $_env['ms_token_key'] ?? '';
    if (!$secret) $secret = ($_env['app_secret'] ?? '') ?: (__DIR__ . '|mm-sales-crm-local-key');
    return hash('sha256', $secret, true);
}

function encryptToken(string $plain): string {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

function decryptToken(string $encoded): string {
    $raw = base64_decode($encoded, true);
    if (!$raw || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plain) ? $plain : '';
}

function httpJson(string $url, string $method = 'GET', array $headers = [], $body = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error, 'status' => $code];
    $json = json_decode($response, true);
    if ($code >= 400) {
        $msg = $json['error']['message'] ?? $json['error_description'] ?? '';
        if ($code === 401) $msg = 'Microsoft 365 authorisation failed — please reconnect your account in Settings';
        elseif (!$msg) $msg = 'HTTP ' . $code;
        return ['success' => false, 'error' => $msg, 'status' => $code, 'raw' => $json];
    }
    return ['success' => true, 'data' => is_array($json) ? $json : [], 'status' => $code];
}

function msTokenRequest(array $params): array {
    $cfg = msConfig();
    $tenant = $cfg['tenant_id'] ?: 'common';
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
    $params['client_id'] = $cfg['client_id'] ?? '';
    if (!empty($cfg['client_secret'])) $params['client_secret'] = $cfg['client_secret'];
    $res = httpJson($url, 'POST', ['Content-Type: application/x-www-form-urlencoded'], http_build_query($params));
    return $res['success'] ? ['success' => true, 'token' => $res['data']] : $res;
}

function msUserConnection(string $userId): ?array {
    $tokens = getMsTokens();
    return $tokens[$userId] ?? null;
}

function saveMsUserConnection(string $userId, array $token, array $profile = []): void {
    $tokens = getMsTokens();
    $existing = $tokens[$userId] ?? [];
    $refresh = $token['refresh_token'] ?? decryptToken($existing['refresh_token_enc'] ?? '');
    $tokens[$userId] = [
        'refresh_token_enc' => $refresh ? encryptToken($refresh) : ($existing['refresh_token_enc'] ?? ''),
        'access_token_enc' => !empty($token['access_token']) ? encryptToken($token['access_token']) : ($existing['access_token_enc'] ?? ''),
        'expires_at' => time() + intval($token['expires_in'] ?? 3500) - 120,
        'ms_user_id' => $profile['id'] ?? ($existing['ms_user_id'] ?? ''),
        'user_principal_name' => $profile['userPrincipalName'] ?? $profile['mail'] ?? ($existing['user_principal_name'] ?? ''),
        'display_name' => $profile['displayName'] ?? ($existing['display_name'] ?? ''),
        'connected_at' => $existing['connected_at'] ?? date('c'),
        'last_sync_at' => date('c'),
    ];
    saveMsTokens($tokens);
}

function msAccessToken(array $user): array {
    $conn = msUserConnection($user['id'] ?? '');
    if (!$conn) return ['success' => false, 'error' => 'Microsoft 365 is not connected'];
    $access = decryptToken($conn['access_token_enc'] ?? '');
    if ($access && ($conn['expires_at'] ?? 0) > time()) return ['success' => true, 'access_token' => $access, 'connection' => $conn];
    $refresh = decryptToken($conn['refresh_token_enc'] ?? '');
    if (!$refresh) return ['success' => false, 'error' => 'Microsoft 365 reconnect required'];
    $res = msTokenRequest([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'scope' => 'offline_access User.Read Calendars.ReadWrite OnlineMeetings.ReadWrite',
    ]);
    if (!$res['success']) return $res;
    saveMsUserConnection($user['id'], $res['token']);
    return ['success' => true, 'access_token' => $res['token']['access_token'], 'connection' => msUserConnection($user['id'])];
}

function msGraph(array $user, string $method, string $path, $body = null): array {
    $token = msAccessToken($user);
    if (!$token['success']) return $token;
    $headers = ['Authorization: Bearer ' . $token['access_token'], 'Content-Type: application/json', 'Prefer: outlook.timezone="Pacific/Auckland"'];
    $res = httpJson('https://graph.microsoft.com/v1.0' . $path, $method, $headers, $body);
    // Track auth failures so the UI can prompt a reconnect
    if (($res['status'] ?? 0) === 401) {
        $tokens = getMsTokens();
        if (isset($tokens[$user['id'] ?? ''])) {
            $tokens[$user['id']]['auth_failed'] = true;
            saveMsTokens($tokens);
        }
    }
    return $res;
}

function msCreateCalendarEvent(array $user, array $meeting): array {
    $attendees = normalizeAttendees($meeting['attendees'] ?? []);
    $eventAttendees = array_map(fn($a) => [
        'emailAddress' => ['address' => $a['email'], 'name' => $a['name'] ?: $a['email']],
        'type' => 'required',
    ], $attendees);
    $isTeams = in_array($meeting['location_type'] ?? '', ['teams', 'hybrid'], true);
    $meetingLink = $meeting['teams_link'] ?? ($meeting['teams_join_url'] ?? '');
    $bodyLines = array_filter([
        $meeting['notes'] ?? '',
        $meetingLink ? 'Join meeting: ' . $meetingLink : '',
    ]);
    $start = $meeting['start_time_local'] ?? $meeting['start_time'];
    $end = $meeting['end_time_local'] ?? $meeting['end_time'];
    $start = preg_replace('/\.\d{3}Z$/', '', $start);
    $end = preg_replace('/\.\d{3}Z$/', '', $end);
    $start = rtrim($start, 'Z');
    $end = rtrim($end, 'Z');
    $locationDisplay = $meeting['location'] ?: (($meeting['location_type'] ?? '') === 'phone' ? 'Phone call' : ($isTeams ? 'Online Meeting' : ''));
    $payload = [
        'subject' => $meeting['title'] ?: 'M&M New Zealand meeting',
        'body' => ['contentType' => 'Text', 'content' => implode("\n\n", $bodyLines) ?: 'M&M New Zealand sales meeting'],
        'start' => ['dateTime' => $start, 'timeZone' => 'Pacific/Auckland'],
        'end' => ['dateTime' => $end, 'timeZone' => 'Pacific/Auckland'],
        'attendees' => $eventAttendees,
        'location' => ['displayName' => $locationDisplay],
    ];
    if ($isTeams) {
        $payload['isOnlineMeeting'] = true;
        $payload['onlineMeetingProvider'] = 'teamsForBusiness';
    }
    $res = msGraph($user, 'POST', '/me/events?$select=id,webLink,onlineMeeting,onlineMeetingUrl,isOnlineMeeting', $payload);
    if (!$res['success']) return $res;
    $event = $res['data'];
    $joinUrl = $event['onlineMeeting']['joinUrl'] ?? $event['onlineMeetingUrl'] ?? '';
    return ['success' => true, 'event' => [
        'id' => $event['id'] ?? '',
        'webLink' => $event['webLink'] ?? '',
        'joinUrl' => $joinUrl,
    ]];
}

function msDeleteCalendarEvent(array $user, string $eventId): array {
    if (!$eventId) return ['success' => false, 'error' => 'No event ID'];
    $res = msGraph($user, 'DELETE', '/me/events/' . rawurlencode($eventId));
    // 204 No Content = success; treat 404 as success (already deleted)
    if ($res['status'] === 204 || $res['status'] === 404) return ['success' => true];
    return ['success' => false, 'error' => $res['error'] ?? 'Failed to delete calendar event'];
}

function buildMeetingFromInput(array $input, array $user, array $existing = []): array {
    $attendees = normalizeAttendees($input['attendees'] ?? ($existing['attendees'] ?? []));
    return array_merge($existing, [
        'lead_id' => $input['lead_id'] ?? ($existing['lead_id'] ?? ''),
        'user_id' => $existing['user_id'] ?? $user['id'],
        'user_name' => $existing['user_name'] ?? $user['name'],
        'title' => trim($input['title'] ?? ($existing['title'] ?? '')),
        'start_time' => $input['start_time'] ?? ($existing['start_time'] ?? ''),
        'end_time' => $input['end_time'] ?? ($existing['end_time'] ?? ''),
        'start_time_local' => $input['start_time_local'] ?? ($existing['start_time_local'] ?? ''),
        'end_time_local' => $input['end_time_local'] ?? ($existing['end_time_local'] ?? ''),
        'duration' => intval($input['duration'] ?? ($existing['duration'] ?? 30)),
        'location' => trim($input['location'] ?? ($existing['location'] ?? '')),
        'location_type' => $input['location_type'] ?? ($existing['location_type'] ?? 'teams'),
        'teams_link' => trim($input['teams_link'] ?? ($existing['teams_link'] ?? '')),
        'teams_join_url' => trim($input['teams_join_url'] ?? ($existing['teams_join_url'] ?? '')),
        'client_email' => $input['client_email'] ?? ($attendees[0]['email'] ?? ($existing['client_email'] ?? '')),
        'attendees' => $attendees,
        'notes' => trim($input['notes'] ?? ($existing['notes'] ?? '')),
    ]);
}

// ===== CHAT + NOTIFICATION HELPERS =====
function isChatAdmin(array $user): bool {
    return in_array($user['role'] ?? '', ['admin', 'super_admin'], true);
}

$_channelsCache = null;

function getChatChannels(): array {
    global $_channelsCache;
    if ($_channelsCache !== null) return $_channelsCache;
    $channels = dbLoadAll('chat_channels');
    if (empty($channels)) {
        $channels = [
            ['id'=>'channel_general','name'=>'general','description'=>'Company-wide chat','created_by'=>'system','created_at'=>date('c')],
            ['id'=>'channel_deals','name'=>'deals','description'=>'Deal updates','created_by'=>'system','created_at'=>date('c')]
        ];
        dbSaveAll('chat_channels', $channels);
    }
    $_channelsCache = $channels;
    return $_channelsCache;
}
function saveChatChannels(array $channels): void {
    global $_channelsCache;
    dbSaveAll('chat_channels', array_values($channels));
    $_channelsCache = array_values($channels);
}
function getChannelMessages(string $channelId): array {
    return dbLoadMessages($channelId);
}
function saveChannelMessages(string $channelId, array $messages): void {
    dbSaveMessages($channelId, array_values($messages));
}
function getDmThreadId(string $userId1, string $userId2): string {
    $ids = [$userId1, $userId2]; sort($ids);
    return 'dm_' . md5($ids[0] . '_' . $ids[1]);
}

// A DM thread id is a deterministic hash of two user ids, so it's guessable
// by anyone who can learn both ids. Every endpoint that reads/writes a
// thread (messages, uploads, delete, pin) must call this first — without it,
// any authenticated user can access any other pair's private DM by
// recomputing the hash, or any private channel by its known id.
function canAccessThread(array $user, string $threadId): bool {
    if (str_starts_with($threadId, 'dm_')) {
        foreach (getUsers() as $u) {
            if (($u['id'] ?? '') === $user['id']) continue;
            if (getDmThreadId($user['id'], $u['id']) === $threadId) return true;
        }
        return false;
    }
    foreach (getChatChannels() as $ch) {
        if ($ch['id'] !== $threadId) continue;
        $members = $ch['members'] ?? [];
        return empty($members) || isChatAdmin($user) || in_array($user['id'], $members, true);
    }
    return false; // unknown channel id
}
function getChatUnreadCounts(string $userId): array {
    $counts = [];
    foreach (getChatChannels() as $ch) {
        $messages = getChannelMessages($ch['id']);
        $last = dbGetLastRead($userId, $ch['id']) ?: '1970-01-01T00:00:00+00:00';
        $counts[$ch['id']] = count(array_filter($messages, fn($m) => ($m['sent_at'] ?? '') > $last && ($m['user_id'] ?? '') !== $userId));
    }
    foreach (getUsers() as $u) {
        if ($u['id'] === $userId) continue;
        $threadId = getDmThreadId($userId, $u['id']);
        $messages = getChannelMessages($threadId);
        $last = dbGetLastRead($userId, $threadId) ?: '1970-01-01T00:00:00+00:00';
        $counts[$threadId] = count(array_filter($messages, fn($m) => ($m['sent_at'] ?? '') > $last && ($m['user_id'] ?? '') !== $userId));
    }
    return $counts;
}

// Notifications are stored on the user record in users.json.
function getUserNotifications(string $userId): array {
    foreach (getUsers() as $u) {
        if ($u['id'] === $userId) return $u['notifications'] ?? [];
    }
    return [];
}
// Add a notification directly into a &$users array (no DB write — caller must saveUsers).
function _applyNotif(array &$users, string $userId, array $notif): void {
    foreach ($users as &$u) {
        if ($u['id'] !== $userId) continue;
        $u['notifications'] = $u['notifications'] ?? [];
        $key = $notif['notif_key'] ?? '';
        if ($key !== '') {
            foreach ($u['notifications'] as $n) {
                if (($n['notif_key'] ?? '') === $key) return;
            }
        }
        $u['notifications'][] = $notif;
        usort($u['notifications'], fn($a,$b) => strtotime($b['created_at']??'0') - strtotime($a['created_at']??'0'));
        $u['notifications'] = array_slice($u['notifications'], 0, 50);
        return;
    }
}

function addUserNotification(string $userId, array $notif): void {
    $users = getUsers();
    _applyNotif($users, $userId, $notif);
    saveUsers($users);
}

// Collect @mention notifications into &$users without saving — caller saves once.
function collectMentionNotifs(array $sender, string $text, string $threadId, string $threadLabel, string $bodyPrefix, string $idPrefix, array &$users): void {
    if (strpos($text, '@') === false) return;
    $senderName = $sender['name'] ?? $sender['email'] ?? 'Someone';
    foreach ($users as &$u) {
        if (($u['id'] ?? '') === ($sender['id'] ?? '')) continue;
        if (isArchived($u) || !($u['is_active'] ?? true)) continue;
        $fullName  = trim($u['name'] ?? '');
        $firstName = $fullName !== '' ? strtok($fullName, ' ') : '';
        $username  = trim($u['username'] ?? '');
        $candidates = array_filter([$fullName, $firstName, $username]);
        usort($candidates, fn($a, $b) => strlen($b) - strlen($a));
        $matched = false;
        foreach ($candidates as $cand) {
            if (preg_match('/@' . preg_quote($cand, '/') . '(?![a-zA-Z0-9_])/i', $text)) { $matched = true; break; }
        }
        if (!$matched) continue;
        _applyNotif($users, $u['id'], [
            'id'         => 'notif_' . bin2hex(random_bytes(6)),
            'notif_key'  => "{$idPrefix}_{$threadId}_{$u['id']}_" . substr(md5($text), 0, 8),
            'type'       => 'mention',
            'title'      => "{$senderName} mentioned you",
            'body'       => "{$bodyPrefix}: " . substr($text, 0, 80),
            'thread_id'  => $threadId,
            'created_at' => date('c'),
            'read'       => false,
        ]);
    }
}

// Legacy wrapper kept for any callers outside of chat-messages POST.
function notifyMentions(array $sender, string $text, string $threadId, string $threadLabel, string $bodyPrefix, string $idPrefix): void {
    $users = getUsers();
    collectMentionNotifs($sender, $text, $threadId, $threadLabel, $bodyPrefix, $idPrefix, $users);
    saveUsers($users);
}

// ===== ROUTING =====
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // ===== LOGIN =====
    case 'login':
        checkRateLimit('login_' . ($input['username'] ?? $input['email'] ?? 'unknown'), 10, 300);
        validateRequired($input, ['password']);
        $login = strtolower(trim($input['username'] ?? $input['email'] ?? ''));
        if ($login === '') respond(['success' => false, 'error' => 'Username required'], 400);
        $password = $input['password'] ?? '';
        $users = getUsers();
        foreach ($users as &$u) {
            $username = strtolower(trim($u['username'] ?? ''));
            $email = strtolower(trim($u['email'] ?? ''));
            if (($username === $login || $email === $login) && password_verify($password, $u['password'])) {
                if (isArchived($u)) respond(['success' => false, 'error' => 'Account archived'], 403);
                if (!($u['is_active'] ?? true)) respond(['success' => false, 'error' => 'Account disabled'], 403);
                // Only generate token if none exists; reuse existing to prevent session invalidation
                if (empty($u['token'])) {
                    $u['token'] = generateToken();
                    saveUsers($users);
                }
                logActivity($u, 'auth.login', ['username' => $username, 'email' => $email]);
                respond(['success' => true, 'user' => [
                    'id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'] ?? '', 'email' => $u['email'],
                    'role' => normalizeRole($u['role'] ?? 'sales_rep'), 'title' => $u['title'] ?? '', 'office' => $u['office'] ?? '',
                    'token' => $u['token']
                ]]);
            }
        }
        respond(['success' => false, 'error' => 'Invalid username or password'], 401);
        break;

    case 'me':
        $u = requireAuth();
        respond(['success' => true, 'user' => [
            'id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'] ?? '', 'email' => $u['email'],
            'role' => normalizeRole($u['role'] ?? 'sales_rep'), 'title' => $u['title'] ?? '', 'office' => $u['office'] ?? '',
            'token' => $u['token']
        ]]);
        break;

    // ===== MICROSOFT 365 =====
    case 'ms-status':
        $user = requireAuth();
        $cfg = msConfig();
        $conn = msUserConnection($user['id']);
        respond(['success' => true, 'enabled' => (bool)($cfg['enabled'] ?? false), 'configured' => !empty($cfg['client_id']) && !empty($cfg['tenant_id']), 'connected' => (bool)$conn, 'connection' => $conn ? [
            'user_principal_name' => $conn['user_principal_name'] ?? '',
            'display_name' => $conn['display_name'] ?? '',
            'connected_at' => $conn['connected_at'] ?? '',
            'expires_at' => $conn['expires_at'] ?? 0,
            'needs_reconnect' => ($conn['expires_at'] ?? 0) < time() && empty($conn['refresh_token_enc']),
        ] : null, 'redirect_uri' => msRedirectUri()]);
        break;

    case 'ms-auth-start':
        $user = requireAuth();
        $cfg = msConfig();
        if (empty($cfg['enabled']) || empty($cfg['client_id']) || empty($cfg['tenant_id'])) {
            respond(['success' => false, 'error' => 'Microsoft 365 is not configured'], 400);
        }
        $state = generateToken();
        $stateFile = sys_get_temp_dir() . '/mmcrm_ms_state_' . $state . '.json';
        file_put_contents($stateFile, json_encode(['user_id' => $user['id'], 'token' => $user['token'], 'created_at' => time()]));
        $authUrl = 'https://login.microsoftonline.com/' . rawurlencode($cfg['tenant_id']) . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id' => $cfg['client_id'],
            'response_type' => 'code',
            'redirect_uri' => msRedirectUri(),
            'response_mode' => 'query',
            'scope' => 'offline_access User.Read Calendars.ReadWrite OnlineMeetings.ReadWrite',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
        respond(['success' => true, 'auth_url' => $authUrl]);
        break;

    case 'ms-auth-callback':
        $code = $_GET['code'] ?? '';
        $state = $_GET['state'] ?? '';
        $stateFile = sys_get_temp_dir() . '/mmcrm_ms_state_' . basename($state) . '.json';
        $stateData = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
        if (!$code || !$stateData || time() - ($stateData['created_at'] ?? 0) > 900) {
            header('Location: ' . baseUrl() . '/index.html?ms=failed');
            exit;
        }
        @unlink($stateFile);
        $tokenRes = msTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => msRedirectUri(),
            'scope' => 'offline_access User.Read Calendars.ReadWrite OnlineMeetings.ReadWrite',
        ]);
        if (!$tokenRes['success']) {
            header('Location: ' . baseUrl() . '/index.html?ms=failed');
            exit;
        }
        $tmpUser = ['id' => $stateData['user_id'], 'token' => $stateData['token']];
        saveMsUserConnection($stateData['user_id'], $tokenRes['token']);
        $profileRes = msGraph($tmpUser, 'GET', '/me?$select=id,displayName,mail,userPrincipalName');
        if ($profileRes['success']) saveMsUserConnection($stateData['user_id'], $tokenRes['token'], $profileRes['data']);
        header('Location: ' . baseUrl() . '/index.html?ms=connected');
        exit;

    case 'ms-disconnect':
        $user = requireAuth();
        $tokens = getMsTokens();
        unset($tokens[$user['id']]);
        saveMsTokens($tokens);
        logActivity($user, 'ms.disconnected', []);
        respond(['success' => true]);
        break;

    case 'ms-calendar-view':
        $user = requireAuth();
        $start = $_GET['start'] ?? date('c');
        $end = $_GET['end'] ?? date('c', strtotime('+14 days'));
        $path = '/me/calendarView?startDateTime=' . rawurlencode($start) . '&endDateTime=' . rawurlencode($end) . '&$select=id,subject,start,end,location,webLink,isOnlineMeeting,onlineMeeting,attendees,isCancelled';
        $res = msGraph($user, 'GET', $path);
        if (!$res['success']) respond(['success' => false, 'error' => $res['error'] ?? 'Calendar unavailable'], 400);
        $events = array_values(array_filter($res['data']['value'] ?? [], fn($e) => empty($e['isCancelled'])));
        respond(['success' => true, 'events' => $events]);
        break;

    case 'ms-sync-cancellations':
        // Check each scheduled CRM meeting with a calendar_event_id against Outlook.
        // If the event no longer exists in Outlook (deleted/cancelled there), mark it cancelled in CRM
        // and revert the linked lead status.
        $user = requireAuth();
        if (!msUserConnection($user['id'] ?? '')) respond(['success' => true, 'cancelled' => 0]);
        $meetings = getMeetings();
        $leads = getLeads();
        $cancelledCount = 0;
        $leadsChanged = false;
        foreach ($meetings as &$m) {
            if (($m['status'] ?? '') !== 'scheduled' || empty($m['calendar_event_id']) || isArchived($m)) continue;
            // Only sync meetings owned by the requesting user (or admin)
            if (($m['user_id'] ?? '') !== $user['id'] && !hasPermission($user['role'] ?? 'sales_rep', 'view_all_leads')) continue;
            $check = msGraph($user, 'GET', '/me/events/' . rawurlencode($m['calendar_event_id']) . '?$select=id,isCancelled,end');
            $gone = !$check['success'] || ($check['status'] ?? 0) === 404 || !empty($check['data']['isCancelled']);
            if (!$gone) continue;
            // Mark cancelled in CRM
            $m['status'] = 'cancelled';
            $m['archived_at'] = date('c');
            $m['archived_by'] = 'ms365-sync';
            $m['archive_reason'] = 'Cancelled in Microsoft 365 calendar';
            $cancelledCount++;
            // Update the linked lead
            if (empty($m['lead_id'])) continue;
            foreach ($leads as &$l) {
                if ($l['id'] !== $m['lead_id']) continue;
                if (!empty($l['meetings'])) {
                    $l['meetings'] = array_values(array_filter($l['meetings'], fn($lm) => ($lm['meeting_id'] ?? '') !== $m['id']));
                }
                if (($l['status'] ?? '') === 'meeting_booked') {
                    $otherScheduled = array_filter($meetings, fn($om) =>
                        $om['id'] !== $m['id'] &&
                        ($om['lead_id'] ?? '') === $m['lead_id'] &&
                        ($om['status'] ?? '') === 'scheduled' &&
                        !isArchived($om)
                    );
                    if (empty($otherScheduled)) {
                        $l['status'] = 'contacted';
                        $l['stage_entered_at'] = date('c');
                        $l['next_action_type'] = 'follow_up';
                        $l['next_action_due'] = date('Y-m-d', strtotime('+3 weekdays'));
                        $l['next_action_note'] = 'Follow up after cancelled meeting';
                    }
                }
                $l['updated_at'] = date('c');
                $leadsChanged = true;
                break;
            }
            unset($l);
        }
        unset($m);
        if ($cancelledCount > 0) {
            saveMeetings($meetings);
            if ($leadsChanged) saveLeads($leads);
            logActivity($user, 'ms.sync_cancellations', ['count' => $cancelledCount]);
        }
        respond(['success' => true, 'cancelled' => $cancelledCount]);
        break;

    case 'ms-create-event':
        $user = requireAuth();
        validateRequired($input, ['meeting_id']);
        $meetings = getMeetings();
        foreach ($meetings as &$m) {
            if ($m['id'] === $input['meeting_id']) {
                if (empty(visibleMeetingsFor($user, [$m]))) {
                    respond(['success' => false, 'error' => 'Access denied'], 403);
                }
                $graph = msCreateCalendarEvent($user, $m);
                if ($graph['success']) {
                    $event = $graph['event'];
                    $m['calendar_event_id'] = $event['id'];
                    $m['calendar_web_link'] = $event['webLink'];
                    $m['teams_join_url'] = $event['joinUrl'] ?: ($m['teams_join_url'] ?? '');
                    $m['teams_link'] = $m['teams_join_url'];
                    $m['calendar_sync_status'] = 'synced';
                    $m['calendar_sync_error'] = '';
                    $m['invite_sent_at'] = date('c');
                    saveMeetings($meetings);
                    respond(['success' => true, 'meeting' => normalizeMeeting($m)]);
                }
                $m['calendar_sync_status'] = 'failed';
                $m['calendar_sync_error'] = $graph['error'] ?? 'Microsoft calendar sync failed';
                saveMeetings($meetings);
                respond(['success' => false, 'error' => $m['calendar_sync_error'], 'meeting' => normalizeMeeting($m)], 400);
            }
        }
        respond(['success' => false, 'error' => 'Meeting not found'], 404);
        break;

    // ===== LEADS =====
    case 'leads':
        $user = requireAuth();
        $leads = getLeads();
        $includeArchived = !empty($_GET['include_archived']) && hasPermission($user['role'] ?? 'sales_rep', 'view_all_leads');
        if (!$includeArchived) {
            $leads = array_values(array_filter($leads, fn($l) => !isArchived($l)));
        }
        // Role hierarchy: super_admin sees all; admin sees own + all
        // sales_rep leads (not super_admin's); sales_rep sees only own.
        $leads = visibleLeadsFor($user, $leads);
        // Filter by owner_id for admin/superadmin drilling into a rep
        if (!empty($_GET['owner_id']) && hasPermission($user['role'] ?? 'sales_rep', 'view_all_leads')) {
            $leads = array_values(array_filter($leads, fn($l) => $l['owner_id'] === $_GET['owner_id']));
        }
        // "My Leads" vs "Team Leads" split for admin/super_admin — lets them
        // separate their own owned leads from the reps' leads below them,
        // since view_all_leads otherwise returns everyone's leads mixed together.
        // $leads is already hierarchy-filtered above, so "team" here naturally
        // excludes super_admin's leads for an admin viewer too.
        if (hasPermission($user['role'] ?? 'sales_rep', 'view_all_leads') && !empty($_GET['scope'])) {
            if ($_GET['scope'] === 'mine') {
                $leads = array_values(array_filter($leads, fn($l) => $l['owner_id'] === $user['id']));
            } elseif ($_GET['scope'] === 'team') {
                $leads = array_values(array_filter($leads, fn($l) => $l['owner_id'] !== $user['id']));
            }
        }
        // Per-status counts within the current scope (ownership/mine/team)
        // but BEFORE the status filter below narrows the set — the filter
        // tab row needs these to stay in sync with what each tab will show
        // when clicked, which the scope-unaware dashboard stats can't do.
        $scopedByStatus = [];
        foreach ($leads as $l) {
            $s = $l['status'] ?? 'new';
            $scopedByStatus[$s] = ($scopedByStatus[$s] ?? 0) + 1;
        }
        // Filter by status
        if (!empty($_GET['status'])) {
            $status = $_GET['status'];
            $leads = array_values(array_filter($leads, fn($l) => $l['status'] === $status));
        }
        // Search
        if (!empty($_GET['search'])) {
            $q = strtolower($_GET['search']);
            $leads = array_values(array_filter($leads, fn($l) =>
                str_contains(strtolower($l['first_name'] ?? ''), $q) ||
                str_contains(strtolower($l['last_name'] ?? ''), $q) ||
                str_contains(strtolower($l['company'] ?? ''), $q) ||
                str_contains(strtolower($l['email'] ?? ''), $q)
            ));
        }
        // Sort by updated_at desc
        usort($leads, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        // Pagination
        $total  = count($leads);
        $page   = max(1, intval($_GET['page'] ?? 1));
        $limit  = min(200, intval($_GET['limit'] ?? 50));
        $leads  = array_slice($leads, ($page - 1) * $limit, $limit);
        respond(['success' => true, 'leads' => $leads, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil($total / max(1, $limit)), 'by_status' => $scopedByStatus]);
        break;

    case 'lead':
        $user = requireAuth();
        if ($method === 'GET') {
            $id = $_GET['id'] ?? '';
            $leads = getLeads();
            foreach ($leads as $l) {
                if ($l['id'] === $id) {
                    if (!canViewLead($user, $l)) {
                        respond(['success' => false, 'error' => 'Access denied'], 403);
                    }
                    respond(['success' => true, 'lead' => $l]);
                }
            }
            respond(['success' => false, 'error' => 'Lead not found'], 404);
        }
        if ($method === 'POST') {
            // Check duplicates first
            $dupes = checkDuplicates($input);
            if (!empty($dupes) && !($input['force'] ?? false)) {
                $dupeInfo = array_map(fn($d) => [
                    'name' => ($d['lead']['first_name'] ?? '') . ' ' . ($d['lead']['last_name'] ?? ''),
                    'company' => $d['lead']['company'] ?? '',
                    'owner_id' => $d['lead']['owner_id'] ?? '',
                    'match_type' => $d['match_type'],
                    'confidence' => $d['confidence']
                ], $dupes);
                // Find owner names
                $users = getUsers();
                $userMap = [];
                foreach ($users as $u) $userMap[$u['id']] = $u['name'];
                foreach ($dupeInfo as &$di) $di['owner_name'] = $userMap[$di['owner_id']] ?? 'Unknown';
                respond(['success' => false, 'error' => 'duplicate', 'duplicates' => $dupeInfo]);
            }

            $lead = [
                'id' => generateId('lead_'),
                'owner_id' => $user['id'],
                'owner_name' => $user['name'],
                'first_name' => $input['first_name'] ?? '',
                'last_name' => $input['last_name'] ?? '',
                'email' => $input['email'] ?? '',
                'phone' => $input['phone'] ?? '',
                'company' => $input['company'] ?? '',
                'title' => $input['title'] ?? '',
                'industry' => $input['industry'] ?? '',
                'country' => $input['country'] ?? '',
                'website' => $input['website'] ?? '',
                'linkedin' => $input['linkedin'] ?? '',
                'notes' => $input['notes'] ?? '',
                'status' => 'new',
                'enrichment' => '',
                'source' => $input['source'] ?? 'manual',
                'next_action_type' => $input['next_action_type'] ?? 'research',
                'next_action_due' => $input['next_action_due'] ?? '',
                'next_action_note' => $input['next_action_note'] ?? 'Research company and contact',
                'stage_entered_at' => date('c'),
                'proposal_value' => '',
                'proposal_sent_at' => '',
                'nurture_until' => '',
                'emails_sent' => 0,
                'last_email_type' => '',
                'email_history' => [],
                'meetings' => [],
                'followup_date' => $input['followup_date'] ?? '',
                'lost_reason' => '',
                'sop_progress' => [],
                'call_logs' => [],
                'requisites' => [],
                'freight_profile' => [
                    'trade_lanes' => [],
                    'cargo_types' => [],
                    'services_needed' => [],
                    'volume_estimate' => '',
                    'incoterms' => '',
                    'current_forwarder' => ''
                ],
                'deal_score' => 0,
                'ai_recommendation' => '',
                'won_details' => ['value' => '', 'service_type' => '', 'notes' => ''],
                'archived_at' => '',
                'archived_by' => '',
                'archive_reason' => '',
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            $leads = getLeads();
            $leads[] = $lead;
            saveLeads($leads);
            logActivity($user, 'lead.created', ['lead_id' => $lead['id'], 'company' => $lead['company']]);
            // Notify admins of new lead
            $allUsers = getUsers();
            foreach ($allUsers as $u) {
                if (!in_array($u['role'] ?? '', ['admin','super_admin'], true)) continue;
                if (($u['id'] ?? '') === $user['id']) continue;
                if (isArchived($u) || !($u['is_active'] ?? true)) continue;
                _applyNotif($allUsers, $u['id'], [
                    'id'         => 'notif_' . bin2hex(random_bytes(6)),
                    'notif_key'  => 'lead_created_' . $lead['id'],
                    'type'       => 'lead_created',
                    'title'      => 'New lead added',
                    'body'       => ($user['name'] ?? 'Someone') . ' added ' . ($lead['company'] ?: trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''))),
                    'lead_id'    => $lead['id'],
                    'created_at' => date('c'),
                    'read'       => false,
                ]);
            }
            saveUsers($allUsers);
            respond(['success' => true, 'lead' => $lead], 201);
        }
        if ($method === 'PUT') {
            $id = $input['id'] ?? '';
            $leads = getLeads();
            foreach ($leads as &$l) {
                if ($l['id'] === $id) {
                    if (!canViewLead($user, $l)) {
                        respond(['success' => false, 'error' => 'Access denied'], 403);
                    }
                    // Reassignment guard
                    if (isset($input['owner_id']) && $input['owner_id'] !== $l['owner_id']) {
                        if (!hasPermission($user['role'] ?? 'sales_rep', 'reassign_lead')) {
                            respond(['success' => false, 'error' => 'Insufficient permissions to reassign leads'], 403);
                        }
                        logActivity($user, 'lead.reassigned', [
                            'lead_id'        => $l['id'],
                            'new_owner_id'   => $input['owner_id'],
                            'new_owner_name' => $input['owner_name'] ?? '',
                        ]);
                        // Notify the new owner
                        $company = $l['company'] ?: trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                        addUserNotification($input['owner_id'], [
                            'id'         => 'notif_' . bin2hex(random_bytes(6)),
                            'notif_key'  => 'lead_assigned_' . $l['id'] . '_' . $input['owner_id'],
                            'type'       => 'lead_assigned',
                            'title'      => 'Lead assigned to you',
                            'body'       => ($user['name'] ?? 'Admin') . ' assigned ' . $company . ' to you',
                            'lead_id'    => $l['id'],
                            'created_at' => date('c'),
                            'read'       => false,
                        ]);
                    }
                    $oldStatus = $l['status'] ?? 'new';
                    if (array_key_exists('archive', $input)) {
                        if ($input['archive']) {
                            $l['archived_at'] = date('c');
                            $l['archived_by'] = $user['name'] ?? '';
                            $l['archive_reason'] = $input['archive_reason'] ?? 'Archived from Sales Intelligence';
                            logActivity($user, 'lead.archived', ['lead_id' => $id]);
                        } else {
                            if (!hasPermission($user['role'] ?? 'sales_rep', 'view_all_leads'))
                                respond(['success' => false, 'error' => 'Only admins can restore leads'], 403);
                            $l['archived_at'] = '';
                            $l['archived_by'] = '';
                            $l['archive_reason'] = '';
                            logActivity($user, 'lead.restored', ['lead_id' => $id]);
                        }
                    }
                    $allowed = ['first_name','last_name','email','phone','company','title','industry','country','website','linkedin','notes','notes_history','status','followup_date','lost_reason','sop_progress','call_logs','requisites','freight_profile','deal_score','ai_recommendation','won_details','owner_id','owner_name','next_action_type','next_action_due','next_action_note','stage_entered_at','proposal_value','proposal_sent_at','nurture_until','source'];
                    $changed = [];
                    foreach ($allowed as $f) {
                        if (isset($input[$f])) { $l[$f] = $input[$f]; $changed[] = $f; }
                    }
                    $l['status'] = normalizeStatus($l['status'] ?? 'new');
                    if ($l['status'] !== $oldStatus && !isset($input['stage_entered_at'])) $l['stage_entered_at'] = date('c');
                    if ($l['status'] === 'proposal_sent' && empty($l['proposal_sent_at'])) $l['proposal_sent_at'] = date('c');
                    if ($l['status'] === 'won') {
                        $details = $l['won_details'] ?? [];
                        if (empty($details['start_date']) && !empty($details['won_date'])) $details['start_date'] = $details['won_date'];
                        $l['won_details'] = $details;
                        if (empty($details['value']) || empty($details['service_type']) || empty($details['start_date'])) {
                            respond(['success' => false, 'error' => 'Won leads require deal value, service type, and start date'], 400);
                        }
                    }
                    if ($l['status'] === 'lost' && empty($l['lost_reason'])) {
                        respond(['success' => false, 'error' => 'Lost leads require a reason'], 400);
                    }
                    // A deal that's won or lost has no pending next action —
                    // whatever was queued (a follow-up, a call) is now moot,
                    // but every view (detail page, Workbench, Prospects list,
                    // Focus Queue) kept showing it as if the deal were still
                    // open until this was cleared.
                    if (in_array($l['status'], ['won', 'lost'], true) && $l['status'] !== $oldStatus) {
                        $l['next_action_type'] = '';
                        $l['next_action_note'] = '';
                        $l['next_action_due'] = '';
                    }
                    if (isActiveLead($l) && empty($l['next_action_type'])) {
                        $next = defaultNextAction($l);
                        $l['next_action_type'] = $next['type'];
                        $l['next_action_note'] = $l['next_action_note'] ?: $next['note'];
                    }
                    if (isset($input['followup_date']) && empty($input['next_action_due'])) {
                        $l['next_action_due'] = $input['followup_date'];
                        $l['next_action_type'] = $l['next_action_type'] ?: 'follow_up';
                    }
                    $l['updated_at'] = date('c');
                    saveLeads($leads);
                    logActivity($user, 'lead.updated', ['lead_id' => $id, 'fields' => $changed]);
                    // Notify admins on won or lost
                    $newStatus = $l['status'] ?? '';
                    if (in_array($newStatus, ['won','lost'], true) && $newStatus !== $oldStatus) {
                        $company = $l['company'] ?: trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                        $emoji = $newStatus === 'won' ? 'Won' : 'Lost';
                        $notifUsers = getUsers();
                        foreach ($notifUsers as $u) {
                            if (!in_array($u['role'] ?? '', ['admin','super_admin'], true)) continue;
                            if (($u['id'] ?? '') === $user['id']) continue;
                            if (isArchived($u) || !($u['is_active'] ?? true)) continue;
                            _applyNotif($notifUsers, $u['id'], [
                                'id'         => 'notif_' . bin2hex(random_bytes(6)),
                                'notif_key'  => 'lead_' . $newStatus . '_' . $id,
                                'type'       => 'lead_' . $newStatus,
                                'title'      => 'Deal ' . $emoji . ': ' . $company,
                                'body'       => ($user['name'] ?? 'Someone') . ' marked ' . $company . ' as ' . $newStatus,
                                'lead_id'    => $id,
                                'created_at' => date('c'),
                                'read'       => false,
                            ]);
                        }
                        saveUsers($notifUsers);
                    }
                    respond(['success' => true, 'lead' => $l]);
                }
            }
            respond(['success' => false, 'error' => 'Lead not found'], 404);
        }
        if ($method === 'DELETE') {
            if (!hasPermission($user['role'] ?? 'sales_rep', 'delete_lead')) {
                respond(['success' => false, 'error' => 'Insufficient permissions to delete leads'], 403);
            }
            $id = $input['id'] ?? $_GET['id'] ?? '';
            $leads = getLeads();
            $leads = array_values(array_filter($leads, fn($l) => $l['id'] !== $id));
            saveLeads($leads);
            logActivity($user, 'lead.deleted', ['lead_id' => $id]);
            respond(['success' => true]);
        }
        break;

    // ===== AI RESEARCH =====
    case 'enrich-lead':
        $user = requireAuth();
        checkRateLimit('ai_' . $user['id'], 20, 60);
        $admin = getAdmin();
        $provider = $admin['default_provider'] ?? 'groq';
        $apiKey = $admin[$provider . '_key'] ?? '';
        if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured. Ask admin to add API keys in Settings.'], 400);

        $leadId = $input['lead_id'] ?? '';
        $leads = getLeads();
        $targetLead = null;
        foreach ($leads as &$l) {
            if ($l['id'] === $leadId) { $targetLead = &$l; break; }
        }
        if (!$targetLead) respond(['success' => false, 'error' => 'Lead not found'], 404);
        if (!canViewLead($user, $targetLead)) respond(['success' => false, 'error' => 'Access denied'], 403);

        $prompt = buildResearchPrompt($targetLead);
        $res = callLLM($provider, $apiKey, $prompt);
        if (!$res['success']) respond(['success' => false, 'error' => 'AI research failed: ' . $res['error']]);

        // Clean and parse
        $content = $res['content'];
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);
        if (!$parsed) respond(['success' => false, 'error' => 'AI returned invalid research data. Try again.']);

        $targetLead['enrichment'] = $content;
        if (in_array($targetLead['status'], ['new','qualified'], true)) {
            $targetLead['status'] = 'researched';
            $targetLead['stage_entered_at'] = date('c');
        }
        $targetLead['next_action_type'] = 'email';
        $targetLead['next_action_note'] = 'Send first personalized outreach';
        $targetLead['updated_at'] = date('c');
        saveLeads($leads);
        logActivity($user, 'lead.enriched', ['lead_id' => $leadId]);

        respond(['success' => true, 'enrichment' => $parsed, 'lead' => $targetLead]);
        break;

    // ===== EMAIL GENERATION =====
    case 'generate-email':
        $user = requireAuth();
        checkRateLimit('ai_' . $user['id'], 20, 60);
        $admin = getAdmin();
        $provider = $admin['default_provider'] ?? 'groq';
        $apiKey = $admin[$provider . '_key'] ?? '';
        if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

        $leadId = $input['lead_id'] ?? '';
        $emailType = $input['email_type'] ?? 'initial';
        $customInstructions = $input['custom_instructions'] ?? '';

        $leads = getLeads();
        $lead = null;
        foreach ($leads as $l) { if ($l['id'] === $leadId) { $lead = $l; break; } }
        if (!$lead) respond(['success' => false, 'error' => 'Lead not found'], 404);
        if (!canViewLead($user, $lead)) respond(['success' => false, 'error' => 'Access denied'], 403);

        $settings = [
            'sender_name' => $user['name'],
            'sender_title' => $admin['sender_title'] ?? 'Sales Representative',
            'sender_company' => $admin['sender_company'] ?? 'Militzer & Münch',
            'company_description' => $admin['company_description'] ?? 'global freight forwarding',
            'value_proposition' => $admin['value_proposition'] ?? '',
            'social_proof' => $admin['social_proof'] ?? '',
            'mm_footer' => $admin['mm_footer'] ?? ''
        ];

        $result = generateEmailContent($provider, $apiKey, $lead, $emailType, $customInstructions, $settings);
        if (!$result['success']) respond(['success' => false, 'error' => $result['error']]);

        respond(['success' => true, 'subject' => $result['subject'], 'body' => $result['body'], 'email_type' => $emailType]);
        break;

    // ===== SAVE EMAIL =====
    case 'save-email':
        $user = requireAuth();
        $leadId = $input['lead_id'] ?? '';
        $subject = $input['subject'] ?? '';
        $body = $input['body'] ?? '';
        $emailType = $input['email_type'] ?? 'initial';

        $leads = getLeads();
        foreach ($leads as &$l) {
            if ($l['id'] === $leadId) {
                if (!canViewLead($user, $l)) respond(['success' => false, 'error' => 'Access denied'], 403);
                $l['email_history'][] = ['type' => $emailType, 'subject' => $subject, 'content' => $body, 'sent_at' => date('c'), 'sent_by' => $user['name']];
                $l['emails_sent'] = ($l['emails_sent'] ?? 0) + 1;
                $l['last_email_type'] = $emailType;
                if (in_array($l['status'], ['new','qualified','researched'], true)) {
                    $l['status'] = 'contacted';
                    $l['stage_entered_at'] = date('c');
                }
                $l['next_action_type'] = 'follow_up';
                $l['next_action_due'] = $l['next_action_due'] ?: date('Y-m-d', strtotime('+3 weekdays'));
                $l['next_action_note'] = 'Follow up after email';
                $l['updated_at'] = date('c');
                saveLeads($leads);
                logActivity($user, 'email.sent', ['lead_id' => $leadId, 'type' => $emailType]);
                respond(['success' => true, 'lead' => $l]);
            }
        }
        respond(['success' => false, 'error' => 'Lead not found'], 404);
        break;

    // ===== CALL PITCH =====
    case 'generate-call-pitch':
        $user = requireAuth();
        checkRateLimit('ai_' . $user['id'], 20, 60);
        $admin = getAdmin();
        $provider = $admin['default_provider'] ?? 'groq';
        $apiKey = $admin[$provider . '_key'] ?? '';
        if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

        $leadId = $input['lead_id'] ?? '';
        $pitchType = $input['pitch_type'] ?? 'cold_no_email';
        $leads = getLeads();
        $lead = null;
        foreach ($leads as $l) { if ($l['id'] === $leadId) { $lead = $l; break; } }
        if (!$lead) respond(['success' => false, 'error' => 'Lead not found'], 404);
        if (!canViewLead($user, $lead)) respond(['success' => false, 'error' => 'Access denied'], 403);

        $settings = [
            'sender_name' => $user['name'],
            'sender_title' => $admin['sender_title'] ?? 'Sales Representative',
            'sender_company' => $admin['sender_company'] ?? 'Militzer & Münch'
        ];

        $result = generateCallPitch($provider, $apiKey, $lead, $pitchType, $settings);
        if (!$result['success']) respond(['success' => false, 'error' => $result['error']]);
        respond(['success' => true, 'title' => $result['title'], 'pitch' => $result['pitch']]);
        break;

    // ===== MEETINGS =====
    case 'meetings':
        $user = requireAuth();
        $meetings = getMeetings();
        $includeArchived = !empty($_GET['include_archived']) && hasPermission($user['role'] ?? 'sales_rep', 'view_all_leads');
        if (!$includeArchived) {
            $meetings = array_values(array_filter($meetings, fn($m) => !isArchived($m)));
        }
        $meetings = visibleMeetingsFor($user, $meetings);
        usort($meetings, fn($a, $b) => strcmp($a['start_time'] ?? '', $b['start_time'] ?? ''));
        respond(['success' => true, 'meetings' => $meetings]);
        break;

    case 'meeting':
        $user = requireAuth();
        if ($method === 'POST') {
            $draft = buildMeetingFromInput($input, $user);
            if (empty($draft['start_time'])) respond(['success' => false, 'error' => 'Meeting date and time required'], 400);
            if (empty($draft['attendees'])) respond(['success' => false, 'error' => 'At least one valid attendee email is required'], 400);
            if (empty($draft['notes'])) respond(['success' => false, 'error' => 'Meeting agenda or notes required'], 400);
            if ($draft['lead_id']) {
                $linkedLead = null;
                foreach (getLeads() as $l) { if ($l['id'] === $draft['lead_id']) { $linkedLead = $l; break; } }
                if ($linkedLead && !canViewLead($user, $linkedLead)) respond(['success' => false, 'error' => 'Access denied'], 403);
            }
            $meeting = array_merge($draft, [
                'id' => generateId('mtg_'),
                'calendar_event_id' => '',
                'calendar_web_link' => '',
                'calendar_sync_status' => 'not_connected',
                'calendar_sync_error' => 'Microsoft 365 is not connected',
                'invite_sent_at' => '',
                'status' => 'scheduled',
                'outcome' => '',
                'outcome_notes' => '',
                'created_at' => date('c')
            ]);
            if (msUserConnection($user['id'] ?? '')) {
                $graph = msCreateCalendarEvent($user, $meeting);
                if ($graph['success']) {
                    $event = $graph['event'];
                    $meeting['calendar_event_id'] = $event['id'];
                    $meeting['calendar_web_link'] = $event['webLink'];
                    $meeting['teams_join_url'] = $event['joinUrl'] ?: ($meeting['teams_join_url'] ?? '');
                    $meeting['teams_link'] = $meeting['teams_join_url'];
                    $meeting['calendar_sync_status'] = 'synced';
                    $meeting['calendar_sync_error'] = '';
                    $meeting['invite_sent_at'] = date('c');
                } else {
                    $meeting['calendar_sync_status'] = 'failed';
                    $meeting['calendar_sync_error'] = $graph['error'] ?? 'Microsoft calendar sync failed';
                }
            }
            $meeting = normalizeMeeting($meeting);
            $meetings = getMeetings();
            $meetings[] = $meeting;
            saveMeetings($meetings);
            logActivity($user, 'meeting.created', ['meeting_id' => $meeting['id'], 'lead_id' => $meeting['lead_id']]);
            // Notify admins of new meeting
            $mtgUsers = getUsers();
            foreach ($mtgUsers as $u) {
                if (!in_array($u['role'] ?? '', ['admin','super_admin'], true)) continue;
                if (($u['id'] ?? '') === $user['id']) continue;
                if (isArchived($u) || !($u['is_active'] ?? true)) continue;
                _applyNotif($mtgUsers, $u['id'], [
                    'id'         => 'notif_' . bin2hex(random_bytes(6)),
                    'notif_key'  => 'meeting_created_' . $meeting['id'],
                    'type'       => 'meeting_created',
                    'title'      => 'Meeting booked',
                    'body'       => ($user['name'] ?? 'Someone') . ' booked: ' . ($meeting['title'] ?? 'Meeting'),
                    'lead_id'    => $meeting['lead_id'] ?? '',
                    'created_at' => date('c'),
                    'read'       => false,
                ]);
            }
            saveUsers($mtgUsers);

            // Update lead status
            if ($meeting['lead_id']) {
                $leads = getLeads();
                foreach ($leads as &$l) {
                    if ($l['id'] === $meeting['lead_id']) {
                        if (in_array($l['status'], ['new','qualified','researched','contacted'], true)) {
                            $l['status'] = 'meeting_booked';
                            $l['stage_entered_at'] = date('c');
                        }
                        $l['next_action_type'] = 'meeting';
                        $l['next_action_due'] = substr($meeting['start_time'], 0, 10);
                        $l['next_action_note'] = 'Prepare for scheduled meeting';
                        $l['meetings'][] = ['meeting_id' => $meeting['id'], 'title' => $meeting['title'], 'date' => $meeting['start_time']];
                        $l['updated_at'] = date('c');
                        break;
                    }
                }
                saveLeads($leads);
            }
            respond(['success' => true, 'meeting' => $meeting, 'calendar_status' => $meeting['calendar_sync_status'], 'calendar_error' => $meeting['calendar_sync_error'] ?? ''], 201);
        }
        if ($method === 'PUT') {
            $id = $input['id'] ?? '';
            $meetings = getMeetings();
            foreach ($meetings as &$m) {
                if ($m['id'] === $id) {
                    if (empty(visibleMeetingsFor($user, [$m]))) {
                        respond(['success' => false, 'error' => 'Access denied'], 403);
                    }
                    foreach (['status','outcome','outcome_notes','title','start_time','end_time','start_time_local','end_time_local','location','location_type','teams_link','teams_join_url','calendar_event_id','calendar_web_link','calendar_sync_status','calendar_sync_error','invite_sent_at','notes','attendees','client_email'] as $f) {
                        if (isset($input[$f])) $m[$f] = $input[$f];
                    }
                    if (array_key_exists('archive', $input)) {
                        if ($input['archive']) {
                            $m['archived_at'] = date('c');
                            $m['archived_by'] = $user['name'] ?? '';
                            $m['archive_reason'] = $input['archive_reason'] ?? 'Meeting cancelled or archived';
                            $wasCancelled = $m['status'] === 'scheduled';
                            $m['status'] = $wasCancelled ? 'cancelled' : $m['status'];

                            // Cancel the Outlook calendar event if one exists
                            if (!empty($m['calendar_event_id']) && msUserConnection($user['id'] ?? '')) {
                                msDeleteCalendarEvent($user, $m['calendar_event_id']);
                            }

                            // Update the linked lead/prospect
                            if (!empty($m['lead_id'])) {
                                $leads = getLeads();
                                foreach ($leads as &$l) {
                                    if ($l['id'] === $m['lead_id']) {
                                        // Remove this meeting from the lead's meetings array
                                        if (!empty($l['meetings'])) {
                                            $l['meetings'] = array_values(array_filter($l['meetings'], fn($lm) => ($lm['meeting_id'] ?? '') !== $m['id']));
                                        }

                                        // Revert lead status from meeting_booked if no other scheduled meetings remain
                                        if (($l['status'] ?? '') === 'meeting_booked') {
                                            $otherScheduled = array_values(array_filter($meetings, fn($om) =>
                                                $om['id'] !== $m['id'] &&
                                                ($om['lead_id'] ?? '') === $m['lead_id'] &&
                                                ($om['status'] ?? '') === 'scheduled' &&
                                                !isArchived($om)
                                            ));
                                            if (empty($otherScheduled)) {
                                                $l['status'] = 'contacted';
                                                $l['stage_entered_at'] = date('c');
                                                $l['next_action_type'] = 'follow_up';
                                                $l['next_action_due'] = date('Y-m-d', strtotime('+3 weekdays'));
                                                $l['next_action_note'] = 'Follow up after cancelled meeting';
                                            } else {
                                                // Still has meetings — update next_action_due to earliest remaining meeting
                                                usort($otherScheduled, fn($a, $b) => strcmp($a['start_time'] ?? '', $b['start_time'] ?? ''));
                                                $l['next_action_type'] = 'meeting';
                                                $l['next_action_due'] = substr($otherScheduled[0]['start_time'], 0, 10);
                                                $l['next_action_note'] = 'Prepare for scheduled meeting';
                                            }
                                        }

                                        $l['updated_at'] = date('c');
                                        break;
                                    }
                                }
                                saveLeads($leads);
                            }
                        } else {
                            $m['archived_at'] = '';
                            $m['archived_by'] = '';
                            $m['archive_reason'] = '';
                        }
                    }
                    $m = normalizeMeeting($m);
                    saveMeetings($meetings);
                    logActivity($user, 'meeting.updated', ['meeting_id' => $id, 'status' => $m['status'] ?? '']);
                    respond(['success' => true, 'meeting' => $m]);
                }
            }
            respond(['success' => false, 'error' => 'Meeting not found'], 404);
        }
        break;

    // ===== SAVE CALL LOG =====
    case 'save-call-log':
        $user = requireAuth();
        $leadId = $input['lead_id'] ?? '';
        $duration = $input['duration'] ?? 0;
        $outcome = $input['outcome'] ?? 'neutral';
        $notes = $input['notes'] ?? '';
        $discussedTopics = $input['discussed_topics'] ?? [];
        $reqItems = $input['requisites'] ?? [];
        if (!$outcome) respond(['success' => false, 'error' => 'Call outcome required'], 400);

        $leads = getLeads();
        foreach ($leads as &$l) {
            if ($l['id'] === $leadId) {
                if (!canViewLead($user, $l)) respond(['success' => false, 'error' => 'Access denied'], 403);
                if (!isset($l['call_logs'])) $l['call_logs'] = [];
                if (!isset($l['requisites'])) $l['requisites'] = [];

                $l['call_logs'][] = [
                    'id' => generateId('call_'),
                    'date' => date('c'),
                    'duration' => intval($duration),
                    'outcome' => $outcome,
                    'notes' => $notes,
                    'discussed_topics' => $discussedTopics,
                    'logged_by' => $user['name']
                ];

                // Append requisites
                foreach ($reqItems as $req) {
                    $l['requisites'][] = [
                        'field' => $req['field'] ?? '',
                        'value' => $req['value'] ?? '',
                        'logged_at' => date('c'),
                        'source' => 'call'
                    ];
                }

                // Auto-advance status
                if (in_array($l['status'], ['new', 'qualified', 'researched'], true)) {
                    $l['status'] = 'contacted';
                    $l['stage_entered_at'] = date('c');
                }
                $l['next_action_type'] = $outcome === 'positive' ? 'meeting' : 'follow_up';
                $l['next_action_due'] = $l['next_action_due'] ?: date('Y-m-d', strtotime($outcome === 'positive' ? '+1 weekday' : '+3 weekdays'));
                $l['next_action_note'] = $outcome === 'positive' ? 'Book discovery meeting' : 'Follow up after call';
                $l['updated_at'] = date('c');
                saveLeads($leads);
                logActivity($user, 'call.logged', ['lead_id' => $leadId, 'outcome' => $outcome]);
                respond(['success' => true, 'lead' => $l]);
            }
        }
        respond(['success' => false, 'error' => 'Lead not found'], 404);
        break;

    // ===== AI RECOMMENDATION =====
    case 'ai-recommend':
        $user = requireAuth();
        checkRateLimit('ai_' . $user['id'], 20, 60);
        $admin = getAdmin();
        $provider = $admin['default_provider'] ?? 'groq';
        $apiKey = $admin[$provider . '_key'] ?? '';
        if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

        $leadId = $input['lead_id'] ?? '';
        $leads = getLeads();
        $lead = null;
        foreach ($leads as &$l) {
            if ($l['id'] === $leadId) { $lead = &$l; break; }
        }
        if (!$lead) respond(['success' => false, 'error' => 'Lead not found'], 404);
        if (!canViewLead($user, $lead)) respond(['success' => false, 'error' => 'Access denied'], 403);

        $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        $rData = !empty($lead['enrichment']) ? 'Available' : 'None';
        $emailCount = $lead['emails_sent'] ?? 0;
        $callCount = count($lead['call_logs'] ?? []);
        $fp = $lead['freight_profile'] ?? [];
        $fpComplete = (!empty($fp['trade_lanes']) ? 1 : 0) + (!empty($fp['cargo_types']) ? 1 : 0) + (!empty($fp['services_needed']) ? 1 : 0) + (!empty($fp['volume_estimate']) ? 1 : 0);
        $hasMeetings = count($lead['meetings'] ?? []);
        $hasFollowup = !empty($lead['followup_date']);
        $daysSinceUpdate = floor((time() - strtotime($lead['updated_at'] ?? 'now')) / 86400);
        $callOutcomes = implode(', ', array_map(fn($c) => $c['outcome'] ?? 'unknown', $lead['call_logs'] ?? []));

        $prompt = "You are a B2B freight forwarding sales advisor for Militzer & Münch (M&M).

LEAD DATA:
- Name: {$name}
- Company: {$lead['company']}
- Title: {$lead['title']}
- Industry: {$lead['industry']}
- Country: {$lead['country']}
- Status: {$lead['status']}
- Research: {$rData}
- Emails Sent: {$emailCount}
- Calls Made: {$callCount} (outcomes: {$callOutcomes})
- Meetings: {$hasMeetings}
- Follow-up Set: " . ($hasFollowup ? $lead['followup_date'] : 'No') . "
- Freight Profile Completeness: {$fpComplete}/4
- Days Since Last Update: {$daysSinceUpdate}

Analyze this lead and return ONLY valid JSON (no markdown, no backticks):

{
  \"deal_score\": 65,
  \"win_probability\": \"Medium\",
  \"next_action\": \"Specific actionable recommendation in 1-2 sentences\",
  \"risk_flags\": [\"Risk 1\", \"Risk 2\"],
  \"reasoning\": \"Brief explanation of scoring in 2-3 sentences\"
}

Score 0-100 based on: engagement level, freight profile completeness, stage progression, follow-up compliance, call outcomes, research quality.";

        $res = callLLM($provider, $apiKey, $prompt);
        if (!$res['success']) respond(['success' => false, 'error' => 'AI recommendation failed: ' . $res['error']]);

        $content = $res['content'];
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);

        $rec = json_decode($content, true);
        if (!$rec) respond(['success' => false, 'error' => 'AI returned invalid recommendation. Try again.']);

        $lead['deal_score'] = intval($rec['deal_score'] ?? 0);
        $lead['ai_recommendation'] = $rec['next_action'] ?? '';
        $lead['updated_at'] = date('c');
        saveLeads($leads);

        respond(['success' => true, 'recommendation' => $rec, 'lead' => $lead]);
        break;

    // ===== TEMPLATES =====
    case 'templates':
        requireAuth();
        respond(['success' => true, 'templates' => getTemplates()]);
        break;

    case 'sops':
        requireAuth();
        respond(['success' => true, 'sops' => getDefaultSOPs()]);
        break;

    // ===== SOP RECORDS (customer SOPs) =====
    case 'sop-records':
        $user = requireAuth();
        if ($method === 'GET') {
            $records = dbLoadAll('sop_records');
            respond(['success' => true, 'records' => array_values($records)]);
        }
        if ($method === 'POST') {
            if (!in_array($user['role'] ?? '', ['admin', 'super_admin'], true))
                respond(['success' => false, 'error' => 'Only admins can create SOPs'], 403);
            $records = dbLoadAll('sop_records');
            $record = ['id' => generateId('sop_'), 'created_by' => $user['id'], 'created_by_name' => $user['name'],
                'created_at' => date('c'), 'updated_at' => date('c'), 'status' => 'active'];
            // Store all submitted fields dynamically
            foreach ($input as $k => $v) {
                if (!in_array($k, ['id','created_by','created_by_name','created_at','updated_at'], true))
                    $record[$k] = $v;
            }
            $records[] = $record;
            dbSaveAll('sop_records', $records);
            logActivity($user, 'sop.created', ['id' => $record['id'], 'customer' => $record['customer_name'] ?? '']);
            // Notify all users of new SOP
            $sopUsers = getUsers();
            foreach ($sopUsers as $u) {
                if (($u['id'] ?? '') === $user['id']) continue;
                if (isArchived($u) || !($u['is_active'] ?? true)) continue;
                _applyNotif($sopUsers, $u['id'], [
                    'id'         => 'notif_' . bin2hex(random_bytes(6)),
                    'notif_key'  => 'sop_created_' . $record['id'],
                    'type'       => 'sop_created',
                    'title'      => 'New SOP created',
                    'body'       => ($user['name'] ?? 'Someone') . ' created SOP for ' . ($record['customer_name'] ?? 'a customer'),
                    'created_at' => date('c'),
                    'read'       => false,
                ]);
            }
            saveUsers($sopUsers);
            respond(['success' => true, 'record' => $record]);
        }
        if ($method === 'PUT') {
            if (!in_array($user['role'] ?? '', ['admin', 'super_admin'], true))
                respond(['success' => false, 'error' => 'Only admins can edit SOPs'], 403);
            $id = $input['id'] ?? '';
            if (!$id) respond(['success' => false, 'error' => 'ID required'], 400);
            $records = dbLoadAll('sop_records');
            $found = false;
            foreach ($records as &$r) {
                if ($r['id'] === $id) {
                    foreach ($input as $k => $v) {
                        if (!in_array($k, ['id','created_by','created_by_name','created_at'], true))
                            $r[$k] = $v;
                    }
                    $r['updated_at'] = date('c');
                    $found = true; break;
                }
            }
            if (!$found) respond(['success' => false, 'error' => 'SOP not found'], 404);
            dbSaveAll('sop_records', $records);
            respond(['success' => true]);
        }
        if ($method === 'DELETE') {
            if (!in_array($user['role'] ?? '', ['admin', 'super_admin'], true))
                respond(['success' => false, 'error' => 'Only admins can delete SOPs'], 403);
            $id = $input['id'] ?? '';
            $records = array_values(array_filter(dbLoadAll('sop_records'), fn($r) => $r['id'] !== $id));
            dbSaveAll('sop_records', $records);
            respond(['success' => true]);
        }
        break;

    // ===== SOP FIELD CONFIG =====
    case 'sop-field-config':
        $user = $method === 'GET' ? requireAuth() : requireAdmin();
        if ($method === 'GET') {
            $admin = getAdmin();
            respond(['success' => true, 'fields' => $admin['sop_field_config'] ?? getDefaultSopFields()]);
        }
        if ($method === 'POST') {
            $admin = getAdmin();
            $admin['sop_field_config'] = $input['fields'] ?? [];
            saveAdmin($admin);
            respond(['success' => true]);
        }
        break;

    // ===== CSV IMPORT =====
    case 'import':
        $user = requireAuth();
        $csvData = $input['leads'] ?? [];
        if (empty($csvData)) respond(['success' => false, 'error' => 'No leads data provided'], 400);

        $leads = getLeads();
        $imported = 0;
        $dupeCount = 0;

        foreach ($csvData as $row) {
            $dupes = checkDuplicates($row);
            if (!empty($dupes)) { $dupeCount++; continue; }
            $leads[] = [
                'id' => generateId('lead_'),
                'owner_id' => $user['id'],
                'owner_name' => $user['name'],
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'email' => $row['email'] ?? '',
                'phone' => $row['phone'] ?? '',
                'company' => $row['company'] ?? '',
                'title' => $row['title'] ?? '',
                'industry' => $row['industry'] ?? '',
                'country' => $row['country'] ?? '',
                'website' => $row['website'] ?? '',
                'linkedin' => $row['linkedin'] ?? '',
                'notes' => $row['notes'] ?? '',
                'status' => 'new',
                'enrichment' => '',
                'source' => 'csv',
                'next_action_type' => 'research',
                'next_action_due' => '',
                'next_action_note' => 'Research company and contact',
                'stage_entered_at' => date('c'),
                'proposal_value' => '',
                'proposal_sent_at' => '',
                'nurture_until' => '',
                'emails_sent' => 0,
                'last_email_type' => '',
                'email_history' => [],
                'meetings' => [],
                'followup_date' => '',
                'lost_reason' => '',
                'sop_progress' => [],
                'call_logs' => [],
                'requisites' => [],
                'freight_profile' => ['trade_lanes'=>[],'cargo_types'=>[],'services_needed'=>[],'volume_estimate'=>'','incoterms'=>'','current_forwarder'=>''],
                'deal_score' => 0,
                'ai_recommendation' => '',
                'won_details' => ['value'=>'','service_type'=>'','notes'=>''],
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            $imported++;
        }
        saveLeads($leads);
        logActivity($user, 'leads.imported', ['count' => $imported]);
        respond(['success' => true, 'imported' => $imported, 'duplicates_skipped' => $dupeCount]);
        break;

    // ===== STATS =====
    case 'stats':
        $user = requireAuth();
        $allLeads = getLeads();
        $allLeads = array_values(array_filter($allLeads, fn($l) => !isArchived($l)));
        $leads = visibleLeadsFor($user, $allLeads);

        $byStatus = [];
        foreach ($leads as $l) {
            $s = $l['status'] ?? 'new';
            $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
        }

        $totalEmails = array_sum(array_column($leads, 'emails_sent'));
        $meetings = getMeetings();
        $meetings = array_values(array_filter($meetings, fn($m) => !isArchived($m)));
        $meetings = visibleMeetingsFor($user, $meetings);

        // Follow-ups due
        $today = date('Y-m-d');
        $followups = array_values(array_filter($leads, fn($l) => !empty($l['followup_date']) && $l['followup_date'] <= $today));
        $nextDue = array_values(array_filter($leads, fn($l) => isActiveLead($l) && !empty($l['next_action_due']) && $l['next_action_due'] <= $today));
        $noNextAction = array_values(array_filter($leads, fn($l) => isActiveLead($l) && empty($l['next_action_type'])));
        $staleLeads = array_values(array_filter($leads, fn($l) => isActiveLead($l) && !empty($l['updated_at']) && strtotime($l['updated_at']) < strtotime('-14 days')));

        // Meeting stats — exclude cancelled meetings
        $meetingsArr = array_values(array_filter($meetings, fn($m) => ($m['status'] ?? '') !== 'cancelled'));
        $weekStart = weekStart();
        $weekEnd = weekEnd();
        $meetingsThisWeek = array_filter($meetingsArr, fn($m) => !empty($m['start_time']) && substr($m['start_time'], 0, 10) >= $weekStart && substr($m['start_time'], 0, 10) <= $weekEnd);
        $meetingsPending = array_filter($meetingsArr, fn($m) => ($m['status'] ?? '') === 'scheduled');
        $meetingsToday = array_filter($meetingsArr, fn($m) => !empty($m['start_time']) && substr($m['start_time'], 0, 10) === $today);

        // Deal scores
        $activeLeads = array_filter($leads, fn($l) => !in_array($l['status'], ['won', 'lost']));
        $scores = array_filter(array_column($activeLeads, 'deal_score'), fn($s) => $s > 0);
        $avgScore = count($scores) > 0 ? round(array_sum($scores) / count($scores)) : 0;
        $pipelineValue = ($byStatus['proposal_sent'] ?? 0);

        // Dollar totals — proposal_value is the editable Deal Value field and
        // is the source of truth; won_details.value is only a fallback for
        // older Won records that predate proposal_value being kept in sync.
        $dealAmount = fn($l) => moneyValue($l['proposal_value'] ?: ($l['won_details']['value'] ?? 0));
        $wonDealValue = array_sum(array_map($dealAmount, array_filter($leads, fn($l) => ($l['status'] ?? '') === 'won')));
        $pipelineDollarValue = array_sum(array_map($dealAmount, array_filter($leads, fn($l) => in_array($l['status'] ?? '', ['proposal_sent', 'meeting_booked'], true))));

        $visibleUserIds = hasPermission($user['role'] ?? 'sales_rep', 'view_team_stats')
            ? array_values(array_map(fn($u) => $u['id'], array_filter(getUsers(), fn($u) => ($u['is_active'] ?? true) && !isArchived($u))))
            : [$user['id']];
        $visibleTargets = targetsForUsers($visibleUserIds);
        $statsPayload = [
            'total_leads' => count($leads),
            'by_status' => $byStatus,
            'total_emails' => $totalEmails,
            'emails_sent' => $totalEmails,
            'total_meetings' => count($meetingsArr),
            'followups_due' => $followups,
            'next_actions_due' => $nextDue,
            'no_next_action' => $noNextAction,
            'stale_leads' => $staleLeads,
            'won' => $byStatus['won'] ?? 0,
            'lost' => $byStatus['lost'] ?? 0,
            'meetings_this_week' => count(array_values($meetingsThisWeek)),
            'meetings_pending' => count(array_values($meetingsPending)),
            'meetings_today' => count(array_values($meetingsToday)),
            'meetings_today_list' => array_values($meetingsToday),
            'avg_deal_score' => $avgScore,
            'pipeline_value' => $pipelineValue,
            'won_deal_value' => $wonDealValue,
            'pipeline_dollar_value' => $pipelineDollarValue,
            'proposals_sent' => $byStatus['proposal_sent'] ?? 0,
            'target_progress' => targetProgressForUser($user['id'], $allLeads, $meetingsArr, $visibleTargets),
        ];

        // Team stats for admin/superadmin
        if (hasPermission($user['role'] ?? 'sales_rep', 'view_team_stats')) {
            $users = getUsers();
            $userMap = [];
            foreach ($users as $u) $userMap[$u['id']] = $u['name'];
            $byOwner = [];
            // $leads (not $allLeads) — team-performance rows must respect the
            // same hierarchy as everything else: an admin viewing this table
            // shouldn't see a row for super_admin's own leads.
            foreach ($leads as $l) {
                $oid = $l['owner_id'] ?? 'unknown';
                if (!isset($byOwner[$oid])) $byOwner[$oid] = ['owner_id' => $oid, 'name' => $userMap[$oid] ?? 'Unknown', 'total' => 0, 'won' => 0, 'lost' => 0, 'emails' => 0, 'overdue' => 0, 'stale' => 0, 'no_next_action' => 0, 'meetings' => 0, 'proposals' => 0];
                $byOwner[$oid]['total']++;
                if ($l['status'] === 'won') $byOwner[$oid]['won']++;
                if ($l['status'] === 'lost') $byOwner[$oid]['lost']++;
                if ($l['status'] === 'proposal_sent') $byOwner[$oid]['proposals']++;
                $byOwner[$oid]['emails'] += ($l['emails_sent'] ?? 0);
                if (!empty($l['next_action_due']) && $l['next_action_due'] <= $today && isActiveLead($l)) $byOwner[$oid]['overdue']++;
                if (isActiveLead($l) && empty($l['next_action_type'])) $byOwner[$oid]['no_next_action']++;
                if (isActiveLead($l) && !empty($l['updated_at']) && strtotime($l['updated_at']) < strtotime('-14 days')) $byOwner[$oid]['stale']++;
            }
            foreach ($meetingsArr as $m) {
                $oid = $m['user_id'] ?? 'unknown';
                if (isset($byOwner[$oid])) $byOwner[$oid]['meetings']++;
            }
            $overdueAll = array_values(array_filter($leads, fn($l) =>
                !empty($l['next_action_due']) && $l['next_action_due'] <= $today && isActiveLead($l)
            ));
            $teamTargets = [];
            foreach (array_keys($byOwner) as $ownerId) {
                $teamTargets[$ownerId] = targetProgressForUser($ownerId, $leads, $meetingsArr, $visibleTargets);
            }
            $statsPayload['by_owner']    = array_map(function($row) use ($teamTargets) {
                $row['target_progress'] = $teamTargets[$row['owner_id']] ?? [];
                $row['targets_at_risk'] = count(array_filter($row['target_progress'], fn($t) => !empty($t['at_risk'])));
                return $row;
            }, array_values($byOwner));
            $statsPayload['overdue_all'] = $overdueAll;
            $statsPayload['team_target_progress'] = $teamTargets;
        }

        respond(['success' => true, 'stats' => $statsPayload]);
        break;

    // ===== TARGETS =====
    case 'targets':
        $user = requireAuth();
        if (hasPermission($user['role'] ?? 'sales_rep', 'view_team_stats')) {
            $activeUsers = array_values(array_filter(getUsers(), fn($u) => ($u['is_active'] ?? true) && !isArchived($u)));
            // Same hierarchy as leads/meetings — an admin managing targets
            // for "their team" shouldn't see (or be able to set) a target
            // for super_admin.
            $viewerRole = normalizeRole($user['role'] ?? 'sales_rep');
            $targetUsers = $viewerRole === 'super_admin'
                ? $activeUsers
                : array_values(array_filter($activeUsers, fn($u) => $u['id'] === $user['id'] || normalizeRole($u['role'] ?? 'sales_rep') !== 'super_admin'));
        } else {
            $targetUsers = [$user];
        }
        $userIds = array_map(fn($u) => $u['id'], $targetUsers);
        $targets = targetsForUsers($userIds);
        respond(['success' => true, 'targets' => $targets, 'users' => array_map(fn($u) => [
            'id' => $u['id'],
            'name' => $u['name'] ?? '',
            'username' => $u['username'] ?? '',
            'email' => $u['email'] ?? '',
            'title' => $u['title'] ?? '',
            'role' => normalizeRole($u['role'] ?? 'sales_rep'),
        ], $targetUsers), 'metrics' => array_map(fn($m) => [
            'metric' => $m,
            'label' => targetMetricLabel($m),
            'default_value' => defaultTargetValue($m),
            'period' => targetPeriod($m),
            'is_limit' => $m === 'overdue_hygiene',
        ], TARGET_METRICS)]);
        break;

    case 'target':
        $adminUser = requireAdmin();
        if (!hasPermission($adminUser['role'] ?? 'sales_rep', 'view_team_stats')) {
            respond(['success' => false, 'error' => 'Admin access required'], 403);
        }
        validateRequired($input, ['user_id', 'metric']);
        if (!array_key_exists('target_value', $input)) {
            respond(['success' => false, 'error' => 'Missing required fields: target_value'], 400);
        }
        $targetUser = null;
        foreach (getUsers() as $u) { if ($u['id'] === $input['user_id']) { $targetUser = $u; break; } }
        if (!$targetUser) {
            respond(['success' => false, 'error' => 'Target user not found'], 404);
        }
        // An admin can set targets for themselves and sales_reps, but not
        // for super_admin — same hierarchy as leads/meetings visibility.
        if (normalizeRole($adminUser['role'] ?? 'sales_rep') !== 'super_admin'
            && $targetUser['id'] !== $adminUser['id']
            && normalizeRole($targetUser['role'] ?? 'sales_rep') === 'super_admin') {
            respond(['success' => false, 'error' => 'Access denied'], 403);
        }
        $target = upsertTarget($input['user_id'], $input['metric'], $input['target_value'], $adminUser);
        logActivity($adminUser, 'target.saved', ['user_id' => $input['user_id'], 'metric' => $input['metric']]);
        respond(['success' => true, 'target' => $target]);
        break;

    // ===== ADMIN SETTINGS =====
    case 'admin-settings':
        if ($method === 'GET') {
            $user = requireAdmin();
            $admin = getAdmin();
            // Mask keys
            $masked = [];
            foreach ($admin as $k => $v) {
                if ((strpos($k, '_key') !== false) && $v && is_string($v)) {
                    $masked[$k] = '********' . substr($v, -4);
                } else {
                    $masked[$k] = $v;
                }
            }
            if (!empty($masked['ms_calendar']['client_secret'])) {
                $masked['ms_calendar']['client_secret'] = '********' . substr($masked['ms_calendar']['client_secret'], -4);
            }
            respond(['success' => true, 'settings' => $masked]);
        }
        if ($method === 'POST') {
            $user = requireAdmin();
            $admin = getAdmin();
            $allowed = ['default_provider','groq_key','gemini_key','anthropic_key',
                        'sender_name','sender_title','sender_company','company_description',
                        'value_proposition','social_proof','mm_footer','feature_flags',
                        'requisite_fields','ms_calendar','sop_field_config'];
            foreach ($allowed as $f) {
                if (isset($input[$f])) {
                    if ($f === 'ms_calendar' && !hasPermission($user['role'] ?? '', 'manage_global_settings')) {
                        respond(['success' => false, 'error' => 'Only superadmin can change Microsoft 365 settings'], 403);
                    }
                    // Don't overwrite key if masked
                    if (strpos($f, '_key') !== false && strpos($input[$f], '********') === 0) continue;
                    if ($f === 'ms_calendar' && !empty($input[$f]['client_secret']) && strpos($input[$f]['client_secret'], '********') === 0) {
                        $input[$f]['client_secret'] = $admin['ms_calendar']['client_secret'] ?? '';
                    }
                    $admin[$f] = $input[$f];
                }
            }
            saveAdmin($admin);
            logActivity($user, 'admin.settings_saved', []);
            respond(['success' => true]);
        }
        break;

    // Read-only, any authenticated user (not just admin) — every rep needs
    // the configured custom requisite fields to log calls correctly, but
    // admin-settings itself is admin-only since it also returns API keys
    // and other sensitive config a rep shouldn't see.
    case 'requisite-fields':
        requireAuth();
        $admin = getAdmin();
        respond(['success' => true, 'requisite_fields' => $admin['requisite_fields'] ?? []]);
        break;

    // Any authenticated user — a bare id/name/company index of every lead in
    // the system (no contact details, no deal data), used only so #client
    // chat tags can be highlighted/clickable for a name outside the viewer's
    // own visibility tier. The actual lead GET still enforces canViewLead()
    // when clicked, so this never leaks more than "a client by this name
    // exists" — same as anyone could infer from seeing the tag in a message.
    case 'client-name-index':
        requireAuth();
        $leads = array_values(array_filter(getLeads(), fn($l) => !isArchived($l)));
        $index = array_map(fn($l) => [
            'id' => $l['id'],
            'first_name' => $l['first_name'] ?? '',
            'last_name' => $l['last_name'] ?? '',
            'company' => $l['company'] ?? '',
        ], $leads);
        respond(['success' => true, 'leads' => $index]);
        break;

    // ===== USER MANAGEMENT =====
    case 'users':
        $user = requireAdmin();
        $users = getUsers();
        $includeArchived = !empty($_GET['include_archived']);
        if (!$includeArchived) $users = array_values(array_filter($users, fn($u) => !isArchived($u)));
        // Same hierarchy as leads/meetings/targets — an admin's Users list
        // shows themselves + sales_reps, not super_admin accounts.
        if (normalizeRole($user['role'] ?? 'sales_rep') !== 'super_admin') {
            $users = array_values(array_filter($users, fn($u) => $u['id'] === $user['id'] || normalizeRole($u['role'] ?? 'sales_rep') !== 'super_admin'));
        }
        $safe = array_map(fn($u) => [
            'id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'] ?? '', 'email' => $u['email'],
            'role' => normalizeRole($u['role'] ?? 'sales_rep'), 'title' => $u['title'] ?? '', 'office' => $u['office'] ?? '',
            'is_active' => $u['is_active'] ?? true, 'created_at' => $u['created_at'] ?? '',
            'archived_at' => $u['archived_at'] ?? '', 'archived_by' => $u['archived_by'] ?? '',
            'archive_reason' => $u['archive_reason'] ?? ''
        ], $users);
        respond(['success' => true, 'users' => $safe]);
        break;

    case 'create-user':
        $adminUser = requireAdmin();
        validateRequired($input, ['name', 'email']);
        $name = $input['name'] ?? '';
        $username = strtolower(trim($input['username'] ?? ''));
        $email = $input['email'] ?? '';
        $role = normalizeRole($input['role'] ?? 'sales_rep');
        if (($adminUser['role'] ?? '') !== 'super_admin' && $role !== 'sales_rep') {
            respond(['success' => false, 'error' => 'Only superadmin can create admin or superadmin users'], 403);
        }
        $office = $input['office'] ?? '';
        $title = $input['title'] ?? '';

        $users = getUsers();
        foreach ($users as $u) {
            if ($u['email'] === $email) respond(['success' => false, 'error' => 'Email already exists'], 400);
            if ($username !== '' && strtolower($u['username'] ?? '') === $username) respond(['success' => false, 'error' => 'Username already exists'], 400);
        }

        $password = $input['password'] ?? generatePassword();
        $users[] = [
            'id' => generateId('user_'),
            'name' => $name, 'username' => $username ?: strtolower(strtok($email, '@') ?: ''), 'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role, 'title' => $title, 'office' => $office,
            'token' => '', 'is_active' => true,
            'archived_at' => '', 'archived_by' => '', 'archive_reason' => '',
            'created_at' => date('c')
        ];
        saveUsers($users);
        logActivity($adminUser, 'user.created', ['email' => $email, 'role' => $role]);
        respond(['success' => true, 'password' => $password]);
        break;

    case 'update-user':
        $adminUser = requireAdmin();
        $id = $input['id'] ?? '';
        $users = getUsers();
        if (isset($input['username'])) {
            $newUsername = strtolower(trim($input['username']));
            foreach ($users as $existing) {
                if (($existing['id'] ?? '') !== $id && $newUsername !== '' && strtolower($existing['username'] ?? '') === $newUsername) {
                    respond(['success' => false, 'error' => 'Username already exists'], 400);
                }
            }
        }
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                // An admin (not super_admin) cannot edit/archive/reset the
                // password of a super_admin account — same hierarchy as
                // leads/meetings/targets. Editing your own account is fine.
                if ($u['id'] !== $adminUser['id']
                    && normalizeRole($adminUser['role'] ?? 'sales_rep') !== 'super_admin'
                    && normalizeRole($u['role'] ?? 'sales_rep') === 'super_admin') {
                    respond(['success' => false, 'error' => 'Access denied'], 403);
                }
                foreach (['name','username','email','title','office','is_active'] as $f) {
                    if (isset($input[$f])) $u[$f] = $input[$f];
                }
                if (isset($u['username'])) $u['username'] = strtolower(trim($u['username']));
                if (array_key_exists('archive', $input)) {
                    if (($input['archive'] ?? false) && ($u['id'] ?? '') === ($adminUser['id'] ?? '')) {
                        respond(['success' => false, 'error' => 'You cannot archive your own account'], 400);
                    }
                    if ($input['archive']) {
                        $u['archived_at'] = date('c');
                        $u['archived_by'] = $adminUser['name'] ?? '';
                        $u['archive_reason'] = $input['archive_reason'] ?? 'Archived from Sales Intelligence';
                        $u['is_active'] = false;
                        $u['token'] = '';
                    } else {
                        $u['archived_at'] = '';
                        $u['archived_by'] = '';
                        $u['archive_reason'] = '';
                        $u['is_active'] = true;
                    }
                }
                if (isset($input['role'])) {
                    $newRole = normalizeRole($input['role']);
                    if (($adminUser['role'] ?? '') !== 'super_admin' && $newRole !== 'sales_rep') {
                        respond(['success' => false, 'error' => 'Only superadmin can assign admin or superadmin role'], 403);
                    }
                    $u['role'] = $newRole;
                }
                if (!empty($input['password'])) $u['password'] = password_hash($input['password'], PASSWORD_DEFAULT);
                saveUsers($users);
                logActivity($adminUser, 'user.updated', ['user_id' => $id]);
                respond(['success' => true]);
            }
        }
        respond(['success' => false, 'error' => 'User not found'], 404);
        break;

    case 'delete-user':
        $adminUser = requireAdmin();
        if (!hasPermission($adminUser['role'] ?? '', 'delete_user')) {
            respond(['success' => false, 'error' => 'Only superadmin can permanently delete users'], 403);
        }
        $id = $input['id'] ?? '';
        if ($id === ($adminUser['id'] ?? '')) respond(['success' => false, 'error' => 'You cannot delete your own account'], 400);
        $users = getUsers();
        $users = array_values(array_filter($users, fn($u) => $u['id'] !== $id));
        saveUsers($users);
        logActivity($adminUser, 'user.deleted', ['user_id' => $id]);
        respond(['success' => true]);
        break;

    // ===== AUDIT LOG =====
    case 'audit-log':
        $user = requireAuth();
        if (!hasPermission($user['role'] ?? '', 'view_audit_log')) {
            respond(['success' => false, 'error' => 'Insufficient permissions'], 403);
        }
        $page   = max(1, intval($_GET['page'] ?? 1));
        $limit  = min(100, intval($_GET['limit'] ?? 50));
        $offset = ($page - 1) * $limit;
        // Same hierarchy as leads/meetings/targets/users — an admin sees
        // their own activity + their sales_reps', never super_admin's.
        $viewerRole = normalizeRole($user['role'] ?? 'sales_rep');
        if ($viewerRole === 'super_admin') {
            $total = (int) db()->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
            $rows  = db()->prepare("SELECT data FROM audit_log ORDER BY updated_at DESC LIMIT :lim OFFSET :off");
            $rows->bindValue(':lim', $limit, PDO::PARAM_INT);
            $rows->bindValue(':off', $offset, PDO::PARAM_INT);
            $rows->execute();
        } else {
            $visibleIds = array_values(array_map(fn($u) => $u['id'], array_filter(getUsers(),
                fn($u) => $u['id'] === $user['id'] || normalizeRole($u['role'] ?? 'sales_rep') !== 'super_admin')));
            $totalStmt = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE data->>'user_id' = ANY(:ids)");
            $totalStmt->execute([':ids' => '{' . implode(',', array_map(fn($id) => '"' . str_replace('"','\\"',$id) . '"', $visibleIds)) . '}']);
            $total = (int) $totalStmt->fetchColumn();
            $rows  = db()->prepare("SELECT data FROM audit_log WHERE data->>'user_id' = ANY(:ids) ORDER BY updated_at DESC LIMIT :lim OFFSET :off");
            $rows->bindValue(':ids', '{' . implode(',', array_map(fn($id) => '"' . str_replace('"','\\"',$id) . '"', $visibleIds)) . '}');
            $rows->bindValue(':lim', $limit, PDO::PARAM_INT);
            $rows->bindValue(':off', $offset, PDO::PARAM_INT);
            $rows->execute();
        }
        $logs = array_map(fn($r) => json_decode($r['data'], true), $rows->fetchAll());
        respond([
            'success' => true,
            'logs'    => $logs,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
        ]);
        break;

    // ===== TEAM MEMBERS (for reassignment dropdown) =====
    case 'team-members':
        $user = requireAuth();
        if (!hasPermission($user['role'] ?? 'sales_rep', 'reassign_lead')) {
            respond(['success' => false, 'error' => 'Insufficient permissions'], 403);
        }
        $viewerRole = normalizeRole($user['role'] ?? 'sales_rep');
        $members = array_values(array_map(
            fn($u) => ['id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'] ?? '', 'title' => $u['title'] ?? '', 'role' => $u['role'] ?? 'sales_rep'],
            array_filter(getUsers(), fn($u) => ($u['is_active'] ?? true)
                && ($viewerRole === 'super_admin' || $u['id'] === $user['id'] || normalizeRole($u['role'] ?? 'sales_rep') !== 'super_admin'))
        ));
        respond(['success' => true, 'members' => $members]);
        break;

    // ===== CSV EXPORT =====
    case 'export-leads':
        $user = requireAuth();
        if (!hasPermission($user['role'] ?? 'sales_rep', 'export_leads')) {
            respond(['success' => false, 'error' => 'Insufficient permissions'], 403);
        }
        $exportLeads = getLeads();
        if (empty($_GET['include_archived'])) {
            $exportLeads = array_filter($exportLeads, fn($l) => !isArchived($l));
        }
        $exportLeads = visibleLeadsFor($user, array_values($exportLeads));
        if (hasPermission($user['role'] ?? 'sales_rep', 'view_all_leads') && !empty($_GET['scope'])) {
            if ($_GET['scope'] === 'mine') {
                $exportLeads = array_filter($exportLeads, fn($l) => $l['owner_id'] === $user['id']);
            } elseif ($_GET['scope'] === 'team') {
                $exportLeads = array_filter($exportLeads, fn($l) => $l['owner_id'] !== $user['id']);
            }
        }
        if (!empty($_GET['status'])) {
            $exportLeads = array_filter($exportLeads, fn($l) => $l['status'] === $_GET['status']);
        }
        $exportLeads = array_values($exportLeads);
        logActivity($user, 'leads.exported', ['count' => count($exportLeads)]);
        // Override headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leads_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['First Name','Last Name','Email','Phone','Company','Title','Industry','Country','Status','Owner','Emails Sent','Calls','Follow-up','Created','Deal Score','Deal Value']);
        foreach ($exportLeads as $l) {
            fputcsv($out, [
                $l['first_name'] ?? '', $l['last_name'] ?? '', $l['email'] ?? '', $l['phone'] ?? '',
                $l['company'] ?? '', $l['title'] ?? '', $l['industry'] ?? '', $l['country'] ?? '',
                $l['status'] ?? '', $l['owner_name'] ?? '', $l['emails_sent'] ?? 0,
                count($l['call_logs'] ?? []), $l['followup_date'] ?? '',
                substr($l['created_at'] ?? '', 0, 10), $l['deal_score'] ?? 0, $l['proposal_value'] ?? ''
            ]);
        }
        fclose($out);
        exit;

    // ===== TEAM CHAT =====
    case 'chat-channels':
        $user = requireAuth();
        if ($method === 'GET') {
            $channels = getChatChannels();
            $unread = getChatUnreadCounts($user['id']);
            // Filter: if channel has members list, only show it to members (admins always see all)
            $isAdmin = isChatAdmin($user);
            $channels = array_values(array_filter($channels, function($ch) use ($user, $isAdmin) {
                $members = $ch['members'] ?? [];
                return empty($members) || $isAdmin || in_array($user['id'], $members, true);
            }));
            foreach ($channels as &$ch) $ch['unread'] = $unread[$ch['id']] ?? 0;
            respond(['success' => true, 'channels' => $channels]);
        }
        if ($method === 'POST') {
            if (!isChatAdmin($user)) respond(['success' => false, 'error' => 'Only admins can create channels'], 403);
            $name = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['name'] ?? '')));
            if (!$name) respond(['success' => false, 'error' => 'Channel name required'], 400);
            $channels = getChatChannels();
            foreach ($channels as $ch) {
                if ($ch['name'] === $name) respond(['success' => false, 'error' => 'Channel already exists'], 400);
            }
            $members = array_values(array_filter((array)($input['members'] ?? []), fn($id) => is_string($id) && $id !== ''));
            $newChannel = ['id' => 'channel_' . bin2hex(random_bytes(4)), 'name' => $name, 'description' => $input['description'] ?? '', 'members' => $members, 'created_by' => $user['id'], 'created_at' => date('c')];
            $channels[] = $newChannel;
            saveChatChannels($channels);
            respond(['success' => true, 'channel' => $newChannel]);
        }
        if ($method === 'DELETE') {
            if (!in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) respond(['success' => false, 'error' => 'Only admins can delete channels'], 403);
            $channelId = $input['id'] ?? '';
            if ($channelId === 'channel_general') respond(['success' => false, 'error' => 'Cannot delete the general channel'], 400);
            if (!$channelId) respond(['success' => false, 'error' => 'Channel ID required'], 400);
            $channels = array_values(array_filter(getChatChannels(), fn($ch) => $ch['id'] !== $channelId));
            saveChatChannels($channels);
            respond(['success' => true]);
        }
        break;

    case 'chat-channel-members':
        $user = requireAuth();
        if (!isChatAdmin($user)) respond(['success' => false, 'error' => 'Admin only'], 403);
        if ($method === 'POST') {
            $channelId = $input['channel_id'] ?? '';
            $members = array_values(array_filter((array)($input['members'] ?? []), fn($id) => is_string($id) && $id !== ''));
            $channels = getChatChannels();
            $found = false;
            foreach ($channels as &$ch) {
                if ($ch['id'] === $channelId) {
                    $ch['members'] = $members;
                    if (!empty($input['name'])) {
                        $newName = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['name'])));
                        if ($newName) $ch['name'] = $newName;
                    }
                    if (isset($input['description'])) $ch['description'] = trim($input['description']);
                    $found = true;
                    break;
                }
            }
            if (!$found) respond(['success' => false, 'error' => 'Channel not found'], 404);
            saveChatChannels($channels);
            respond(['success' => true]);
        }
        break;

    case 'chat-messages':
        $user = requireAuth();
        $threadId = $_GET['thread'] ?? $input['thread'] ?? '';
        if (!$threadId) respond(['success' => false, 'error' => 'Thread required'], 400);
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $threadId);
        if (!canAccessThread($user, $safe)) respond(['success' => false, 'error' => 'Access denied'], 403);

        if ($method === 'GET') {
            $messages = getChannelMessages($safe);
            $since = $_GET['since'] ?? null;
            if ($since) $messages = array_values(array_filter($messages, fn($m) => ($m['sent_at'] ?? '') > $since));
            dbSetLastRead($user['id'], $safe, date('c'));
            respond(['success' => true, 'messages' => array_slice($messages, -100)]);
        }

        if ($method === 'POST') {
            $text = trim($input['text'] ?? '');
            if (!$text) respond(['success' => false, 'error' => 'Message required'], 400);
            $messages = getChannelMessages($safe);
            $msg = [
                'id'        => 'msg_' . bin2hex(random_bytes(6)),
                'user_id'   => $user['id'],
                'user_name' => $user['name'] ?? $user['email'],
                'text'      => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'),
                'sent_at'   => date('c'),
                'reactions' => []
            ];
            $messages[] = $msg;
            saveChannelMessages($safe, $messages);

            // Load users ONCE, apply all notifications, save ONCE
            $senderName = $user['name'] ?? $user['email'];
            $isDm = str_starts_with($threadId, 'dm_');
            $allUsers = getUsers();
            $usersChanged = false;

            if ($isDm) {
                foreach ($allUsers as $u) {
                    if (($u['id'] ?? '') === $user['id']) continue;
                    if (getDmThreadId($user['id'], $u['id']) === $threadId) {
                        _applyNotif($allUsers, $u['id'], [
                            'id'         => 'notif_' . bin2hex(random_bytes(6)),
                            'notif_key'  => 'dm_' . $msg['id'],
                            'type'       => 'chat_dm',
                            'title'      => "DM from {$senderName}",
                            'body'       => substr($text, 0, 100),
                            'thread_id'  => $threadId,
                            'created_at' => date('c'),
                            'read'       => false,
                        ]);
                        $usersChanged = true;
                        break;
                    }
                }
            } else {
                $threadLabel = '#' . $safe;
                $channelMembers = [];
                foreach (getChatChannels() as $ch) {
                    if ($ch['id'] === $threadId) {
                        $threadLabel = '#' . $ch['name'];
                        $channelMembers = $ch['members'] ?? [];
                        break;
                    }
                }
                // Collect @mention notifs into $allUsers (no save yet)
                collectMentionNotifs($user, $text, $threadId, $threadLabel, "In {$threadLabel}", 'mention', $allUsers);
                // Private channel members also get a notification
                if (!empty($channelMembers)) {
                    $mentionedIds = [];
                    foreach ($allUsers as $u) {
                        $fullName  = trim($u['name'] ?? '');
                        $firstName = $fullName !== '' ? strtok($fullName, ' ') : '';
                        $username  = trim($u['username'] ?? '');
                        foreach (array_filter([$fullName, $firstName, $username]) as $cand) {
                            if (preg_match('/@' . preg_quote($cand, '/') . '(?![a-zA-Z0-9_])/i', $text)) {
                                $mentionedIds[] = $u['id']; break;
                            }
                        }
                    }
                    foreach ($allUsers as $u) {
                        if (($u['id'] ?? '') === $user['id']) continue;
                        if (isArchived($u) || !($u['is_active'] ?? true)) continue;
                        if (!in_array($u['id'], $channelMembers)) continue;
                        if (in_array($u['id'], $mentionedIds)) continue;
                        _applyNotif($allUsers, $u['id'], [
                            'id'         => 'notif_' . bin2hex(random_bytes(6)),
                            'notif_key'  => 'chan_msg_' . $threadId . '_' . $msg['id'] . '_' . $u['id'],
                            'type'       => 'chat_message',
                            'title'      => "{$senderName} in {$threadLabel}",
                            'body'       => substr($text, 0, 100),
                            'thread_id'  => $threadId,
                            'created_at' => date('c'),
                            'read'       => false,
                        ]);
                    }
                    $usersChanged = true;
                }
                if (strpos($text, '@') !== false) $usersChanged = true;
            }
            // Single DB write for all notifications
            if ($usersChanged) saveUsers($allUsers);

            respond(['success' => true, 'message' => $msg]);
        }
        break;

    case 'chat-react':
        $user = requireAuth();
        if ($method !== 'POST') break;
        $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['thread'] ?? '');
        $msgId    = $input['message_id'] ?? '';
        $emoji    = $input['emoji'] ?? '';
        if (!$threadId || !$msgId || !$emoji) respond(['success' => false, 'error' => 'Missing fields'], 400);
        $messages = getChannelMessages($threadId);
        foreach ($messages as &$m) {
            if ($m['id'] === $msgId) {
                if (!isset($m['reactions'])) $m['reactions'] = [];
                $existing = false;
                foreach ($m['reactions'] as &$r) {
                    if ($r['emoji'] === $emoji) {
                        if (in_array($user['id'], $r['users'])) {
                            $r['users'] = array_values(array_filter($r['users'], fn($u) => $u !== $user['id']));
                        } else {
                            $r['users'][] = $user['id'];
                        }
                        if (empty($r['users'])) $m['reactions'] = array_values(array_filter($m['reactions'], fn($rx) => $rx['emoji'] !== $emoji));
                        $existing = true;
                        break;
                    }
                }
                unset($r);
                if (!$existing) $m['reactions'][] = ['emoji' => $emoji, 'users' => [$user['id']]];
                break;
            }
        }
        unset($m);
        saveChannelMessages($threadId, $messages);
        respond(['success' => true]);
        break;

    case 'chat-dm-threads':
        $user = requireAuth();
        if ($method !== 'GET') break;
        $users = getUsers();
        $unread = getChatUnreadCounts($user['id']);
        $threads = [];
        foreach ($users as $u) {
            if ($u['id'] === $user['id']) continue;
            if (isArchived($u) || !($u['is_active'] ?? true)) continue;
            $threadId = getDmThreadId($user['id'], $u['id']);
            $threads[] = [
                'thread_id' => $threadId,
                'user_id'   => $u['id'],
                'user_name' => $u['name'] ?? $u['email'],
                'unread'    => $unread[$threadId] ?? 0
            ];
        }
        respond(['success' => true, 'threads' => $threads]);
        break;

    case 'chat-delete-message':
        if ($method !== 'POST') break;
        $user = requireAuth();
        $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['thread'] ?? '');
        $msgId = $input['message_id'] ?? '';
        if (!$threadId || !$msgId) respond(['success' => false, 'error' => 'Missing fields'], 400);
        if (!canAccessThread($user, $threadId)) respond(['success' => false, 'error' => 'Access denied'], 403);
        $messages = getChannelMessages($threadId);
        $found = false;
        $isAdminUser = in_array($user['role'] ?? '', ['admin', 'super_admin'], true);
        foreach ($messages as $m) {
            if ($m['id'] === $msgId) {
                if ($m['user_id'] !== $user['id'] && !$isAdminUser) {
                    respond(['success' => false, 'error' => 'You can only delete your own messages'], 403);
                }
                $found = true;
                break;
            }
        }
        if (!$found) respond(['success' => false, 'error' => 'Message not found'], 404);
        $messages = array_values(array_filter($messages, fn($m) => $m['id'] !== $msgId));
        saveChannelMessages($threadId, $messages);
        respond(['success' => true]);
        break;

    case 'chat-pin-message':
        if ($method !== 'POST') break;
        $user = requireAuth();
        if (!isChatAdmin($user)) respond(['success' => false, 'error' => 'Only admins can pin messages'], 403);
        $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['thread'] ?? '');
        $msgId    = $input['message_id'] ?? '';
        $unpin    = !empty($input['unpin']);
        if (!$threadId) respond(['success' => false, 'error' => 'Thread required'], 400);
        if (!canAccessThread($user, $threadId)) respond(['success' => false, 'error' => 'Access denied'], 403);
        $messages = getChannelMessages($threadId);

        if ($unpin) {
            foreach ($messages as &$m) {
                if ($m['id'] === $msgId) {
                    $m['pinned'] = false; $m['pinned_at'] = null; $m['pinned_by'] = null;
                    break;
                }
            }
            unset($m);
            saveChannelMessages($threadId, $messages);
            respond(['success' => true]);
        } else {
            $replacedName = null;
            foreach ($messages as &$m) {
                if (!empty($m['pinned']) && $m['id'] !== $msgId) {
                    if (($m['pinned_by'] ?? '') !== $user['id'] && !empty($m['pinned_by_name'])) {
                        $replacedName = $m['pinned_by_name'];
                    }
                    $m['pinned'] = false; $m['pinned_at'] = null; $m['pinned_by'] = null;
                }
            }
            unset($m);
            foreach ($messages as &$m) {
                if ($m['id'] === $msgId) {
                    $m['pinned'] = true;
                    $m['pinned_at'] = date('c');
                    $m['pinned_by'] = $user['id'];
                    $m['pinned_by_name'] = $user['name'] ?? $user['email'];
                    break;
                }
            }
            unset($m);
            saveChannelMessages($threadId, $messages);
            respond(['success' => true, 'replaced' => $replacedName]);
        }
        break;

    case 'chat-upload':
        if ($method !== 'POST') break;
        $user = requireAuth();
        $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['thread'] ?? '');
        if (!$threadId) respond(['success' => false, 'error' => 'Thread required'], 400);
        if (!canAccessThread($user, $threadId)) respond(['success' => false, 'error' => 'Access denied'], 403);
        if (empty($_FILES['file'])) respond(['success' => false, 'error' => 'No file uploaded'], 400);

        $file = $_FILES['file'];
        if ($file['size'] > 5 * 1024 * 1024) respond(['success' => false, 'error' => 'File too large (max 5MB)'], 400);

        $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf','text/plain','text/csv'];
        if (!in_array($file['type'], $allowed)) respond(['success' => false, 'error' => 'File type not allowed'], 400);

        $uploadDir = DATA_DIR . 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(8)) . '.' . strtolower($ext);
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) respond(['success' => false, 'error' => 'Upload failed'], 500);

        $caption = trim($_POST['caption'] ?? '');
        $messages = getChannelMessages($threadId);
        $isImage = strpos($file['type'], 'image/') === 0;
        $msg = [
            'id'        => 'msg_' . bin2hex(random_bytes(6)),
            'user_id'   => $user['id'],
            'user_name' => $user['name'] ?? $user['email'],
            'text'      => $caption !== '' ? htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') : '',
            'file'      => ['name' => $file['name'], 'path' => 'data/uploads/' . $filename, 'type' => $file['type'], 'size' => $file['size'], 'is_image' => $isImage],
            'sent_at'   => date('c'),
            'reactions' => []
        ];
        $messages[] = $msg;
        saveChannelMessages($threadId, $messages);
        respond(['success' => true, 'message' => $msg]);
        break;

    case 'chat-unread':
        $user = requireAuth();
        if ($method !== 'GET') break;
        $counts = getChatUnreadCounts($user['id']);
        respond(['success' => true, 'total' => array_sum($counts), 'counts' => $counts]);
        break;

    // ===== NOTIFICATIONS =====
    case 'notifications':
        $user = requireAuth();
        if ($method === 'GET') {
            $notifications = getUserNotifications($user['id']);
            usort($notifications, fn($a, $b) => strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0'));
            respond(['success' => true, 'notifications' => $notifications]);
        }
        if ($method === 'POST') {
            $notifAction = $input['action'] ?? '';
            $notifId = $input['notification_id'] ?? '';
            $users = getUsers();
            foreach ($users as &$u) {
                if ($u['id'] !== $user['id']) continue;
                $u['notifications'] = $u['notifications'] ?? [];
                if ($notifAction === 'mark_read') {
                    foreach ($u['notifications'] as &$n) { if ($n['id'] === $notifId) $n['read'] = true; }
                    unset($n);
                } elseif ($notifAction === 'mark_all_read') {
                    foreach ($u['notifications'] as &$n) { $n['read'] = true; }
                    unset($n);
                } elseif ($notifAction === 'dismiss') {
                    $u['notifications'] = array_values(array_filter($u['notifications'], fn($n) => $n['id'] !== $notifId));
                }
                saveUsers($users);
                break;
            }
            respond(['success' => true]);
        }
        break;

    // ===== ACTIVITY PING =====
    case 'activity-ping':
        $user = requireAuth();
        $page = trim($input['page'] ?? $_GET['page'] ?? '');
        db()->prepare("
            INSERT INTO activity_pings (user_id, user_name, user_role, page, pinged_at)
            VALUES (:uid, :uname, :urole, :page, now())
        ")->execute([
            ':uid'   => $user['id'],
            ':uname' => $user['name'] ?? $user['email'] ?? '',
            ':urole' => $user['role'] ?? '',
            ':page'  => $page,
        ]);
        // Prune pings older than 90 days to keep the table lean
        db()->exec("DELETE FROM activity_pings WHERE pinged_at < now() - INTERVAL '90 days'");
        respond(['success' => true]);

    // ===== USER SESSIONS (admin only) =====
    case 'user-sessions':
        $user = requireAuth();
        if (!hasPermission($user['role'] ?? '', 'view_audit_log')) {
            respond(['success' => false, 'error' => 'Insufficient permissions'], 403);
        }

        $days = intval($_GET['days'] ?? 7);
        if ($days < 1 || $days > 90) $days = 7;

        // All pings in the requested window
        $stmt = db()->prepare("
            SELECT user_id, user_name, user_role, page, pinged_at
            FROM activity_pings
            WHERE pinged_at >= now() - INTERVAL '{$days} days'
            ORDER BY user_id, pinged_at ASC
        ");
        $stmt->execute();
        $allPings = $stmt->fetchAll();

        // Last seen per user (across all time)
        $lastSeenAll = db()->query("
            SELECT DISTINCT ON (user_id) user_id, user_name, user_role, page, pinged_at
            FROM activity_pings ORDER BY user_id, pinged_at DESC
        ")->fetchAll();
        $lastSeenMap = [];
        foreach ($lastSeenAll as $ls) $lastSeenMap[$ls['user_id']] = $ls;

        // Group pings into sessions (gap > 30min = new session)
        $userPings = [];
        foreach ($allPings as $p) $userPings[$p['user_id']][] = $p;

        $userSessions = []; // uid => [ [start, end, duration_mins, pages[]] ]
        foreach ($userPings as $uid => $pings) {
            $sessions = [];
            $sessStart = null; $sessEnd = null; $sessPages = []; $prevT = null;
            foreach ($pings as $p) {
                $t = strtotime($p['pinged_at']);
                if ($prevT === null || ($t - $prevT) > 1800) {
                    // Save previous session
                    if ($sessStart !== null) {
                        $dur = max(1, round(($prevT - strtotime($sessStart)) / 60) + 1);
                        $sessions[] = ['start' => $sessStart, 'end' => $sessEnd, 'duration_mins' => $dur, 'pages' => array_values(array_unique($sessPages))];
                    }
                    $sessStart = $p['pinged_at']; $sessPages = [];
                }
                $sessEnd = $p['pinged_at'];
                if ($p['page']) $sessPages[] = $p['page'];
                $prevT = $t;
            }
            if ($sessStart !== null) {
                $dur = max(1, round(($prevT - strtotime($sessStart)) / 60) + 1);
                $sessions[] = ['start' => $sessStart, 'end' => $sessEnd, 'duration_mins' => $dur, 'pages' => array_values(array_unique($sessPages))];
            }
            $userSessions[$uid] = $sessions;
        }

        // Build per-user summary with individual sessions
        $result = [];
        $allUsers = getUsers();
        // Same hierarchy as leads/meetings/targets/users/audit-log — an
        // admin sees their own sessions + their sales_reps', never super_admin's.
        $viewerRole = normalizeRole($user['role'] ?? 'sales_rep');
        foreach ($allUsers as $u) {
            if (isArchived($u)) continue;
            if ($viewerRole !== 'super_admin'
                && $u['id'] !== $user['id']
                && normalizeRole($u['role'] ?? 'sales_rep') === 'super_admin') continue;
            $uid = $u['id'];
            $ls  = $lastSeenMap[$uid] ?? null;
            $sessions = $userSessions[$uid] ?? [];
            // Today's active mins
            $todayMins = 0;
            $today = date('Y-m-d');
            foreach ($sessions as $s) {
                if (substr($s['start'], 0, 10) === $today) $todayMins += $s['duration_mins'];
            }
            $result[] = [
                'user_id'           => $uid,
                'user_name'         => $u['name'] ?? $u['email'],
                'user_role'         => $u['role'],
                'last_seen'         => $ls['pinged_at'] ?? null,
                'last_page'         => $ls['page'] ?? null,
                'active_mins_today' => min($todayMins, 480),
                'sessions_this_period' => count($sessions),
                'sessions'          => array_reverse($sessions), // newest first
            ];
        }
        usort($result, fn($a,$b) => strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''));
        respond(['success' => true, 'sessions' => $result, 'days' => $days]);

    // ===== NEWS FEED =====
    case 'news-feed':
        requireAuth();
        $cacheKey = 'news_feed_cache';
        $forceBust = isset($_GET['bust']);
        if (!$forceBust) {
            $cached = kvGet('news_cache', $cacheKey, null);
            $ttl  = 1800; // 30 minutes
            $stale = 7200; // serve stale up to 2 hours while refreshing in background
            if ($cached && isset($cached['fetched_at'])) {
                $age = time() - $cached['fetched_at'];
                if ($age < $ttl) {
                    // Fresh — serve immediately
                    respond(['success' => true, 'articles' => $cached['articles'], 'sources' => $cached['sources'], 'cached' => true, 'age' => $age]);
                } elseif ($age < $stale) {
                    // Stale but usable — serve now, mark for background refresh
                    respond(['success' => true, 'articles' => $cached['articles'], 'sources' => $cached['sources'], 'cached' => true, 'stale' => true, 'age' => $age]);
                }
                // Older than 2 hours — fall through and re-fetch synchronously
            }
        }
        // Always clear cache when busting so keyword reset takes effect
        if ($forceBust) kvSet('news_cache', $cacheKey, []);

        $defaultSources = [
            // ── NZ sources (nz:true → appear first) ──────────────────────────
            ['id' => 'nzherald',      'name' => 'NZ Herald Business', 'url' => 'https://www.nzherald.co.nz/arc/outboundfeeds/rss/section/business/?outputType=xml', 'enabled' => true,  'nz' => true],
            ['id' => 'transporttalk', 'name' => 'Transport Talk NZ',  'url' => 'https://transporttalk.co.nz/feed/',                                                 'enabled' => true,  'nz' => true],
            ['id' => 'nztrucking',    'name' => 'NZ Trucking',        'url' => 'https://nztrucking.co.nz/feed/',                                                    'enabled' => true,  'nz' => true],
            ['id' => 'stuffbiz',      'name' => 'Stuff Business',     'url' => 'https://www.stuff.co.nz/rss/business',                                              'enabled' => true,  'nz' => true],
            // ── Global freight publications ───────────────────────────────────
            ['id' => 'freightwaves',  'name' => 'FreightWaves',       'url' => 'https://www.freightwaves.com/feed',                                                 'enabled' => true,  'nz' => false],
            ['id' => 'loadstar',      'name' => 'The Loadstar',       'url' => 'https://theloadstar.com/feed/',                                                     'enabled' => true,  'nz' => false],
            ['id' => 'scdive',        'name' => 'Supply Chain Dive',  'url' => 'https://www.supplychaindive.com/feeds/news/',                                       'enabled' => true,  'nz' => false],
            ['id' => 'aircargonews',  'name' => 'Air Cargo News',     'url' => 'https://www.aircargonews.net/feed/',                                                'enabled' => true,  'nz' => false],
        ];
        $storedSources = kvGet('news_config', 'sources', null);
        // Always use full source list — overwrite if busting or source list is outdated
        $knownIds = array_column($defaultSources, 'id');
        $storedIds = is_array($storedSources) ? array_column($storedSources, 'id') : [];
        $missingIds = array_diff($knownIds, $storedIds);
        if ($forceBust || $storedSources === null || !empty($missingIds)) {
            kvSet('news_config', 'sources', $defaultSources);
            $sourcesConfig = $defaultSources;
        } else {
            $sourcesConfig = $storedSources;
        }

        $keywords = kvGet('news_config', 'keywords', null);
        // If no keywords saved yet, or bust requested, write the strict freight-only list
        $strictKeywords = [
            'freight', 'logistics', 'freight forwarder', 'freight forwarding',
            'shipping line', 'container ship', 'cargo', 'customs clearance',
            'supply chain', 'warehousing', 'air freight', 'sea freight',
            'FCL', 'LCL', 'tariff', 'trade lane',
            'ports of auckland', 'port of tauranga', 'lyttelton port',
            'napier port', 'militzer', 'freight rates', 'ocean freight',
        ];
        if (!is_array($keywords) || empty($keywords) || $forceBust) {
            kvSet('news_config', 'keywords', $strictKeywords);
            $keywords = $strictKeywords;
        }
        $newsApiKey = kvGet('news_config', 'newsapi_key', '');

        // NZ geography signals — used to classify an article as NZ-relevant
        $nzSignals = ['new zealand', 'auckland', 'wellington', 'christchurch', 'tauranga', 'hamilton', 'napier', 'lyttelton', 'whangarei', 'nz port', 'nz trade'];

        $nzArticles     = [];
        $globalArticles = [];
        $sourceStatus   = [];
        $httpCtx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0 (compatible; CRM/1.0)']]);

        // Returns true if haystack contains at least one keyword
        $matchesKeywords = function(string $haystack) use ($keywords): bool {
            foreach ($keywords as $kw) { if (stripos($haystack, $kw) !== false) return true; }
            return false;
        };

        $parseRssArticle = function(array $src, string $title, string $link, string $summary, int $ts): array {
            return ['id' => md5($link), 'title' => $title, 'link' => $link,
                'summary' => mb_substr($summary, 0, 280), 'published' => date('c', $ts),
                'ts' => $ts, 'source_id' => $src['id'], 'source' => $src['name'],
                'nz' => !empty($src['nz'])];
        };

        // ── RSS sources — fetch all in parallel with curl_multi ─────────────
        $freightPubs = ['freightwaves','transporttalk','loadstar','scdive','aircargonews','nztrucking'];
        $enabledSources = array_filter($sourcesConfig, fn($s) => !empty($s['enabled']));
        foreach (array_filter($sourcesConfig, fn($s) => empty($s['enabled'])) as $s) {
            $sourceStatus[$s['id']] = 'disabled';
        }

        // Build curl_multi handles
        $mh      = curl_multi_init();
        $handles = [];
        foreach ($enabledSources as $src) {
            $ch = curl_init($src['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 4,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CRM/1.0)',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$src['id']] = ['ch' => $ch, 'src' => $src];
        }

        // Execute all requests in parallel
        $active = null;
        do { curl_multi_exec($mh, $active); curl_multi_select($mh); } while ($active > 0);

        // Parse each response
        libxml_use_internal_errors(true);
        foreach ($handles as $srcId => ['ch' => $ch, 'src' => $src]) {
            $xml = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if (!$xml) { $sourceStatus[$srcId] = 'error'; continue; }
            $feed = simplexml_load_string($xml);
            if (!$feed) { $sourceStatus[$srcId] = 'parse_error'; continue; }
            $items = $feed->channel->item ?? $feed->entry ?? [];
            $count = 0;
            $isFreightPub = in_array($srcId, $freightPubs);
            foreach ($items as $item) {
                $title   = trim((string)($item->title ?? ''));
                $link    = trim((string)($item->link ?? $item->id ?? ''));
                $summary = strip_tags(trim((string)($item->description ?? $item->summary ?? $item->content ?? '')));
                $pubDate = trim((string)($item->pubDate ?? $item->published ?? $item->updated ?? ''));
                $ts      = $pubDate ? strtotime($pubDate) : time();
                if (!$ts || $ts < 0) $ts = time();
                if (!$title || !$link) continue;
                $haystack = strtolower($title . ' ' . $summary);
                if (!$isFreightPub && !$matchesKeywords($haystack)) continue;
                $article = $parseRssArticle($src, $title, $link, $summary, $ts);
                if ($article['nz']) $nzArticles[] = $article;
                else $globalArticles[] = $article;
                if (++$count >= 25) break;
            }
            $sourceStatus[$srcId] = 'ok';
        }
        curl_multi_close($mh);

        // NewsAPI disabled — too noisy on free tier, returns off-topic articles
        if (false && $newsApiKey) {
        }

        // Deduplicate all articles and sort newest first
        $merged = array_merge($nzArticles, $globalArticles);
        $seen2 = []; $unique = [];
        foreach ($merged as $a) { if (!isset($seen2[$a['id']])) { $seen2[$a['id']] = true; $unique[] = $a; } }
        usort($unique, fn($a, $b) => $b['ts'] - $a['ts']);
        $unique = array_slice($unique, 0, 80);
        $articles = array_map(function($a) { unset($a['ts']); return $a; }, $unique);
        kvSet('news_cache', $cacheKey, ['fetched_at' => time(), 'articles' => $articles, 'sources' => $sourceStatus]);
        respond(['success' => true, 'articles' => $articles, 'sources' => $sourceStatus, 'cached' => false, 'age' => 0]);

    case 'fetch-article':
        requireAuth();
        $url = trim($_GET['url'] ?? '');
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) respond(['success' => false, 'error' => 'Invalid URL'], 400);
        // Only allow http/https
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) respond(['success' => false, 'error' => 'Invalid URL'], 400);

        $ctx = stream_context_create(['http' => [
            'timeout'     => 15,
            'user_agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            'header'      => "Accept: text/html,application/xhtml+xml\r\nAccept-Language: en-US,en;q=0.9\r\n",
            'follow_location' => 1,
            'max_redirects'   => 5,
        ]]);
        $html = @file_get_contents($url, false, $ctx);
        if (!$html) respond(['success' => false, 'error' => 'Could not fetch article'], 502);

        // ── Extract article content ───────────────────────────────────────────
        // Remove scripts, styles, nav, header, footer, aside, ads
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is',  '', $html);
        $html = preg_replace('/<(nav|header|footer|aside|form|noscript|iframe|svg|button|input|select|textarea)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Try to find main content block — ordered by specificity
        $content = '';
        $patterns = [
            '/<article\b[^>]*>(.*?)<\/article>/is',
            '/<div[^>]+class="[^"]*(?:article|post|content|story|entry|body)[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<main\b[^>]*>(.*?)<\/main>/is',
            '/<div[^>]+id="[^"]*(?:article|post|content|story|body)[^"]*"[^>]*>(.*?)<\/div>/is',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $html, $m)) { $content = $m[1]; break; }
        }
        if (!$content) {
            // Fallback: grab everything inside <body>
            preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $html, $m);
            $content = $m[1] ?? $html;
        }

        // Strip remaining tags we don't want, keep semantic ones
        $allowed = '<p><h1><h2><h3><h4><h5><ul><ol><li><blockquote><strong><em><b><i><a><br><img><figure><figcaption><table><tr><td><th>';
        $content = strip_tags($content, $allowed);

        // Fix relative URLs → absolute
        $base = $scheme . '://' . parse_url($url, PHP_URL_HOST);
        $content = preg_replace('/href="\/([^"]*)"/', 'href="' . $base . '/$1"', $content);
        $content = preg_replace('/src="\/([^"]*)"/',  'src="'  . $base . '/$1"', $content);

        // Remove empty tags and excessive whitespace
        $content = preg_replace('/<p>\s*<\/p>/', '', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $content = trim($content);

        respond(['success' => true, 'content' => $content, 'url' => $url]);

    case 'news-sources':
        $user = requireAuth();
        if ($method === 'GET') {
            $sources = kvGet('news_config', 'sources', []);
            $keywords   = kvGet('news_config', 'keywords',    ['freight', 'logistics', 'freight forwarding', 'cargo', 'customs clearance', 'supply chain', 'air freight', 'sea freight', 'freight rates', 'ocean freight']);
            $newsApiKey = kvGet('news_config', 'newsapi_key', '');
            respond(['success' => true, 'sources' => $sources, 'keywords' => $keywords, 'newsapi_key' => $newsApiKey]);
        }
        if (!hasPermission($user['role'] ?? '', 'manage_users')) respond(['success' => false, 'error' => 'Admin only'], 403);
        if (isset($input['sources'])) {
            $sources = array_map(function($s) {
                return ['id' => $s['id'] ?? generateId('src_'), 'name' => trim($s['name'] ?? ''), 'url' => trim($s['url'] ?? ''), 'enabled' => !empty($s['enabled'])];
            }, (array)$input['sources']);
            kvSet('news_config', 'sources', $sources);
        }
        if (isset($input['keywords'])) {
            $kws = array_filter(array_map('trim', (array)$input['keywords']));
            kvSet('news_config', 'keywords', array_values($kws));
        }
        if (isset($input['newsapi_key'])) {
            kvSet('news_config', 'newsapi_key', trim($input['newsapi_key']));
        }
        // Bust cache so next load re-fetches
        kvSet('news_cache', 'news_feed_cache', []);
        respond(['success' => true]);

    default:
        respond(['success' => false, 'error' => 'Unknown action: ' . $action], 404);
}
