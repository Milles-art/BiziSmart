#requires -Version 7.4
# Used by the disposable drill before writing its temporary connection file.
param([Parameter(Mandatory = $true)][string]$Path)
. (Join-Path $PSScriptRoot 'mysql-common.ps1')
Initialize-PrivateDirectory $Path
