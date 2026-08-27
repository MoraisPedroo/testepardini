# Teste de Impressão - Hermes Pardini

Portal estático de teste de impressão térmica. Um único `index.html`, sem
back-end.

## Arquitetura

A máquina do usuário roda apenas o **DPPrinter**, o middleware local. Ele
escuta duas portas ao mesmo tempo e intercepta os dois tráfegos:

| Sistema no portal | Porta |
|---|---|
| **Teste Pardini (ZBP)** — padrão | fixa `9100`, não aparece na tela |
| **GTI** | o usuário digita (padrão `8080`) |

Os dois botões usam o mesmo código; muda só a porta.

### As rotas de cada servico

O DPPrinter roda **dois servicos Flask distintos**, um por porta, cada um com
a sua rota. Confirmado no fonte do middleware (`dpprinter/api/`):

```
ZBP  (9100)          GET  /default
                     POST /write   { device, data }

GTI  (porta proxy)   POST /gti-printer-proxy/api/printer/print   <- payload cru
                     GET  /gti-printer-proxy/api/status
```

A rota do GTI **nao** e a raiz. `POST /` responde 404 nas duas portas — foi o
que fez as primeiras versoes deste portal nao imprimirem nada.

Para conferir se o proxy esta de pe e em que porta:

```bash
curl http://127.0.0.1:8080/gti-printer-proxy/api/status
```

Ele responde `{"browserprint":9100,"port":8080,"status":"ready"}`.

### Por que `Content-Type: text/plain`

Mantem a requisicao "simples" (sem preflight de CORS) e faz o Flask entregar
o corpo cru — com `x-www-form-urlencoded` ele trataria o payload como campo
de formulario e o ZPL/EPL chegaria deformado.

### Por que nao usar `mode: no-cors`

Com `no-cors` a resposta e opaca: um 404 de rota errada fica invisivel e a
tela pinta de verde sem ter impresso nada — foi exatamente o que aconteceu em
teste. Sem ele da para ler o status e so mostrar verde quando o middleware
aceitou. O DPPrinter usa `flask_cors`, entao devolve
`Access-Control-Allow-Origin: *` e nao ha problema de CORS.

## Mensagens na tela

- **Verde** — o middleware aceitou o envio (HTTP 2xx de verdade).
- **Vermelho** — com o motivo:

| Situação | Mensagem |
|---|---|
| Nada escutando na porta | "Não foi possível falar com o DPPrinter..." |
| Porta responde mas não aceita nenhum dos dois contratos | "Nada foi impresso: o serviço na porta X não aceita..." |
| Nenhuma impressora padrão configurada | "IMPRESSORA NÃO DEFINIDA — ..." |
| O middleware recusou o trabalho | a mensagem devolvida por ele |

## Estrutura

```
index.html                     O portal inteiro (HTML + Tailwind CDN + JS)
logopardini.png                Logo do topo e favicon
downloads/                     Instaladores .exe — veja downloads/LEIA-ME.txt
vercel.json                    Cabeçalhos, cleanUrls e cache do HTML
.vercelignore                  Garante que os .exe subam no deploy pela CLI
exemplos/dpprinter-exemplo.js  Emulador local de duas portas, para testes
```

## Deploy na Vercel

Framework Preset **Other**, sem build command e sem output directory — o
`index.html` na raiz já é a página inicial.

### Os instaladores e o limite do GitHub

O `GTI Printer Proxy 3 Pardini.exe` tem ~130 MB, acima do limite de 100 MB por
arquivo do GitHub: ele não entra no repositório. Por isso os `.exe` ficam fora
do Git (`.gitignore`).

Para os três botões de download funcionarem, publique **pela CLI**, direto da
pasta local — assim os instaladores sobem como arquivos estáticos sem passar
pelo GitHub:

```bash
vercel deploy --prod
```

É para isso que existe o `.vercelignore`: sem ele a Vercel usaria o
`.gitignore` e deixaria os `.exe` de fora.

Se você publicar pelo deploy automático ligado ao GitHub, o site sobe normal,
mas os botões de download vão dar 404 — os arquivos não estarão lá.

### Cache

O `vercel.json` marca o HTML como `must-revalidate`. Sem isso o navegador
segura a página antiga e você acaba testando código velho. Para conferir qual
versão está carregada, abra o Console: a página escreve
`Teste de Impressao - versao ...` ao carregar.

## Testando sem o DPPrinter

O emulador sobe as duas portas e imprime no console o que receber:

```bash
node exemplos/dpprinter-exemplo.js 3000 3001
```

Selecione GTI, aponte para 3000 ou 3001 e envie. Para encaminhar de verdade a
uma impressora de rede, defina `IMPRESSORA_IP` no topo do arquivo.
