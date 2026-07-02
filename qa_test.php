<?php
/**
 * QA Test Suite — runs against localhost:8000
 * Usage: php qa_test.php
 */

$BASE = 'http://localhost:8000/api.php';
$pass = 0; $fail = 0;

function req(string $action, string $method, ?string $token, ?array $body = null): array {
    global $BASE;
    $ctx = [
        'http' => [
            'method'  => $method,
            'header'  => "Content-Type: application/json\r\n" . ($token ? "X-User-Token: $token\r\n" : ''),
            'content' => $body ? json_encode($body) : null,
            'ignore_errors' => true,
        ]
    ];
    $res = @file_get_contents("$BASE?action=$action", false, stream_context_create($ctx));
    return json_decode($res ?: '{}', true) ?: [];
}

function ok(array $r): bool  { return ($r['success'] ?? false) === true; }
function no(array $r): bool  { return ($r['success'] ?? true)  === false; }

function p(string $label, bool $result): void {
    global $pass, $fail;
    if ($result) { $pass++; echo "  [PASS] $label\n"; }
    else         { $fail++; echo "  [FAIL] $label\n"; }
}

function section(string $s): void { echo "\n--- $s ---\n"; }

// ── LOGIN ──────────────────────────────────────────────────────────────────
section('AUTH');
$loginS  = req('login', 'POST', null, ['username'=>'super',  'password'=>'Test1234!']);
$loginA  = req('login', 'POST', null, ['username'=>'admin',  'password'=>'Test1234!']);
$loginS1 = req('login', 'POST', null, ['username'=>'sales1', 'password'=>'Test1234!']);
$loginS2 = req('login', 'POST', null, ['username'=>'sales2', 'password'=>'Test1234!']);

p('super_admin login',    ok($loginS));
p('admin login',          ok($loginA));
p('sales1 login',         ok($loginS1));
p('sales2 login',         ok($loginS2));
p('bad password rejected', no(req('login','POST',null,['username'=>'super','password'=>'wrong'])));
p('no token rejected',     no(req('stats','GET',null)));

$ST  = $loginS['user']['token']  ?? '';
$AT  = $loginA['user']['token']  ?? '';
$S1T = $loginS1['user']['token'] ?? '';
$S2T = $loginS2['user']['token'] ?? '';

// ── STATS ──────────────────────────────────────────────────────────────────
section('STATS / DASHBOARD');
p('super_admin gets stats', ok(req('stats','GET',$ST)));
p('admin gets stats',       ok(req('stats','GET',$AT)));
p('sales_rep gets stats',   ok(req('stats','GET',$S1T)));

// ── LEADS ──────────────────────────────────────────────────────────────────
section('LEADS');
$allLeads = req('leads','GET',$ST);
$s1Leads  = req('leads','GET',$S1T);
p('super sees all leads',         ok($allLeads));
p('admin sees all leads',         ok(req('leads','GET',$AT)));
p('sales1 sees own leads only',   ok($s1Leads));

$foreign = array_filter($s1Leads['leads'] ?? [], fn($l) => $l['owner_id'] !== 'user_sales1');
p('sales1 has no foreign leads',  count($foreign) === 0);

$nl  = req('lead','POST',$S1T, ['company'=>'QA Co','status'=>'new','value'=>1000]);
$lid = $nl['lead']['id'] ?? '';
p('sales1 creates lead',          ok($nl) && $lid !== '');
p('sales1 edits own lead',        ok(req('lead','PUT',$S1T, ['id'=>$lid,'company'=>'QA Updated'])));
p('sales2 CANNOT edit s1 lead',   no(req('lead','PUT',$S2T, ['id'=>$lid,'company'=>'Hacked'])));
p('admin CAN edit any lead',      ok(req('lead','PUT',$AT,  ['id'=>$lid,'company'=>'Admin Edit'])));
p('sales_rep CANNOT delete lead', no(req('lead','DELETE',$S1T, ['id'=>$lid])));
p('super CAN delete lead',        ok(req('lead','DELETE',$ST, ['id'=>$lid])));

// ── MEETINGS ───────────────────────────────────────────────────────────────
section('MEETINGS');
$nm  = req('meeting','POST',$S1T, ['title'=>'QA Mtg','start_time'=>'2026-07-01T10:00:00','end_time'=>'2026-07-01T11:00:00','location_type'=>'teams','attendees'=>[['email'=>'qa@test.com','name'=>'QA']],'notes'=>'QA test']);
$mid = $nm['meeting']['id'] ?? '';
p('sales1 creates meeting',         ok($nm) && $mid !== '');
p('sales1 sees own meetings',       ok(req('meetings','GET',$S1T)));
p('admin sees all meetings',        ok(req('meetings','GET',$AT)));
p('sales1 edits own meeting',       ok(req('meeting','PUT',$S1T, ['id'=>$mid,'title'=>'Updated'])));
p('sales2 CANNOT edit s1 meeting',  no(req('meeting','PUT',$S2T, ['id'=>$mid,'title'=>'Hacked'])));
p('admin CAN edit any meeting',     ok(req('meeting','PUT',$AT, ['id'=>$mid,'title'=>'Admin Edit'])));
p('sales1 cancels own meeting',     ok(req('meeting','PUT',$S1T, ['id'=>$mid,'archive'=>true,'archive_reason'=>'QA test cancel'])));

// ── USER MANAGEMENT ────────────────────────────────────────────────────────
section('USER MANAGEMENT');
p('super sees users',             ok(req('users','GET',$ST)));
p('admin sees users',             ok(req('users','GET',$AT)));
p('sales_rep CANNOT see users',   no(req('users','GET',$S1T)));

$ts  = time();
$nu  = req('create-user','POST',$ST, ['name'=>'QA User','email'=>"qa_{$ts}_s@test.com",'username'=>"qa_{$ts}_s",'role'=>'sales_rep','password'=>'Test1234!']);
// The create-user endpoint returns {success, password} — get id from users list
$uid = '';
$users = req('users','GET',$ST);
foreach ($users['users'] ?? [] as $u) { if ($u['username'] === "qa_{$ts}_s") { $uid = $u['id']; break; } }
p('super creates user',           ok($nu) && $uid !== '');
p('admin creates user',           ok(req('create-user','POST',$AT, ['name'=>'QA User2','email'=>"qa_{$ts}_a@test.com",'username'=>"qa_{$ts}_a",'role'=>'sales_rep','password'=>'Test1234!'])));
p('admin CANNOT create admin',    no(req('create-user','POST',$AT, ['name'=>'Bad','email'=>"bad_{$ts}@test.com",'username'=>"bad_{$ts}",'role'=>'admin','password'=>'Test1234!'])));
p('sales_rep CANNOT create user', no(req('create-user','POST',$S1T, ['name'=>'Hack','email'=>"hack_{$ts}@test.com",'username'=>"hack_{$ts}",'role'=>'sales_rep','password'=>'Test1234!'])));
p('admin CANNOT delete user',     no(req('delete-user','DELETE',$AT, ['id'=>$uid])));
p('super CAN delete user',        ok(req('delete-user','DELETE',$ST, ['id'=>$uid])));

// ── CHAT: CHANNELS ─────────────────────────────────────────────────────────
section('CHAT: CHANNELS');
$chs = req('chat-channels','GET',$ST);
p('super sees channels',          ok($chs) && count($chs['channels'] ?? []) >= 1);
p('admin sees channels',          ok(req('chat-channels','GET',$AT)));
p('sales1 sees channels',         ok(req('chat-channels','GET',$S1T)));

$ts2 = time();
$nc  = req('chat-channels','POST',$ST, ['name'=>"qa-chan-{$ts2}",'description'=>'QA','members'=>['user_sales1','user_sales2']]);
$cid = $nc['channel']['id'] ?? '';
p('super creates channel',        ok($nc) && $cid !== '');
p('admin creates channel',        ok(req('chat-channels','POST',$AT, ['name'=>"qa-admin-{$ts2}",'members'=>[]])));
p('sales_rep creates channel',    ok(req('chat-channels','POST',$S1T, ['name'=>"qa-sales-{$ts2}",'members'=>[]])));
p('admin edits channel',          ok(req('chat-channel-members','POST',$AT, ['channel_id'=>$cid,'name'=>'qa-renamed','members'=>['user_sales1']])));
p('sales_rep CANNOT edit channel',no(req('chat-channel-members','POST',$S1T, ['channel_id'=>$cid,'members'=>[]])));

// ── CHAT: CHANNEL DELETE PERMISSIONS ───────────────────────────────────────
section('CHAT: CHANNEL DELETE');
p('sales_rep CANNOT delete channel', no(req('chat-channels','DELETE',$S1T, ['id'=>$cid])));
p('admin CANNOT delete channel',     no(req('chat-channels','DELETE',$AT,  ['id'=>$cid])));
p('super CAN delete channel',        ok(req('chat-channels','DELETE',$ST,  ['id'=>$cid])));
p('cannot delete #general',          no(req('chat-channels','DELETE',$ST,  ['id'=>'channel_general'])));

// ── CHAT: MESSAGES ─────────────────────────────────────────────────────────
section('CHAT: MESSAGES');
$sm1 = req('chat-messages','POST',$S1T, ['thread'=>'channel_general','text'=>'Hello QA']);
$m1  = $sm1['message']['id'] ?? '';
$sm2 = req('chat-messages','POST',$S2T, ['thread'=>'channel_general','text'=>'Reply s2']);
$m2  = $sm2['message']['id'] ?? '';
p('sales1 sends message',           ok($sm1) && $m1 !== '');
p('sales2 sends message',           ok($sm2) && $m2 !== '');

$fm = req('chat-messages&thread=channel_general','GET',$S1T);
p('fetch messages',                 ok($fm));
p('messages returned',              count($fm['messages'] ?? []) >= 1);

p('sales1 deletes OWN message',     ok(req('chat-delete-message','POST',$S1T, ['thread'=>'channel_general','message_id'=>$m1])));
p('sales1 CANNOT delete s2 msg',    no(req('chat-delete-message','POST',$S1T, ['thread'=>'channel_general','message_id'=>$m2])));
p('admin CAN delete any message',   ok(req('chat-delete-message','POST',$AT,  ['thread'=>'channel_general','message_id'=>$m2])));
$sm3 = req('chat-messages','POST',$S2T, ['thread'=>'channel_general','text'=>'super del test']);
$m3  = $sm3['message']['id'] ?? '';
p('super CAN delete any message',   ok(req('chat-delete-message','POST',$ST, ['thread'=>'channel_general','message_id'=>$m3])));

// ── CHAT: PIN ──────────────────────────────────────────────────────────────
section('CHAT: PIN / UNPIN');
$sm4 = req('chat-messages','POST',$S1T, ['thread'=>'channel_general','text'=>'Pin me']);
$m4  = $sm4['message']['id'] ?? '';
p('pin message',   ok(req('chat-pin-message','POST',$S1T, ['thread'=>'channel_general','message_id'=>$m4,'unpin'=>false])));
p('unpin message', ok(req('chat-pin-message','POST',$S1T, ['thread'=>'channel_general','message_id'=>$m4,'unpin'=>true])));

// ── CHAT: REACTIONS ────────────────────────────────────────────────────────
section('CHAT: REACTIONS');
$sm5 = req('chat-messages','POST',$S1T, ['thread'=>'channel_general','text'=>'React me']);
$m5  = $sm5['message']['id'] ?? '';
p('add reaction',    ok(req('chat-react','POST',$S1T, ['thread'=>'channel_general','message_id'=>$m5,'emoji'=>'👍'])));
p('toggle off',      ok(req('chat-react','POST',$S1T, ['thread'=>'channel_general','message_id'=>$m5,'emoji'=>'👍'])));

// ── CHAT: DIRECT MESSAGES ──────────────────────────────────────────────────
section('CHAT: DIRECT MESSAGES');
$dt = req('chat-dm-threads','GET',$S1T);
p('sales1 gets DM thread list',   ok($dt));
p('DM threads >= 1',              count($dt['threads'] ?? []) >= 1);

$ids = ['user_sales1','user_sales2']; sort($ids);
$dmtid = 'dm_' . md5($ids[0] . '_' . $ids[1]);

$sdm = req("chat-messages",'POST',$S1T, ['thread'=>$dmtid,'text'=>'DM from s1 to s2']);
p('send DM',           ok($sdm));

$fdm = req("chat-messages&thread=$dmtid",'GET',$S2T);
p('sales2 reads DM',   ok($fdm));
p('DM has messages',   count($fdm['messages'] ?? []) >= 1);

// ── NOTIFICATIONS ──────────────────────────────────────────────────────────
section('NOTIFICATIONS');
$notifs = req('notifications','GET',$S2T);
p('sales2 gets notifications',  ok($notifs));
p('has DM notification',        count($notifs['notifications'] ?? []) >= 1);
$nid = $notifs['notifications'][0]['id'] ?? '';
p('mark one read',     ok(req('notifications','POST',$S2T, ['action'=>'mark_read','notification_id'=>$nid])));
p('mark all read',     ok(req('notifications','POST',$S2T, ['action'=>'mark_all_read'])));
p('dismiss',           ok(req('notifications','POST',$S2T, ['action'=>'dismiss','notification_id'=>$nid])));

// ── UNREAD BADGE ───────────────────────────────────────────────────────────
section('UNREAD BADGE');
p('chat-unread endpoint',  ok(req('chat-unread','GET',$S1T)));

// ── SUMMARY ────────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n==============================\n";
echo "  RESULTS: $pass/$total passed\n";
if ($fail > 0) echo "  $fail FAILURES\n";
echo "==============================\n";
