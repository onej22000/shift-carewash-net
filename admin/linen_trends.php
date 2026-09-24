<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$employee = current_employee();
if ($employee === null || !in_array($employee['role'], ['admin', 'staff'], true)) {
    header('Location: /staff/login.php');
    exit;
}
$isAdmin = $employee['role'] === 'admin';
$dashboardPath = $isAdmin ? '/admin/dashboard.php' : '/staff/dashboard.php';
$logoutPath = $isAdmin ? '/admin/logout.php' : '/staff/logout.php';
$pdo = getPdo();

// 施設管理画面と同じテーブルを参照。実績の数値は書き換えない。
$facilities = $pdo->query(
    "SELECT id, name, onboarding_start_date, room_count, pickup_schedule
     FROM facilities
     WHERE onboarding_start_date IS NOT NULL AND is_active = 1
       AND (facility_type IS NULL OR facility_type != 'クリーニング所')
     ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

// 入居者数の入力対象月：受託開始月〜当月の各月末。
$currentMonthEnd = date('Y-m-t');
$residentMonths = [];
foreach ($facilities as $facility) {
    $months = [];
    $cursor = new DateTimeImmutable(substr((string) $facility['onboarding_start_date'], 0, 7) . '-01');
    while (($monthEnd = $cursor->format('Y-m-t')) <= $currentMonthEnd) {
        $months[] = $monthEnd;
        $cursor = $cursor->modify('+1 month');
    }
    $residentMonths[(int) $facility['id']] = $months;
}

// 入居者数の保存（管理者のみ）。空欄は未入力に戻す＝行を削除。
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facilityId = (int) ($_POST['facility_id'] ?? 0);
    $redirect = '/admin/linen_trends.php' . (isset($residentMonths[$facilityId]) ? '?facility=' . $facilityId : '');
    $input = $_POST['resident_count'] ?? [];
    if (!$isAdmin) {
        set_flash('error', '入居者数の入力・編集は管理者のみ可能です。');
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
    } elseif (empty($residentMonths[$facilityId])) {
        set_flash('error', '入居者数を入力できる施設・月が見つかりません。');
    } elseif (!is_array($input) || array_diff(array_map('strval', array_keys($input)), $residentMonths[$facilityId])) {
        set_flash('error', '入力対象外の月が含まれています。画面を再読み込みしてやり直してください。');
    } else {
        $errors = [];
        $values = [];
        foreach ($residentMonths[$facilityId] as $monthEnd) {
            $raw = $input[$monthEnd] ?? '';
            $raw = is_string($raw) ? trim($raw) : null;
            if ($raw === '') {
                $values[$monthEnd] = null;
            } elseif ($raw !== null && preg_match('/\A\d{1,5}\z/', $raw)) {
                $values[$monthEnd] = (int) $raw;
            } else {
                $errors[] = $monthEnd . '：0以上の整数で入力してください';
            }
        }
        if ($errors) {
            set_flash('error', '保存していません。' . implode(' ／ ', $errors));
        } else {
            $upsert = $pdo->prepare(
                'INSERT INTO facility_resident_counts (facility_id, month_end_date, resident_count, created_by, updated_by)
                 VALUES (:facility_id, :month_end_date, :resident_count, :created_by, :updated_by)
                 ON DUPLICATE KEY UPDATE
                     updated_by = IF(resident_count <> VALUES(resident_count), VALUES(updated_by), updated_by),
                     resident_count = VALUES(resident_count)'
            );
            $delete = $pdo->prepare('DELETE FROM facility_resident_counts WHERE facility_id = :facility_id AND month_end_date = :month_end_date');
            $pdo->beginTransaction();
            try {
                foreach ($values as $monthEnd => $count) {
                    if ($count === null) {
                        $delete->execute([':facility_id' => $facilityId, ':month_end_date' => $monthEnd]);
                    } else {
                        $upsert->execute([
                            ':facility_id' => $facilityId, ':month_end_date' => $monthEnd, ':resident_count' => $count,
                            ':created_by' => (int) $employee['id'], ':updated_by' => (int) $employee['id'],
                        ]);
                    }
                }
                $pdo->commit();
                set_flash('success', '入居者数を保存しました。');
            } catch (Throwable $e) {
                $pdo->rollBack();
                set_flash('error', '入居者数の保存に失敗しました。');
            }
        }
    }
    header('Location: ' . $redirect);
    exit;
}
$flash = pop_flash();
$csrfToken = $isAdmin ? csrf_token() : '';
$requestedFacilityId = (int) ($_GET['facility'] ?? 0);

// 入力済みの入居者数（対象月の範囲内のみ）。0も入力値として保持し、予測時に除外する。
$residentCounts = [];
foreach ($pdo->query('SELECT facility_id, month_end_date, resident_count FROM facility_resident_counts') as $row) {
    $id = (int) $row['facility_id'];
    if (isset($residentMonths[$id]) && in_array((string) $row['month_end_date'], $residentMonths[$id], true)) {
        $residentCounts[$id][(string) $row['month_end_date']] = (int) $row['resident_count'];
    }
}
$residentPayload = [];
foreach ($facilities as $facility) {
    $id = (int) $facility['id'];
    $residentPayload[(string) $id] = ['months' => $residentMonths[$id], 'counts' => (object) ($residentCounts[$id] ?? [])];
}

// 集荷日単位で集計。未入力を含む日は部分合計を確定値として学習しない。
$rows = $pdo->query(
    "SELECT cc.facility_id, cc.pickup_date, MAX(cc.arrival_date) AS arrival_date,
         CASE WHEN COUNT(cc.return_ready_laundry_net_count) = COUNT(*)
                   AND COUNT(cc.arrival_date) = COUNT(*) AND MAX(cc.arrival_date) <= CURRENT_DATE()
              THEN SUM(cc.return_ready_laundry_net_count) ELSE NULL END AS laundry_net_count
     FROM collection_cycles AS cc
     INNER JOIN facilities AS f ON f.id = cc.facility_id
     WHERE f.onboarding_start_date IS NOT NULL AND f.is_active = 1
       AND cc.deleted_at IS NULL
       AND cc.pickup_date > f.onboarding_start_date
       AND cc.pickup_date <= CURRENT_DATE()
     GROUP BY cc.facility_id, cc.pickup_date
     ORDER BY cc.facility_id, cc.pickup_date"
)->fetchAll(PDO::FETCH_ASSOC);

$payload = [];
foreach ($facilities as $facility) {
    $payload[(string) $facility['id']] = [
        'id' => (int) $facility['id'], 'name' => (string) $facility['name'],
        'startDate' => (string) $facility['onboarding_start_date'],
        'roomCount' => $facility['room_count'] !== null ? (int) $facility['room_count'] : null,
        'schedule' => (string) ($facility['pickup_schedule'] ?? ''), 'points' => [],
    ];
}
foreach ($rows as $row) {
    $id = (string) $row['facility_id'];
    if (!isset($payload[$id])) { continue; }
    $payload[$id]['points'][] = [
        'date' => (string) $row['pickup_date'],
        'arrivalDate' => (string) $row['arrival_date'],
        'nets' => $row['laundry_net_count'] !== null ? (int) $row['laundry_net_count'] : null,
    ];
}
$payload = array_values($payload);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>推移予測</title>
    <style>
        :root { --navy:#183b56; --blue:#247ba0; --orange:#f28e2b; --ink:#243447; --muted:#667085; --line:#d9e2e8; --bg:#f4f7f9; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif; color:var(--ink); background:var(--bg); }
        header { padding:18px 22px; color:#fff; background:var(--navy); }
        header h1 { margin:0 0 6px; font-size:clamp(1.25rem,3vw,1.75rem); }
        header nav { font-size:.9rem; }
        header a { color:#fff; }
        main { max-width:1180px; margin:0 auto; padding:20px; }
        .toolbar,.card { background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 3px 12px rgba(24,59,86,.06); }
        .toolbar { display:flex; gap:14px; align-items:end; flex-wrap:wrap; padding:16px; margin-bottom:16px; }
        label { display:block; font-weight:700; margin-bottom:6px; }
        select { min-width:260px; max-width:100%; padding:10px 12px; border:1px solid #aebdc7; border-radius:8px; font-size:1rem; background:#fff; }
        .updated { margin-left:auto; color:var(--muted); font-size:.9rem; }
        .card { padding:18px; margin-bottom:16px; }
        .meta { display:flex; gap:22px; flex-wrap:wrap; margin-bottom:14px; }
        .meta strong { display:block; color:var(--navy); font-size:1.1rem; }
        .meta span { color:var(--muted); font-size:.82rem; }
        .chart-wrap { width:100%; overflow-x:auto; }
        svg { width:100%; min-width:640px; height:auto; display:block; }
        .legend { display:flex; gap:8px 20px; flex-wrap:wrap; justify-content:center; margin:8px 0 0; font-size:.9rem; }
        .legend .swatch { display:inline-block; width:30px; min-width:0; height:10px; vertical-align:middle; margin-right:6px; }
        table { width:100%; border-collapse:collapse; margin-top:18px; }
        th,td { padding:9px 10px; border-bottom:1px solid #e6ecef; text-align:right; }
        th { background:#eaf3f8; color:var(--navy); }
        th:first-child,td:first-child { text-align:left; }
        .empty { padding:42px 10px; color:var(--muted); text-align:center; }
        .note { color:var(--muted); font-size:.85rem; margin:12px 0 0; }
        .message { padding:12px 14px; border-radius:8px; margin:0 0 16px; }
        .message.success { background:#e7f6ec; color:#1e6b3a; border:1px solid #b7e1c4; }
        .message.error { background:#fdecec; color:#9b1c1c; border:1px solid #f3b8b8; }
        .resident-form details { border:1px solid var(--line); border-radius:8px; padding:10px 12px; margin-top:10px; }
        .resident-form summary { cursor:pointer; font-weight:700; color:var(--navy); }
        .resident-form input[type=number] { width:110px; padding:7px 8px; border:1px solid #aebdc7; border-radius:6px; font-size:1rem; text-align:right; }
        .resident-form button { margin-top:10px; padding:9px 18px; border:0; border-radius:8px; background:var(--navy); color:#fff; font-size:1rem; cursor:pointer; }
        .excluded { color:var(--muted); }
        @media (max-width:600px) { main { padding:12px; } .updated { width:100%; margin-left:0; } }
    </style>
</head>
<body>
<header>
    <h1>推移予測</h1>
    <nav>ログイン中: <?= htmlspecialchars((string) $employee['name'], ENT_QUOTES, 'UTF-8') ?>さん | <a href="<?= htmlspecialchars($dashboardPath, ENT_QUOTES, 'UTF-8') ?>">ダッシュボード</a> | <a href="<?= htmlspecialchars($logoutPath, ENT_QUOTES, 'UTF-8') ?>">ログアウト</a></nav>
</header>
<main>
    <?php if ($flash !== null): ?>
        <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <section class="toolbar">
        <div>
            <label for="facility">表示する施設</label>
            <select id="facility"></select>
        </div>
        <div>
            <label for="forecastDays">増加予想</label>
            <select id="forecastDays">
                <option value="30">30日先</option>
                <option value="60">60日先</option>
                <option value="90">90日先</option>
                <option value="120">120日先</option>
                <option value="150">150日先</option>
                <option value="180">180日先</option>
                <option value="365">365日先</option>
            </select>
        </div>
        <div class="updated">表示時点: <?= htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') ?></div>
    </section>
    <section class="card" id="content"></section>
    <section class="card" id="residentContent"></section>
    <section class="card resident-form">
        <h2>月末入居者数<?= $isAdmin ? 'の入力' : '（閲覧のみ）' ?></h2>
        <p class="note">各月末日時点の入居者数です。受託開始月から当月末まで表示します。0と未入力の月は入居者数予測から除外します（0人としては計算しません）。<?= $isAdmin ? '空欄にして保存すると未入力に戻ります。' : '入力・編集は管理者のみ可能です。' ?></p>
        <?php
        $pendingNames = [];
        foreach ($facilities as $facility):
            $id = (int) $facility['id'];
            if (!$residentMonths[$id]) { $pendingNames[] = (string) $facility['name']; continue; }
            $counts = $residentCounts[$id] ?? [];
            $entered = count(array_filter($counts, fn($c) => $c > 0));
        ?>
        <details<?= $requestedFacilityId === $id ? ' open' : '' ?>>
            <summary><?= htmlspecialchars((string) $facility['name'], ENT_QUOTES, 'UTF-8') ?>（受託開始日：<?= htmlspecialchars((string) $facility['onboarding_start_date'], ENT_QUOTES, 'UTF-8') ?> ／ 予測に使える月：<?= $entered ?>件）</summary>
            <?php if ($isAdmin): ?>
            <form method="post" action="/admin/linen_trends.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="facility_id" value="<?= $id ?>">
            <?php endif; ?>
                <div class="chart-wrap"><table>
                    <thead><tr><th>月末日</th><th>入居者数</th></tr></thead>
                    <tbody>
                    <?php foreach ($residentMonths[$id] as $monthEnd): $count = $counts[$monthEnd] ?? null; ?>
                        <tr>
                            <td><label for="resident-<?= $id ?>-<?= $monthEnd ?>"><?= htmlspecialchars($monthEnd, ENT_QUOTES, 'UTF-8') ?></label></td>
                            <td><?php if ($isAdmin): ?><input type="number" id="resident-<?= $id ?>-<?= $monthEnd ?>" name="resident_count[<?= $monthEnd ?>]" min="0" max="99999" step="1" inputmode="numeric" value="<?= $count !== null ? $count : '' ?>"><?php else: ?><?= $count !== null ? $count . '人' : '未入力' ?><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php if ($isAdmin): ?>
                <button type="submit">この施設の入居者数を保存</button>
            </form>
            <?php endif; ?>
        </details>
        <?php endforeach; ?>
        <?php if ($pendingNames): ?>
            <p class="note">受託開始前のため入力対象の月がない施設：<?= htmlspecialchars(implode('、', $pendingNames), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </section>
</main>
<script>
const facilities = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const today = <?= json_encode(date('Y-m-d')) ?>;
const residents = <?= json_encode($residentPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
// UTC日付演算でブラウザーのタイムゾーンによる日付ずれを防止する。
const DAY = 86400000;
const time = date => Date.parse(date + 'T00:00:00Z');
const dateAt = value => new Date(value).toISOString().slice(0, 10);
const elapsed = (date, base) => Math.round((time(date) - time(base)) / DAY);
const schedules = {'月・木':[1,4], '火・金':[2,5], '水・土':[3,6]};
function firstPickup(f) {
    const weekdays = schedules[f.schedule];
    if (!weekdays || !Number.isFinite(time(f.startDate))) return null;
    // 「受託開始日の次の集荷日」なので当日は含めない。
    for (let i=1; i<=7; i++) {
        const t = time(f.startDate) + i*DAY;
        if (weekdays.includes(new Date(t).getUTCDay())) return dateAt(t);
    }
    return null;
}
function validPoints(f) {
    return f.points.filter(p => Number.isFinite(p.nets) && p.nets >= 0)
        .slice().sort((a,b) => a.date.localeCompare(b.date));
}
function median(values) {
    const a = values.slice().sort((a,b)=>a-b), n=a.length;
    return n ? (a[Math.floor((n-1)/2)] + a[Math.floor(n/2)])/2 : 0;
}
// 定期集荷の何回目か。欠測があってもサイクルを詰めない。
function cycleAt(f,date) {
    const first=firstPickup(f);
    if(!first || date<first) return -1;
    let count=-1;
    for(let t=time(first);t<=time(date);t+=DAY)
        if(schedules[f.schedule].includes(new Date(t).getUTCDay())) count++;
    return count;
}
function buildModel(all) {
    const refs = all.filter(f => f.name.replace(/[\s　]/g,'') === 'アルク枚方長尾');
    if (refs.length !== 1) return {error:'基準施設「アルク枚方長尾」を一意に特定できません。施設名を確認してください。'};
    const ref=refs[0], first=firstPickup(ref);
    if (!first) return {error:'枚方長尾の集荷曜日を設定してください。'};
    const points=validPoints(ref).filter(p=>p.date>=first);
    if (points.length<2) return {error:'枚方長尾の入力済み集荷実績が2日以上必要です。'};
    // 単調回帰（PAVA）。一時的な減少を平滑化し、実績自体はそのまま表示する。
    const blocks=[];
    points.forEach((p,i)=>{
        blocks.push({lo:i,hi:i,sum:p.nets,n:1});
        while(blocks.length>1) {
            const b=blocks[blocks.length-1], a=blocks[blocks.length-2];
            if(a.sum/a.n<=b.sum/b.n) break;
            blocks.splice(-2,2,{lo:a.lo,hi:b.hi,sum:a.sum+b.sum,n:a.n+b.n});
        }
    });
    const nodes=points.map(p=>({cycle:cycleAt(ref,p.date),nets:0}));
    for(const b of blocks) for(let i=b.lo;i<=b.hi;i++) nodes[i].nets=b.sum/b.n;
    const last=nodes[nodes.length-1];
    // 直近8サイクルを中心に増加数/経過サイクルを計算。横ばい区間も含める。
    const recent=nodes.filter(p=>p.cycle>=last.cycle-8);
    const start=recent.length>=2?recent[0]:nodes[nodes.length-2];
    const span=last.cycle-start.cycle;
    const rate=span>0?Math.max(0,(last.nets-start.nets)/span):0;
    function netCount(cycle) {
        if(cycle<0) return 0;
        if(cycle<=nodes[0].cycle) return nodes[0].nets*(cycle+1)/(nodes[0].cycle+1);
        for(let i=1;i<nodes.length;i++) if(cycle<=nodes[i].cycle) {
            const a=nodes[i-1], b=nodes[i];
            if(b.cycle===a.cycle) return b.nets;
            return a.nets+(b.nets-a.nets)*(cycle-a.cycle)/(b.cycle-a.cycle);
        }
        return last.nets+rate*(cycle-last.cycle);
    }
    return {ref, first, points, nodes, rate, netCount, recentStart:start, lastDate:points[points.length-1].date};
}
function predictFacility(f, model, endDate, today) {
    const first=firstPickup(f), cap=f.roomCount*0.9, actual=validPoints(f);
    const last=actual.length?actual[actual.length-1]:null;
    if(model.error || !first || !(cap>0)) return {points:[], reason:model.error || '居室数・集荷曜日が未設定のため予測できません。'};
    const warnings=[];
    if(last && last.nets>cap) warnings.push('最新実績が居室数の90%を超えています。急落を避けて最新値を維持します。1人あたりネット数・居室数を確認してください。');
    if(f.points.some(p=>p.nets===null)) warnings.push('未入力を含む集荷日の合計は未確定として、学習対象から除外しています。');
    const anchor=last ? model.netCount(cycleAt(f,last.date)) : 0;
    const points=[];
    const begin=Math.max(time(first),time(today)+DAY,last?time(last.date)+DAY:0);
    for(let t=begin;t<=time(endDate);t+=DAY) {
        if(!schedules[f.schedule].includes(new Date(t).getUTCDay())) continue;
        const date=dateAt(t), baseline=model.netCount(cycleAt(f,date));
        let value=Math.min(cap,baseline);
        if(last) {
            // 居室数で増加数を拡大縮小せず、同じサイクル数の増分を加算。
            value=Math.min(Math.max(cap,last.nets),last.nets+Math.max(0,baseline-anchor));
        }
        points.push({date,nets:Math.round(value*10)/10});
    }
    const todayEstimate=last?last.nets:Math.min(cap,model.netCount(cycleAt(f,today)));
    return {points, warnings, last, first, cap, todayEstimate};
}
// 各施設の直近1回量を持ち越して合算。非集荷施設を0に戻さない。
// 過去に入力済み実績のない稼働施設は不明として扱う。
function aggregateLevels(all,results,today) {
    const dates=[...new Set(all.flatMap(f=>f.points.map(p=>p.date)))].sort();
    function totalAt(date, future) {
        let total=0;
        for(const f of all) {
            const first=firstPickup(f);
            if(!first) return null;
            if(date<first) continue;
            const actual=validPoints(f).filter(p=>p.date<=date);
            let value=actual.length?actual[actual.length-1].nets:null;
            if(future) {
                const r=results.find(r=>r.f.id===f.id);
                if(!r || r.reason) return null;
                const predictions=r.points.filter(p=>p.date<=date);
                if(predictions.length) value=predictions[predictions.length-1].nets;
                // 実績のない施設も今日時点のモデル値を集計基準にする。
                else if(value===null) value=r.todayEstimate;
            }
            if(value===null || value===undefined) return null;
            total+=value;
        }
        return Math.round(total*10)/10;
    }
    const forecastDates=[...new Set(results.flatMap(r=>r.points.map(p=>p.date)))].sort();
    return {actual:dates.map(date=>({date,nets:totalAt(date,false)})),
        forecast:forecastDates.map(date=>({date,nets:totalAt(date,true)})),
        anchor:{date:today,nets:totalAt(today,true)}};
}
function aggregate(points) {
    const byDate=new Map();
    for(const p of points) {
        const row=byDate.get(p.date) || {date:p.date,nets:0,incomplete:false};
        if(p.nets===null) row.incomplete=true; else row.nets+=p.nets;
        byDate.set(p.date,row);
    }
    return [...byDate.values()].sort((a,b)=>a.date.localeCompare(b.date))
        .map(p=>({date:p.date,nets:p.incomplete?null:Math.round(p.nets*10)/10}));
}
const select=document.getElementById('facility');
const forecastDaysSelect=document.getElementById('forecastDays');
const content=document.getElementById('content');
const escapeHtml=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
for(const f of [{id:'all',name:'全施設合計'},...facilities]) {
    const option=document.createElement('option'); option.value=f.id; option.textContent=f.name; select.appendChild(option);
}
// 入居者数の保存後は、保存した施設を選択した状態で戻す。
const requestedFacility=<?= json_encode($requestedFacilityId > 0 ? (string) $requestedFacilityId : null) ?>;
if(requestedFacility && facilities.some(f=>String(f.id)===requestedFacility)) select.value=requestedFacility;
const model=buildModel(facilities);
function calculationDetails(results,model,end) {
    if(model.error) return `<section><h3>予測の算出根拠</h3><p>${escapeHtml(model.error)}</p></section>`;
    const last=model.nodes[model.nodes.length-1], start=model.recentStart;
    const fmt=n=>Number(n).toFixed(2);
    const refRows=model.points.map((p,i)=>{
        const n=model.nodes[i], prev=i?model.nodes[i-1]:null;
        const span=prev?n.cycle-prev.cycle:0;
        return `<tr><td>${escapeHtml(p.date)}</td><td>${n.cycle+1}</td><td>${p.nets}</td><td>${fmt(n.nets)}</td><td>${span>0?fmt((n.nets-prev.nets)/span):'—'}</td></tr>`;
    }).join('');
    const facilityRows=results.map(r=>{
        const last=r.last, endPoint=r.points.length?r.points[r.points.length-1]:null;
        const endCycle=endPoint?cycleAt(r.f,endPoint.date):null;
        const anchorCycle=last?cycleAt(r.f,last.date):null;
        const added=endPoint?Math.max(0,model.netCount(endCycle)-(last?model.netCount(anchorCycle):0)):null;
        return `<tr><td>${escapeHtml(r.f.name)}</td><td>${escapeHtml(firstPickup(r.f)||'未設定')}</td><td>${last?`${escapeHtml(last.date)}：${last.nets}`:'実績なし'}</td><td>${r.f.roomCount>0?fmt(r.f.roomCount*0.9):'未設定'}</td><td>${endCycle!==null?endCycle+1:'—'}</td><td>${added!==null?fmt(added):'—'}</td><td>${endPoint?endPoint.nets:'算出不可'}</td></tr>`;
    }).join('');
    return `<section><h3>予測の算出根拠</h3>
      <p><strong>基準施設：アルク枚方長尾 ／ 増加数：${fmt(model.rate)}ネット／集荷サイクル</strong></p>
      <p>第${start.cycle+1}回〜第${last.cycle+1}回の平滑化実績から、（${fmt(last.nets)} − ${fmt(start.nets)}）ネット ÷ ${last.cycle-start.cycle}サイクル ＝ ${fmt(model.rate)}ネット／回。直近約8サイクルを使用し、欠測期間の集荷回数も分母に含めます。</p>
      <p>実績がある施設：<strong>最新ネット数 ＋ 同じ経過サイクル区間に枚方長尾で増えるネット数</strong>。実績のない施設：初回集荷から同じサイクル数まで進んだ枚方長尾のネット数。いずれも<strong>居室数 × 0.9</strong>で頭打ちにします（最新実績が上限超過の場合は最新値を維持）。居室数の比率で増加数は変えません。</p>
      <p class="note">実績がある期間は、上下動を平滑化した実測曲線を使い、記録のないサイクルは補間します。その先は上記の増加数を加算します。増加数が0なら先の予測は横ばいです。全施設合計は各施設の直近1回量を持ち越して足し、集荷のない曜日に0へ戻しません。1年後に必ず上限へ到達するという前提ではありません。</p>
      <details><summary>枚方長尾の参照実績と補正値（${model.points.length}件）</summary><div class="chart-wrap"><table><thead><tr><th>集荷日</th><th>第何回</th><th>入力実績</th><th>平滑化ネット数</th><th>前記録からの増加／回</th></tr></thead><tbody>${refRows}</tbody></table></div></details>
      <details open><summary>施設別の計算内訳（${escapeHtml(end)}まで）</summary><div class="chart-wrap"><table><thead><tr><th>施設</th><th>初回集荷日</th><th>最新実績日：ネット数</th><th>90%上限</th><th>予測最終回</th><th>上限適用前の増分・新施設は予測量</th><th>期間末予測</th></tr></thead><tbody>${facilityRows}</tbody></table></div></details>
      </section>`;
}
// 縦軸の最大値：表示する全系列（ネット・入居者の実績と予測）の最大値に約10%の余白を足し、4等分で整数の目盛りになるよう切り上げる。
// 90%上限はここに含めない（縦軸の範囲に入るときだけ線を引く）。
function chartCeiling(actual, predicted, res) {
    const values=actual.concat(predicted).filter(p=>p.nets!==null).map(p=>p.nets)
        .concat(res?res.actual.concat(res.predicted).map(p=>p.residents):[]);
    return Math.ceil(Math.max(4,...values)*1.1/4)*4;
}
// ネット数と入居者数を1本の軸（数）に同じ目盛りで重ねる。
// res：入居者数の系列（null＝データなし）。cap：90%上限＝居室数×0.9（未設定なら null）。
// 90%上限が縦軸の範囲に入るときは破線、入らないときはグラフ上部に文字で表示する。
function chartSvg(actual, predicted, base, end, res, cap) {
    const width=980,height=440,left=70,right=45,top=24,bottom=62;
    const netColor='#f28e2b', resColor='#8e44ad', capColor='#b42318';
    const maxX=Math.max(1,elapsed(end,base));
    const ceiling=chartCeiling(actual,predicted,res), capVisible=cap>0 && cap<=ceiling;
    const sx=date=>left+elapsed(date,base)/maxX*(width-left-right);
    const sy=value=>top+(1-value/ceiling)*(height-top-bottom);
    function path(points,key) {
        let active=false,result='';
        for(const p of points) {
            if(p[key]===null) {active=false;continue;}
            result+=`${active?' L':' M'} ${sx(p.date).toFixed(1)} ${sy(p[key]).toFixed(1)}`; active=true;
        }
        return result;
    }
    let grid='';
    for(let i=0;i<=4;i++) {
        const y=top+i*(height-top-bottom)/4, x=left+i*(width-left-right)/4;
        const date=dateAt(time(base)+Math.round(maxX*i/4)*DAY);
        grid+=`<line x1="${left}" y1="${y}" x2="${width-right}" y2="${y}" stroke="#d9e2e8" stroke-dasharray="4 4"/><text x="${left-12}" y="${y+4}" text-anchor="end" fill="#667085" font-size="12">${ceiling*(4-i)/4}</text><text x="${x}" y="${height-bottom+23}" text-anchor="middle" fill="#667085" font-size="12">${date}</text>`;
    }
    grid+=`<line x1="${left}" y1="${top}" x2="${left}" y2="${height-bottom}" stroke="#98a2b3"/><text x="${left-12}" y="14" text-anchor="end" fill="#495867" font-size="12">数</text>`;
    // 実績は見える点、予測は破線のみ（点は透明にしてツールチップ用の当たり判定だけ残す）。
    const dots=(points,key,color,label,unit,visible)=>points.filter(p=>p[key]!==null).map(p=>`<circle cx="${sx(p.date)}" cy="${sy(p[key])}" r="${visible?3.5:6}" fill="${visible?color:'transparent'}"><title>${escapeHtml(p.date)}${visible?'':'（予測）'}\n${label} ${p[key]}${unit}</title></circle>`).join('');
    let layers='';
    if(cap>0) {
        const capText=Math.round(cap*10)/10;
        if(!capVisible) layers+=`<text x="${width-right}" y="14" text-anchor="end" fill="${capColor}" font-size="12">90%上限：${capText}</text>`;
        else layers+=`<line x1="${left}" y1="${sy(cap)}" x2="${width-right}" y2="${sy(cap)}" stroke="${capColor}" stroke-width="1.5" stroke-dasharray="6 5"><title>90%上限（居室数×0.9） ${capText}</title></line><text x="${width-right-6}" y="${sy(cap)-6}" text-anchor="end" fill="${capColor}" font-size="12">90%上限（居室数×0.9）${capText}</text>`;
    }
    layers+=`<path d="${path(actual,'nets')}" fill="none" stroke="${netColor}" stroke-width="3"/><path d="${path(predicted,'nets')}" fill="none" stroke="${netColor}" stroke-width="3" stroke-dasharray="9 7"/>`;
    if(res) layers+=`<path d="${path(res.actual,'residents')}" fill="none" stroke="${resColor}" stroke-width="3"/><path d="${path(res.predicted,'residents')}" fill="none" stroke="${resColor}" stroke-width="3" stroke-dasharray="9 7"/>`;
    layers+=dots(actual,'nets',netColor,'ネット','',true)+dots(predicted,'nets',netColor,'ネット','',false);
    if(res) layers+=dots(res.actual,'residents',resColor,'入居者','人',true)+dots(res.predicted,'residents',resColor,'入居者','人',false);
    return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="1回あたりの洗濯ネット${res?'と月末入居者数':''}の実績と予測">${grid}${layers}<text x="490" y="428" text-anchor="middle" fill="#495867">日付</text></svg>`;
}
// 凡例の見本。グラフと同じ色・線種（実線＋点／破線）で描く。
function legendSwatch(color, dash, dot) {
    return `<svg class="swatch" viewBox="0 0 30 10" aria-hidden="true"><line x1="1" y1="5" x2="29" y2="5" stroke="${color}" stroke-width="${dash==='cap'?1.5:3}"${dash?` stroke-dasharray="${dash==='cap'?'4 3':'6 4'}"`:''}/>${dot?`<circle cx="15" cy="5" r="3.5" fill="${color}"/>`:''}</svg>`;
}
// グラフ用の入居者数系列。施設別はその施設、全施設は実績から予測した施設と他施設ペースで推定した施設を日付ごとに合算。
// 実績は実績のある施設の最新入力値を持ち越し（受託開始日＝0人）、予測は最新実績日より後を予測値・推定値で置き換える。
function residentSeries(selected, end) {
    const pace=residentPace();
    const results=selected.map(f=>({f,...residentForecast(f,pace,end)})).filter(r=>!r.reason);
    if(!results.length) return null;
    const round=n=>Math.round(n*10)/10;
    const measured=results.filter(r=>!r.estimated);
    const counts={measured:measured.length, estimated:results.length-measured.length};
    if(selected.length===1) {
        const r=results[0];
        // 推定施設は実績の線を持たない。受託開始日が過去なら開始日＝0人から予測線を引く。
        if(r.estimated) return {actual:[], predicted:r.f.startDate<r.points[0].date?[{date:r.f.startDate,residents:0},...r.points]:r.points, ...counts};
        return {actual:[{date:r.f.startDate,residents:0},...r.actual], predicted:r.points.length?[r.last,...r.points]:[], ...counts};
    }
    const carry=(r,date)=>{ const a=r.actual.filter(p=>p.date<=date); return a.length?a[a.length-1].residents:0; };
    const levelAt=(r,date)=>r.estimated||date>r.last.date?r.valueAt(date):carry(r,date);
    const actualDates=[...new Set(measured.flatMap(r=>[r.f.startDate,...r.actual.map(p=>p.date)]))].sort();
    const actual=actualDates.map(date=>({date,residents:round(measured.reduce((s,r)=>s+carry(r,date),0))}));
    const anchor=actual.length?actual[actual.length-1]:null;
    const totalAt=date=>({date,residents:round(results.reduce((s,r)=>s+levelAt(r,date),0))});
    const forecastDates=[...new Set(results.flatMap(r=>r.points.map(p=>p.date)))].filter(date=>!anchor || date>anchor.date).sort();
    const predicted=forecastDates.map(totalAt);
    return {actual, predicted:predicted.length && anchor?[totalAt(anchor.date),...predicted]:predicted, ...counts};
}
function render() {
    if(!facilities.length) {content.innerHTML='<div class="empty">受託開始日が登録された施設はありません。</div>';return;}
    const all=select.value==='all';
    const selected=all?facilities:facilities.filter(f=>String(f.id)===select.value);
    const horizon=Number(forecastDaysSelect.value), end=dateAt(time(today)+horizon*DAY);
    const results=selected.map(f=>({f,...predictFacility(f,model,end,today)}));
    const levels=all?aggregateLevels(selected,results,today):null;
    const actual=all?levels.actual:selected[0].points;
    const rawForecast=all?levels.forecast:results[0].points;
    const anchor=all?levels.anchor:results[0].last;
    const predicted=anchor && rawForecast.length?[anchor,...rawForecast]:rawForecast;
    // 入居者数はネット数と同じ軸に重ねる。予測期間はネットと同じ end（増加予想のプルダウン）に連動。
    const res=residentSeries(selected,end);
    const dates=[today,...actual.map(p=>p.date),...predicted.map(p=>p.date),...(res?res.actual.concat(res.predicted).map(p=>p.date):[])].sort();
    const base=dates[0];
    const warnings=results.flatMap(r=>[...(r.reason?[`${r.f.name}：${r.reason}`]:[]),...(r.warnings||[]).map(w=>`${r.f.name}：${w}`)]);
    if(!model.error && model.rate===0) warnings.push('基準実績に増加が観測されていないため、観測範囲より先は横ばいです。');

    if(!model.error && elapsed(today,model.lastDate)>14) warnings.push('枚方長尾の最新入力済み実績から14日以上経過しています。最新の入力状況を確認してください。');
    const registered=actual.filter(p=>p.nets!==null);
    const summary=all?'各施設の直近1回あたりのネット数を合算（非集荷日も値を維持）':`受託開始日：${selected[0].startDate} ／ 初回集荷予定日：${firstPickup(selected[0])||'未設定'} ／ 90%目安：${selected[0].roomCount>0?Math.round(selected[0].roomCount*0.9*10)/10:'未設定'}`;
    const modelNote=model.error?model.error:`基準：アルク枚方長尾 ／ 最新実績：${model.lastDate} ／ 学習：${model.points.length}集荷日 ／ 直近の増加数：${model.rate.toFixed(2)}ネット/集荷サイクル`;
    const excluded=results.filter(r=>r.reason).length;
    const title=all?(excluded?'全施設合計（予測に必要な設定不足）':'全施設合計'):selected[0].name;
    const configured=selected.filter(f=>f.roomCount>0);
    const rooms=configured.reduce((sum,f)=>sum+f.roomCount,0);
    const capacityText=configured.length===selected.length?`${Math.round(rooms*0.9*10)/10}ネット`:'未確定（居室数未設定あり）';
    const lastForecast=rawForecast.filter(p=>p.nets!==null).slice(-1)[0];
    // グラフの90%上限線：全施設は全施設の居室数合計×0.9、施設別はその施設の居室数×0.9。居室数未設定があれば引かない。
    const cap=configured.length===selected.length && rooms>0?rooms*0.9:null;
    const resLabel=!res?'':all?`入居者数（実績${res.measured}施設＋推定${res.estimated}施設）`:res.estimated?'入居者数（他施設ペースで推定）':'入居者数';
    const capVisible=cap>0 && cap<=chartCeiling(actual,predicted,res);
    const resMissing=res?selected.length-res.measured-res.estimated:0;
    content.innerHTML=`<h2>${escapeHtml(title)}</h2>
      <p>${escapeHtml(summary)}</p><p>対象：${selected.length}施設 ／ 登録居室数合計：${rooms}室${configured.length<selected.length?'（設定済み施設のみ）':''} ／ 90%合計上限：${capacityText} ／ 期間末の予測：${lastForecast?lastForecast.nets+'ネット':'算出不可'}</p><p class="note">${escapeHtml(modelNote)}</p>
      ${warnings.length?`<p class="note" role="status">${warnings.map(escapeHtml).join('<br>')}</p>`:''}
      <div class="meta"><div><span>実績の記録日数</span><strong>${actual.length}日</strong></div><div><span>直近の1回量合計</span><strong>${registered.length?registered[registered.length-1].nets:'—'}</strong></div><div><span>予測期間</span><strong>本日から${horizon}日先まで</strong></div></div>
      <div class="chart-wrap">${chartSvg(actual,predicted,base,end,res,cap)}</div>
      <div class="legend"><span>${legendSwatch('#f28e2b','',true)}ネット：実績</span><span>${legendSwatch('#f28e2b','net',false)}ネット：予測（新施設を含む）</span>${res?`${res.actual.length?`<span>${legendSwatch('#8e44ad','',true)}${escapeHtml(resLabel)}：実績</span>`:''}<span>${legendSwatch('#8e44ad','res',false)}${escapeHtml(resLabel)}：予測</span>`:''}${capVisible?`<span>${legendSwatch('#b42318','cap',false)}90%上限（居室数×0.9）</span>`:''}</div>
      ${res?(all?`<p class="note">入居者数：実績${res.measured}施設＋推定${res.estimated}施設の合計。推定は入居者数が未入力の施設で、実績のある施設の平均ペース×居室数×受託開始日からの日数で算出します（開始日までは0人、上限は居室数×0.9）。${resMissing?`データなしの${resMissing}施設は含みません。`:''}</p>`:(res.estimated?'<p class="note">入居者数：実績が未入力のため、他施設ペースで推定しています。</p>':'')):'<p class="note">入居者数：データなし</p>'}
      ${calculationDetails(results,model,end)}
      <p class="note">開始日の次の集荷日を第1回とし、枚方長尾の各サイクルのネット増加数をそのまま各施設に適用します。居室数で増加数を倍率補正しません。居室数の90%は上限だけに使うため、小さい施設ほど早く到達します。観測範囲の先は直近約8サイクルの平均増加数を使います。全施設の線は各施設の直近1回量を持ち越した合計で、当日の集荷量や累計枚数ではありません。過去に一度も実績がない稼働施設を含む期間は実績合計を未確定にします。1人1回1ネットの仮定です。</p>
      <table><thead><tr><th>集荷日</th><th>1回量（全施設では直近値合計）</th></tr></thead><tbody>${actual.map(p=>`<tr><td>${escapeHtml(p.date)}</td><td>${p.nets??'—'}</td></tr>`).join('')}</tbody></table>
      <details><summary>予測値を確認（${rawForecast.length}日）</summary><table><thead><tr><th>集荷日</th><th>予測ネット数</th></tr></thead><tbody>${rawForecast.map(p=>`<tr><td>${escapeHtml(p.date)}</td><td>${p.nets}</td></tr>`).join('')}</tbody></table></details>
      <p class="note">ページ表示時に最新DBを読み取ります。同じ集荷日の複数記録は合計します。未入力を含む日の実績は「—」と表示します。予測は小数1桁の参考値です。</p>`;
}
select.addEventListener('change',render);
forecastDaysSelect.addEventListener('change',render);
render();

// ===== 入居者数推移予測（洗濯ネット予測とは独立に計算。ネット数⇔入居者数の換算はしない） =====
const residentContent=document.getElementById('residentContent');
// 予測に使う実績：対象月のうち入居者数が0以外で入力された月末だけ。0・未入力は除外。
function residentPoints(f) {
    const r=residents[String(f.id)];
    if(!r) return [];
    return r.months.filter(m=>Number.isInteger(r.counts[m]) && r.counts[m]>0).map(m=>({date:m,residents:r.counts[m]}));
}
// 受託開始日＝0人を起点に含め、全データ点の最小二乗法で1日あたりの増加人数を出す。
// 最新実績＋傾き×経過日数で延長し、上限はネット予測と同じ居室数×0.9（最新実績が上限超過なら最新値を維持）。
function predictResidents(f, endDate, today) {
    const actual=residentPoints(f);
    if(!actual.length) return {actual, points:[], reason:'データなし'};
    const data=[{date:f.startDate,residents:0},...actual].map(p=>({x:elapsed(p.date,f.startDate),y:p.residents}));
    const mx=data.reduce((s,p)=>s+p.x,0)/data.length, my=data.reduce((s,p)=>s+p.y,0)/data.length;
    const sxx=data.reduce((s,p)=>s+(p.x-mx)**2,0), sxy=data.reduce((s,p)=>s+(p.x-mx)*(p.y-my),0);
    if(!(sxx>0)) return {actual, points:[], reason:'受託開始日と同じ日付の実績しかないため傾きを算出できません。'};
    const slope=sxy/sxx, cap=f.roomCount*0.9, last=actual[actual.length-1];
    const warnings=[];
    if(!(cap>0)) warnings.push('居室数が未設定のため、上限なしで予測しています。');
    else if(last.residents>cap) warnings.push('最新実績が居室数の90%を超えています。最新値を維持します。');
    if(slope<0) warnings.push('傾きがマイナスのため減少方向の予測です（0人未満にはしません）。');
    const valueAt=date=>{
        let value=Math.max(0,last.residents+slope*elapsed(date,last.date));
        if(cap>0) value=Math.min(Math.max(cap,last.residents),value);
        return Math.round(value*10)/10;
    };
    // 予測点：最新実績日と本日より後の各月末、および予測期間末日。
    const from=last.date>today?last.date:today, dates=[];
    let cursor=new Date(time(from.slice(0,8)+'01'));
    while(true) {
        const monthEnd=dateAt(Date.UTC(cursor.getUTCFullYear(),cursor.getUTCMonth()+1,0));
        if(monthEnd>endDate) break;
        if(monthEnd>from) dates.push(monthEnd);
        cursor=new Date(Date.UTC(cursor.getUTCFullYear(),cursor.getUTCMonth()+1,1));
    }
    if(endDate>from && !dates.includes(endDate)) dates.push(endDate);
    return {actual, last, slope, cap, warnings, valueAt, points:dates.map(date=>({date,residents:valueAt(date)}))};
}
// 基準ペース：自分の実績から予測できた施設それぞれの「傾き（人/日）÷居室数」の平均。該当施設がなければ null（全施設データなし）。
function residentPace() {
    const sources=facilities.map(f=>({f,...predictResidents(f,today,today)})).filter(r=>!r.reason && r.f.roomCount>0)
        .map(r=>({name:r.f.name,rate:r.slope/r.f.roomCount}));
    return sources.length?{rate:sources.reduce((s,p)=>s+p.rate,0)/sources.length, sources}:null;
}
// 入居者数が未入力の施設の推定：居室数×基準ペース×受託開始日からの日数。受託開始日までは0人、上限は居室数×0.9。
// 予測点：本日、本日より後の各月末、期間内の受託開始日・上限到達日、予測期間末日。
function estimateResidents(f, pace, endDate, today) {
    if(!pace) return {actual:[], points:[], reason:'データなし'};
    if(!(f.roomCount>0)) return {actual:[], points:[], reason:'データなし（居室数が未設定のため推定できません）'};
    const cap=f.roomCount*0.9, slope=f.roomCount*pace.rate, warnings=[];
    if(!(slope>0)) warnings.push('基準ペースが0以下のため、推定値は0人のままです。');
    const valueAt=date=>Math.round(Math.min(cap,Math.max(0,slope*elapsed(date,f.startDate)))*10)/10;
    const dates=new Set([today,endDate]);
    let cursor=new Date(time(today.slice(0,8)+'01'));
    while(true) {
        const monthEnd=dateAt(Date.UTC(cursor.getUTCFullYear(),cursor.getUTCMonth()+1,0));
        if(monthEnd>endDate) break;
        if(monthEnd>today) dates.add(monthEnd);
        cursor=new Date(Date.UTC(cursor.getUTCFullYear(),cursor.getUTCMonth()+1,1));
    }
    const capDate=slope>0?dateAt(time(f.startDate)+Math.ceil(cap/slope)*DAY):null;
    for(const d of [f.startDate,capDate]) if(d && d>today && d<=endDate) dates.add(d);
    return {actual:[], last:null, slope, cap, warnings, valueAt, estimated:true, points:[...dates].sort().map(date=>({date,residents:valueAt(date)}))};
}
// 実績のある施設は自分の実績から予測（従来どおり）。入居者数が未入力の施設だけ他施設ペースで推定する。
function residentForecast(f, pace, endDate) {
    const r=predictResidents(f,endDate,today);
    return r.reason==='データなし'?estimateResidents(f,pace,endDate,today):{...r, estimated:false};
}
// 1人あたりネット数：ネット推移予測と同じ指標（validPoints＝集荷日単位の確定済み返却準備ネット数合計）の、
// 同じ月の最後の確定集荷日の値 ÷ 月末入居者数。0・未入力の月、確定集荷日のない月は算出しない。
function netsPerResident(f) {
    const r=residents[String(f.id)], nets=validPoints(f);
    return (r?r.months:[]).map(m=>{
        const count=Number.isInteger(r.counts[m])?r.counts[m]:null;
        const inMonth=nets.filter(p=>p.date.slice(0,7)===m.slice(0,7) && p.date<=m);
        const net=inMonth.length?inMonth[inMonth.length-1]:null;
        return {month:m, count, net, ratio:count>0 && net?Math.round(net.nets/count*100)/100:null};
    });
}
function renderResidents() {
    if(!facilities.length) {residentContent.innerHTML='';return;}
    const all=select.value==='all';
    const selected=all?facilities:facilities.filter(f=>String(f.id)===select.value);
    const horizon=Number(forecastDaysSelect.value), end=dateAt(time(today)+horizon*DAY);
    const pace=residentPace();
    const results=selected.map(f=>({f,...residentForecast(f,pace,end)}));
    const fmt=n=>Number(n).toFixed(2);
    const pct=n=>(n*100).toFixed(2)+'%';
    const method=r=>r.reason?'—':r.estimated?'他施設ペース（推定）':'実績';
    const paceText=pace?`基準ペース ${pct(pace.rate)}/日（実績のある施設の「傾き÷居室数」の平均：${pace.sources.map(p=>`${p.name} ${pct(p.rate)}`).join('、')}）`:'基準ペース：算出不可（入居者数の実績がある施設がありません）';
    const intro=`<h2>入居者数推移予測</h2><p class="note">洗濯ネット推移予測とは別に、月末入居者数から計算します（1人で複数ネットを使う方がいるため、ネット数と入居者数は換算しません）。実績のある施設は、受託開始日を0人の起点とし、0以外で入力された月末の実績と合わせて最小二乗法で1日あたりの増加人数を求め、最新実績から延長します。0・未入力の月は計算に使いません。入居者数が未入力の施設は、実績のある施設それぞれの「傾き（人/日）÷居室数」の平均（基準ペース）を使い、居室数×基準ペース×受託開始日からの日数で推定します（受託開始日までは0人）。実績のある施設が1つもない場合は、全施設をデータなしとします。上限はいずれも居室数×0.9です。グラフは上の推移予測に、ネット数と同じ軸で重ねて表示しています。</p>`;
    const forecastTable=r=>`<details><summary>予測値を確認（${r.points.length}件）</summary><table><thead><tr><th>日付</th><th>予測入居者数</th></tr></thead><tbody>${r.points.map(p=>`<tr><td>${escapeHtml(p.date)}</td><td>${p.residents}</td></tr>`).join('')}</tbody></table></details>`;
    if(all) {
        const rows=results.map(r=>{
            const endPoint=r.points.length?r.points[r.points.length-1]:null;
            return `<tr><td>${escapeHtml(r.f.name)}</td><td>${method(r)}</td><td>${r.actual.length}</td><td>${r.last?`${escapeHtml(r.last.date)}：${r.last.residents}人`:'—'}</td><td>${r.reason?'—':fmt(r.slope)}</td><td>${r.f.roomCount>0?fmt(r.f.roomCount*0.9):'未設定'}</td><td>${r.reason?escapeHtml(r.reason):endPoint?endPoint.residents+'人':'—'}</td></tr>`;
        }).join('');
        const usable=results.filter(r=>!r.reason && r.points.length);
        const measured=usable.filter(r=>!r.estimated).length, estimated=usable.length-measured;
        const total=Math.round(usable.reduce((s,r)=>s+r.points[r.points.length-1].residents,0)*10)/10;
        residentContent.innerHTML=`${intro}
          <p>期間末（${escapeHtml(end)}）の予測合計：<strong>${usable.length?total+'人':'データなし'}</strong>${usable.length?`（実績${measured}施設＋推定${estimated}施設の合計${results.length-usable.length?`。データなし等の${results.length-usable.length}施設は含みません`:''}）`:''}</p>
          <p class="note">${escapeHtml(paceText)}。推定施設の予測人数＝居室数×基準ペース×受託開始日からの日数（受託開始日までは0人、上限は居室数×0.9）。推定施設の「傾き」は居室数×基準ペースです。</p>
          <div class="chart-wrap"><table><thead><tr><th>施設</th><th>予測方法</th><th>予測に使う月数</th><th>最新実績</th><th>傾き（人/日）</th><th>90%上限</th><th>期間末予測</th></tr></thead><tbody>${rows}</tbody></table></div>
          <p class="note">グラフには、実績${measured}施設＋推定${estimated}施設の合計をネット数と同じ軸で表示しています。施設を選ぶと、施設別の内訳と1人あたりネット数を表示します。</p>`;
        return;
    }
    const r=results[0], f=r.f;
    const perResident=netsPerResident(f);
    const perRows=perResident.map(p=>`<tr><td>${escapeHtml(p.month)}</td><td>${p.count===null?'未入力':p.count+'人'}</td><td>${p.count>0?'○':`<span class="excluded">除外（${p.count===null?'未入力':'0人'}）</span>`}</td><td>${p.net?`${escapeHtml(p.net.date)}：${p.net.nets}`:'—'}</td><td>${p.ratio!==null?fmt(p.ratio):'—'}</td></tr>`).join('');
    const perTable=perResident.length?`<h3>月別実績と入居者1人あたりのネット数（参考値）</h3>
      <div class="chart-wrap"><table><thead><tr><th>月末日</th><th>入居者数</th><th>予測に使用</th><th>同月の最終確定集荷日：ネット数</th><th>1人あたりネット数</th></tr></thead><tbody>${perRows}</tbody></table></div>
      <p class="note">ネット数は洗濯ネット推移予測と同じ指標（集荷日ごとの返却準備ネット数の合計。未入力を含む日・到着日未確定の日は除外）で、その月の最後の確定集荷日の1回量です。1人あたりネット数は参考値で、予測には使いません。</p>`:'<p class="note">受託開始前のため、入力対象の月はまだありません。</p>';
    if(r.reason) {
        residentContent.innerHTML=`${intro}<h3>${escapeHtml(f.name)}</h3><div class="empty">${escapeHtml(r.reason)}</div>${perTable}`;
        return;
    }
    const endPoint=r.points.length?r.points[r.points.length-1]:null;
    const summary=r.estimated
        ?`予測方法：<strong>他施設ペース（推定）</strong> ／ 受託開始日：${escapeHtml(f.startDate)}（この日から増加） ／ 基準ペース ${pct(pace.rate)}/日 × 居室数${f.roomCount}室 ＝ <strong>${fmt(r.slope)}人/日</strong> ／ 90%上限：${Math.round(r.cap*10)/10}人 ／ 期間末（${escapeHtml(end)}）の予測：<strong>${endPoint?endPoint.residents+'人':'—'}</strong>`
        :`予測方法：<strong>実績</strong> ／ 起点：${escapeHtml(f.startDate)}＝0人 ／ 予測に使う実績：${r.actual.length}か月 ／ 傾き：<strong>${fmt(r.slope)}人/日</strong>（約${(r.slope*30).toFixed(1)}人/30日） ／ 90%上限：${r.cap>0?Math.round(r.cap*10)/10+'人':'未設定'} ／ 期間末（${escapeHtml(end)}）の予測：<strong>${endPoint?endPoint.residents+'人':'—'}</strong>`;
    residentContent.innerHTML=`${intro}<h3>${escapeHtml(f.name)}</h3>
      <p>${summary}</p>
      ${r.estimated?`<p class="note">入居者数の実績が未入力のため、${escapeHtml(paceText)}から推定しています。月末入居者数を入力すると、この施設自身の実績による予測に切り替わります。</p>`:''}
      ${r.warnings.length?`<p class="note" role="status">${r.warnings.map(escapeHtml).join('<br>')}</p>`:''}
      ${forecastTable(r)}
      ${perTable}`;
}
select.addEventListener('change',renderResidents);
forecastDaysSelect.addEventListener('change',renderResidents);
renderResidents();

</script>
</body>
</html>
