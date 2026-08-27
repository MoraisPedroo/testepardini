/* =============================================================================
 * DPPrinter - servidor de EXEMPLO (apenas para conferir o portal)
 * -----------------------------------------------------------------------------
 * Emula a arquitetura do middleware: escuta DUAS portas ao mesmo tempo e
 * intercepta os dois trafegos, recebendo o payload cru em POST /.
 *
 *   9100    -> trafego de quem acha que esta usando o ZBP
 *   8080    -> trafego de quem acha que esta usando o GTI (porta flexivel)
 *
 * Uso:
 *   node exemplos/dpprinter-exemplo.js            (9100 e 8080)
 *   node exemplos/dpprinter-exemplo.js 9100 3000  (portas personalizadas)
 *
 * O payload EPL/ZPL recebido aparece no console. Nada e impresso de verdade,
 * a menos que voce preencha IMPRESSORA_IP abaixo.
 * ========================================================================== */

const http = require('http');
const net  = require('net');

const PORTA_ZBP = parseInt(process.argv[2], 10) || 9100;
const PORTA_GTI = parseInt(process.argv[3], 10) || 8080;

/* Para encaminhar de verdade a uma impressora de rede, preencha o IP. */
const IMPRESSORA_IP    = null;   // ex.: '192.168.1.55'
const IMPRESSORA_PORTA = 9100;

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

function criarServidor(porta, rotulo) {
  const servidor = http.createServer((req, res) => {
    /* O portal usa mode:'no-cors', entao a resposta e opaca de qualquer
     * forma. Os cabecalhos ficam aqui so para quem quiser ler o retorno. */
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.setHeader('Access-Control-Allow-Private-Network', 'true');

    if (req.method === 'OPTIONS') { res.writeHead(204); return res.end(); }

    if (req.method !== 'POST') {
      res.writeHead(405, { 'Content-Type': 'text/plain; charset=utf-8' });
      return res.end('Use POST.');
    }

    let payload = '';
    req.on('data', (c) => { payload += c; });
    req.on('end', async () => {
      console.log(`\n--- [${rotulo}:${porta}] recebido em ${new Date().toLocaleString('pt-BR')} ---`);
      console.log(payload);

      try {
        await enviarParaImpressora(payload);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: true }));
      } catch (e) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, error: e.message }));
      }
    });
  });

  servidor.on('error', (e) => {
    if (e.code === 'EADDRINUSE') {
      console.error(`[${rotulo}] porta ${porta} ja esta em uso — o DPPrinter real deve estar rodando.`);
    } else {
      console.error(`[${rotulo}] erro:`, e.message);
    }
  });

  servidor.listen(porta, '127.0.0.1', () => {
    console.log(`[${rotulo}] ouvindo em http://127.0.0.1:${porta}`);
  });

  return servidor;
}

criarServidor(PORTA_ZBP, 'ZBP');
criarServidor(PORTA_GTI, 'GTI');
console.log('Aguardando testes do portal... (Ctrl+C para parar)');
