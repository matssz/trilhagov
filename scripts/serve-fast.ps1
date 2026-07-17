$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$php = (Get-Command php).Source
$phpDirectory = Split-Path -Parent $php
$opcache = Join-Path $phpDirectory 'ext/php_opcache.dll'

if (-not (Test-Path -LiteralPath $opcache)) {
    throw "A extensao OPcache nao foi encontrada em $phpDirectory."
}

$router = Join-Path $projectRoot 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
$artisan = Join-Path $projectRoot 'artisan'

Set-Location -LiteralPath $projectRoot
& $php $artisan optimize

if ($LASTEXITCODE -ne 0) {
    throw 'Nao foi possivel preparar os caches do Laravel.'
}

Set-Location -LiteralPath (Join-Path $projectRoot 'public')

& $php `
    -d "zend_extension=$opcache" `
    -d opcache.enable=1 `
    -d opcache.enable_cli=1 `
    -d opcache.validate_timestamps=1 `
    -d opcache.revalidate_freq=0 `
    -d upload_max_filesize=12M `
    -d post_max_size=14M `
    -S 127.0.0.1:8001 `
    $router
