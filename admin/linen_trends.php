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

$facilities = $pdo->query(
    "SELECT id, name, onboarding_start_date
     FROM facilities
     WHERE onboarding_start_date IS NOT NULL
       AND is_active = 1
     ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$rows = $pdo->query(
    "SELECT
         f.id AS facility_id,
         f.name AS facility_name,
         f.onboarding_start_date,
         cc.arrival_date,
         DATEDIFF(cc.arrival_date, f.onboarding_start_date) AS elapsed_days,
         SUM(cc.arrival_bag_count) AS arrival_bag_count,
         SUM(cc.return_ready_laundry_net_count) AS laundry_net_count
     FROM facilities AS f
     INNER JOIN collection_cycles AS cc ON cc.facility_id = f.id
     WHERE f.onboarding_start_date IS NOT NULL
       AND f.is_active = 1
       AND cc.deleted_at IS NULL
       AND cc.arrival_date IS NOT NULL
       AND cc.arrival_date >= f.onboarding_start_date
       AND (
           cc.arrival_bag_count IS NOT NULL
           OR cc.return_ready_laundry_net_count IS NOT NULL
       )
     GROUP BY f.id, f.name, f.onboarding_start_date, cc.arrival_date
     ORDER BY f.name, cc.arrival_date"
)->fetchAll(PDO::FETCH_ASSOC);

$payload = [];
foreach ($facilities as $facility) {
    $id = (string) $facility['id'];
    $payload[$id] = [
        'id' => (int) $facility['id'],
        'name' => (string) $facility['name'],
        'startDate' => (string) $facility['onboarding_start_date'],
        'points' => [],
    ];
}
foreach ($rows as $row) {
    $id = (string) $row['facility_id'];
    if (!isset($payload[$id])) {
        continue;
    }
    $payload[$id]['points'][] = [
        'date' => (string) $row['arrival_date'],
        'days' => (int) $row['elapsed_days'],
        'bags' => $row['arrival_bag_count'] !== null ? (int) $row['arrival_bag_count'] : null,
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
    <title>リネン袋・洗濯ネット推移</title>
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
        .legend { display:flex; gap:20px; justify-content:center; margin:8px 0 0; font-size:.9rem; }
        .swatch { display:inline-block; width:18px; height:3px; vertical-align:middle; margin-right:6px; }
        table { width:100%; border-collapse:collapse; margin-top:18px; }
        th,td { padding:9px 10px; border-bottom:1px solid #e6ecef; text-align:right; }
        th { background:#eaf3f8; color:var(--navy); }
        th:first-child,td:first-child { text-align:left; }
        .empty { padding:42px 10px; color:var(--muted); text-align:center; }
        .note { color:var(--muted); font-size:.85rem; margin:12px 0 0; }
        @media (max-width:600px) { main { padding:12px; } .updated { width:100%; margin-left:0; } }
    </style>
</head>
<body>
<header>
    <h1>リネン袋・洗濯ネット推移</h1>
    <nav>ログイン中: <?= htmlspecialchars((string) $employee['name'], ENT_QUOTES, 'UTF-8') ?>さん | <a href="<?= htmlspecialchars($dashboardPath, ENT_QUOTES, 'UTF-8') ?>">ダッシュボード</a> | <a href="<?= htmlspecialchars($logoutPath, ENT_QUOTES, 'UTF-8') ?>">ログアウト</a></nav>
</header>
<main>
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
            </select>
        </div>
        <div class="updated">表示時点: <?= htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') ?></div>
    </section>
    <section class="card" id="content"></section>
</main>
<script>
const facilities = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const select = document.getElementById('facility');
const forecastDaysSelect = document.getElementById('forecastDays');
const content = document.getElementById('content');
const escapeHtml = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

const allOption = document.createElement('option');
allOption.value = 'all';
allOption.textContent = '全施設合計';
select.appendChild(allOption);
for (const facility of facilities) {
    const option = document.createElement('option');
    option.value = facility.id;
    option.textContent = facility.name;
    select.appendChild(option);
}

function linePath(points, key, sx, sy) {
    let path = '';
    let active = false;
    for (const point of points) {
        if (point[key] === null) { active = false; continue; }
        path += `${active ? ' L' : ' M'} ${sx(point.days).toFixed(1)} ${sy(point[key]).toFixed(1)}`;
        active = true;
    }
    return path;
}

function aggregateAll() {
    const byDay = new Map();
    for (const facility of facilities) {
        for (const point of facility.points) {
            const current = byDay.get(point.days) || {date:'',days:point.days,bags:null,nets:null};
            if (point.bags !== null) current.bags = (current.bags ?? 0) + point.bags;
            if (point.nets !== null) current.nets = (current.nets ?? 0) + point.nets;
            byDay.set(point.days, current);
        }
    }
    return {id:'all',name:'全施設合計',startDate:'施設ごとの受託開始日を基準',points:[...byDay.values()].sort((a,b)=>a.days-b.days)};
}

function forecast(points, key, horizon) {
    const valid=points.filter(p=>p[key]!==null);
    if(valid.length<2) return null;
    const n=valid.length;
    const sumX=valid.reduce((s,p)=>s+p.days,0), sumY=valid.reduce((s,p)=>s+p[key],0);
    const sumXY=valid.reduce((s,p)=>s+p.days*p[key],0), sumXX=valid.reduce((s,p)=>s+p.days*p.days,0);
    const denominator=n*sumXX-sumX*sumX;
    if(denominator===0) return null;
    const slope=Math.max(0,(n*sumXY-sumX*sumY)/denominator);
    const last=valid[valid.length-1];
    const targetDays=Math.max(...points.map(p=>p.days))+horizon;
    return {start:last, end:{days:targetDays,[key]:Math.max(0,last[key]+slope*(targetDays-last.days))}, slope};
}

function chartSvg(points, horizon) {
    const width=980, height=440, left=70, right=25, top=24, bottom=62;
    const normalized=points;
    const forecasts={bags:forecast(normalized,'bags',horizon),nets:forecast(normalized,'nets',horizon)};
    const maxX=Math.max(1,...normalized.map(p=>p.days),...Object.values(forecasts).filter(Boolean).map(f=>f.end.days));
    const values=normalized.flatMap(p=>[p.bags,p.nets]).filter(v=>v!==null);
    for(const f of Object.values(forecasts)){ if(f) values.push(f.end.bags??f.end.nets); }
    const maxY=Math.max(1,...values);
    const ceiling=Math.max(1,Math.ceil(maxY/2)*2);
    const sx=x=>left+(x/maxX)*(width-left-right);
    const sy=y=>top+(1-y/ceiling)*(height-top-bottom);
    let grid='';
    for(let i=0;i<=4;i++){
        const y=top+i*(height-top-bottom)/4;
        const value=Math.round((ceiling*(4-i)/4)*10)/10;
        grid+=`<line x1="${left}" y1="${y}" x2="${width-right}" y2="${y}" stroke="#d9e2e8" stroke-dasharray="4 4"/><text x="${left-12}" y="${y+4}" text-anchor="end" fill="#667085" font-size="12">${value}</text>`;
    }
    const ticks=[0,.25,.5,.75,1].map(v=>Math.round(maxX*v)).filter((v,i,a)=>a.indexOf(v)===i);
    let xLabels='';
    for(const tick of ticks){ const x=sx(tick); xLabels+=`<line x1="${x}" y1="${height-bottom}" x2="${x}" y2="${height-bottom+5}" stroke="#8796a5"/><text x="${x}" y="${height-bottom+23}" text-anchor="middle" fill="#667085" font-size="12">${tick}</text>`; }
    const dots=(key,color)=>normalized.filter(p=>p[key]!==null).map(p=>`<circle cx="${sx(p.days)}" cy="${sy(p[key])}" r="4" fill="${color}"><title>${p.date||p.days+'日目'}: ${p[key]}</title></circle>`).join('');
    const forecastLine=(key,color)=>{const f=forecasts[key];return f?`<path d="M ${sx(f.start.days)} ${sy(f.start[key])} L ${sx(f.end.days)} ${sy(f.end[key])}" fill="none" stroke="${color}" stroke-width="3" stroke-dasharray="9 7"/><circle cx="${sx(f.end.days)}" cy="${sy(f.end[key])}" r="5" fill="#fff" stroke="${color}" stroke-width="3"><title>${f.end.days}日目予想: ${f.end[key].toFixed(1)}</title></circle>`:''};
    return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="到着リネン袋数と洗濯ネット数の推移グラフ">
      ${grid}${xLabels}
      <line x1="${left}" y1="${top}" x2="${left}" y2="${height-bottom}" stroke="#8796a5"/>
      <line x1="${left}" y1="${height-bottom}" x2="${width-right}" y2="${height-bottom}" stroke="#8796a5"/>
      <path d="${linePath(normalized,'bags',sx,sy)}" fill="none" stroke="#247ba0" stroke-width="3"/>
      <path d="${linePath(normalized,'nets',sx,sy)}" fill="none" stroke="#f28e2b" stroke-width="3"/>
      ${dots('bags','#247ba0')}${dots('nets','#f28e2b')}
      ${forecastLine('bags','#247ba0')}${forecastLine('nets','#f28e2b')}
      <text x="${(left+width-right)/2}" y="${height-12}" text-anchor="middle" fill="#495867" font-size="13">受託開始からの経過日数（日）</text>
      <text x="18" y="${(top+height-bottom)/2}" text-anchor="middle" fill="#495867" font-size="13" transform="rotate(-90 18 ${(top+height-bottom)/2})">数量</text>
    </svg>`;
}

function render() {
    const facility=select.value==='all' ? aggregateAll() : (facilities.find(f=>String(f.id)===select.value) || facilities[0]);
    if(!facility){ content.innerHTML='<div class="empty">受託開始日が登録された施設はありません。</div>'; return; }
    if(!facility.points.length){ content.innerHTML=`<h2>${escapeHtml(facility.name)}</h2><div class="empty">到着実績はまだありません。</div>`; return; }
    const totalBags=facility.points.reduce((sum,p)=>sum+(p.bags??0),0);
    const registeredNets=facility.points.filter(p=>p.nets!==null);
    const totalNets=registeredNets.reduce((sum,p)=>sum+p.nets,0);
    const horizon=Number(forecastDaysSelect.value);
    const rows=facility.points.map(p=>`<tr><td>${escapeHtml(p.date||'—')}</td><td>${p.days}</td><td>${p.bags??'—'}</td><td>${p.nets??'—'}</td></tr>`).join('');
    content.innerHTML=`
      <h2>${escapeHtml(facility.name)}</h2>
      <div class="meta"><div><span>受託開始日</span><strong>${escapeHtml(facility.startDate)}</strong></div><div><span>記録日数</span><strong>${facility.points.length}日</strong></div><div><span>到着リネン袋 合計</span><strong>${totalBags}</strong></div><div><span>洗濯ネット 合計</span><strong>${registeredNets.length?totalNets:'—'}</strong></div></div>
      <div class="chart-wrap">${chartSvg(facility.points,horizon)}</div>
      <div class="legend"><span><i class="swatch" style="background:#247ba0"></i>到着リネン袋数</span><span><i class="swatch" style="background:#f28e2b"></i>洗濯ネット数</span><span>破線＝増加予想</span></div>
      <table><thead><tr><th>到着日</th><th>経過日数</th><th>到着リネン袋数</th><th>洗濯ネット数</th></tr></thead><tbody>${rows}</tbody></table>
      <p class="note">同日の複数記録は合計しています。未登録値は「—」で表示します。予想は過去実績の直線傾向を使い、減少傾向は横ばいとして試算した参考値です。実績が少ない場合は精度が低くなります。ページを開くたびに最新のデータベースから再集計されます。</p>`;
}

select.addEventListener('change', render);
forecastDaysSelect.addEventListener('change', render);
render();
</script>
</body>
</html>
