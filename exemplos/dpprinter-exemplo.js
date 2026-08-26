/* =============================================================================
 * DPPrinter - servidor de EXEMPLO (apenas para validar o portal)
 * -----------------------------------------------------------------------------
 * Nao substitui o middleware oficial: serve para conferir se o portal esta
 * enviando o payload corretamente e como referencia dos cabecalhos de CORS
 * que o middleware real precisa devolver.
 *
 * Uso:  node exemplos/dpprinter-exemplo.js 3000
 *
 * Depois selecione "DPPrinter" no portal, porta 3000, e envie o teste.
 * O payload EPL/ZPL recebido aparece no console.
 * ========================================================================== */

const http = require('http');
const net  = require('net');

const PORTA = parseInt(process.argv[2], 10) || 3000;

/* Se quiser encaminhar de verdade para a impressora de rede, preencha abaixo.
 * Deixe IMPRESSORA_IP = null para apenas exibir o payload no console.        */
const IMPRESSORA_IP    = null;   // ex.: '10.20.30.40'
const IMPRESSORA_PORTA = 9100;

function cabecalhosCors(res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  /* Exigido pelo Chrome quando o portal esta em HTTPS (Private Network Access) */
  res.setHeader('Access-Control-Allow-Private-Network', 'true');
  res.setHeader('Access-Control-Max-Age', '86400');
}

function enviarParaImpressora(payload) {
  return new Promise((resolve, reject) => {
    if (!IMPRESSORA_IP) { return resolve('somente console'); }
    const socket = net.createConnection(IMPRESSORA_PORTA, IMPRESSORA_IP, () => {
      socket.write(payload, 'binary', () => socket.end());
    });
    socket.on('close', () => resolve(`enviado para ${IMPRESSORA_IP}:${IMPRESSORA_PORTA}`));
    socket.on('error', reject);
  });
}

const servidor = http.createServer((req, res) => {
  cabecalhosCors(res);

  if (req.method === 'OPTIONS') { res.writeHead(204); return res.end(); }

  if (req.method !== 'POST') {
    res.writeHead(405, { 'Content-Type': 'text/plain; charset=utf-8' });
    return res.end('Use POST.');
  }

  let corpo = '';
  req.on('data', (c) => { corpo += c; });
  req.on('end', async () => {
    let payload = corpo;
    let meta    = {};

    /* O portal envia JSON por padrao e texto puro quando a opcao esta marcada */
    if ((req.headers['content-type'] || '').includes('application/json')) {
      try {
        const j = JSON.parse(corpo);
        payload = j.payload || '';
        meta    = { impressora: j.printer, linguagem: j.language, copias: j.copies };
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'text/plain; charset=utf-8' });
        return res.end('JSON invalido.');
      }
    }

    console.log('\n--- Trabalho recebido em', new Date().toLocaleString('pt-BR'), '---');
    console.log('Rota:', req.url, '| Meta:', meta);
    console.log(payload);

    try {
      const destino = await enviarParaImpressora(payload);
      res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end(`OK (${payload.length} bytes, ${destino})`);
    } catch (e) {
      res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('Falha ao falar com a impressora: ' + e.message);
    }
  });
});

servidor.listen(PORTA, '127.0.0.1', () => {
  console.log(`DPPrinter (exemplo) ouvindo em http://127.0.0.1:${PORTA}`);
  console.log('Aguardando testes do portal... (Ctrl+C para parar)');
});
