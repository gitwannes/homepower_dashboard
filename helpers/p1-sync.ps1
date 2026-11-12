#requires -Version 5.1
# --- file: helpers/p1-sync.ps1 ---
<#
================================================================================
P1 Sync (local only - gitignored). Reads helpers/p1-sync.config.json.
Per-target source_subdir / exclude_dirs / exclude_files.
Never copies .env*.
================================================================================
#>
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet("test", "run")]
    [string]$Mode,

    [string[]]$Target = @(),

    [switch]$All,

    [switch]$VerboseSync
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Get-StringList {
    param([object]$Value)
    $out = @()
    if ($null -eq $Value) { return $out }
    if ($Value -is [System.Array]) {
        foreach ($item in $Value) {
            if ($null -ne $item -and -not [string]::IsNullOrWhiteSpace([string]$item)) {
                $out += [string]$item
            }
        }
        return $out
    }
    $one = [string]$Value
    if (-not [string]::IsNullOrWhiteSpace($one)) {
        $out += $one
    }
    return $out
}

$HelpersDir = $PSScriptRoot
$ConfigPath = Join-Path $HelpersDir "p1-sync.config.json"

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    Write-Host ("ERROR: Config not found: {0}" -f $ConfigPath) -ForegroundColor Red
    exit 10
}

$config = Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
$RootSource = [string]$config.source
if ([string]::IsNullOrWhiteSpace($RootSource)) {
    Write-Host "ERROR: config.source is empty." -ForegroundColor Red
    exit 10
}
if (-not (Test-Path -LiteralPath $RootSource)) {
    Write-Host ("ERROR: Local source directory not found: {0}" -f $RootSource) -ForegroundColor Red
    exit 10
}

# Shared defaults (all targets).
$DefaultExcludeDirs = @(
    ".git", ".idea", "docs", "import_queue", "__pycache__", "_archive"
)
$DefaultExcludeFiles = @(
    "meter_history.db*", "*.sqlite", ".env", ".env.*", "*.me",
    ".gitignore", "LICENSE", "LICENSE.*", "*.md"
)

function Get-JsonProp {
    param(
        [object]$Object,
        [string]$Name
    )
    if ($null -eq $Object) { return $null }
    $p = $Object.PSObject.Properties[$Name]
    if ($null -eq $p) { return $null }
    return $p.Value
}

$targetMap = @{}
if ($null -ne $config.targets) {
    foreach ($prop in $config.targets.PSObject.Properties) {
        $val = $prop.Value
        $subdirRaw = Get-JsonProp -Object $val -Name "source_subdir"
        $subdir = if ($null -eq $subdirRaw) { "" } else { [string]$subdirRaw }
        $ipRaw = Get-JsonProp -Object $val -Name "ip"
        $ip = if ($null -eq $ipRaw) { "" } else { [string]$ipRaw }
        $targetMap[$prop.Name.ToLowerInvariant()] = @{
            Name              = $prop.Name
            Path              = [string](Get-JsonProp -Object $val -Name "path")
            IP                = $ip
            SourceSubdir      = $subdir
            ExtraExcludeDirs  = Get-StringList -Value (Get-JsonProp -Object $val -Name "exclude_dirs")
            ExtraExcludeFiles = Get-StringList -Value (Get-JsonProp -Object $val -Name "exclude_files")
        }
    }
}

if ($targetMap.Count -eq 0) {
    Write-Host "ERROR: config.targets is empty." -ForegroundColor Red
    exit 10
}

$selected = @()
if ($All) {
    $selected = @($targetMap.Values)
} else {
    if ($Target.Count -eq 0) {
        Write-Host "WARNING: No -Target name and no -All. Nothing to do." -ForegroundColor Yellow
        Write-Host ("Available targets: {0}" -f ($targetMap.Keys -join ", ")) -ForegroundColor Gray
        exit 0
    }
    foreach ($name in $Target) {
        $key = $name.ToLowerInvariant()
        if (-not $targetMap.ContainsKey($key)) {
            Write-Host ("ERROR: Unknown target '{0}'. Available: {1}" -f $name, ($targetMap.Keys -join ", ")) -ForegroundColor Red
            exit 10
        }
        $selected += $targetMap[$key]
    }
}

$DryRun = ($Mode -eq "test")
$runModeLabel = if ($DryRun) { "DRY RUN / PREVIEW" } else { "LIVE MIRROR" }

$hasErrors = $false

foreach ($t in $selected) {
    Write-Host "----------------------------------------------------------------------" -ForegroundColor White
    Write-Host ("TARGET: {0} ({1})" -f $t.Name, $t.Path) -ForegroundColor Cyan
    Write-Host "----------------------------------------------------------------------" -ForegroundColor White

    $jobSource = $RootSource
    if (-not [string]::IsNullOrWhiteSpace($t.SourceSubdir)) {
        $jobSource = Join-Path $RootSource $t.SourceSubdir
        if (-not (Test-Path -LiteralPath $jobSource)) {
            Write-Host ("ERROR: source_subdir not found for {0}: {1}" -f $t.Name, $jobSource) -ForegroundColor Red
            $hasErrors = $true
            continue
        }
    }

    $excludeDirs = @($DefaultExcludeDirs) + @($t.ExtraExcludeDirs)
    $excludeFiles = @($DefaultExcludeFiles) + @($t.ExtraExcludeFiles)

    $accessible = $false
    try {
        if (Test-Path -LiteralPath $t.Path) {
            $accessible = $true
        } elseif (-not $t.Path.StartsWith("\\")) {
            # Local folder: create destination if the parent exists.
            $parent = Split-Path -Parent $t.Path
            if (-not [string]::IsNullOrWhiteSpace($parent) -and (Test-Path -LiteralPath $parent)) {
                New-Item -ItemType Directory -Path $t.Path -Force | Out-Null
                $accessible = Test-Path -LiteralPath $t.Path
                if ($accessible) {
                    Write-Host ("Created local destination folder: {0}" -f $t.Path) -ForegroundColor DarkYellow
                }
            }
        }
    } catch {
        $accessible = $false
    }

    if (-not $accessible) {
        Write-Host ("ERROR: Cannot access destination for {0}: {1}" -f $t.Name, $t.Path) -ForegroundColor Red
        if ($t.Path.StartsWith("\\")) {
            Write-Host ("  SMB host {0} is unreachable or credentials are not accepted." -f $t.IP) -ForegroundColor Yellow
        } else {
            Write-Host "  Local folder missing (and parent could not be used to create it)." -ForegroundColor Yellow
        }
        Write-Host "  Skipping this target and proceeding..." -ForegroundColor DarkYellow
        Write-Host ""
        $hasErrors = $true
        continue
    }

    Write-Host ("Access confirmed to {0}." -f $t.Path) -ForegroundColor Green
    Write-Host ""
    Write-Host ("=== SYNC JOB: PC --> {0} ({1}) ===" -f $t.Name, $runModeLabel) -ForegroundColor White
    Write-Host ("  Source : {0}" -f $jobSource)
    Write-Host ("  Dest   : {0}" -f $t.Path)
    Write-Host ("  Excluded Dirs  : {0}" -f ($excludeDirs -join ", "))
    Write-Host ("  Excluded Files : {0}" -f ($excludeFiles -join ", "))
    Write-Host ""

    $robocopyArgs = @(
        $jobSource,
        $t.Path,
        "/MIR",
        "/FFT",
        "/R:1",
        "/W:1",
        "/NP"
    )

    if ($DryRun) {
        $robocopyArgs += "/L"
    }
    if (-not $VerboseSync) {
        $robocopyArgs += "/NDL"
    }
    if ($excludeDirs.Count -gt 0) {
        $robocopyArgs += "/XD"
        $robocopyArgs += $excludeDirs
    }
    if ($excludeFiles.Count -gt 0) {
        $robocopyArgs += "/XF"
        $robocopyArgs += $excludeFiles
    }

    if ($VerboseSync) {
        Write-Host ("robocopy {0}" -f ($robocopyArgs -join " ")) -ForegroundColor DarkGray
        Write-Host ""
    }

    & robocopy.exe @robocopyArgs | ForEach-Object {
        $line = $_
        if ([string]::IsNullOrWhiteSpace($line)) { return }

        if ($line -match '^\s*\*EXTRA File|\*EXTRA Dir') {
            Write-Host $line -ForegroundColor Red
        } elseif ($line -match '^\s*New File|\s*Newer|\s*Older') {
            Write-Host $line -ForegroundColor Yellow
        } elseif ($line -match 'Total\s+Copied\s+Skipped') {
            Write-Host $line -ForegroundColor Cyan
        } else {
            if ($VerboseSync -or $line -match 'Dirs\s+:|Files\s+:|Bytes\s+:|Times\s+:') {
                Write-Host $line -ForegroundColor Gray
            }
        }
    }

    $rc = $LASTEXITCODE
    if ($rc -ge 8) {
        Write-Host ("Robocopy encountered an error on {0} (exit code {1})" -f $t.Name, $rc) -ForegroundColor Red
        $hasErrors = $true
    } else {
        Write-Host ("Target {0} sync successful." -f $t.Name) -ForegroundColor Green
    }
    Write-Host ""
}

Write-Host "======================================================================" -ForegroundColor White
if ($DryRun) {
    Write-Host "PREVIEW FINISHED." -ForegroundColor Cyan
} elseif ($hasErrors) {
    Write-Host "SYNC FINISHED WITH ERRORS on one or more targets." -ForegroundColor Yellow
} else {
    Write-Host "SYNC FINISHED: All accessible targets are up to date." -ForegroundColor Green
}
Write-Host "======================================================================" -ForegroundColor White

if ($hasErrors) { exit 1 } else { exit 0 }
