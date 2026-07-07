<?php
$pageTitle = "中国共产党建党教育专题";
$year = date("Y");

$timeline = [
    ["1921年", "中国共产党成立，中国革命面貌焕然一新。"],
    ["1927年", "革命道路在艰难探索中不断发展。"],
    ["1934年-1936年", "长征展现了坚定信念、艰苦奋斗和革命理想。"],
    ["1949年", "中华人民共和国成立，中华民族发展进入新的历史阶段。"],
    ["1978年", "改革开放开启社会主义现代化建设新时期。"],
    ["新时代", "继续推进国家发展、民族复兴和社会建设。"]
];

$spirits = [
    ["伟大建党精神", "坚持真理、坚守理想，践行初心、担当使命。"],
    ["长征精神", "坚定理想信念，不怕艰难险阻，依靠人民、团结奋进。"],
    ["延安精神", "实事求是、自力更生、艰苦奋斗、服务人民。"],
    ["改革开放精神", "解放思想、勇于创新、开放包容、敢闯敢试。"]
];

$questions = [
    ["中国共产党成立于哪一年？", "1921年。"],
    ["建党教育主要学习什么？", "学习党史、初心使命、奋斗历程和红色精神。"],
    ["为什么要开展党史学习教育？", "为了增强历史认知、责任意识和理想信念。"]
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="/favicon.svg?v=20260626" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.ico?v=20260626">
<title><?php echo $pageTitle; ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:"Microsoft YaHei",Arial,sans-serif;
    background:#f8f1e6;
    color:#3b1d1d;
    line-height:1.8;
}
a{text-decoration:none}
.hero{
    min-height:420px;
    background:
    radial-gradient(circle at top left,rgba(255,215,100,.45),transparent 35%),
    linear-gradient(135deg,#7b0000,#c40000 55%,#e6a900);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:50px 20px;
}
.hero h1{font-size:48px;margin-bottom:18px;letter-spacing:2px}
.hero p{font-size:20px;max-width:760px;margin:auto}
.hero .btn{
    display:inline-block;
    margin-top:30px;
    background:#ffd166;
    color:#7b0000;
    padding:13px 34px;
    border-radius:40px;
    font-weight:bold;
}
.nav{
    position:sticky;
    top:0;
    z-index:20;
    background:#6e0000;
    text-align:center;
    padding:14px 8px;
    box-shadow:0 4px 12px rgba(0,0,0,.18);
}
.nav a{
    color:#fff;
    margin:0 14px;
    font-weight:bold;
    font-size:15px;
}
.container{
    max-width:1180px;
    margin:auto;
    padding:42px 20px;
}
.section{
    background:#fff;
    border-radius:18px;
    padding:34px;
    margin-bottom:30px;
    box-shadow:0 10px 30px rgba(120,0,0,.10);
}
.section h2{
    color:#b40000;
    font-size:30px;
    margin-bottom:18px;
    border-left:7px solid #c40000;
    padding-left:15px;
}
.lead{
    font-size:18px;
    color:#5a2929;
}
.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(245px,1fr));
    gap:22px;
}
.card{
    background:#fff7ec;
    border:1px solid #f0d2b5;
    border-radius:16px;
    padding:24px;
    transition:.2s;
}
.card:hover{
    transform:translateY(-4px);
    box-shadow:0 8px 20px rgba(120,0,0,.15);
}
.card h3{color:#a90000;margin-bottom:10px;font-size:22px}
.article-card{
    display:block;
    color:#3b1d1d;
}
.article-card .meta{
    color:#8b5a5a;
    font-size:14px;
    margin-bottom:8px;
}
.article-card .more{
    color:#b40000;
    font-weight:bold;
    margin-top:14px;
}
.timeline{
    position:relative;
    margin-top:20px;
    padding-left:26px;
    border-left:4px solid #c40000;
}
.timeline-item{
    margin-bottom:24px;
    position:relative;
}
.timeline-item:before{
    content:"";
    width:15px;
    height:15px;
    background:#c40000;
    border-radius:50%;
    position:absolute;
    left:-35px;
    top:7px;
}
.timeline-item strong{
    color:#b00000;
    font-size:20px;
}
.banner{
    background:linear-gradient(135deg,#fff0d0,#ffe2b0);
    border-radius:18px;
    padding:32px;
    text-align:center;
    margin-bottom:30px;
    border:1px solid #f2c477;
}
.banner h2{
    color:#9b0000;
    font-size:32px;
    margin-bottom:12px;
}
.two-col{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:25px;
}
.imgbox{
    background:linear-gradient(135deg,#b40000,#ffcc66);
    border-radius:18px;
    min-height:260px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:34px;
    font-weight:bold;
    text-align:center;
    padding:30px;
}
.qa{
    border-bottom:1px solid #ead1c0;
    padding:18px 0;
}
.qa:last-child{border-bottom:none}
.qa strong{color:#b00000}
.footer{
    background:#5d0000;
    color:#fff;
    text-align:center;
    padding:32px 20px;
    margin-top:30px;
}
.footer p{opacity:.9}
@media(max-width:768px){
    .hero h1{font-size:34px}
    .hero p{font-size:17px}
    .nav a{display:inline-block;margin:5px 8px}
    .two-col{grid-template-columns:1fr}
    .section{padding:24px}
}
</style>
</head>
<body>

<header class="hero">
    <div>
        <h1>中国共产党建党教育专题</h1>
        <p>学习党史知识，传承红色基因，弘扬奋斗精神，凝聚新时代奋进力量。</p>
        <a class="btn" href="#study">开始学习</a>
    </div>
</header>

<nav class="nav">
    <a href="#intro">建党概述</a>
    <a href="#history">历史脉络</a>
    <a href="#spirit">红色精神</a>
    <a href="#study">学习内容</a>
    <a href="#qa">知识问答</a>
    <a href="#articles">推荐阅读</a>
</nav>

<main class="container">

<section class="banner">
    <h2>学党史 · 悟思想 · 强信念 · 践行动</h2>
    <p>通过专题学习，进一步了解中国共产党百余年来的发展历程、历史经验和精神力量。</p>
</section>

<section id="intro" class="section">
    <h2>一、建党概述</h2>
    <div class="two-col">
        <div>
            <p class="lead">
                中国共产党成立于1921年。建党教育是党史学习教育的重要组成部分，
                主要围绕党的成立背景、发展历程、初心使命、重大历史事件和红色精神展开。
            </p>
            <br>
            <p>
                通过建党教育，可以帮助学习者理解中国共产党为什么成立、如何发展、
                如何在不同历史阶段承担使命，并从历史经验中汲取继续前进的力量。
            </p>
        </div>
        <div class="imgbox">
            红色教育<br>专题展示
        </div>
    </div>
</section>

<section id="history" class="section">
    <h2>二、重要历史脉络</h2>
    <div class="timeline">
        <?php foreach($timeline as $item): ?>
        <div class="timeline-item">
            <strong><?php echo $item[0]; ?></strong>
            <p><?php echo $item[1]; ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section id="spirit" class="section">
    <h2>三、红色精神谱系</h2>
    <div class="grid">
        <?php foreach($spirits as $spirit): ?>
        <div class="card">
            <h3><?php echo $spirit[0]; ?></h3>
            <p><?php echo $spirit[1]; ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section id="study" class="section">
    <h2>四、学习内容模块</h2>
    <div class="grid">
        <div class="card">
            <h3>党史学习</h3>
            <p>了解党的创建、发展、奋斗和建设历程，形成系统历史认知。</p>
        </div>
        <div class="card">
            <h3>初心使命</h3>
            <p>理解为人民谋幸福、为民族谋复兴的价值追求。</p>
        </div>
        <div class="card">
            <h3>先进人物</h3>
            <p>学习革命先辈、先进模范和优秀党员的精神品质。</p>
        </div>
        <div class="card">
            <h3>实践活动</h3>
            <p>结合主题党日、参观学习、交流研讨、志愿服务等形式开展教育。</p>
        </div>
    </div>
</section>

<section class="section">
    <h2>五、学习目标</h2>
    <p>
        建党教育不仅是历史知识学习，也是一种思想教育和责任教育。
        学习目标可以概括为：知史爱党、知史爱国、坚定信念、增强担当。
    </p>
    <br>
    <ul style="padding-left:25px;">
        <li>了解中国共产党成立和发展的基本历史。</li>
        <li>理解不同历史阶段的重要任务和奋斗目标。</li>
        <li>学习革命精神、奋斗精神和奉献精神。</li>
        <li>把学习成果转化为实际行动和责任担当。</li>
    </ul>
</section>

<section id="qa" class="section">
    <h2>六、党史知识问答</h2>
    <?php foreach($questions as $q): ?>
    <div class="qa">
        <p><strong>问：</strong><?php echo $q[0]; ?></p>
        <p><strong>答：</strong><?php echo $q[1]; ?></p>
    </div>
    <?php endforeach; ?>
</section>

<section id="articles" class="section">
    <h2>七、推荐阅读</h2>
    <p>以下内容为党史学习资料导读，点击可查看静态文章页。</p>
    <br>
    <div class="grid">
        <a class="card article-card" href="/study-articles/first-congress.php">
            <div class="meta">党史资料</div>
            <h3>中国共产党第一次全国代表大会</h3>
            <p>了解中国共产党成立的重要历史节点、会议经过和深远意义。</p>
            <p class="more">查看文章</p>
        </a>
        <a class="card article-card" href="/study-articles/founding-spirit.php">
            <div class="meta">精神谱系</div>
            <h3>伟大建党精神</h3>
            <p>学习伟大建党精神的基本内容、时代价值和实践启示。</p>
            <p class="more">查看文章</p>
        </a>
        <a class="card article-card" href="/study-articles/long-march-spirit.php">
            <div class="meta">党史百问</div>
            <h3>长征精神的丰富内涵</h3>
            <p>认识长征的伟大意义，理解理想信念和艰苦奋斗的精神力量。</p>
            <p class="more">查看文章</p>
        </a>
    </div>
</section>

</main>

<footer class="footer">
    <p>© <?php echo $year; ?> 中国共产党建党教育专题页面</p>
    <p>学习党史知识，传承红色精神，凝聚奋进力量</p>
</footer>

</body>
</html>
