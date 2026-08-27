# Teste de Impressão - Hermes Pardini

Portal estático de teste de impressão térmica. Um único `index.html`, sem
back-end.

## Arquitetura

A máquina do usuário roda apenas o **DPPrinter**, o middleware local. Ele
escuta duas portas ao mesmo tempo e intercepta os dois tráfegos, garantindo
retrocompatibilidade com o MyPardini:

| Sistema no portal | Porta | O que o DPPrinter intercepta |
|---|---|---|
| **Teste Pardini (ZBP)** — padrão | fixa `9100`, não aparece na tela | quem acha que está usando o ZBP |
| **GTI** | o usuário digita (padrão `8080`) | quem acha que está usando o GTI |

Os dois botões usam **exatamente o mesmo código**. A única diferença é a porta:

```js
fetch('http://127.0.0.1:' + porta + '/', {
  method: 'POST',
  mode: 'no-cors',
  headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
  body: payload            // ZPL ou EPL, os mesmos do sistema legado
});
```

O portal não valida se o programa está lá nem qual é ele. Só dispara o payload
para a porta correspondente; quem decide o que fazer é o middleware.

## Mensagens na tela

- **Verde** — a requisição saiu: "Impressão enviada na porta 9100 em ZPL pelo
  sistema Teste Pardini."
- **Vermelho** — a conexão falhou (serviço parado ou porta errada).

### O limite do `no-cors`

Com `mode: 'no-cors'` a resposta é **opaca**: dá para saber que a conexão
aconteceu, mas não o que o servidor respondeu. Na prática:

| Situação | O que a tela mostra |
|---|---|
| DPPrinter recebeu e imprimiu | verde |
| DPPrinter respondeu erro (404, 500...) | **verde também** |
| Nada escutando na porta | vermelho |

Ou seja, verde significa "a requisição chegou em alguém", não "imprimiu". Para
distinguir os dois seria preciso tirar o `no-cors` e ler a resposta — o que
exige o middleware devolver cabeçalho CORS.

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
