<?php

require_once __DIR__ . '/config.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function authHiddenError(Throwable $e): string
{
    error_log('e2sb: ' . $e->getMessage());

    return 'Something went wrong. Try again.';
}

function authSecurityHeaders(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

authSecurityHeaders();

function appNav(string $active): void
{
    $admin = function_exists('authIsAdmin') && authIsAdmin();
    $items = [
        'playlist' => ['Playlist', 'index.php', false],
    ];
    if ($admin) {
        $items['epg'] = ['EPG', 'epg.php', true];
        $items['mapping'] = ['EPG mapping', 'epg-mapping.php', true];
        $items['users'] = ['Users', 'users.php', false];
    }
    echo '<nav class="nav">';
    foreach ($items as $key => $item) {
        $class = $key === $active ? ' class="active' . ($item[2] ? ' slow' : '') . '"' : ($item[2] ? ' class="slow"' : '');
        echo '<a' . $class . ' href="' . h($item[1]) . '">' . h($item[0]) . '</a>';
    }
    if ($admin) {
        echo '<button class="nav-btn" type="button" id="xtream-open">Xtream</button>';
        $eitClass = $active === 'eit' ? ' class="active"' : '';
        echo '<a' . $eitClass . ' href="eit.php">EIT</a>';
        echo '<form method="post" action="create-all.php">';
        echo '<button class="nav-btn" type="submit" name="create_all" value="1">Create All</button>';
        echo '</form>';
    }
    echo '<label class="theme"><span>Theme</span><select id="theme">';
    foreach (['dark' => 'Dark', 'light' => 'Light', 'ocean' => 'Ocean', 'amber' => 'Amber'] as $value => $label) {
        echo '<option value="' . h($value) . '">' . h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="btn" type="button" id="help-open">How it works</button>';
    $version = appVersion();
    if ($version !== '') {
        echo '<span class="version">' . h($version) . '</span>';
    }
    echo '</nav>';
}

function appMenubar(string $active, string $activeHouse = ''): void
{
    $account = function_exists('authUser') ? authUser() : null;
    $admin = function_exists('authIsAdmin') && authIsAdmin($account);
    $homes = ($account !== null && function_exists('homeList')) ? homeList((int) $account['id']) : [];
    echo '<header class="menubar">';
    echo '<a class="brand compact" href="index.php">';
    echo '<span class="mark" aria-hidden="true"></span>';
    echo '<strong>E2 Stream Builder</strong>';
    echo '</a>';
    appNav($active);
    if ($account === null) {
        echo '<button class="nav-btn" type="button" id="login-open">Log in</button>';
        echo '<button class="nav-btn" type="button" id="register-open">Create account</button>';
        echo '</header>';

        return;
    }
    echo '<label class="house-switch"><span>You are</span><select id="house-pick">';
    if ($homes === []) {
        echo '<option value="">No house</option>';
    }
    foreach ($homes as $house) {
        $token = (string) $house['token'];
        $selected = $token === $activeHouse ? ' selected' : '';
        echo '<option value="' . h($token) . '"' . $selected . '>' . h((string) $house['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="nav-btn" type="button" id="add-house-open">Add house</button>';
    if (!$admin && $activeHouse !== '') {
        echo '<form method="post" action="index.php" onsubmit="return confirm(\'Remove this house?\');">';
        echo authCsrfField();
        echo '<input type="hidden" name="delete_home" value="1">';
        echo '<input type="hidden" name="token" id="remove-house-token" value="' . h($activeHouse) . '">';
        echo '<button class="nav-btn" type="submit">Remove house</button>';
        echo '</form>';
    }
    echo '<button class="nav-btn" type="button" id="password-open">Password</button>';
    echo '<form method="post" action="index.php">';
    echo authCsrfField();
    echo '<input type="hidden" name="logout" value="1">';
    echo '<button class="nav-btn" type="submit">Log out</button>';
    echo '</form>';
    echo '</header>';
}

function appThemeScript(): void
{
    echo '<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">';
    echo '<script>(function(){try{var t=localStorage.getItem("e2-theme")||"dark";if(t!=="light"&&t!=="ocean"&&t!=="amber")t="dark";document.documentElement.setAttribute("data-theme",t);}catch(e){document.documentElement.setAttribute("data-theme","dark");}})();</script>';
}

function appShellStyle(): void
{
    echo '<style>
#loading,#help,#xtream,#receiver,#add-house,#login,#register,#forgot,#password,#remote{display:none !important}
#loading.is-open,#help.is-open,#xtream.is-open,#receiver.is-open,#add-house.is-open,#login.is-open,#register.is-open,#forgot.is-open,#password.is-open,#remote.is-open{display:flex !important;position:fixed !important;top:0;right:0;bottom:0;left:0;z-index:4000;align-items:center;justify-content:center;margin:0;padding:24px;background:rgba(0,0,0,.55);color:#f4f7f4}
#loading.is-open{flex-direction:column;gap:14px;z-index:5000 !important}
#loading p{max-width:36rem;margin:0;text-align:center;line-height:1.45;word-break:break-word}
#loading .spinner{width:46px;height:46px;border:4px solid rgba(255,255,255,.28);border-top-color:#e2a85a;border-radius:50%;animation:e2spin .8s linear infinite}
#help .dialog,#xtream .dialog,#receiver .dialog,#add-house .dialog,#login .dialog,#register .dialog,#forgot .dialog,#password .dialog,#remote .dialog{width:min(640px,100%);max-height:min(80vh,720px);overflow:auto;background:var(--raise,#181e19);color:var(--text,#e7efe6);border:1px solid var(--line,#313a32);border-radius:14px;padding:18px;box-shadow:0 18px 40px rgba(0,0,0,.35)}
#remote .dialog{width:min(720px,100%)}
#help .dialog h2,#xtream .dialog h2,#receiver .dialog h2,#add-house .dialog h2,#login .dialog h2,#register .dialog h2,#forgot .dialog h2,#password .dialog h2,#remote .dialog h2{margin:0 0 8px}
#help .dialog p,#help .dialog li,#xtream .dialog p,#xtream .dialog li,#receiver .dialog p,#add-house .dialog p,#login .dialog p,#register .dialog p,#forgot .dialog p,#password .dialog p,#remote .dialog p,#remote .dialog li{color:var(--muted,#93a196)}
#login .dialog p.error,#register .dialog p.error,#forgot .dialog p.error,#password .dialog p.error,#add-house .dialog p.error,#receiver .dialog p.error,#login .dialog p.login-alert{color:#e07a68 !important;font-weight:700}
#forgot .dialog p.oknote{color:#8fbf7a !important;font-weight:700}
#login .dialog .field input,#register .dialog .field input,#forgot .dialog .field input,#password .dialog .field input,#add-house .dialog .field input{width:100%;box-sizing:border-box}
#help .dialog ol{margin:0 0 12px;padding-left:1.2rem}
@keyframes e2spin{to{transform:rotate(360deg)}}
html[data-theme="light"]{color-scheme:light;--bg:#f4f1ea !important;--raise:#fffdf8 !important;--raise-2:#efe8dc !important;--line:#d7cec0 !important;--text:#241c14 !important;--muted:#6d645b !important;--accent:#b86a1d !important;--accent-ink:#fff8ef !important;--accent-line:#8d4e12 !important;--good:#2f7d46 !important;--warn:#a15c12 !important;--bad:#b42318 !important;--sat:#3d5a73 !important;--stage:#1c1916 !important;--shadow:0 18px 40px rgba(70,48,20,.12) !important}
html[data-theme="ocean"]{color-scheme:dark;--bg:#0d1720 !important;--raise:#142230 !important;--raise-2:#1b2d3e !important;--line:#2c455c !important;--text:#e7f2f8 !important;--muted:#93adbf !important;--accent:#3db7c9 !important;--accent-ink:#062026 !important;--accent-line:#2a8f9e !important;--good:#7dcea0 !important;--warn:#e2b15a !important;--bad:#e07a68 !important;--sat:#9eb4d0 !important;--stage:#071018 !important;--shadow:0 18px 40px rgba(0,0,0,.32) !important}
html[data-theme="amber"]{color-scheme:dark;--bg:#1a120c !important;--raise:#261910 !important;--raise-2:#322016 !important;--line:#4d3424 !important;--text:#f8efe6 !important;--muted:#c4a892 !important;--accent:#f0a04b !important;--accent-ink:#2a1606 !important;--accent-line:#c47a2a !important;--good:#c6d48a !important;--warn:#f0a04b !important;--bad:#e07a68 !important;--sat:#d7c3a4 !important;--stage:#100b08 !important;--shadow:0 18px 40px rgba(0,0,0,.35) !important}
</style>';
    echo '<script>
document.addEventListener("DOMContentLoaded",function(){
  var root=document.documentElement;
  var theme=document.getElementById("theme");
  var help=document.getElementById("help");
  var loading=document.getElementById("loading");
  function applyTheme(name){
    if(name!=="light"&&name!=="ocean"&&name!=="amber")name="dark";
    root.setAttribute("data-theme",name);
    if(theme)theme.value=name;
    try{localStorage.setItem("e2-theme",name);}catch(e){}
  }
  if(theme){
    theme.value=root.getAttribute("data-theme")||"dark";
    theme.addEventListener("change",function(){applyTheme(theme.value);});
  }
  var xtream=document.getElementById("xtream");
  function openHelp(){if(help)help.classList.add("is-open");}
  function closeHelp(){if(help)help.classList.remove("is-open");}
  function openXtream(){if(xtream)xtream.classList.add("is-open");}
  function closeXtream(){if(xtream)xtream.classList.remove("is-open");}
  var helpOpen=document.getElementById("help-open");
  if(helpOpen)helpOpen.addEventListener("click",openHelp);
  var xtreamOpen=document.getElementById("xtream-open");
  if(xtreamOpen)xtreamOpen.addEventListener("click",openXtream);
  document.querySelectorAll("[data-close-help]").forEach(function(button){button.addEventListener("click",closeHelp);});
  document.querySelectorAll("[data-close-xtream]").forEach(function(button){button.addEventListener("click",closeXtream);});
  if(help)help.addEventListener("click",function(event){if(event.target===help)closeHelp();});
  if(xtream)xtream.addEventListener("click",function(event){if(event.target===xtream)closeXtream();});
  function bindOverlay(id, openId){
    var overlay=document.getElementById(id);
    if(!overlay)return;
    function show(){overlay.classList.add("is-open");}
    function hide(){if(overlay.classList.contains("is-required"))return;overlay.classList.remove("is-open");}
    document.querySelectorAll("#"+openId+",[data-open=\""+id+"\"]").forEach(function(open){open.addEventListener("click",show);});
    overlay.querySelectorAll("[data-close]").forEach(function(button){button.addEventListener("click",hide);});
    overlay.addEventListener("click",function(event){if(event.target===overlay)hide();});
    document.addEventListener("keydown",function(event){if(event.key==="Escape")hide();});
  }
  bindOverlay("remote","remote-open");
  bindOverlay("add-house","add-house-open");
  bindOverlay("login","login-open");
  bindOverlay("password","password-open");
  bindOverlay("register","register-open");
  bindOverlay("forgot","forgot-open");
  document.querySelectorAll("#add-house-open,#password-open,#login-open,#register-open").forEach(function(button){
    button.addEventListener("click",function(){
      var id=button.id.replace(/-open$/,"");
      if(!document.getElementById(id))location.href="index.php?open="+id;
    });
  });
  var housePick=document.getElementById("house-pick");
  if(housePick&&!document.querySelector(".housebar,.user-house")){
    housePick.addEventListener("change",function(){
      if(housePick.value)location.href="index.php?house="+encodeURIComponent(housePick.value);
    });
  }
  var toForgot=document.getElementById("forgot-open");
  if(toForgot)toForgot.addEventListener("click",function(){
    var login=document.getElementById("login");
    var forgot=document.getElementById("forgot");
    var from=login?login.querySelector("input[name=username]"):null;
    var into=forgot?forgot.querySelector("input[name=username]"):null;
    if(from&&into&&from.value)into.value=from.value;
    if(login)login.classList.remove("is-open");
  });
  var toRegister=document.getElementById("login-to-register");
  if(toRegister)toRegister.addEventListener("click",function(){
    var login=document.getElementById("login");
    var register=document.getElementById("register");
    var from=login?login.querySelector("input[name=username]"):null;
    var into=register?register.querySelector("input[name=username]"):null;
    if(from&&into&&from.value)into.value=from.value;
    if(login)login.classList.remove("is-open");
    if(register)register.classList.add("is-open");
  });
  document.addEventListener("keydown",function(event){if(event.key==="Escape"){closeHelp();closeXtream();}});
  if(/[?&]xtream=1(?:&|$)/.test(location.search))openXtream();
  function showLoading(text){
    if(!loading)return;
    loading.classList.add("is-open");
    var label=document.getElementById("loading-text");
    if(label)label.textContent=text||"Loading…";
  }
  window.addEventListener("pageshow",function(){
    if(loading)loading.classList.remove("is-open");
    var label=document.getElementById("loading-text");
    if(label)label.textContent="Loading…";
  });
  function startProgress(form, event){
    if(event.defaultPrevented)return true;
    var submitter=event.submitter||null;
    var action=submitter&&submitter.name==="action"?String(submitter.value):"";
    var target=form.getAttribute("action")||"";
    var isDownload=action==="download_source"||action==="refresh";
    var isXtream=target.indexOf("xtream-build.php")!==-1;
    var isCreate=target.indexOf("create-all.php")!==-1;
    var isEit=action==="build_eit";
    if(!isDownload&&!isXtream&&!isCreate&&!isEit)return false;
    event.preventDefault();
    var startText="Starting the EPG download…";
    if(isXtream)startText="Starting the Xtream build…";
    if(isCreate)startText="Reading channels from the receiver…";
    if(isEit)startText="Reading the receiver guide…";
    showLoading(startText);
    var body=new FormData(form);
    if(submitter&&submitter.name)body.append(submitter.name,submitter.value);
    body.append("progress","1");
    var url=form.getAttribute("action")||location.href;
    var finished=false;
    fetch(url,{method:"POST",body:body,credentials:"same-origin"})
      .then(function(response){
        if(!response.body)throw new Error("The browser could not read the progress stream.");
        var reader=response.body.getReader();
        var decoder=new TextDecoder();
        var buffer="";
        function handleLine(line){
          line=line.trim();
          if(!line||line.charAt(0)!=="{")return;
          var data=JSON.parse(line);
          if(data.text)showLoading(data.text);
          if(data.done){
            finished=true;
            if(data.redirect)location.href=data.redirect;
            else location.reload();
          }
        }
        function pump(){
          return reader.read().then(function(result){
            if(result.done){
              if(buffer.trim()){try{handleLine(buffer);}catch(e){}}
              if(!finished)location.reload();
              return;
            }
            buffer+=decoder.decode(result.value,{stream:true});
            var lines=buffer.split("\\n");
            buffer=lines.pop();
            lines.forEach(function(line){try{handleLine(line);}catch(e){}});
            if(!finished)return pump();
          });
        }
        return pump();
      })
      .catch(function(error){
        showLoading(error&&error.message?error.message:"The update failed.");
      });
    return true;
  }
  function onWork(form){
    form.addEventListener("submit",function(event){
      if(startProgress(form,event))return;
      if(!event.defaultPrevented)showLoading();
    });
  }
  document.querySelectorAll("a.slow,a[href=\\"epg.php\\"],a[href^=\\"epg-mapping.php\\"]").forEach(function(link){
    link.addEventListener("click",function(event){
      if(event.defaultPrevented||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||link.target==="_blank")return;
      showLoading();
    });
  });
  if(/(?:^|\\/)(?:epg(?:-mapping)?|eit)\\.php$/.test(location.pathname)){
    document.querySelectorAll("form").forEach(onWork);
  }
  document.querySelectorAll("form.slow,form[action=\\"create-all.php\\"]").forEach(onWork);
});
</script>';
}

function appChrome(): void
{
    echo '<div id="loading"><div class="spinner" aria-hidden="true"></div><p id="loading-text">Loading…</p></div>';
    echo '<div id="help">';
    echo '<div class="dialog" role="dialog" aria-modal="true" aria-labelledby="help-title">';
    echo '<h2 id="help-title">How this works</h2>';
    $admin = function_exists('authIsAdmin') && authIsAdmin();
    if ($admin) {
        echo '<p>This site connects to your Enigma2 receiver through OpenWebIF and reads all the bouquets and channels. It turns them into a playlist for an IPTV player, and adds a programme guide. Each bouquet becomes a category in that list.</p>';
        echo '<p>Publishing a house stores a personal <code>channels.m3u8</code> on this server. Paste that address into an IPTV player. Playback goes straight to the receiver.</p>';
        echo '<ol>';
        echo '<li>Enter the receiver address next to the house and save.</li>';
        echo '<li>Copy the M3U URL (<code>channels.m3u8</code>) into TiviMate as a playlist. Its first line points at the guide, so updating the playlist also loads the EPG.</li>';
        echo '<li>Copy the EPG URL into TiviMate as an XMLTV source. Use the <code>.gz</code> address.</li>';
        echo '<li>TiviMate matches the guide with <code>tvg-id</code>. That id is the Rytec channel id, for example <code>NPO1.nl</code>. Playback goes straight to the receiver.</li>';
        echo '</ol>';
        echo '<p><strong>Download this source</strong> fetches only that country file. <strong>Download all</strong> fetches every enabled source, matches your channels, and rebuilds the guide. The spinner shows the current step.</p>';
        echo '<p>Logos come from the tv-logos collection in <code>logos/</code>. An exact filename wins, such as <code>npo1-nl.png</code> for <code>NPO1.nl</code>. Otherwise the logo that shares the most words with the channel name is used. Short pieces such as <code>Jr</code> are ignored, so Veronica / Disney Jr. uses <code>veronica-disney-xd-nl.png</code>. A channel with no matching file stays without a logo. The same address is the guide icon, the playlist <code>tvg-logo</code>, and the Xtream stream icon.</p>';
        echo '<p>On EPG mapping you can correct a wrong link. A manual link is kept and always wins over automatic matching.</p>';
        echo '<p>The guide refreshes once per interval when the playlist or EPG is requested. On Linux, <code>xtream-update.php</code> does the nightly download and Xtream rebuild, and rebuilds the guide of every published house from those new programmes. The house channel list stays as it was published. The public address is remembered from the last time the site was opened.</p>';
        echo '<p><strong>Xtream Codes:</strong> press <strong>Xtream</strong> next to EPG mapping. Build the list there, then in the IPTV player add a playlist, choose Xtream Codes, and paste the server URL, username and password. Each bouquet is a category, in the same order as on the receiver. The player asks this server for the stream, and the server redirects to the receiver.</p>';
        echo '<p>More detail is in <code>README.md</code>.</p>';
    } else {
        echo '<p>Each house keeps a personal channel list on this server. Paste that address into an IPTV player. The player has to be on the same network as your receiver. Playback goes straight to the receiver.</p>';
        echo '<p>The guide is stored beside the playlist and refreshes each night. The channel list changes only when you publish again. Other accounts do not see your houses.</p>';
        echo '<ol>';
        echo '<li>Add a house. The address uses the house name, for example <code>/u/kilder/channels.m3u8</code>.</li>';
        echo '<li>Drag <strong>Publish house</strong> to the bookmarks bar. Do not click it on this website.</li>';
        echo '<li>On the receiver’s network, open its web page in this browser, for example <code>http://192.168.1.10</code>.</li>';
        echo '<li>Click <strong>Publish house</strong> on that page. The browser reads the channels through OpenWebIF and sends them here.</li>';
        echo '<li>Copy the channels address and the guide address into your IPTV player. The bouquets become the categories.</li>';
        echo '</ol>';
    }
    echo '<button class="btn primary" type="button" data-close-help>Close</button>';
    echo '</div></div>';
    if ($admin) {
        appXtreamModal();
    }
}

function appXtreamModal(): void
{
    require_once __DIR__ . '/xtream.lib.php';
    $xtream = xtreamSettings();
    $stats = ['categories' => 0, 'channels' => 0, 'epg_error' => '', 'built_at' => null, 'build_error' => ''];
    $loadError = null;
    try {
        $stats = xtreamStats();
    } catch (Throwable $e) {
        $loadError = authHiddenError($e);
    }
    $return = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    if (!in_array($return, ['index.php', 'epg.php', 'epg-mapping.php'], true)) {
        $return = 'index.php';
    }
    $base = appBaseUrl();
    echo '<div id="xtream">';
    echo '<div class="dialog" role="dialog" aria-modal="true" aria-labelledby="xtream-title">';
    echo '<h2 id="xtream-title">Xtream Codes</h2>';
    echo '<p>Build the channel list in the same order as the bouquets on the receiver, then add it in TiviMate as an Xtream Codes playlist. The server URL does not include <code>player_api.php</code>.</p>';
    if ($loadError !== null) {
        echo '<p class="error">' . h($loadError) . '</p>';
    }
    if (isset($_GET['xtream'])) {
        echo '<p class="oknote">Xtream catalog built.</p>';
    }
    if ($stats['build_error'] !== '') {
        echo '<p class="error">' . h($stats['build_error']) . '</p>';
    }
    if ($stats['epg_error'] !== '') {
        echo '<p class="error">' . h($stats['epg_error']) . '</p>';
    }
    echo '<form method="post" action="xtream-build.php" class="stack slow">';
    echo '<input type="hidden" name="build_xtream" value="1">';
    echo '<input type="hidden" name="return" value="' . h($return) . '">';
    echo '<label class="field wide"><span>Username</span><input name="xtream_username" value="' . h($xtream['xtream_username']) . '" maxlength="64" required></label>';
    echo '<label class="field wide"><span>Password</span><input name="xtream_password" value="' . h($xtream['xtream_password']) . '" maxlength="64" autocomplete="off" placeholder="Generated on first build"></label>';
    echo '<button class="btn primary" type="submit">Build Xtream</button>';
    echo '</form>';
    echo '<p class="meta">Server URL</p><p class="url">' . h($base) . '</p>';
    echo '<button class="btn" type="button" data-copy="' . h($base) . '">Copy</button>';
    echo '<p class="meta">Username</p><p class="url">' . h($xtream['xtream_username']) . '</p>';
    echo '<button class="btn" type="button" data-copy="' . h($xtream['xtream_username']) . '">Copy</button>';
    if ($xtream['xtream_password'] !== '') {
        echo '<p class="meta">Password</p><p class="url">' . h($xtream['xtream_password']) . '</p>';
        echo '<button class="btn" type="button" data-copy="' . h($xtream['xtream_password']) . '">Copy</button>';
    }
    $built = $stats['built_at'] ? ' · built ' . h((string) $stats['built_at']) : '';
    echo '<p class="meta">' . (int) $stats['categories'] . ' categories · ' . (int) $stats['channels'] . ' channels' . $built . '</p>';
    echo '<button class="btn" type="button" data-close-xtream>Close</button>';
    echo '</div></div>';
}

function mediaUrls(): array
{
    $base = appBaseUrl();

    return [
        'm3u' => $base . '/channels.m3u8',
        'playlist' => $base . '/playlist.php',
        'epg' => $base . '/epg.xml.gz',
        'epg_plain' => $base . '/epg.xml',
        'eit' => $base . '/epg-eit.xml.gz',
    ];
}

function webifGet(string $path, int $timeout = 90): string
{
    $settings = receiverSettings();
    $url = 'http://' . $settings['host'] . ':' . $settings['webif_port'] . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('No connection to the receiver: ' . $error);
    }
    if ($code !== 200) {
        throw new RuntimeException('The receiver responded with HTTP ' . $code);
    }

    return $body;
}

function webifJson(string $path, int $timeout = 90): array
{
    $data = json_decode(webifGet($path, $timeout), true);
    if (!is_array($data)) {
        throw new RuntimeException('The receiver did not return valid JSON.');
    }

    return $data;
}

/**
 * @return list<array{ref: string, name: string, stream: bool}>
 */
function fetchBouquets(): array
{
    $data = webifJson('/api/bouquets');
    $rows = $data['bouquets'] ?? [];
    $bouquets = [];

    foreach ($rows as $row) {
        if (!is_array($row) || count($row) < 2) {
            continue;
        }
        $ref = (string) $row[0];
        $name = trim((string) $row[1]);
        if ($ref === '' || $name === '') {
            continue;
        }
        $bouquets[] = [
            'ref' => $ref,
            'name' => $name,
            'stream' => strncmp($name, 'Stream ', 7) === 0,
        ];
    }

    return $bouquets;
}

function fetchAllServices(): array
{
    return webifJson('/api/getallservices');
}

function isMarker(string $sref): bool
{
    $parts = explode(':', $sref);

    return isset($parts[1]) && $parts[1] === '64';
}

function isStreamService(string $sref): bool
{
    $type = explode(':', $sref, 2)[0];

    return in_array($type, ['4097', '5001', '5002', '8193'], true);
}

function directStreamUrl(string $sref, string $name): ?string
{
    if (!isStreamService($sref)) {
        return null;
    }

    $parts = explode(':', $sref);
    if (count($parts) < 11) {
        return null;
    }

    $decoded = rawurldecode(implode(':', array_slice($parts, 10)));
    $name = trim($name);
    if ($name !== '' && str_ends_with($decoded, ':' . $name)) {
        $decoded = substr($decoded, 0, -strlen(':' . $name));
    } else {
        $pos = strrpos($decoded, ':');
        if ($pos !== false) {
            $candidate = substr($decoded, 0, $pos);
            if (isDirectUrl($candidate)) {
                $decoded = $candidate;
            }
        }
    }

    $decoded = trim($decoded);

    return isDirectUrl($decoded) ? $decoded : null;
}

function isDirectUrl(string $url): bool
{
    return (bool) preg_match('#^(https?|rtmp|rtmps|rtsp|mms|mmsh)://#i', $url);
}

function encodeServiceRef(string $sref): string
{
    $encoded = preg_replace_callback(
        '/[^A-Za-z0-9:\/._~%-]|%(?![0-9A-Fa-f]{2})/',
        static function (array $match): string {
            return rawurlencode($match[0]);
        },
        $sref
    );

    return $encoded ?? '';
}

function receiverStreamUrl(string $sref): string
{
    $settings = receiverSettings();

    return 'http://' . $settings['host'] . ':' . $settings['stream_port'] . '/' . encodeServiceRef($sref);
}

function playbackKind(string $url, string $source): string
{
    $lower = strtolower($url);
    if (preg_match('#^(rtmp|rtmps|rtsp|mms|mmsh)://#', $lower)) {
        return 'external';
    }
    if ($source === 'satellite') {
        return 'ts';
    }
    if (str_contains($lower, '.m3u8') || str_contains($lower, 'format=m3u8') || str_contains($lower, 'output=hls')) {
        return 'hls';
    }
    if (preg_match('#\.(mp4|m4v|webm)(\?|$)#', $lower)) {
        return 'file';
    }
    if (str_contains($lower, '.ts')) {
        return 'ts';
    }

    return 'hls';
}

/**
 * @return array{url: string, source: string, kind: string}|null
 */
function channelPlayback(string $sref, string $name): ?array
{
    if ($sref === '' || $name === '' || isMarker($sref)) {
        return null;
    }

    if (isStreamService($sref)) {
        $url = directStreamUrl($sref, $name);
        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'source' => 'stream',
            'kind' => playbackKind($url, 'stream'),
        ];
    }

    if (explode(':', $sref, 2)[0] !== '1') {
        return null;
    }

    return [
        'url' => receiverStreamUrl($sref),
        'source' => 'satellite',
        'kind' => 'ts',
    ];
}

function m3uText(string $value): string
{
    $value = str_replace(["\r", "\n", '"'], ['', '', "'"], $value);

    return trim($value);
}

function canonicalServiceRef(string $sref): string
{
    $parts = explode(':', trim(rawurldecode($sref)));
    if (count($parts) > 11) {
        $parts = array_slice($parts, 0, 11);
    }
    while (count($parts) < 11) {
        $parts[] = '';
    }
    for ($i = 2; $i <= 6; $i++) {
        if ($parts[$i] !== '' && ctype_xdigit($parts[$i])) {
            $parts[$i] = strtoupper($parts[$i]);
        }
    }

    return implode(':', array_slice($parts, 0, 10)) . ':';
}

function satelliteFromSref(string $sref): string
{
    $parts = explode(':', canonicalServiceRef($sref));
    if (($parts[0] ?? '') !== '1' || !ctype_xdigit($parts[6] ?? '')) {
        return '';
    }
    $position = (hexdec($parts[6]) >> 16) & 0xFFFF;
    if ($position <= 0 || $position > 3600) {
        return '';
    }
    $west = $position > 1800;
    $tenths = $west ? 3600 - $position : $position;
    $label = number_format($tenths / 10, 1, '.', '') . ($west ? '°W' : '°E');
    $known = [
        '19.2°E' => 'Astra 19.2°E',
        '23.5°E' => 'Astra 23.5°E',
        '28.2°E' => 'Astra 28.2°E',
        '13.0°E' => 'Hotbird 13.0°E',
    ];

    return $known[$label] ?? $label;
}

function playlistTvgIds(): array
{
    static $loaded = false;
    static $map = [];
    if (!empty($GLOBALS['playlist_tvg_reset'])) {
        $loaded = false;
        $map = [];
        unset($GLOBALS['playlist_tvg_reset']);
    }
    if ($loaded) {
        return $map;
    }
    $loaded = true;
    $file = __DIR__ . '/epg.lib.php';
    if (!is_file($file)) {
        return [];
    }
    require_once $file;
    try {
        $map = epgTvgIdMap();
    } catch (Throwable $e) {
        $map = [];
    }

    return $map;
}

/**
 * @param array<string, true>|null $selectedRefs null = alle bouquets
 * @return list<array{name: string, url: string, group: string}>
 */
function playlistRows(array $services, ?array $selectedRefs, string $type): array
{
    $rows = [];
    foreach ($services['services'] ?? [] as $bouquet) {
        if (!is_array($bouquet)) {
            continue;
        }
        $bouquetRef = (string) ($bouquet['servicereference'] ?? '');
        if ($selectedRefs !== null && !isset($selectedRefs[$bouquetRef])) {
            continue;
        }
        $group = m3uText((string) ($bouquet['servicename'] ?? ''));
        if ($group === '') {
            $group = 'Bouquet';
        }
        foreach ($bouquet['subservices'] ?? [] as $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $rawRef = (string) ($channel['servicereference'] ?? '');
            $name = m3uText((string) ($channel['servicename'] ?? ''));
            $play = channelPlayback($rawRef, $name);
            if ($play === null) {
                continue;
            }
            if ($type === 'stream' && $play['source'] !== 'stream') {
                continue;
            }
            if ($type === 'tv' && $play['source'] !== 'satellite') {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'match_name' => $name,
                'sref' => canonicalServiceRef($rawRef),
                'url' => $play['url'],
                'group' => $group,
                'bouquet_ref' => $bouquetRef !== '' ? $bouquetRef : 'group:' . $group,
                'satellite' => satelliteFromSref($rawRef),
            ];
        }
    }

    return disambiguatePlaylistRows($rows);
}

/**
 * Dezelfde stream in meerdere bouquets krijgt een eigen naam en adres,
 * zodat een speler die dubbele adressen weglaat ze allemaal houdt.
 *
 * @param list<array{name: string, url: string, group: string}> $rows
 * @return list<array{name: string, url: string, group: string}>
 */
function disambiguatePlaylistRows(array $rows): array
{
    $counts = [];
    foreach ($rows as $row) {
        $counts[$row['url']] = ($counts[$row['url']] ?? 0) + 1;
    }

    $seen = [];
    foreach ($rows as $index => $row) {
        if ($counts[$row['url']] < 2) {
            continue;
        }
        $seen[$row['url']] = ($seen[$row['url']] ?? 0) + 1;
        $number = $seen[$row['url']];
        $rows[$index]['name'] = $row['name'] . $number;
        $rows[$index]['url'] = $row['url'] . '#e2=' . $number;
    }

    return $rows;
}

/**
 * @param array<string, true>|null $selectedRefs null = alle bouquets
 */
function writePlaylist(array $services, ?array $selectedRefs, string $type, ?string $epgUrl = null): int
{
    $epg = $epgUrl ?? mediaUrls()['epg'];
    echo '#EXTM3U url-tvg="' . $epg . '" x-tvg-url="' . $epg . "\"\n";
    $count = 0;
    $tvgIds = playlistTvgIds();
    foreach (playlistRows($services, $selectedRefs, $type) as $row) {
        $name = $row['name'];
        $attrs = '';
        $tvgId = $tvgIds[$row['sref'] ?? ''] ?? '';
        $logo = $tvgId !== '' ? logoUrlForXmltvId($tvgId, [$name]) : '';
        if ($logo === '') {
            $logo = logoUrlForLabel($name);
        }
        if ($tvgId !== '') {
            $attrs .= ' tvg-id="' . m3uText($tvgId) . '"';
        }
        if ($logo !== '') {
            $attrs .= ' tvg-logo="' . m3uText($logo) . '"';
        }
        echo '#EXTINF:-1' . $attrs . ' tvg-name="' . $name . '" group-title="' . $row['group'] . '",' . $name . "\n";
        echo $row['url'] . "\n";
        $count++;
    }

    return $count;
}

function publishedPlaylistPath(): string
{
    return __DIR__ . '/data/channels.m3u8';
}

function savePublishedPlaylist(string $body): void
{
    $path = publishedPlaylistPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        return;
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
    }
}

/**
 * @return list<array{name: string, url: string, source: string, kind: string}>
 */
function channelsForBouquet(string $ref): array
{
    $known = false;
    foreach (fetchBouquets() as $bouquet) {
        if ($bouquet['ref'] === $ref) {
            $known = true;
            break;
        }
    }
    if (!$known) {
        throw new InvalidArgumentException('This bouquet is not on the receiver.');
    }

    $data = webifJson('/api/getservices?sRef=' . rawurlencode($ref));
    $channels = [];
    foreach ($data['services'] ?? [] as $channel) {
        if (!is_array($channel)) {
            continue;
        }
        $name = trim((string) ($channel['servicename'] ?? ''));
        $play = channelPlayback((string) ($channel['servicereference'] ?? ''), $name);
        if ($play === null) {
            continue;
        }
        $channels[] = [
            'name' => $name,
            'url' => $play['url'],
            'source' => $play['source'],
            'kind' => $play['kind'],
        ];
    }

    return $channels;
}

function appBaseUrl(): string
{
    $configured = normalizePublicUrl(envValue('PUBLIC_URL'));
    if ($configured !== '') {
        return $configured;
    }
    $stored = normalizePublicUrl((string) (loadSettingsRaw()['public_base'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        return requestBaseUrl();
    }

    return 'http://openwebif.test';
}

function normalizePublicUrl(string $url): string
{
    $url = rtrim(trim($url), '/');
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (($scheme !== 'http' && $scheme !== 'https') || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    if (isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '');
    if ($path === '/') {
        $path = '';
    }

    return $scheme . '://' . $host . $port . $path;
}

function requestBaseUrl(): string
{
    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) $_SERVER['HTTP_HOST'];
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim($dir, '/');
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }

    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function logoDir(): string
{
    return __DIR__ . '/logos';
}

/** @return array<string, string> basename => path under logos/ */
function logoIndex(): array
{
    static $index = null;
    if (is_array($index)) {
        return $index;
    }
    $index = [];
    $root = logoDir();
    if (!is_dir($root)) {
        return $index;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        if (strcasecmp($file->getExtension(), 'png') !== 0) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $name = strtolower($file->getFilename());
        $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $prefer = !isset($index[$name])
            || (str_starts_with($relative, 'countries/') && !str_starts_with($index[$name], 'countries/'));
        if ($prefer) {
            $index[$name] = $relative;
        }
    }

    return $index;
}

/** @return array{0: string, 1: string}|null */
function logoIdParts(string $id): ?array
{
    $id = strtolower(trim($id));
    $dot = strrpos($id, '.');
    if ($dot === false || $dot === 0 || $dot === strlen($id) - 1) {
        return null;
    }
    $name = preg_replace('/[^a-z0-9]+/', '', substr($id, 0, $dot)) ?? '';
    $country = preg_replace('/[^a-z0-9]+/', '', substr($id, $dot + 1)) ?? '';
    if ($name === '' || $country === '') {
        return null;
    }

    return [$name, $country];
}

/** @return list<string> */
function logoWords(string $text): array
{
    $parts = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        return [];
    }
    $noise = ['hd' => true, 'sd' => true, 'uhd' => true, 'tv' => true, 'channel' => true];
    $words = [];
    foreach ($parts as $part) {
        if (isset($noise[$part]) || ctype_digit($part) || strlen($part) < 3) {
            continue;
        }
        $words[$part] = true;
    }

    return array_keys($words);
}

/**
 * @param list<array{file: string, words: list<string>}> $logos
 * @param list<string> $channelWords
 */
function logoPickByWords(array $channelWords, array $logos): ?string
{
    if ($channelWords === [] || $logos === []) {
        return null;
    }
    $want = array_fill_keys($channelWords, true);
    $bestFile = null;
    $bestScore = 0;
    $bestExtra = PHP_INT_MAX;
    $tied = false;
    $sharedWord = '';
    foreach ($logos as $logo) {
        $score = 0;
        $only = '';
        foreach ($logo['words'] as $word) {
            if (isset($want[$word])) {
                $score++;
                $only = $word;
            }
        }
        if ($score === 0) {
            continue;
        }
        $extra = count($logo['words']) - $score;
        $file = $logo['file'];
        $better = $bestFile === null
            || $score > $bestScore
            || ($score === $bestScore && $extra < $bestExtra)
            || ($score === $bestScore && $extra === $bestExtra && $file < $bestFile);
        if ($better) {
            $tied = false;
            $bestScore = $score;
            $bestExtra = $extra;
            $bestFile = $file;
            $sharedWord = $score === 1 ? $only : '';
        } elseif ($score === $bestScore && $extra === $bestExtra) {
            $tied = true;
        }
    }
    if ($bestFile === null || $bestScore < 1) {
        return null;
    }
    if ($bestScore >= 2) {
        return $bestFile;
    }
    if ($tied || $sharedWord === '') {
        return null;
    }
    $owners = 0;
    foreach ($logos as $logo) {
        if (in_array($sharedWord, $logo['words'], true)) {
            $owners++;
            if ($owners > 1) {
                return null;
            }
        }
    }

    return $bestFile;
}

/** @return array<string, list<array{file: string, words: list<string>}>> */
function logoCountryLogos(): array
{
    static $byCountry = null;
    if (is_array($byCountry)) {
        return $byCountry;
    }
    $byCountry = [];
    foreach (logoIndex() as $basename => $relative) {
        if (preg_match('/^(.+)-([a-z0-9]+)\.png$/', $basename, $match) !== 1) {
            continue;
        }
        $words = logoWords($match[1]);
        if ($words === []) {
            continue;
        }
        $byCountry[$match[2]][] = [
            'file' => $relative,
            'words' => $words,
        ];
    }

    return $byCountry;
}

/** @return array<string, list<string>> */
function logoChannelLabels(): array
{
    static $loaded = false;
    static $labels = [];
    if ($loaded) {
        return $labels;
    }
    $loaded = true;
    if (!function_exists('epgDb')) {
        $file = __DIR__ . '/epg.lib.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
    if (!function_exists('epgDb')) {
        return $labels;
    }
    try {
        foreach (epgDb()->query('SELECT xmltv_id, display_name, alt_names FROM epg_channels') as $row) {
            $id = (string) $row['xmltv_id'];
            $labels[$id][] = (string) $row['display_name'];
            $alts = json_decode((string) $row['alt_names'], true);
            if (!is_array($alts)) {
                continue;
            }
            foreach ($alts as $alt) {
                if (is_string($alt) && $alt !== '') {
                    $labels[$id][] = $alt;
                }
            }
        }
        foreach (epgDb()->query("SELECT xmltv_id, service_name FROM epg_mappings
            WHERE xmltv_id IS NOT NULL AND xmltv_id != ''") as $row) {
            $labels[(string) $row['xmltv_id']][] = (string) $row['service_name'];
        }
    } catch (Throwable $e) {
        $labels = [];
    }

    return $labels;
}

function logoFileForXmltvId(string $id, array $labels = []): ?string
{
    $parts = logoIdParts($id);
    if ($parts === null) {
        return null;
    }
    [$name, $country] = $parts;
    $index = logoIndex();
    $exact = $index[$name . '-' . $country . '.png'] ?? null;
    if ($exact !== null) {
        return $exact;
    }
    $words = [];
    foreach (array_merge($labels, logoChannelLabels()[trim($id)] ?? []) as $label) {
        foreach (logoWords((string) $label) as $word) {
            $words[$word] = true;
        }
    }

    return logoPickByWords(array_keys($words), logoCountryLogos()[$country] ?? []);
}

function logoFileForLabel(string $label): ?string
{
    $words = logoWords($label);
    if ($words === []) {
        return null;
    }
    $want = array_fill_keys($words, true);
    $bestFile = null;
    $bestScore = 0;
    $bestExtra = PHP_INT_MAX;
    $bestCountryRank = PHP_INT_MAX;
    $all = [];
    foreach (logoCountryLogos() as $country => $logos) {
        $countryRank = $country === 'nl' ? 0 : 1;
        foreach ($logos as $logo) {
            $all[] = $logo;
            $score = 0;
            foreach ($logo['words'] as $word) {
                if (isset($want[$word])) {
                    $score++;
                }
            }
            if ($score < 2) {
                continue;
            }
            $extra = count($logo['words']) - $score;
            $file = $logo['file'];
            $better = $bestFile === null
                || $score > $bestScore
                || ($score === $bestScore && $extra < $bestExtra)
                || ($score === $bestScore && $extra === $bestExtra && $countryRank < $bestCountryRank)
                || ($score === $bestScore && $extra === $bestExtra && $countryRank === $bestCountryRank && $file < $bestFile);
            if ($better) {
                $bestFile = $file;
                $bestScore = $score;
                $bestExtra = $extra;
                $bestCountryRank = $countryRank;
            }
        }
    }
    if ($bestFile !== null) {
        return $bestFile;
    }

    return logoPickByWords($words, $all);
}

function logoUrlForFile(?string $file): string
{
    if ($file === null || $file === '') {
        return '';
    }
    $parts = array_map('rawurlencode', explode('/', $file));

    return appBaseUrl() . '/logos/' . implode('/', $parts);
}

function logoUrlForXmltvId(string $id, array $labels = []): string
{
    return logoUrlForFile(logoFileForXmltvId($id, $labels));
}

function logoUrlForLabel(string $label): string
{
    return logoUrlForFile(logoFileForLabel($label));
}
