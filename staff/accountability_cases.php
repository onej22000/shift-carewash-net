<?php
require_once __DIR__ . '/config.php';

$active_nav = 'accountability_cases';

// 政治家・議会をめぐる問題事案の記録。
// 「確認された事実」（第三者委員会の認定、本人の会見・証言、議会での議決など出典を伴う出来事）と
// 「未確定の主張」（一方の当事者のみが主張しており、他方が否定している、または係争中の内容）を
// 明確に分けて掲載する。DBを使わず、更新頻度が低い性質上ここでは配列に直接記述している。
$cases = [
    [
        'title' => '横浜市長パワハラ問題',
        'subject' => '山中竹春（横浜市長）',
        'status_label' => 'パワハラ認定・続投表明',
        'status_color' => 'background:#fee2e2;color:#991b1b;',
        'facts' => [
            ['date' => '2026-07-30', 'text' => '外部弁護士らによる第三者調査委員会が調査報告書を提出。市長の言動のうち複数項目をパワーハラスメントと認定した。認定された内容には、職員を「ポンコツ」「人間のクズ」等と呼ぶ発言、銃を撃つ仕草を伴う言動、書類を投げつける行為、深夜・休日を問わない私用メール対応の要求などが含まれる。'],
            ['date' => '2026-08-13〜14', 'text' => '横浜市会総務委員会が2日間にわたり開催され、山中市長への質疑が行われた。'],
            ['date' => '2026-08-14', 'text' => '日本維新の会・無所属系会派が、市長への不信任決議案を提出する方針を決定した。'],
            ['date' => '2026-08頃', 'text' => '山中市長は報告書の内容を受け入れ、告発した幹部職員に謝罪する一方、辞職はせず続投する意向を表明した。'],
        ],
        'current_status' => '不信任決議案の提出に向けた手続きが進められている。自民党市議団内でも辞職を求める議員と慎重な議員に意見が分かれており、今後の議会の対応が焦点となっている。',
        'claims' => [],
        'sources' => [
            ['label' => '日本経済新聞「パワハラ認定の山中竹春・横浜市長、議会で謝罪」', 'url' => 'https://www.nikkei.com/article/DGXZQOCC1311V0T10C26A8000000/', 'date' => '2026年8月'],
            ['label' => '日本維新の会横浜市会議員団「横浜市長に対する不信任決議案を提出することを決定しました」', 'url' => 'https://yokohama-ishin.com/news/1167/', 'date' => '2026年8月14日'],
            ['label' => '選挙ドットコム「【8/14夜時点】横浜市長パワハラ問題、今後どうなる？」', 'url' => 'https://go2senkyo.com/seijika/168298/posts/1470086', 'date' => '2026年8月15日'],
            ['label' => '選挙ドットコム「横浜市長のパワハラ認定の影響を地元紙記者が徹底解説」', 'url' => 'https://go2senkyo.com/articles/2026/08/14/141003.html', 'date' => '2026年8月14日'],
        ],
    ],
    [
        'title' => '福岡県議会 金銭授受をめぐる証言',
        'subject' => '福岡県議会（自民党県議団関係）',
        'status_label' => '証言対立・議長辞意表明',
        'status_color' => 'background:#fef3c7;color:#92400e;',
        'facts' => [
            ['date' => '2020年頃', 'text' => '吉松源昭県議が、議長就任に際して当時の自民党県議団幹部（蔵内勇夫氏）に対し、ゴルフ代（根回し名目）1000万円、料亭での飲食代49万円、「お車代」などを含め、計2000万円超を支払ったと証言している。'],
            ['date' => '2026年7月', 'text' => '江藤秀之県議（副議長就任）も、中尾正幸県議に500万円を渡したと記者会見で証言した。証言者は複数名に及んでいる。'],
            ['date' => '2026年7月', 'text' => '吉松県議は、当時のやり取りを録音した音声データが存在すると説明し、声紋分析の結果を公表するとしている。捜査当局への協力にも応じていると述べた。'],
            ['date' => '2026年8月', 'text' => '蔵内勇夫議長が辞意を表明。これを受け、準備が進められていた議長への辞職勧告決議案は採決が見送られた。'],
        ],
        'current_status' => '音声データの分析や警察の捜査協力が伝えられており、事実関係の確認が続いている。議長本人の辞意表明により、県議会側の対応も変化している段階にある。',
        'claims' => [
            '吉松県議・江藤県議側は「金銭を要求され、支払った」と主張しているのに対し、金銭の受領を指摘された中尾正幸県議は受領を否定し、「吉松県議側から金銭の話が出た」と正反対の説明をしている。双方の主張が対立しており、現時点で事実関係は確定していない。',
        ],
        'sources' => [
            ['label' => '日本経済新聞「福岡県議会の金銭授受疑惑『500万円手渡した』元副議長も証言」', 'url' => 'https://www.nikkei.com/article/DGXZQOJC274H00X20C26A7000000/', 'date' => '2026年7月'],
            ['label' => '西日本新聞「福岡県議2人が正副議長就任前、『自民幹部へ2750万円支払い』証言」', 'url' => 'https://www.nishinippon.co.jp/item/1511831/', 'date' => '2026年'],
            ['label' => 'TNCニュース「『証言』の吉松県議『警察に捜査協力』明らかに」', 'url' => 'https://news.tnc.co.jp/news/articles/NID2026070731120', 'date' => '2026年7月'],
            ['label' => '日本経済新聞「福岡県議会の蔵内勇夫議長が辞意、辞職勧告案は採決中止」', 'url' => 'https://www.nikkei.com/article/DGXZQOJC1770R0X10C26A8000000/', 'date' => '2026年8月'],
            ['label' => '西日本新聞「福岡県議会、蔵内議長の辞職勧告決議案の採決せず 自民県議の緊急動議を可決」', 'url' => 'https://www.nishinippon.co.jp/item/1527656/', 'date' => '2026年8月'],
        ],
    ],
    [
        'title' => '兵庫県知事をめぐる問題',
        'subject' => '斎藤元彦（兵庫県知事）',
        'status_label' => 'パワハラ認定・起債許可団体へ移行',
        'status_color' => 'background:#fee2e2;color:#991b1b;',
        'facts' => [
            ['date' => '2024-03', 'text' => '当時の西播磨県民局長が、知事に関するパワーハラスメント疑惑等を記した告発文書を作成・配布。兵庫県は当該局長を懲戒処分とした。'],
            ['date' => '2024-07-07', 'text' => '告発を行った元局長が死亡した。'],
            ['date' => '2025-03', 'text' => '兵庫県が設置した第三者調査委員会が報告書を提出。指摘された16件の行為のうち10件についてパワーハラスメントに当たると認定した。あわせて、告発文書は公益通報者保護法上の公益通報に該当すると判断し、文書の「作成者探し」は同法違反に当たると指摘した。'],
            ['date' => '2025-03', 'text' => '「おねだり」（贈答品受領等）疑惑については、第三者委員会は贈収賄には当たらず、その多くは社会儀礼の範囲を明らかに超えるものとまでは言えないと判断した。'],
            ['date' => '2026年時点', 'text' => '斎藤知事は第三者委員会の結論を受け入れておらず、「県としては、適正、適法、適切に行ってきたという認識に変わりはない」との説明を継続している。'],
            ['date' => '2026-08-18', 'text' => '兵庫県は、地方債の発行に国の許可が必要となる「起債許可団体」へ移行した。2025年度決算に基づく実質公債費比率は単年度21.7％、3か年平均19.0％となる見込みで、許可基準の18％を超えた。県が起債許可団体となるのは約14年ぶり。起債には公債費負担適正化計画の策定と国の許可が必要になり、投資事業の抑制が課題となる。'],
            ['date' => '財政悪化の背景', 'text' => '県は、阪神・淡路大震災の復興債、県債管理基金の積立不足、過去から続く高水準の公共投資、分収造林・地域整備事業の処理、長期金利上昇を主な要因として説明している。県の財政見通しでは、2026〜2028年度の収支不足は合計530億円に上り、実質公債費比率の3か年平均は今後さらに上昇する見込み。'],
        ],
        'current_status' => '告発文書の「作成者探し」の適法性に加え、財政運営も知事の説明責任を問う論点となっている。起債許可団体への移行は財政破綻や早期健全化団体への移行を意味するものではないが、新たな県債発行には国の許可が必要となる。斎藤知事は、震災復興債や過去からの課題、金利上昇など複数の要因を挙げ、分収造林事業等の処理は将来負担を抑えるために必要だったと説明している。告発文書問題が財政悪化を直接引き起こしたとする根拠は確認されておらず、両者は分けて評価する必要がある。',
        'claims' => [
            '「おねだり」疑惑をめぐっては、第三者委員会が贈収賄に該当しないと判断した一方で、県の対応や知事の説明を疑問視する見方も報道では伝えられている。贈収賄に当たらないという判断と、道義的な適否は別の論点であり、社会的な評価は分かれている。',
        ],
        'sources' => [
            ['label' => '神戸新聞NEXT「斎藤知事の告発者捜し『違法』 パワハラも10項目認定 第三者委が報告書」', 'url' => 'https://www.kobe-np.co.jp/news/society/202503/0018770194.shtml', 'date' => '2025年3月'],
            ['label' => '関西テレビ「斎藤知事いまだ変えぬ『告発文書対応は適正』認識」', 'url' => 'https://www.ktv.jp/news/feature/260707-saito/', 'date' => '2026年7月7日'],
            ['label' => '企業法務ナビ「第三者委員会、兵庫県の公益通報者保護法違反と知事のパワハラを認定」', 'url' => 'https://www.corporate-legal.jp/news/6045', 'date' => '2025年'],
            ['label' => '時事ドットコム「斎藤元彦兵庫県知事 関連ニュース まとめ」', 'url' => 'https://www.jiji.com/jc/v7?id=harassment_hyogo', 'date' => '随時更新'],
            ['label' => '兵庫県「令和8年度当初予算（案）にかかる知事記者会見」', 'url' => 'https://web.pref.hyogo.lg.jp/governor/g_kaiken20260212.html', 'date' => '2026年2月12日'],
            ['label' => '兵庫県「令和8年度 兵庫県IR資料」', 'url' => 'https://web.pref.hyogo.lg.jp/kk21/documents/irr080608.pdf', 'date' => '2026年6月'],
            ['label' => '神戸新聞NEXT「兵庫県、長期金利上昇で収支不足が大幅悪化見通し」', 'url' => 'https://kobe-np.co.jp/news/society/202602/0020012578.shtml', 'date' => '2026年2月12日'],
            ['label' => 'サンテレビNEWS「兵庫県が過去に違法な起債と不適切処理」', 'url' => 'https://www.sun-tv.co.jp/suntvnews/news/2026/07/14/95616/', 'date' => '2026年7月14日'],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="政治家・議会をめぐる問題事案を、確認された事実と未確定の主張を区別して出典付きで記録。POLITICAは事実だけを扱います。">
<meta property="og:type" content="website">
<meta property="og:title" content="政治家・議会の問題事案 | POLITICA（ポリティカ）">
<meta property="og:description" content="政治家・議会をめぐる問題事案を、確認された事実と未確定の主張を区別して出典付きで記録。">
<meta property="og:image" content="https://seiji-data.site/ogp.png">
<meta property="og:url" content="https://seiji-data.site/accountability_cases.php">
<meta name="twitter:card" content="summary_large_image">
<title>政治家・議会の問題事案 | POLITICA（ポリティカ）</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Noto Sans JP',sans-serif; background:#f5f7fb; color:#1a1a2e; line-height:1.7; }
main { max-width:920px; margin:0 auto; padding:32px 16px 80px; }
footer { text-align:center; padding:32px; color:#94a3b8; font-size:12px; }
.page-title { font-size:22px; font-weight:700; margin-bottom:8px; }
.page-lead { font-size:13px; color:#64748b; margin-bottom:20px; }
.neutral-notice {
  background:#fffbeb; border:1px solid #fde68a; border-radius:10px;
  padding:14px 18px; font-size:13px; color:#78350f; margin-bottom:16px; line-height:1.8;
}
.neutral-notice strong { color:#92400e; }
.method-notice {
  background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px;
  padding:14px 18px; font-size:13px; color:#1e3a8a; margin-bottom:32px; line-height:1.9;
}
.method-notice strong { color:#1e40af; }
.method-notice dl { margin-top:8px; }
.method-notice dt { font-weight:700; display:inline; }
.method-notice dd { display:inline; margin:0 0 0 4px; }
.method-notice .method-row { margin-top:6px; }

.case-grid { display:grid; grid-template-columns:1fr; gap:24px; }
.case-card {
  background:#fff; border-radius:16px; overflow:hidden;
  box-shadow:0 2px 12px rgba(0,0,0,0.06);
}
.case-head { background:#0a2540; padding:20px 24px; }
.status-pill { display:inline-block; font-size:11px; font-weight:800; padding:3px 10px; border-radius:20px; margin-bottom:10px; }
.case-title { font-size:18px; font-weight:700; color:#fff; margin-bottom:4px; }
.case-sub { font-size:13px; color:#cbd5e1; }

.case-body { padding:22px 24px 26px; }
.case-section { margin-bottom:20px; }
.case-section:last-child { margin-bottom:0; }
.section-heading {
  font-size:12px; font-weight:800; letter-spacing:0.5px;
  display:flex; align-items:center; gap:6px; margin-bottom:10px;
}
.section-heading.fact { color:#166534; }
.section-heading.status { color:#0369a1; }
.section-heading.claim { color:#92400e; }
.section-heading.source { color:#475569; }
.section-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
.section-heading.fact .section-dot { background:#22c55e; }
.section-heading.status .section-dot { background:#0ea5e9; }
.section-heading.claim .section-dot { background:#f59e0b; }
.section-heading.source .section-dot { background:#94a3b8; }

.fact-list { list-style:none; }
.fact-list li { display:flex; gap:12px; padding:8px 0; border-bottom:1px solid #f1f5f9; font-size:14px; }
.fact-list li:last-child { border-bottom:none; }
.fact-date { flex:0 0 auto; font-size:12px; font-weight:700; color:#166534; background:#f0fdf4; padding:2px 8px; border-radius:6px; height:fit-content; white-space:nowrap; }
.fact-text { flex:1; }

.status-text { font-size:14px; background:#f0f9ff; border-radius:10px; padding:12px 14px; }

.claim-list { list-style:none; }
.claim-list li { font-size:13.5px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px 14px; color:#78350f; }
.claim-list li + li { margin-top:8px; }

.source-list { list-style:none; font-size:12.5px; color:#64748b; }
.source-list li { padding:4px 0; }
.source-list a { color:#0a63c9; text-decoration:none; }
.source-list a:hover { text-decoration:underline; }
.source-date { color:#94a3b8; }

@media (min-width:760px) {
  .case-grid { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<?php require __DIR__ . '/includes/header.php'; ?>
<main>
<h1 class="page-title">政治家・議会の問題事案</h1>
<p class="page-lead">パワーハラスメント認定・金銭をめぐる証言など、政治家・議会関係者をめぐる問題事案を出典付きで記録しています。</p>

<div class="neutral-notice">
<strong>【中立性に関する注記】</strong>
本ページは特定の政党・政治家を応援または批判する意図を持ちません。掲載内容は各出典元の報道・公的発表に基づくものであり、POLITICA独自の評価や断定は含みません。
</div>

<div class="method-notice">
<strong>【本ページの分類基準】</strong> POLITICAでは「事実だけが、政治を語る。」という方針のもと、各事案を次の3つに区別して記載しています。
<div class="method-row"><dt>確認された事実：</dt><dd>第三者委員会の認定、本人の会見・証言、議会での議決など、出典を伴って裏付けられた出来事。</dd></div>
<div class="method-row"><dt>現在の状況：</dt><dd>本稿執筆時点で進行中の手続きや、今後の見通しに関する報道内容。</dd></div>
<div class="method-row"><dt>未確定の主張：</dt><dd>当事者の一方のみが述べており、他方が否定している、または裁判・捜査等で係争中のため、事実として確定していない内容。「疑惑」「主張」という言葉を用い、確認された事実とは明確に分けています。</dd></div>
</div>

<div class="case-grid">
<?php foreach ($cases as $case): ?>
<article class="case-card">
  <div class="case-head">
    <span class="status-pill" style="<?= $case['status_color'] ?>"><?= htmlspecialchars($case['status_label']) ?></span>
    <div class="case-title"><?= htmlspecialchars($case['title']) ?></div>
    <div class="case-sub">対象：<?= htmlspecialchars($case['subject']) ?></div>
  </div>
  <div class="case-body">

    <div class="case-section">
      <div class="section-heading fact"><span class="section-dot"></span>確認された事実</div>
      <ul class="fact-list">
        <?php foreach ($case['facts'] as $f): ?>
        <li><span class="fact-date"><?= htmlspecialchars($f['date']) ?></span><span class="fact-text"><?= htmlspecialchars($f['text']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="case-section">
      <div class="section-heading status"><span class="section-dot"></span>現在の状況</div>
      <p class="status-text"><?= htmlspecialchars($case['current_status']) ?></p>
    </div>

    <?php if (!empty($case['claims'])): ?>
    <div class="case-section">
      <div class="section-heading claim"><span class="section-dot"></span>未確定の主張</div>
      <ul class="claim-list">
        <?php foreach ($case['claims'] as $c): ?>
        <li><?= htmlspecialchars($c) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="case-section">
      <div class="section-heading source"><span class="section-dot"></span>出典</div>
      <ul class="source-list">
        <?php foreach ($case['sources'] as $s): ?>
        <li><a href="<?= htmlspecialchars($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($s['label']) ?></a> <span class="source-date">（<?= htmlspecialchars($s['date']) ?>）</span></li>
        <?php endforeach; ?>
      </ul>
    </div>

  </div>
</article>
<?php endforeach; ?>
</div>

</main>
<footer>本サイトは公開情報を出典付きで整理したものです。記載内容の正確性には万全を期していますが、最新情報は各出典元をご確認ください。事実と異なる内容にお気づきの際はお問い合わせフォームまでご連絡ください。</footer>
</body>
</html>
