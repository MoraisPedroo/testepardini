<?php
/* =============================================================================
 * PORTAL DE TESTE DE IMPRESSAO  -  Rede de Laboratorios
 * -----------------------------------------------------------------------------
 * Arquivo unico: back-end (PHP) + interface (HTML/Tailwind) + integracoes (JS).
 *
 * Aplicacoes suportadas:
 *   ZBP        -> Zebra Browser Print (servico local, porta fixa 9100)
 *   GTI        -> WebClientPrint / WCPP (gera arquivo de spool no servidor)
 *   DPPrinter  -> middleware Node.js local (POST via fetch em 127.0.0.1:<porta>)
 *
 * Requisitos: PHP 5.6+ (compativel ate 8.x). Pasta ./spool com permissao de escrita.
 * ========================================================================== */

error_reporting(E_ALL);
ini_set('display_errors', '0');   // evita quebrar o JSON das chamadas AJAX
set_time_limit(0);

/* ------------------------- Sessao / token (legado) ------------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'cli';
    session_name(md5('seg' . $ip . $ua));
    session_cache_expire(10);
    session_start();
}

if (empty($_SESSION['token'])) {
    $_SESSION['token'] = md5(time() . mt_rand());
}

/* --------------------- SDK WebClientPrint (opcional) ---------------------- */
/* Se o SDK da Neodynamic estiver na pasta ele e carregado; caso contrario o
 * portal continua funcionando e apenas gera o arquivo de spool.             */
$WCP_DISPONIVEL = false;
if (file_exists(__DIR__ . '/WebClientPrint.php')) {
    include_once __DIR__ . '/WebClientPrint.php';
    $WCP_DISPONIVEL = class_exists('Neodynamic\SDK\Web\WebClientPrint');
}

/* ------------------------------ Configuracao ------------------------------ */
define('PP_SPOOL_DIR', __DIR__ . '/spool');
define('PP_SPOOL_TTL', 3600);      // segundos que um arquivo de spool sobrevive
define('PP_QT_MAX',    50);        // trava de seguranca para a quantidade

/* --------------------------- Geracao do payload --------------------------- */
/* ATENCAO: estes dois templates sao espelhados em JS (const PAYLOADS, abaixo).
 * Se alterar um, altere o outro. O servidor NUNCA grava texto vindo do
 * cliente - ele sempre regera o payload a partir de linguagem + quantidade.  */
function pp_payload($linguagem, $qt)
{
    $qt = intval($qt);
    if ($qt <= 0)        { $qt = 1; }
    if ($qt > PP_QT_MAX) { $qt = PP_QT_MAX; }

    if (strtoupper($linguagem) === 'EPL') {
        return "N\n"
             . "R30,10\n"
             . "D8\n"
             . "A210,0,0,1,1,1,N,\"TESTE TH\"\n"
             . "B285,65,0,2,2,4,70,N,\"200558309501\"\n"
             . "P{$qt}\n"
             . "N\n";
    }

    return "^XA^MMT^PW400^LL240^LS0"
         . "^FT38,221^A0N,40,41^FH\\^CI28^FDTeste de Impressão^FS^CI27"
         . "^PQ{$qt},0,1,Y^XZ";
}

/* Remove spools antigos para a pasta nao crescer indefinidamente. */
function pp_limpar_spool()
{
    if (!is_dir(PP_SPOOL_DIR)) { return; }
    $limite = time() - PP_SPOOL_TTL;
    foreach (glob(PP_SPOOL_DIR . '/*.txt') as $arq) {
        if (filemtime($arq) < $limite) { @unlink($arq); }
    }
}

function pp_json($dados, $http = 200)
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ============================ ENDPOINT AJAX ==============================
 * action=wcpp -> valida o token, gera o arquivo de spool e devolve o caminho.
 * Mantem exatamente a regra do codigo legado (sessao + token + ./spool).
 * ======================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {

    $token = isset($_POST['token']) ? $_POST['token'] : '';
    if (empty($token) || !hash_equals((string) $_SESSION['token'], (string) $token)) {
        pp_json(array('ok' => false, 'erro' => 'Token invalido ou sessao expirada. Atualize a pagina (F5) e tente novamente.'), 403);
    }

    if ($_POST['action'] !== 'wcpp') {
        pp_json(array('ok' => false, 'erro' => 'Acao desconhecida.'), 400);
    }

    $qt = isset($_POST['qt']) ? intval($_POST['qt']) : 1;
    if ($qt <= 0)        { $qt = 1; }
    if ($qt > PP_QT_MAX) { $qt = PP_QT_MAX; }

    $linguagem = (isset($_POST['linguagem']) && strtoupper($_POST['linguagem']) === 'EPL') ? 'EPL' : 'ZPL';
    $porta     = isset($_POST['porta']) ? preg_replace('/\D/', '', $_POST['porta']) : '';

    /* ---- GTI / WebClientPrint: grava o arquivo de spool (logica legada) ---- */
    $print_data = pp_payload($linguagem, $qt);

    if (!is_dir(PP_SPOOL_DIR)) {
        @mkdir(PP_SPOOL_DIR, 0775, true);
    }
    if (!is_dir(PP_SPOOL_DIR) || !is_writable(PP_SPOOL_DIR)) {
        pp_json(array('ok' => false, 'erro' => 'A pasta ./spool nao existe ou nao tem permissao de escrita no servidor.'), 500);
    }

    pp_limpar_spool();

    $nome_arquivo = date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.txt';
    $caminho      = PP_SPOOL_DIR . '/' . $nome_arquivo;

    $file = @fopen($caminho, 'w');
    if (!$file) {
        pp_json(array('ok' => false, 'erro' => 'Nao foi possivel criar o arquivo de spool: ' . $nome_arquivo), 500);
    }
    fwrite($file, $print_data);
    fclose($file);

    pp_json(array(
        'ok'        => true,
        'arquivo'   => $nome_arquivo,
        'url'       => 'spool/' . $nome_arquivo,
        'bytes'     => strlen($print_data),
        'linguagem' => $linguagem,
        'porta'     => $porta,
        'qt'        => $qt,
        'sessao'    => session_id(),
        'sdk'       => $WCP_DISPONIVEL,
    ));
}

/* ------------------ Configuracao entregue ao front-end -------------------- */
$CFG = array(
    'mode'          => 'php',
    'token'         => $_SESSION['token'],
    'endpoint'      => basename(__FILE__),
    'wcpDisponivel' => $WCP_DISPONIVEL,
);
?>
<!doctype html>
<html lang="pt-BR" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Portal de Teste de Impressão</title>
<meta name="description" content="Teste de comunicação com impressoras térmicas e download dos instaladores (ZBP, GTI/WCPP e DPPrinter).">
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          marca: {
            50:'#effaf6',100:'#d7f2e7',200:'#b1e5d2',300:'#7dd0b7',
            400:'#48b498',500:'#26997e',600:'#177a66',700:'#136254',
            800:'#124e44',900:'#104039'
          }
        },
        fontFamily: { sans: ['Inter','Segoe UI','system-ui','sans-serif'] }
      }
    }
  };
</script>
<style>
  body { font-family: Inter, "Segoe UI", system-ui, sans-serif; }
  /* Destaque do card de aplicacao selecionado */
  .app-card:has(input:checked) { border-color:#177a66; background-color:#effaf6; }
  .app-card:has(input:focus-visible) { outline:2px solid #177a66; outline-offset:2px; }
  #log::-webkit-scrollbar { width:10px; }
  #log::-webkit-scrollbar-thumb { background:#334155; border-radius:9999px; }
</style>
</head>
<body class="h-full bg-slate-100 text-slate-800 antialiased">

<!-- ============================== CABECALHO ============================== -->
<header class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
  <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 sm:px-6">
    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-marca-600 text-white shadow-sm">
      <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829V7.5a1.5 1.5 0 011.5-1.5h7.56a1.5 1.5 0 011.5 1.5v6.329M6.72 13.829H5.25A2.25 2.25 0 003 16.079v1.921a1.5 1.5 0 001.5 1.5h1.22m1-5.671h10.56m0 0h1.47a2.25 2.25 0 012.25 2.25V18a1.5 1.5 0 01-1.5 1.5h-1.22m-11.56-5.671V19.5A1.5 1.5 0 008.22 21h7.56a1.5 1.5 0 001.5-1.5v-5.671"/>
      </svg>
    </div>
    <div class="min-w-0">
      <h1 class="truncate text-base font-semibold text-slate-900 sm:text-lg">Portal de Teste de Impressão</h1>
      <p class="truncate text-xs text-slate-500">Impressoras térmicas &middot; ZBP, GTI e DPPrinter em um só lugar</p>
    </div>
    <a href="#downloads" class="ml-auto hidden items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 sm:inline-flex">
      <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-6L12 15m0 0l4.5-4.5M12 15V3"/>
      </svg>
      Baixar programas
    </a>
  </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8">

  <!-- Aviso do modo estatico (Vercel) - escondido quando servido por PHP -->
  <div id="avisoEstatico" class="mb-6 hidden rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
    <p class="font-semibold">Modo estático (sem PHP)</p>
    <p class="mt-1">Os testes de <strong>ZBP</strong> e <strong>DPPrinter</strong> funcionam normalmente porque rodam na própria máquina.
    O teste <strong>GTI (WCPP)</strong> precisa do servidor PHP para gerar o arquivo de spool &mdash; informe a URL do servidor em <em>Opções avançadas</em> ou acesse o portal pelo servidor interno.</p>
  </div>

  <div class="grid gap-6 lg:grid-cols-5">

    <!-- ========================= COLUNA: PASSO A PASSO ==================== -->
    <form id="formTeste" class="lg:col-span-3 space-y-5" novalidate>

      <!-- ---------------------------- PASSO 1 -------------------------- -->
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex items-center gap-3">
          <span class="flex h-7 w-7 items-center justify-center rounded-full bg-marca-600 text-sm font-bold text-white">1</span>
          <h2 class="text-base font-semibold text-slate-900">Escolha a aplicação que vai testar</h2>
        </div>

        <fieldset class="grid gap-3 sm:grid-cols-3">
          <legend class="sr-only">Aplicação</legend>

          <label class="app-card cursor-pointer rounded-xl border-2 border-slate-200 bg-white p-4 transition hover:border-marca-300 hover:bg-marca-50">
            <input type="radio" name="aplicacao" value="ZBP" class="sr-only" checked>
            <div class="flex items-start gap-3">
              <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-900 text-xs font-bold text-white">ZBP</span>
              <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900">Zebra Browser Print</p>
                <p class="mt-0.5 text-xs leading-snug text-slate-500">Serviço oficial da Zebra instalado na estação.</p>
                <span class="mt-2 inline-block rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">Porta fixa 9100</span>
              </div>
            </div>
          </label>

          <label class="app-card cursor-pointer rounded-xl border-2 border-slate-200 bg-white p-4 transition hover:border-marca-300 hover:bg-marca-50">
            <input type="radio" name="aplicacao" value="GTI" class="sr-only">
            <div class="flex items-start gap-3">
              <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-xs font-bold text-white">GTI</span>
              <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900">WebClientPrint</p>
                <p class="mt-0.5 text-xs leading-snug text-slate-500">Gera o spool no servidor e o WCPP imprime.</p>
                <span class="mt-2 inline-block rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">Porta editável</span>
              </div>
            </div>
          </label>

          <label class="app-card cursor-pointer rounded-xl border-2 border-slate-200 bg-white p-4 transition hover:border-marca-300 hover:bg-marca-50">
            <input type="radio" name="aplicacao" value="DPPRINTER" class="sr-only">
            <div class="flex items-start gap-3">
              <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-marca-600 text-xs font-bold text-white">DP</span>
              <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900">DPPrinter</p>
                <p class="mt-0.5 text-xs leading-snug text-slate-500">Novo middleware local em Node.js.</p>
                <span class="mt-2 inline-block rounded-md bg-marca-100 px-2 py-0.5 text-[11px] font-medium text-marca-800">Padrão 3000</span>
              </div>
            </div>
          </label>
        </fieldset>

        <p id="dicaApp" class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600"></p>
      </section>

      <!-- ---------------------------- PASSO 2 -------------------------- -->
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex items-center gap-3">
          <span class="flex h-7 w-7 items-center justify-center rounded-full bg-marca-600 text-sm font-bold text-white">2</span>
          <h2 class="text-base font-semibold text-slate-900">Configure a porta e a etiqueta</h2>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
          <div>
            <label for="porta" class="block text-sm font-medium text-slate-700">Porta</label>
            <input id="porta" name="porta" type="number" inputmode="numeric" min="1" max="65535" value="9100"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none transition focus:border-marca-500 focus:ring-2 focus:ring-marca-200 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500">
            <p id="portaAviso" class="mt-1 text-xs text-slate-500">&nbsp;</p>
          </div>

          <div>
            <label for="qt" class="block text-sm font-medium text-slate-700">Quantidade de etiquetas</label>
            <input id="qt" name="qt" type="number" inputmode="numeric" min="1" max="50" value="1"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none transition focus:border-marca-500 focus:ring-2 focus:ring-marca-200">
            <p class="mt-1 text-xs text-slate-500">Máximo de 50 por envio.</p>
          </div>

          <div>
            <span class="block text-sm font-medium text-slate-700">Linguagem</span>
            <div class="mt-1 grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1">
              <label class="cursor-pointer">
                <input type="radio" name="linguagem" value="ZPL" class="peer sr-only" checked>
                <span class="block rounded-md px-3 py-1.5 text-center text-sm font-medium text-slate-600 transition peer-checked:bg-white peer-checked:text-marca-700 peer-checked:shadow-sm">ZPL</span>
              </label>
              <label class="cursor-pointer">
                <input type="radio" name="linguagem" value="EPL" class="peer sr-only">
                <span class="block rounded-md px-3 py-1.5 text-center text-sm font-medium text-slate-600 transition peer-checked:bg-white peer-checked:text-marca-700 peer-checked:shadow-sm">EPL</span>
              </label>
            </div>
            <p class="mt-1 text-xs text-slate-500">Na dúvida, use ZPL.</p>
          </div>
        </div>

        <!-- Opcoes avancadas -->
        <details class="mt-4 rounded-lg border border-slate-200 bg-slate-50">
          <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-slate-700">Opções avançadas</summary>
          <div class="grid gap-4 border-t border-slate-200 p-3 sm:grid-cols-2">
            <div>
              <label for="impressora" class="block text-sm font-medium text-slate-700">Nome da impressora <span class="font-normal text-slate-400">(opcional)</span></label>
              <input id="impressora" type="text" placeholder="Ex.: ZDesigner GC420t"
                     class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-marca-500 focus:ring-2 focus:ring-marca-200">
              <p class="mt-1 text-xs text-slate-500">Em branco = impressora padrão.</p>
            </div>
            <div>
              <label for="endpoint" class="block text-sm font-medium text-slate-700">Caminho no DPPrinter</label>
              <input id="endpoint" type="text" value="/" placeholder="/"
                     class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-marca-500 focus:ring-2 focus:ring-marca-200">
              <p class="mt-1 text-xs text-slate-500">Padrão <code>/</code>. Use <code>/print</code> se o middleware exigir.</p>
            </div>
            <div>
              <label for="backend" class="block text-sm font-medium text-slate-700">URL do servidor PHP (GTI)</label>
              <input id="backend" type="text" placeholder="http://servidor-interno/testeimpressao/index.php"
                     class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-marca-500 focus:ring-2 focus:ring-marca-200">
              <p class="mt-1 text-xs text-slate-500">Só é necessário no modo estático (Vercel).</p>
            </div>
            <div class="flex items-start gap-2 sm:pt-7">
              <input id="rawBody" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-marca-600 focus:ring-marca-500">
              <label for="rawBody" class="text-sm text-slate-700">Enviar ao DPPrinter como <strong>texto puro</strong> (sem JSON)</label>
            </div>
          </div>
        </details>
      </section>

      <!-- ---------------------------- PASSO 3 -------------------------- -->
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex items-center gap-3">
          <span class="flex h-7 w-7 items-center justify-center rounded-full bg-marca-600 text-sm font-bold text-white">3</span>
          <h2 class="text-base font-semibold text-slate-900">Envie o teste</h2>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
          <button id="btnEnviar" type="submit"
                  class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-marca-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-marca-700 focus:outline-none focus:ring-2 focus:ring-marca-400 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-70">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829V7.5a1.5 1.5 0 011.5-1.5h7.56a1.5 1.5 0 011.5 1.5v6.329M6.72 13.829H5.25A2.25 2.25 0 003 16.079v1.921a1.5 1.5 0 001.5 1.5h1.22m1-5.671h10.56m0 0h1.47a2.25 2.25 0 012.25 2.25V18a1.5 1.5 0 01-1.5 1.5h-1.22m-11.56-5.671V19.5A1.5 1.5 0 008.22 21h7.56a1.5 1.5 0 001.5-1.5v-5.671"/>
            </svg>
            <span id="txtEnviar">Enviar teste de impressão</span>
          </button>
          <button id="btnPayload" type="button"
                  class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
            Ver código enviado
          </button>
        </div>

        <pre id="preview" class="mt-4 hidden max-h-56 overflow-auto rounded-lg bg-slate-900 p-3 text-xs leading-relaxed text-slate-100"></pre>
      </section>
    </form>

    <!-- ============================ COLUNA: LOG ========================== -->
    <aside class="lg:col-span-2">
      <div class="lg:sticky lg:top-24">
        <div id="alerta" class="mb-4 hidden rounded-xl border p-4 text-sm" role="status" aria-live="polite"></div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Log da operação</h2>
            <div class="flex gap-1">
              <button id="btnCopiar" type="button" class="rounded-md px-2 py-1 text-xs font-medium text-slate-600 transition hover:bg-slate-100">Copiar</button>
              <button id="btnLimpar" type="button" class="rounded-md px-2 py-1 text-xs font-medium text-slate-600 transition hover:bg-slate-100">Limpar</button>
            </div>
          </div>
          <div id="log" class="h-80 overflow-auto bg-slate-900 px-4 py-3 font-mono text-xs leading-relaxed text-slate-300" aria-live="polite"></div>
        </div>

        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <h3 class="mb-2 text-sm font-semibold text-slate-900">Não imprimiu? Confira</h3>
          <ul class="list-disc space-y-1 pl-5 text-xs text-slate-600">
            <li>O programa correspondente está instalado e em execução (ícone na bandeja do Windows).</li>
            <li>A impressora está ligada, com etiquetas e sem luz vermelha.</li>
            <li>A porta informada é a mesma configurada no programa.</li>
            <li>Antivírus ou firewall pode bloquear o serviço local &mdash; libere <code>127.0.0.1</code>.</li>
          </ul>
        </div>
      </div>
    </aside>
  </div>

  <!-- ============================= DOWNLOADS ============================= -->
  <section id="downloads" class="mt-10 scroll-mt-24">
    <div class="mb-4">
      <h2 class="text-lg font-semibold text-slate-900">Downloads dos programas</h2>
      <p class="text-sm text-slate-500">Baixe e instale apenas o programa da aplicação que a unidade utiliza.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">

      <article class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md">
        <span class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-slate-900 text-xs font-bold text-white">ZBP</span>
        <h3 class="text-sm font-semibold text-slate-900">Zebra Browser Print</h3>
        <p class="mt-1 flex-1 text-xs leading-relaxed text-slate-500">Serviço da Zebra que permite ao navegador conversar com a impressora pela porta 9100.</p>
        <a href="downloads/ZebraBrowserPrintSetup.exe" download
           class="mt-4 inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-6L12 15m0 0l4.5-4.5M12 15V3"/></svg>
          Baixar instalador
        </a>
      </article>

      <article class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md">
        <span class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-600 text-xs font-bold text-white">GTI</span>
        <h3 class="text-sm font-semibold text-slate-900">WebClientPrint (WCPP)</h3>
        <p class="mt-1 flex-1 text-xs leading-relaxed text-slate-500">Cliente que lê o arquivo de spool gerado pelo servidor e envia para a impressora.</p>
        <a href="downloads/WebClientPrintSetup.exe" download
           class="mt-4 inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-6L12 15m0 0l4.5-4.5M12 15V3"/></svg>
          Baixar instalador
        </a>
      </article>

      <article class="flex flex-col rounded-2xl border-2 border-marca-500 bg-white p-5 shadow-sm transition hover:shadow-md">
        <div class="mb-3 flex items-center justify-between">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-marca-600 text-xs font-bold text-white">DP</span>
          <span class="rounded-full bg-marca-100 px-2 py-0.5 text-[11px] font-semibold text-marca-800">Novo</span>
        </div>
        <h3 class="text-sm font-semibold text-slate-900">DPPrinter</h3>
        <p class="mt-1 flex-1 text-xs leading-relaxed text-slate-500">Middleware local em Node.js que substitui os anteriores. Recomendado para novas instalações.</p>
        <a href="downloads/DPPrinterSetup.exe" download
           class="mt-4 inline-flex items-center justify-center gap-2 rounded-lg bg-marca-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-marca-700">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-6L12 15m0 0l4.5-4.5M12 15V3"/></svg>
          Baixar instalador
        </a>
      </article>
    </div>

    <p class="mt-3 text-xs text-slate-500">
      Coloque os instaladores na pasta <code class="rounded bg-slate-200 px-1">downloads/</code> com estes nomes de arquivo (veja <code class="rounded bg-slate-200 px-1">downloads/LEIA-ME.txt</code>).
    </p>
  </section>

  <footer class="mt-10 border-t border-slate-200 pt-5 text-xs text-slate-500">
    Portal de Teste de Impressão &middot; Em caso de falha persistente, copie o log acima e abra o chamado para a equipe de TI.
  </footer>
</main>

<!-- SDK do Zebra Browser Print (opcional - coloque os arquivos em ./js) -->
<script src="js/BrowserPrint-3.1.250.min.js" onerror="void 0"></script>
<script src="js/BrowserPrint-Zebra-1.1.250.min.js" onerror="void 0"></script>

<script>window.PORTAL_CFG = <?php echo json_encode($CFG, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;</script>

<script>
/* =============================================================================
 * PORTAL DE TESTE DE IMPRESSAO - logica de interface e integracoes
 * ========================================================================== */
(function () {
  'use strict';

  var CFG = window.PORTAL_CFG || { mode: 'static', token: '', endpoint: '', wcpDisponivel: false };

  /* ------------------------- Payloads (espelho do PHP) --------------------
   * Mantenha identico a pp_payload() no topo deste arquivo.                */
  var PAYLOADS = {
    EPL: function (qt) {
      return 'N\n' +
             'R30,10\n' +
             'D8\n' +
             'A210,0,0,1,1,1,N,"TESTE TH"\n' +
             'B285,65,0,2,2,4,70,N,"200558309501"\n' +
             'P' + qt + '\n' +
             'N\n';
    },
    ZPL: function (qt) {
      return '^XA^MMT^PW400^LL240^LS0' +
             '^FT38,221^A0N,40,41^FH\\^CI28^FDTeste de Impressão^FS^CI27' +
             '^PQ' + qt + ',0,1,Y^XZ';
    }
  };

  var APPS = {
    ZBP: {
      nome: 'ZBP (Zebra Browser Print)',
      porta: 9100,
      travada: true,
      dica: 'O Zebra Browser Print usa obrigatoriamente a porta 9100 — o campo fica bloqueado.'
    },
    GTI: {
      nome: 'GTI (WebClientPrint / WCPP)',
      porta: 9100,
      travada: false,
      dica: 'O servidor gera um arquivo de spool e o WCPP instalado na estação faz a impressão.'
    },
    DPPRINTER: {
      nome: 'DPPrinter',
      porta: 3000,
      travada: false,
      dica: 'Middleware local em Node.js. Confirme que o serviço está rodando em 127.0.0.1.'
    }
  };

  /* ------------------------------- Elementos ----------------------------- */
  var $ = function (s) { return document.querySelector(s); };

  var form       = $('#formTeste');
  var inpPorta   = $('#porta');
  var inpQt      = $('#qt');
  var inpImp     = $('#impressora');
  var inpEndp    = $('#endpoint');
  var inpBackend = $('#backend');
  var chkRaw     = $('#rawBody');
  var elLog      = $('#log');
  var elAlerta   = $('#alerta');
  var elDica     = $('#dicaApp');
  var elAviso    = $('#portaAviso');
  var elPreview  = $('#preview');
  var btnEnviar  = $('#btnEnviar');
  var txtEnviar  = $('#txtEnviar');

  /* --------------------------------- Log --------------------------------- */
  var CORES = {
    info:   'text-slate-300',
    ok:     'text-emerald-400',
    erro:   'text-rose-400',
    alerta: 'text-amber-400',
    passo:  'text-sky-400'
  };

  function agora() {
    var d = new Date();
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function log(msg, tipo) {
    var linha = document.createElement('div');
    linha.className = 'whitespace-pre-wrap break-words ' + (CORES[tipo] || CORES.info);
    linha.textContent = '[' + agora() + '] ' + msg;
    elLog.appendChild(linha);
    elLog.scrollTop = elLog.scrollHeight;
  }

  function alerta(tipo, titulo, detalhe) {
    var estilos = {
      ok:     'border-emerald-300 bg-emerald-50 text-emerald-900',
      erro:   'border-rose-300 bg-rose-50 text-rose-900',
      alerta: 'border-amber-300 bg-amber-50 text-amber-900'
    };

    elAlerta.className = 'mb-4 rounded-xl border p-4 text-sm ' + (estilos[tipo] || estilos.alerta);
    elAlerta.innerHTML = '';

    var h = document.createElement('p');
    h.className = 'font-semibold';
    h.textContent = titulo;
    elAlerta.appendChild(h);

    if (detalhe) {
      var p = document.createElement('p');
      p.className = 'mt-1 leading-relaxed';
      p.textContent = detalhe;
      elAlerta.appendChild(p);
    }

    elAlerta.classList.remove('hidden');
  }

  /* ------------------------------ Estado da UI --------------------------- */
  function appSelecionada() {
    var el = form.querySelector('input[name="aplicacao"]:checked');
    return el ? el.value : 'ZBP';
  }

  function linguagemSelecionada() {
    var el = form.querySelector('input[name="linguagem"]:checked');
    return el ? el.value : 'ZPL';
  }

  function quantidade() {
    var q = parseInt(inpQt.value, 10);
    if (isNaN(q) || q <= 0) { q = 1; }
    if (q > 50) { q = 50; }
    return q;
  }

  /* Regra de negocio: ZBP trava a porta em 9100; GTI e DPPrinter liberam. */
  function aplicarRegrasDePorta() {
    var app = APPS[appSelecionada()];

    if (app.travada) {
      inpPorta.value    = String(app.porta);
      inpPorta.disabled = true;
      inpPorta.readOnly = true;
      elAviso.textContent = 'Porta obrigatória para o ZBP.';
      elAviso.className   = 'mt-1 text-xs font-medium text-marca-700';
    } else {
      if (inpPorta.disabled) { inpPorta.value = String(app.porta); }
      inpPorta.disabled = false;
      inpPorta.readOnly = false;
      elAviso.textContent = 'Sugestão: ' + app.porta + '. Altere se a unidade usar outra.';
      elAviso.className   = 'mt-1 text-xs text-slate-500';
    }

    elDica.textContent = app.dica;
    if (!elPreview.classList.contains('hidden')) { atualizarPreview(); }
  }

  function atualizarPreview() {
    elPreview.textContent = PAYLOADS[linguagemSelecionada()](quantidade());
  }

  /* ============================ ENVIO: ZBP ================================ */
  function enviarZBP(payload, porta) {
    /* Caminho 1 - SDK oficial do Browser Print, se os .js estiverem em ./js */
    if (window.BrowserPrint && typeof window.BrowserPrint.getDefaultDevice === 'function') {
      log('SDK BrowserPrint detectado. Buscando impressora padrão...', 'passo');

      return new Promise(function (resolve, reject) {
        window.BrowserPrint.getDefaultDevice('printer', function (device) {
          if (!device) {
            reject(new Error('Nenhuma impressora padrão configurada no Zebra Browser Print.'));
            return;
          }
          log('Impressora encontrada: ' + (device.name || device.uid || 'padrão'), 'passo');
          device.send(payload,
            function () { resolve('SDK BrowserPrint'); },
            function (e) { reject(new Error(String(e))); });
        }, function (e) { reject(new Error(String(e))); });
      });
    }

    /* Caminho 2 - API HTTP local do servico Browser Print */
    log('SDK não carregado. Usando a API HTTP local em 127.0.0.1:' + porta + '.', 'passo');
    var base = 'http://127.0.0.1:' + porta;

    return fetch(base + '/default?type=printer', { method: 'GET' })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status + ' ao consultar a impressora padrão.'); }
        return r.json();
      })
      .then(function (device) {
        log('Impressora padrão: ' + (device.name || device.uid || 'desconhecida'), 'passo');
        return fetch(base + '/write', {
          method: 'POST',
          headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
          body: JSON.stringify({ device: device, data: payload })
        });
      })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status + ' ao enviar para a impressora.'); }
        return 'API HTTP do Browser Print';
      });
  }

  /* ============================ ENVIO: GTI ================================
   * Mantem a logica legada: o PHP valida o token da sessao e grava o arquivo
   * de spool em ./spool. O front so recebe o resultado e loga na tela.
   * ======================================================================= */
  function enviarGTI(porta, linguagem, qt) {
    var alvo = (CFG.mode === 'php')
      ? (CFG.endpoint || 'index.php')
      : (inpBackend.value || '').trim();

    if (!alvo) {
      return Promise.reject(new Error(
        'O teste GTI precisa do servidor PHP. Informe a URL do servidor em "Opções avançadas" ou acesse o portal pelo servidor interno.'
      ));
    }

    log('Solicitando ao servidor a geração do arquivo de spool...', 'passo');

    var dados = new URLSearchParams();
    dados.append('action', 'wcpp');
    dados.append('token', CFG.token || '');
    dados.append('software', 'WCPP');
    dados.append('linguagem', linguagem);
    dados.append('qt', String(qt));
    dados.append('porta', String(porta));

    return fetch(alvo, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: dados.toString(),
      credentials: 'include'
    })
      .then(function (r) {
        return r.json().catch(function () {
          throw new Error('O servidor respondeu algo que não é JSON (HTTP ' + r.status + '). Verifique se o PHP está ativo nessa URL.');
        });
      })
      .then(function (j) {
        if (!j.ok) { throw new Error(j.erro || 'Falha ao gerar o arquivo de spool.'); }

        log('Spool gravado: ' + j.arquivo + ' (' + j.bytes + ' bytes)', 'ok');

        /* Dispara o WCPP quando o script cliente do SDK estiver presente. */
        if (window.jsWebClientPrint && typeof window.jsWebClientPrint.print === 'function') {
          log('Acionando o WebClientPrint na estação...', 'passo');
          window.jsWebClientPrint.print('spool=' + encodeURIComponent(j.arquivo));
          return 'WCPP acionado, spool ' + j.arquivo;
        }

        log('Script do WCPP não carregado nesta página — o cliente instalado fará a leitura do spool.', 'alerta');
        return 'spool ' + j.arquivo;
      });
  }

  /* ========================== ENVIO: DPPRINTER ============================ */
  function enviarDPPrinter(payload, porta, linguagem, qt) {
    var caminho = (inpEndp.value || '/').trim();
    if (caminho.charAt(0) !== '/') { caminho = '/' + caminho; }

    var url = 'http://127.0.0.1:' + porta + caminho;
    log('POST para ' + url + ' ...', 'passo');

    var opcoes;
    if (chkRaw.checked) {
      opcoes = {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
        body: payload
      };
    } else {
      opcoes = {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          printer:  (inpImp.value || '').trim() || null,
          language: linguagem,
          copies:   qt,
          payload:  payload
        })
      };
    }

    return fetch(url, opcoes)
      .then(function (r) {
        if (!r.ok) { throw new Error('O DPPrinter respondeu HTTP ' + r.status + '.'); }
        return r.text().catch(function () { return ''; });
      })
      .then(function (txt) {
        if (txt) { log('Resposta do DPPrinter: ' + txt.slice(0, 300), 'info'); }
        return 'DPPrinter em 127.0.0.1:' + porta;
      });
  }

  /* ------------------------------ Submissao ------------------------------ */
  function ocupado(estado) {
    btnEnviar.disabled    = estado;
    txtEnviar.textContent = estado ? 'Enviando...' : 'Enviar teste de impressão';
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var chave     = appSelecionada();
    var app       = APPS[chave];
    var linguagem = linguagemSelecionada();
    var qt        = quantidade();
    var porta     = app.travada ? app.porta : parseInt(inpPorta.value, 10);

    inpQt.value = String(qt);

    if (!app.travada && (isNaN(porta) || porta < 1 || porta > 65535)) {
      alerta('erro', 'Porta inválida', 'Informe um número de porta entre 1 e 65535.');
      log('Envio cancelado: porta inválida.', 'erro');
      inpPorta.focus();
      return;
    }

    var payload = PAYLOADS[linguagem](qt);

    elAlerta.classList.add('hidden');
    log('--- Novo teste: ' + app.nome + ' | porta ' + porta + ' | ' + linguagem + ' | ' + qt + ' etiqueta(s) ---', 'passo');
    ocupado(true);

    var promessa;
    if (chave === 'ZBP') {
      promessa = enviarZBP(payload, porta);
    } else if (chave === 'GTI') {
      promessa = enviarGTI(porta, linguagem, qt);
    } else {
      promessa = enviarDPPrinter(payload, porta, linguagem, qt);
    }

    promessa
      .then(function (detalhe) {
        var msg = 'Sucesso! Impressão enviada via ' + app.nome +
                  ' na porta ' + porta +
                  ' utilizando a linguagem ' + linguagem + '.';
        log(msg + (detalhe ? ' (' + detalhe + ')' : ''), 'ok');
        alerta('ok', msg, qt + ' etiqueta(s) enviada(s). Se nada sair na impressora, confira a lista "Não imprimiu? Confira".');
      })
      .catch(function (e) {
        var motivo = (e && e.message) ? e.message : String(e);

        /* fetch() em 127.0.0.1 falha com TypeError quando o servico esta
         * parado ou quando o CORS nao esta liberado no middleware.       */
        if (/Failed to fetch|NetworkError|Load failed/i.test(motivo)) {
          motivo = 'Não foi possível falar com o serviço local em 127.0.0.1:' + porta +
                   '. Verifique se o programa está instalado e em execução, e se ele libera CORS para este site.';

          /* Portal em HTTPS chamando a rede local: o Chrome exige que o
           * servico local responda o preflight de Private Network Access. */
          if (location.protocol === 'https:') {
            motivo += ' Como este portal está em HTTPS, o serviço local também precisa responder' +
                      ' o cabeçalho "Access-Control-Allow-Private-Network: true" no preflight (OPTIONS).';
          }
        }

        log('FALHA: ' + motivo, 'erro');
        alerta('erro', 'Falha ao enviar via ' + app.nome + ' (porta ' + porta + ')', motivo);
      })
      .then(function () { ocupado(false); });
  });

  /* ------------------------------- Eventos ------------------------------- */
  Array.prototype.forEach.call(form.querySelectorAll('input[name="aplicacao"]'), function (r) {
    r.addEventListener('change', aplicarRegrasDePorta);
  });

  Array.prototype.forEach.call(form.querySelectorAll('input[name="linguagem"]'), function (r) {
    r.addEventListener('change', function () {
      if (!elPreview.classList.contains('hidden')) { atualizarPreview(); }
    });
  });

  inpQt.addEventListener('input', function () {
    if (!elPreview.classList.contains('hidden')) { atualizarPreview(); }
  });

  $('#btnPayload').addEventListener('click', function () {
    var oculto = elPreview.classList.contains('hidden');
    if (oculto) { atualizarPreview(); }
    elPreview.classList.toggle('hidden');
    this.textContent = oculto ? 'Ocultar código' : 'Ver código enviado';
  });

  $('#btnLimpar').addEventListener('click', function () {
    elLog.innerHTML = '';
    elAlerta.classList.add('hidden');
    log('Log limpo.', 'info');
  });

  $('#btnCopiar').addEventListener('click', function () {
    var texto = elLog.innerText;
    var btn   = this;
    var ok    = function () {
      btn.textContent = 'Copiado!';
      setTimeout(function () { btn.textContent = 'Copiar'; }, 1500);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(ok, ok);
    } else {
      var ta = document.createElement('textarea');
      ta.value = texto;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) { /* ignora */ }
      document.body.removeChild(ta);
      ok();
    }
  });

  /* -------------------------------- Inicio -------------------------------- */
  aplicarRegrasDePorta();

  if (CFG.mode !== 'php') {
    document.getElementById('avisoEstatico').classList.remove('hidden');
    log('Portal carregado em modo estático (sem PHP).', 'alerta');
  } else {
    log('Portal carregado. Servidor PHP ativo' +
        (CFG.wcpDisponivel ? ' com SDK WebClientPrint.' : ' (SDK WebClientPrint não encontrado).'), 'info');
  }

  log('Selecione a aplicação, confira a porta e clique em "Enviar teste de impressão".', 'info');
})();
</script>
</body>
</html>
