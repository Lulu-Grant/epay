<?php
if(!defined('IN_CRONLITE')){
	include(__DIR__."/includes/common.php");
}

$forced_role = isset($help_force_role) ? $help_force_role : null;
$is_admin = $forced_role === 'admin' || (isset($islogin) && $islogin == 1);
$is_user = $forced_role === 'user' || (isset($islogin2) && $islogin2 == 1);

$docs = [
	'user-manual' => [
		'title' => '用户使用文档',
		'file' => 'docs/user-manual.md',
		'roles' => ['admin'],
		'summary' => '完整的平台使用总手册，覆盖商户、管理员、对接、排障。'
	],
	'merchant-quickstart' => [
		'title' => '商户快速上手指南',
		'file' => 'docs/merchant-quickstart.md',
		'roles' => ['admin', 'user'],
		'summary' => '商户登录、资料配置、API 对接、测试支付和上线验收。'
	],
	'admin-operations-runbook' => [
		'title' => '管理员日常运维手册',
		'file' => 'docs/admin-operations-runbook.md',
		'roles' => ['admin'],
		'summary' => '日常巡检、通道维护、订单处理、结算和投诉处理规范。'
	],
	'troubleshooting-guide' => [
		'title' => '支付系统故障排查手册',
		'file' => 'docs/troubleshooting-guide.md',
		'roles' => ['admin'],
		'summary' => '登录、支付、通知、退款、结算、统计等异常排查路径。'
	],
];

function help_has_role($meta, $is_admin, $is_user){
	if($is_admin && in_array('admin', $meta['roles']))return true;
	if($is_user && in_array('user', $meta['roles']))return true;
	return false;
}

function help_href($url){
	$url = trim($url);
	if(preg_match('/^(https?:\/\/|\/|#|[A-Za-z0-9_.\/?-]+$)/', $url)){
		return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
	}
	return '#';
}

function help_token($html, &$tokens){
	$key = "\x1A".count($tokens)."\x1A";
	$tokens[$key] = $html;
	return $key;
}

function help_inline($text){
	$tokens = [];
	$text = preg_replace_callback('/`([^`]+)`/', function($m) use (&$tokens){
		return help_token('<code>'.htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8').'</code>', $tokens);
	}, $text);
	$text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) use (&$tokens){
		$label = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
		return help_token('<a href="'.help_href($m[2]).'" target="_blank" rel="noopener noreferrer">'.$label.'</a>', $tokens);
	}, $text);
	$text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	$text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
	foreach($tokens as $key=>$html){
		$text = str_replace($key, $html, $text);
	}
	return $text;
}

function help_table_cells($line){
	$line = trim($line);
	$line = trim($line, '|');
	return array_map('trim', explode('|', $line));
}

function help_flush_paragraph(&$html, &$paragraph){
	if(!empty($paragraph)){
		$html .= '<p>'.help_inline(implode(' ', $paragraph)).'</p>';
		$paragraph = [];
	}
}

function help_close_lists(&$html, &$list_type){
	if($list_type){
		$html .= '</'.$list_type.'>';
		$list_type = null;
	}
}

function help_markdown($markdown){
	$lines = preg_split("/\r\n|\n|\r/", $markdown);
	$html = '';
	$paragraph = [];
	$list_type = null;
	$in_code = false;
	$code = [];
	$code_lang = '';
	$count = count($lines);

	for($i=0;$i<$count;$i++){
		$line = rtrim($lines[$i]);

		if(preg_match('/^```([A-Za-z0-9_-]*)\s*$/', $line, $m)){
			if($in_code){
				$html .= '<pre><code'.($code_lang ? ' class="language-'.htmlspecialchars($code_lang, ENT_QUOTES, 'UTF-8').'"' : '').'>'.htmlspecialchars(implode("\n", $code), ENT_NOQUOTES, 'UTF-8').'</code></pre>';
				$in_code = false;
				$code = [];
				$code_lang = '';
			}else{
				help_flush_paragraph($html, $paragraph);
				help_close_lists($html, $list_type);
				$in_code = true;
				$code_lang = $m[1];
			}
			continue;
		}

		if($in_code){
			$code[] = $line;
			continue;
		}

		if(trim($line) === ''){
			help_flush_paragraph($html, $paragraph);
			help_close_lists($html, $list_type);
			continue;
		}

		if(preg_match('/^\|.*\|\s*$/', $line) && isset($lines[$i+1]) && preg_match('/^\|\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|\s*$/', trim($lines[$i+1]))){
			help_flush_paragraph($html, $paragraph);
			help_close_lists($html, $list_type);
			$headers = help_table_cells($line);
			$html .= '<div class="table-wrap"><table><thead><tr>';
			foreach($headers as $cell){
				$html .= '<th>'.help_inline($cell).'</th>';
			}
			$html .= '</tr></thead><tbody>';
			$i += 2;
			for(;$i<$count;$i++){
				$row_line = rtrim($lines[$i]);
				if(!preg_match('/^\|.*\|\s*$/', $row_line)){
					$i--;
					break;
				}
				$cells = help_table_cells($row_line);
				$html .= '<tr>';
				foreach($cells as $cell){
					$html .= '<td>'.help_inline($cell).'</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table></div>';
			continue;
		}

		if(preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)){
			help_flush_paragraph($html, $paragraph);
			help_close_lists($html, $list_type);
			$level = strlen($m[1]);
			$id = trim(preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fa5}_-]+/u', '-', strip_tags($m[2])), '-');
			$html .= '<h'.$level.($id ? ' id="'.htmlspecialchars($id, ENT_QUOTES, 'UTF-8').'"' : '').'>'.help_inline($m[2]).'</h'.$level.'>';
			continue;
		}

		if(preg_match('/^>\s*(.+)$/', $line, $m)){
			help_flush_paragraph($html, $paragraph);
			help_close_lists($html, $list_type);
			$html .= '<blockquote>'.help_inline($m[1]).'</blockquote>';
			continue;
		}

		if(preg_match('/^-\s+(.+)$/', $line, $m)){
			help_flush_paragraph($html, $paragraph);
			if($list_type !== 'ul'){
				help_close_lists($html, $list_type);
				$list_type = 'ul';
				$html .= '<ul>';
			}
			$html .= '<li>'.help_inline($m[1]).'</li>';
			continue;
		}

		if(preg_match('/^\d+\.\s+(.+)$/', $line, $m)){
			help_flush_paragraph($html, $paragraph);
			if($list_type !== 'ol'){
				help_close_lists($html, $list_type);
				$list_type = 'ol';
				$html .= '<ol>';
			}
			$html .= '<li>'.help_inline($m[1]).'</li>';
			continue;
		}

		$paragraph[] = $line;
	}

	if($in_code){
		$html .= '<pre><code>'.htmlspecialchars(implode("\n", $code), ENT_NOQUOTES, 'UTF-8').'</code></pre>';
	}
	help_flush_paragraph($html, $paragraph);
	help_close_lists($html, $list_type);
	return $html;
}

function help_doc_path($relative){
	$private = dirname(ROOT).'/epay-helpdocs/'.basename($relative);
	if(is_file($private))return $private;
	return ROOT.$relative;
}

$requested = isset($_GET['doc']) ? trim($_GET['doc']) : '';
if($requested === ''){
	$requested = $is_admin ? 'user-manual' : 'merchant-quickstart';
}
if(!isset($docs[$requested])){
	http_response_code(404);
	exit('Document not found');
}

$current = $docs[$requested];
if(!help_has_role($current, $is_admin, $is_user)){
	if(in_array('user', $current['roles'])){
		exit("<script language='javascript'>window.location.href='/user/login.php';</script>");
	}
	exit("<script language='javascript'>window.location.href='/admin/login.php';</script>");
}

$available_docs = [];
foreach($docs as $key=>$meta){
	if(help_has_role($meta, $is_admin, $is_user)){
		$available_docs[$key] = $meta;
	}
}

$path = help_doc_path($current['file']);
if(!is_file($path)){
	http_response_code(404);
	exit('Document file not found');
}
$content = file_get_contents($path);
$body = help_markdown($content);
?>
<!doctype html>
<html lang="zh-CN">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo htmlspecialchars($current['title'], ENT_QUOTES, 'UTF-8')?> - <?php echo htmlspecialchars($conf['sitename'], ENT_QUOTES, 'UTF-8')?></title>
	<style>
		:root {
			--bg: #f5f7f6;
			--panel: #ffffff;
			--ink: #1f2926;
			--muted: #697773;
			--line: #dfe6e2;
			--brand: #0f766e;
			--brand-dark: #0b4f4a;
			--accent: #b42318;
			--code-bg: #f0f4f2;
			--thead: #edf5f2;
		}
		* { box-sizing: border-box; }
		body {
			margin: 0;
			background: var(--bg);
			color: var(--ink);
			font: 15px/1.72 "Helvetica Neue", "Microsoft YaHei", sans-serif;
		}
		a { color: var(--brand-dark); text-decoration: none; }
		a:hover { color: var(--accent); text-decoration: underline; }
		.topbar {
			position: sticky;
			top: 0;
			z-index: 20;
			background: var(--panel);
			border-bottom: 1px solid var(--line);
		}
		.topbar-inner {
			max-width: 1180px;
			margin: 0 auto;
			padding: 14px 22px;
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 16px;
		}
		.brand {
			font-size: 17px;
			font-weight: 700;
			color: var(--ink);
		}
		.actions {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
			align-items: center;
		}
		.actions a {
			display: inline-flex;
			align-items: center;
			min-height: 34px;
			padding: 6px 12px;
			border: 1px solid var(--line);
			border-radius: 4px;
			background: #fff;
			color: var(--ink);
		}
		.layout {
			max-width: 1180px;
			margin: 0 auto;
			padding: 24px 22px 44px;
			display: grid;
			grid-template-columns: 280px minmax(0, 1fr);
			gap: 22px;
		}
		.sidebar, .content {
			background: var(--panel);
			border: 1px solid var(--line);
			border-radius: 6px;
		}
		.sidebar {
			align-self: start;
			position: sticky;
			top: 82px;
			padding: 14px;
		}
		.side-title {
			margin: 2px 6px 10px;
			font-size: 13px;
			color: var(--muted);
			font-weight: 700;
		}
		.doc-link {
			display: block;
			padding: 10px 11px;
			border-radius: 4px;
			color: var(--ink);
			border: 1px solid transparent;
			margin-bottom: 7px;
		}
		.doc-link strong { display: block; font-size: 14px; }
		.doc-link span { display: block; color: var(--muted); font-size: 12px; line-height: 1.5; margin-top: 2px; }
		.doc-link.active {
			border-color: #b7d7d0;
			background: #eef8f5;
		}
		.content { padding: 30px 36px; min-width: 0; }
		.content h1 {
			margin: 0 0 12px;
			font-size: 30px;
			line-height: 1.25;
			color: var(--brand-dark);
		}
		.content h2 {
			margin: 34px 0 12px;
			padding-top: 4px;
			font-size: 22px;
			border-top: 1px solid var(--line);
		}
		.content h3 { margin: 26px 0 10px; font-size: 18px; }
		.content h4 { margin: 22px 0 8px; font-size: 16px; }
		.content p { margin: 10px 0; }
		.content ul, .content ol { margin: 10px 0 16px 22px; padding: 0; }
		.content li { margin: 5px 0; }
		.content blockquote {
			margin: 14px 0;
			padding: 10px 14px;
			border-left: 4px solid var(--brand);
			background: #f6fbf9;
			color: var(--muted);
		}
		code {
			padding: 2px 5px;
			border-radius: 4px;
			background: var(--code-bg);
			color: #7a2e12;
			font-family: Menlo, Consolas, monospace;
			font-size: 13px;
		}
		pre {
			overflow: auto;
			padding: 14px;
			background: #13201d;
			color: #eef8f5;
			border-radius: 6px;
		}
		pre code {
			padding: 0;
			background: transparent;
			color: inherit;
		}
		.table-wrap { overflow-x: auto; margin: 14px 0 20px; }
		table {
			width: 100%;
			border-collapse: collapse;
			min-width: 560px;
			font-size: 14px;
		}
		th, td {
			border: 1px solid var(--line);
			padding: 9px 10px;
			text-align: left;
			vertical-align: top;
		}
		th { background: var(--thead); color: var(--brand-dark); }
		tr:nth-child(even) td { background: #fbfcfc; }
		@media (max-width: 860px) {
			.topbar-inner { align-items: flex-start; flex-direction: column; }
			.layout { display: block; padding: 14px 12px 30px; }
			.sidebar { position: static; margin-bottom: 14px; }
			.content { padding: 22px 18px; }
			.content h1 { font-size: 24px; }
		}
	</style>
</head>
<body>
	<header class="topbar">
		<div class="topbar-inner">
			<a class="brand" href="<?php echo $is_admin ? '/admin/help.php' : '/user/help_center.php'?>"><?php echo htmlspecialchars($conf['sitename'], ENT_QUOTES, 'UTF-8')?> 使用帮助</a>
			<nav class="actions">
				<?php if($is_admin){?><a href="/admin/">返回管理后台</a><?php }?>
				<?php if($is_user){?><a href="/user/">返回用户中心</a><?php }?>
				<a href="/doc.html" target="_blank" rel="noopener noreferrer">开发文档</a>
			</nav>
		</div>
	</header>
	<main class="layout">
		<aside class="sidebar">
			<div class="side-title">文档目录</div>
			<?php foreach($available_docs as $key=>$meta){?>
				<a class="doc-link <?php echo $key===$requested?'active':null?>" href="<?php echo $is_admin ? '/admin/help.php' : '/user/help_center.php'?>?doc=<?php echo urlencode($key)?>">
					<strong><?php echo htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8')?></strong>
					<span><?php echo htmlspecialchars($meta['summary'], ENT_QUOTES, 'UTF-8')?></span>
				</a>
			<?php }?>
		</aside>
		<article class="content">
			<?php echo $body?>
		</article>
	</main>
</body>
</html>
