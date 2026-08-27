# Teste de Impressão - Hermes Pardini

Portal estático de teste de impressão térmica. Um único `index.html`, sem
back-end: o navegador conversa direto com o proxy que roda na máquina do
usuário.

## Como funciona

| Sistema | Porta |
|---|---|
| **Teste Pardini (ZBP)** — opção padrão | fixa em `9100`, não aparece na tela |
| **GTI** | o usuário digita a porta (padrão `9100`) |

O DPPrinter e o Zebra Browser Print expõem a **mesma API HTTP local**, e é ela
que o portal usa:

```
GET  http://127.0.0.1:{porta}/default?type=printer   -> dados da impressora padrão
POST http://127.0.0.1:{porta}/write                  -> { device, data }
```

O `POST /write` responde `{"success": true}`. As requisições usam
`Content-Type: text/plain` de propósito: isso as mantém como requisições
"simples", sem preflight de CORS.

> **Não use `mode: 'no-cors'` aqui.** Ele deixa a resposta opaca, o que impede
> ler o status e a mensagem de erro — e foi o que escondeu um 404 de rota
> errada. O DPPrinter já devolve `Access-Control-Allow-Origin: *`, então o
> fetch normal funciona e dá para mostrar o erro real.

Uma observação sobre portas: o DPPrinter também abre a `8080`, mas ela **não**
responde às rotas de impressão. A porta que imprime é a `9100`.

## Mensagens na tela

Verde, em caso de sucesso:

> Impressão enviada na porta 9100 em ZPL pelo sistema Teste Pardini.

Vermelho, com o motivo, nos casos de erro:

| Situação | Mensagem |
|---|---|
| Proxy parado, não instalado ou porta errada | "O {sistema} não respondeu em 127.0.0.1:{porta}..." |
| Serviço responde mas não tem a rota | "...respondeu, mas não reconhece a rota /default..." |
| Nenhuma impressora padrão configurada | "IMPRESSORA NÃO DEFINIDA — ..." |
| O proxy recusou o trabalho | a mensagem devolvida pelo próprio proxy |

Um detalhe: se um proxy responder **sem** cabeçalho CORS, o navegador bloqueia
a leitura e o erro fica indistinguível de "serviço parado". Por isso essa
mensagem cita as duas causas.

## Estrutura

```
index.html                     O portal inteiro (HTML + Tailwind CDN + JS)
logopardini.png                Logo do topo e favicon
downloads/                     Instaladores .exe — veja downloads/LEIA-ME.txt
vercel.json                    Cabeçalhos, cleanUrls e cache do HTML
.vercelignore                  Garante que os .exe subam no deploy pela CLI
exemplos/dpprinter-exemplo.js  Emulador local do DPPrinter, para testes
```

## Deploy na Vercel

Framework Preset **Other**, sem build command e sem output directory — o
`index.html` na raiz já é a página inicial.

### Os instaladores e o limite do GitHub

O `GTI Printer Proxy 3 Pardini.exe` tem ~130 MB, acima do limite de 100 MB por
arquivo do GitHub: ele não entra no repositório de jeito nenhum. Por isso os
`.exe` ficam fora do Git (`.gitignore`).

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

## Testando sem impressora

```bash
node exemplos/dpprinter-exemplo.js 3000
```

Selecione GTI, porta 3000, e envie: o payload aparece no console. Para testar a
mensagem de erro de impressora ausente:

```bash
node exemplos/dpprinter-exemplo.js 3001 --sem-impressora
```
