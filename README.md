# Teste de Impressão - Hermes Pardini

Portal estático de teste de impressão térmica. Um único `index.html`, sem
back-end: o navegador dispara o payload direto para o DPPrinter que roda na
máquina do usuário.

## Como funciona

| Sistema | Porta |
|---|---|
| **Teste Pardini (ZBP)** — opção padrão | fixa em `9100`, não aparece na tela |
| **GTI** | o usuário digita a porta |

Ao clicar em **Enviar ZPL** ou **Enviar EPL**, o JavaScript monta o payload
(mantidos os mesmos do sistema legado) e faz:

```js
fetch('http://127.0.0.1:' + porta + '/', {
  method: 'POST',
  mode: 'no-cors',
  headers: { 'Content-Type': 'text/plain' },
  body: payload
});
```

O `mode: 'no-cors'` faz o envio "cego": a requisição sai sem o navegador
bloquear por CORS, mas a resposta é **opaca**. Ou seja, o portal não tem como
saber se o DPPrinter recebeu, se a porta está errada ou se o serviço está
parado — a mensagem de confirmação aparece sempre. Isso é intencional: o
diagnóstico fica com o DPPrinter, não com a página.

## Estrutura

```
index.html                     O portal inteiro (HTML + Tailwind CDN + JS)
logopardini.png                Logo do topo e favicon
downloads/                     Instaladores .exe — veja downloads/LEIA-ME.txt
vercel.json                    Cabeçalhos e cleanUrls
exemplos/dpprinter-exemplo.js  Servidor local para conferir o que chega
```

## Deploy na Vercel

Framework Preset **Other**, sem build command e sem output directory. O
`index.html` na raiz já é servido como a página inicial.

Os instaladores não vão para o Git (`.gitignore`). Coloque os `.exe` em
`downloads/` antes do deploy, ou aponte os `href` para onde eles já ficam
hoje na rede interna.

### Um detalhe do HTTPS

A Vercel serve em HTTPS. Chamadas para `http://127.0.0.1` não são bloqueadas
como conteúdo misto (o Chrome trata `127.0.0.1` como origem confiável), mas o
Chrome aplica *Private Network Access*: dependendo da versão, o DPPrinter pode
precisar responder o preflight `OPTIONS` com
`Access-Control-Allow-Private-Network: true`. Com `no-cors` e `text/plain` não
há preflight na maioria dos casos, mas vale testar pelo domínio publicado, e
não só abrindo o arquivo local.

## Conferindo sem impressora

```bash
node exemplos/dpprinter-exemplo.js 9100
```

Envie um teste pelo portal: o payload aparece no console. Para encaminhar de
verdade a uma impressora de rede, defina `IMPRESSORA_IP` no topo do arquivo.
