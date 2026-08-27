/* =============================================================================
 * DPPrinter - servidor de EXEMPLO (apenas para conferir o portal)
 * -----------------------------------------------------------------------------
 * Emula a mesma API HTTP local do DPPrinter e do Zebra Browser Print:
 *
 *   GET  /default?type=printer  -> JSON com a impressora padrao
 *   POST /write                 -> { device, data }  ->  {"success": true}
 *
 * Uso:
 *   node exemplos/dpprinter-exemplo.js 3000
 *   node exemplos/dpprinter-exemplo.js 3000 --sem-impressora   (testa o erro)
 *
 * O payload EPL/ZPL recebido aparece no console. Nada e impresso de verdade,
 * a menos que voce preencha IMPRESSORA_IP abaixo.
 * ========================================================================== */

const http = require('http');
const net  = require('net');

const PORTA          = parseInt(process.argv[2], 10) || 3000;
const SEM_IMPRESSORA = process.argv.indexOf('--sem-impressora') !== -1;

/* Para encaminhar de verdade a uma impressora de rede, preencha o IP. */
const IMPRESSORA_IP    = null;   // ex.: '192.168.1.55'
const IMPRESSORA_PORTA = 9100;

const DISPOSITIVO = {
  connection:   'network',
  deviceType:   'printer',
  manufacturer: 'Zebra',
  name:         '(EXEMPLO) Zebra - 127.0.0.1',
  uid:          '(EXEMPLO) Zebra - 127.0.0.1',
  version:      3
};

function responder(res, status, corpo, tipo) {
  res.writeHead(status, {
    'Content-Type': tipo || 'application/json',
    'Access-Control-Allow-Origin': '*'
  });
  res.end(corpo);
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
  const rota = req.url.split('?')[0];

  /* ---- impressora padrao ---- */
  if (req.method === 'GET' && rota === '/default') {
    if (SEM_IMPRESSORA) {
      console.log('GET /default -> nenhuma impressora configurada (modo --sem-impressora)');
      return responder(res, 200, '{}');
    }
    console.log('GET /default -> ' + DISPOSITIVO.name);
    return responder(res, 200, JSON.stringify(DISPOSITIVO));
  }

  /* ---- envio do trabalho ---- */
  if (req.method === 'POST' && rota === '/write') {
    let corpo = '';
    req.on('data', (c) => { corpo += c; });
    req.on('end', async () => {
      let payload = corpo;
      let device  = null;

      try {
        const j = JSON.parse(corpo);
        payload = j.data || '';
        device  = j.device || null;
      } catch (e) { /* corpo cru, segue como esta */ }

      console.log('\n--- Trabalho recebido em', new Date().toLocaleString('pt-BR'), '---');
      console.log('Impressora:', device ? device.name : '(nao informada)');
      console.log(payload);

      try {
        await enviarParaImpressora(payload);
        return responder(res, 200, JSON.stringify({ success: true }));
      } catch (e) {
        return responder(res, 200, JSON.stringify({ success: false, error: e.message }));
      }
    });
    return;
  }

  console.log(req.method, rota, '-> 404');
  responder(res, 404, 'Not Found', 'text/plain');
});

servidor.listen(PORTA, '127.0.0.1', () => {
  console.log(`DPPrinter (exemplo) ouvindo em http://127.0.0.1:${PORTA}`);
  if (SEM_IMPRESSORA) { console.log('Modo --sem-impressora: /default devolve vazio.'); }
  console.log('Aguardando testes do portal... (Ctrl+C para parar)');
});
