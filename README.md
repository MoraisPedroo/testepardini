# Portal de Teste de Impressão

Portal único para testar a comunicação com impressoras térmicas e baixar os
instaladores necessários. Substitui o formulário PHP legado.

| Aplicação   | O que faz                                                    | Porta            |
|-------------|--------------------------------------------------------------|------------------|
| **ZBP**     | Zebra Browser Print (serviço local da Zebra)                  | **9100** (fixa)  |
| **GTI**     | WebClientPrint / WCPP — o servidor gera o arquivo de spool    | editável (9100)  |
| **DPPrinter** | Middleware Node.js local, via `POST` para `127.0.0.1`       | editável (3000)  |

Linguagens: **ZPL** (padrão) e **EPL** — os payloads são os mesmos do código legado.

---

## Estrutura

```
index.php                    Portal completo (PHP + HTML/Tailwind + JS)  <- fonte de verdade
public/index.html            Versão estática, gerada pelo build.ps1      <- é o que a Vercel publica
build.ps1                    Regera public/ a partir do index.php
vercel.json                  Configuração do deploy estático
spool/                       Arquivos de spool do WCPP (precisa de escrita)
downloads/                   Instaladores (.exe) — veja downloads/LEIA-ME.txt
js/                          SDK do Zebra Browser Print — veja js/LEIA-ME.txt
exemplos/dpprinter-exemplo.js  Servidor de teste do DPPrinter
```

---

## 1. Vercel (o que você pediu) — atenção

**A Vercel não executa PHP.** Um `index.php` publicado lá é servido como texto.
Por isso o projeto tem duas saídas a partir do mesmo código-fonte:

- `index.php` → servidor interno com PHP (IIS/Apache). **Os três testes funcionam.**
- `public/index.html` → Vercel. **ZBP e DPPrinter funcionam** (rodam na máquina do
  usuário). O **GTI depende do PHP**: informe a URL do servidor interno no campo
  *Opções avançadas → URL do servidor PHP*, ou acesse o portal pelo próprio servidor.

Deploy:

```bash
vercel deploy --prod
```

O `vercel.json` já aponta `outputDirectory: public`. Não configure framework
(preset **Other**).

### Importante: HTTPS chamando 127.0.0.1

A Vercel serve em HTTPS. Chamadas para `http://127.0.0.1` **não** são bloqueadas
como conteúdo misto (o Chrome trata `127.0.0.1` como origem confiável), mas o
Chrome aplica o **Private Network Access**: o serviço local precisa responder o
preflight `OPTIONS` com:

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: POST, OPTIONS
Access-Control-Allow-Headers: Content-Type
Access-Control-Allow-Private-Network: true
```

O `exemplos/dpprinter-exemplo.js` já devolve exatamente esses cabeçalhos — use-o
como referência para o middleware real. Sem isso, o teste falha com "Não foi
possível falar com o serviço local" (o portal explica isso na própria mensagem).

Se preferir evitar o assunto por completo, hospede o portal no servidor interno
por HTTP — aí não há preflight de rede privada.

---

## 2. Servidor interno com PHP (recomendado para o GTI)

1. Copie `index.php`, `spool/`, `downloads/` e `js/` para o diretório do site.
2. Garanta escrita em `spool/` (IIS: dar Modify ao `IIS_IUSRS`).
3. Acesse `http://servidor/testeimpressao/`.

Opcional: se o SDK `WebClientPrint.php` da Neodynamic estiver na mesma pasta, ele
é carregado automaticamente. Sem ele o portal continua funcionando e apenas gera
o arquivo de spool (o cliente WCPP instalado faz a leitura).

A lógica legada foi mantida: sessão com `session_name(md5('seg'.IP.UserAgent))`,
token validado a cada envio e spool em `./spool/<data-hora>.txt`. Mudanças:
o servidor **regera** o payload a partir de linguagem + quantidade (nunca grava
texto vindo do navegador), limita a 50 etiquetas e apaga spools com mais de 1 hora.

---

## 3. Instaladores e SDK

- Coloque os `.exe` em `downloads/` com os nomes indicados em `downloads/LEIA-ME.txt`.
- Coloque os `.js` do Browser Print em `js/` (opcional) — veja `js/LEIA-ME.txt`.
- Rode `build.ps1` para copiar tudo para `public/` antes do deploy.

---

## 4. Testando sem a impressora

```bash
node exemplos/dpprinter-exemplo.js 3000
```

Selecione **DPPrinter**, porta **3000**, e envie. O payload aparece no console do
servidor de exemplo. Para encaminhar de verdade a uma impressora de rede, defina
`IMPRESSORA_IP` no topo do arquivo.

---

## 5. Editando o portal

`index.php` é a **fonte de verdade**. Depois de alterar a interface:

```bash
powershell -ExecutionPolicy Bypass -File .\build.ps1
```

Isso regera `public/index.html` e copia `downloads/` e `js/`. O script aborta se
sobrar qualquer tag PHP na versão estática.

Se alterar os payloads, altere nos **dois** lugares: a função `pp_payload()` (PHP,
usada pelo GTI) e o objeto `PAYLOADS` (JS, usado por ZBP e DPPrinter). Ambos estão
comentados no `index.php` indicando o espelhamento.
