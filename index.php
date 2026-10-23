<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, max-age=0');

$playlistUrl = 'http://netxv8.sbs/get.php?username=crizan2024&password=crizan2024&type=m3u';
$cacheTtl = 300;
$cacheFile = sys_get_temp_dir() . '/iptv_playlist_' . hash('sha256', __FILE__) . '.cache';
umask(0077);
$adultKeywords = [
    'adult', 'adults', 'xxx', 'porn', 'porno', 'pornografia', 'erotic', 'erotico',
    'erótico', 'sex', 'sexo explícito', 'sexual explicito', '18+', '21+', 'playboy',
    'hot', 'redlight', 'red light', 'sexy', 'hentai', 'hardcore'
];

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isAdultContent(string $name, string $group, array $keywords): bool {
    $text = mb_strtolower($name . ' ' . $group, 'UTF-8');
    foreach ($keywords as $word) {
        $word = trim(mb_strtolower((string)$word, 'UTF-8'));
        if ($word !== '' && mb_strpos($text, $word, 0, 'UTF-8') !== false) return true;
    }
    return false;
}

function fetchPlaylist(string $url, string $cacheFile, int $ttl): string {
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile) < $ttl)) {
        $cached = file_get_contents($cacheFile);
        if (is_string($cached) && $cached !== '') return $cached;
    }
    if ($url === '' || !function_exists('curl_init')) {
        if (is_file($cacheFile)) {
            $cached = file_get_contents($cacheFile);
            if (is_string($cached) && $cached !== '') return $cached;
        }
        throw new RuntimeException('Playlist não configurada ou serviço indisponível.');
    }

    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
        throw new RuntimeException('Configuração de playlist inválida.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_MAXFILESIZE => 25 * 1024 * 1024,
        CURLOPT_USERAGENT => 'IPTV-Website/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/x-mpegURL, application/vnd.apple.mpegurl, text/plain'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);

    if ($err !== 0 || !is_string($body) || $status < 200 || $status >= 300 || strlen($body) > 25 * 1024 * 1024) {
        if (is_file($cacheFile)) {
            $cached = file_get_contents($cacheFile);
            if (is_string($cached) && $cached !== '') return $cached;
        }
        throw new RuntimeException('Não foi possível carregar os canais agora.');
    }

    if (stripos($body, '#EXTM3U') === false) {
        throw new RuntimeException('A fonte retornou uma playlist inválida.');
    }
    @file_put_contents($cacheFile, $body, LOCK_EX);
    @chmod($cacheFile, 0600);
    return $body;
}

function parsePlaylist(string $m3u, array $adultKeywords): array {
    $lines = preg_split('/\r\n|\r|\n/', $m3u) ?: [];
    $channels = [];
    $meta = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (stripos($line, '#EXTINF:') === 0) {
            $group = '';
            $logo = '';
            $name = '';

            if (preg_match('/group-title="([^"]*)"/i', $line, $m)) $group = trim($m[1]);
            if (preg_match('/tvg-logo="([^"]*)"/i', $line, $m)) $logo = trim($m[1]);
            if (preg_match('/tvg-name="([^"]*)"/i', $line, $m) && trim($m[1]) !== '') $name = trim($m[1]);

            $comma = strrpos($line, ',');
            if ($comma !== false) {
                $displayName = trim(substr($line, $comma + 1));
                if ($name === '') $name = $displayName;
            }
            $meta = ['name' => $name ?: 'Canal', 'group' => $group ?: 'Outros', 'logo' => $logo];
            continue;
        }

        if ($line[0] === '#') continue;
        if ($meta !== null) {
            if (filter_var($line, FILTER_VALIDATE_URL) &&
                in_array(strtolower((string)parse_url($line, PHP_URL_SCHEME)), ['http', 'https'], true) &&
                !isAdultContent($meta['name'], $meta['group'], $adultKeywords)) {
                $channels[] = [
                    'id' => count($channels),
                    'name' => mb_substr($meta['name'], 0, 180, 'UTF-8'),
                    'group' => mb_substr($meta['group'], 0, 120, 'UTF-8'),
                    'logo' => filter_var($meta['logo'], FILTER_VALIDATE_URL) ? $meta['logo'] : '',
                    'url' => $line
                ];
            }
            $meta = null;
        }
    }
    return $channels;
}

function getChannels(string $playlistUrl, string $cacheFile, int $cacheTtl, array $adultKeywords): array {
    $m3u = fetchPlaylist($playlistUrl, $cacheFile, $cacheTtl);
    return parsePlaylist($m3u, $adultKeywords);
}

if (isset($_GET['api']) && $_GET['api'] === 'channels') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $channels = getChannels($playlistUrl, $cacheFile, $cacheTtl, $adultKeywords);
        echo json_encode(['ok' => true, 'channels' => $channels], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'Canais temporariamente indisponíveis. Tente novamente mais tarde.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#080808">
<title>JADIEL TM</title>
<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<style>
:root{color-scheme:dark;--bg:#080808;--panel:#121212;--panel2:#191919;--line:#292929;--text:#f5f5f5;--muted:#9a9a9a}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 Inter,system-ui,-apple-system,Segoe UI,sans-serif}
button,input{font:inherit}button{cursor:pointer}.top{height:70px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;background:#080808ed;backdrop-filter:blur(14px);z-index:5}
.brand{font-weight:850;letter-spacing:.08em;font-size:19px}.brand span{color:#888}.topright{display:flex;gap:12px;align-items:center}.contact{color:#fff;text-decoration:none;border:1px solid #383838;padding:9px 13px;border-radius:12px;font-size:13px}
.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:calc(100vh - 70px)}aside{border-right:1px solid var(--line);padding:22px 14px;position:sticky;top:70px;height:calc(100vh - 70px);overflow:auto}
.side-label{color:#777;font-size:11px;letter-spacing:.14em;font-weight:700;padding:8px 12px 12px}.cat{width:100%;border:0;background:transparent;color:#bdbdbd;text-align:left;padding:11px 12px;border-radius:10px;display:flex;justify-content:space-between;gap:8px;margin:2px 0}.cat:hover,.cat.active{background:#202020;color:#fff}.count{color:#777;font-size:12px}
main{padding:26px;min-width:0}.heading{display:flex;justify-content:space-between;align-items:end;gap:18px;margin-bottom:22px}.heading h1{font-size:clamp(24px,3vw,34px);margin:0;letter-spacing:-.04em}.sub{color:var(--muted);margin-top:5px}.search{background:#141414;border:1px solid #303030;color:white;padding:12px 15px;border-radius:12px;width:min(330px,100%);outline:none}.search:focus{border-color:#777}
.playerbox{display:none;background:#111;border:1px solid var(--line);border-radius:18px;padding:14px;margin-bottom:24px}.playerbox.show{display:block}.player-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.now{display:flex;gap:12px;align-items:center;min-width:0}.now img{width:46px;height:46px;object-fit:contain;background:#202020;border-radius:10px}.now strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.now small{color:var(--muted)}.back{border:1px solid #393939;background:#1c1c1c;color:#fff;padding:9px 12px;border-radius:10px}video{width:100%;max-height:70vh;background:#000;border-radius:12px;aspect-ratio:16/9}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:14px}.channel{border:1px solid #242424;background:linear-gradient(145deg,#171717,#101010);border-radius:15px;padding:14px;text-align:left;color:#fff;min-width:0;transition:transform .18s,border-color .18s}.channel:hover{transform:translateY(-3px);border-color:#555}.logo{height:92px;display:flex;align-items:center;justify-content:center;margin-bottom:12px}.logo img{max-width:100%;max-height:86px;object-fit:contain}.fallback{font-size:30px;color:#777;font-weight:800}.channel-name{font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.channel-group{font-size:12px;color:#888;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.status{padding:45px 18px;text-align:center;color:#aaa;border:1px dashed #333;border-radius:16px}.status b{color:#eee;display:block;margin-bottom:7px}.mobile-cats{display:none}
@media(max-width:850px){.layout{grid-template-columns:1fr}aside{display:none}.mobile-cats{display:block;margin-bottom:18px}.mobile-cats select{width:100%;background:#151515;color:#fff;border:1px solid #333;border-radius:12px;padding:12px}.heading{align-items:stretch;flex-direction:column}.search{width:100%}main{padding:18px}.grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px}.channel{padding:11px}.logo{height:78px}.top{padding:0 16px}}
</style>
</head>
<body>
<header class="top">
  <div class="brand">JADIEL <span>TM</span></div>
  <div class="topright"><a class="contact" href="https://t.me/JADIEL_TM" target="_blank" rel="noopener noreferrer">Suporte e contato ↗</a></div>
</header>
<div class="layout">
<aside><div class="side-label">BIBLIOTECA</div><nav id="sidebar"><button class="cat active" data-group="*">Todos os canais <span class="count" id="allCount">—</span></button></nav></aside>
<main>
  <div class="mobile-cats"><select id="categorySelect" aria-label="Selecionar categoria"><option value="*">Todas as categorias</option></select></div>
  <div class="heading"><div><h1 id="pageTitle">Todos os canais</h1><div class="sub" id="subtitle">Carregando sua programação…</div></div><input class="search" id="search" type="search" placeholder="Pesquisar canal ou categoria…" autocomplete="off"></div>
  <section class="playerbox" id="playerBox">
    <div class="player-head"><div class="now"><img id="nowLogo" alt="" hidden><div><strong id="nowName">Canal</strong><small id="nowGroup"></small></div></div><button class="back" id="backBtn">← Voltar</button></div>
    <video id="video" controls playsinline></video>
    <div class="sub" id="playerMessage" role="status"></div>
  </section>
  <div class="grid" id="channels"></div>
  <div class="status" id="status" hidden><b id="statusTitle">Carregando canais</b><span id="statusText"></span></div>
</main>
</div>
<script>
(() => {
 const api = new URL(location.href); api.search='?api=channels';
 const grid=document.getElementById('channels'),status=document.getElementById('status'),search=document.getElementById('search');
 const sidebar=document.getElementById('sidebar'),select=document.getElementById('categorySelect');
 const playerBox=document.getElementById('playerBox'),video=document.getElementById('video');
 let channels=[],group='*',hls=null;
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 function showStatus(title,msg){status.hidden=false;document.getElementById('statusTitle').textContent=title;document.getElementById('statusText').textContent=msg}
 function hideStatus(){status.hidden=true}
 function stopPlayer(){if(hls){hls.destroy();hls=null}video.pause();video.removeAttribute('src');video.load();playerBox.classList.remove('show')}
 function play(ch){
   playerBox.classList.add('show');document.getElementById('nowName').textContent=ch.name;document.getElementById('nowGroup').textContent=ch.group;
   const im=document.getElementById('nowLogo');im.hidden=!ch.logo;if(ch.logo)im.src=ch.logo;
   document.getElementById('playerMessage').textContent='Carregando reprodução…';
   if(hls){hls.destroy();hls=null}video.pause();video.removeAttribute('src');
   const url=ch.url;
   if(/\.m3u8($|\?)/i.test(url)){
     if(video.canPlayType('application/vnd.apple.mpegurl')){video.src=url;video.play().catch(()=>{});}
     else if(window.Hls&&Hls.isSupported()){hls=new Hls();hls.loadSource(url);hls.attachMedia(video);hls.on(Hls.Events.MANIFEST_PARSED,()=>video.play().catch(()=>{}));hls.on(Hls.Events.ERROR,(_,d)=>{if(d.fatal)document.getElementById('playerMessage').textContent='Este canal está indisponível ou o formato não é compatível.'})}
     else document.getElementById('playerMessage').textContent='Seu navegador não suporta este formato de transmissão.';
   }else{video.src=url;video.play().catch(()=>{document.getElementById('playerMessage').textContent='Toque em reproduzir para iniciar o canal.'})}
 }
 video.addEventListener('playing',()=>document.getElementById('playerMessage').textContent='');
 video.addEventListener('error',()=>document.getElementById('playerMessage').textContent='Este canal está indisponível ou o formato não é compatível');
 document.getElementById('backBtn').addEventListener('click',stopPlayer);
 function renderCategories(){
   const counts={};channels.forEach(c=>counts[c.group]=(counts[c.group]||0)+1);
   sidebar.innerHTML='';select.innerHTML='<option value="*">Todas as categorias</option>';
   const all=document.createElement('button');all.className='cat'+(group==='*'?' active':'');all.dataset.group='*';all.innerHTML='Todos os canais <span class="count">'+channels.length+'</span>';sidebar.appendChild(all);
   Object.keys(counts).sort((a,b)=>a.localeCompare(b,'pt-BR')).forEach(g=>{
     const b=document.createElement('button');b.className='cat'+(group===g?' active':'');b.dataset.group=g;b.innerHTML=esc(g)+' <span class="count">'+counts[g]+'</span>';sidebar.appendChild(b);
     const o=document.createElement('option');o.value=g;o.textContent=g+' ('+counts[g]+')';select.appendChild(o);
   });
   document.getElementById('allCount').textContent=channels.length;
   select.value=group;
 }
 function render(){
   const q=search.value.trim().toLocaleLowerCase('pt-BR');
   const list=channels.filter(c=>(group==='*'||c.group===group)&&(!q||(c.name+' '+c.group).toLocaleLowerCase('pt-BR').includes(q)));
   grid.innerHTML='';hideStatus();
   document.getElementById('pageTitle').textContent=group==='*'?'Todos os canais':group;
   document.getElementById('subtitle').textContent=list.length+' canal(is)';
   if(!list.length){showStatus('Nenhum canal encontrado','Tente outra pesquisa ou selecione uma categoria diferente.');return}
   list.forEach(ch=>{
     const b=document.createElement('button');b.className='channel';
     b.innerHTML='<div class="logo">'+(ch.logo?'<img loading="lazy" src="'+esc(ch.logo)+'" alt="" onerror="this.remove()">':'')+'<span class="fallback">'+esc((ch.name||'TV').slice(0,1).toUpperCase())+'</span></div><div class="channel-name">'+esc(ch.name)+'</div><div class="channel-group">'+esc(ch.group)+'</div>';
     b.addEventListener('click',()=>play(ch));grid.appendChild(b);
   });
 }
 sidebar.addEventListener('click',e=>{const b=e.target.closest('[data-group]');if(!b)return;group=b.dataset.group;renderCategories();render()});
 select.addEventListener('change',()=>{group=select.value;renderCategories();render()});search.addEventListener('input',render);
 async function init(){
   showStatus('Carregando canais','Conectando à playlist…');
   try{
     const res=await fetch(api,{headers:{Accept:'application/json'},cache:'no-store'});
     const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.message||'Falha ao carregar');
     channels=Array.isArray(data.channels)?data.channels:[];
     renderCategories();render();
     if(!channels.length)showStatus('Nenhum canal disponível','A playlist está vazia ou todos os canais foram filtrados.');
   }catch(e){showStatus('Canais temporariamente indisponíveis','Não foi possível carregar a programação. Tente novamente mais tarde.')}
 }
 init();
})();
</script>
</body>
</html>