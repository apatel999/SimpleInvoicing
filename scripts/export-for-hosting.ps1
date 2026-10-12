$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$out = Join-Path $root "deploy\export"
New-Item -ItemType Directory -Force -Path $out | Out-Null

Write-Host "Dumping invoicehost from simpleinvoicing-db..."
docker exec simpleinvoicing-db sh -c 'mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction --routines --triggers invoicehost > /tmp/invoicehost.sql'
docker cp simpleinvoicing-db:/tmp/invoicehost.sql (Join-Path $out "invoicehost.sql")
docker exec simpleinvoicing-db rm -f /tmp/invoicehost.sql

Write-Host "Copying uploads..."
if (Test-Path (Join-Path $root "app-saas\uploads")) {
    Copy-Item -Recurse -Force (Join-Path $root "app-saas\uploads") (Join-Path $out "uploads-saas")
}

Write-Host "Export ready in deploy\export"
Get-ChildItem $out | Format-Table Name, Length
