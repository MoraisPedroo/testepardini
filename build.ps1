<#
    build.ps1 - gera a versao estatica (public/index.html) a partir do index.php
    ---------------------------------------------------------------------------
    O index.php e a UNICA fonte de verdade da interface. Este script extrai o
    HTML dele, troca o bloco de configuracao do PHP por uma versao estatica e
    copia as pastas de apoio para public/, que e o que a Vercel publica.

    Uso:  powershell -ExecutionPolicy Bypass -File .\build.ps1
#>

$ErrorActionPreference = 'Stop'
$raiz    = Split-Path -Parent $MyInvocation.MyCommand.Path
$origem  = Join-Path $raiz 'index.php'
$destino = Join-Path $raiz 'public\index.html'

if (-not (Test-Path $origem)) { throw "Arquivo nao encontrado: $origem" }

# 1) Pega tudo a partir do <!doctype html>
$linhas = Get-Content $origem -Encoding UTF8
$inicio = ($linhas | Select-String -Pattern '^<!doctype html>' | Select-Object -First 1).LineNumber
if (-not $inicio) { throw 'Nao encontrei a linha <!doctype html> no index.php.' }
$html = $linhas[($inicio - 1)..($linhas.Count - 1)]

# 2) Troca a config gerada pelo PHP pela config do modo estatico
$cfgEstatica = '<script>window.PORTAL_CFG = {"mode":"static","token":"","endpoint":"","wcpDisponivel":false};</script>'
$html = $html | ForEach-Object {
    if ($_ -match '^<script>window\.PORTAL_CFG = <\?php') { $cfgEstatica } else { $_ }
}

if ($html -match '<\?php') { throw 'Sobrou codigo PHP na versao estatica - revise o index.php.' }

# 3) Grava em UTF-8 sem BOM
New-Item -ItemType Directory -Force -Path (Join-Path $raiz 'public') | Out-Null
$utf8SemBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($destino, ($html -join "`r`n") + "`r`n", $utf8SemBom)

# 4) Copia instaladores e SDK para dentro de public/
foreach ($pasta in @('downloads', 'js')) {
    $de   = Join-Path $raiz $pasta
    $para = Join-Path $raiz "public\$pasta"
    if (Test-Path $de) {
        New-Item -ItemType Directory -Force -Path $para | Out-Null
        Copy-Item -Path (Join-Path $de '*') -Destination $para -Recurse -Force
    }
}

Write-Host "OK - public/index.html gerado ($($html.Count) linhas) e pastas copiadas." -ForegroundColor Green
