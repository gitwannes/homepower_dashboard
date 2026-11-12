param(
    [string]$SourcePath = ".",             # Folder containing archives, or path to a single archive
    [string]$OutputDir  = ".\mean_xmls",   # Where all matching XMLs will end up
    [int]$MaxDepth      = 3                # Max nesting level
)

# 1. Locate 7-Zip CLI
$7z = (Get-Command 7z.exe -ErrorAction SilentlyContinue).Source
if (-not $7z) {
    $searchPaths = @(
        "$env:ProgramFiles\7-Zip\7z.exe",
        "${env:ProgramFiles(x86)}\7-Zip\7z.exe"
    )
    $7z = $searchPaths | Where-Object { Test-Path $_ } | Select-Object -First 1
}

if (-not $7z) {
    throw "7-Zip (7z.exe) was not found. Please install 7-Zip or ensure it is in your PATH."
}

$workDir = Join-Path $PSScriptRoot "_temp_unpack_workspace"

# 2. Prepare directories
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
New-Item -ItemType Directory -Force -Path $workDir   | Out-Null

try {
    # 3. Identify root archives
    if (Test-Path $SourcePath -PathType Leaf) {
        $rootArchives = @(Get-Item -Path $SourcePath)
    } else {
        $rootArchives = @(Get-ChildItem -Path $SourcePath -File | Where-Object { $_.Extension -match '^\.(zip|7z)$' })
    }

    if (-not $rootArchives -or $rootArchives.Count -eq 0) {
        Write-Warning "No .zip or .7z archives found in '$SourcePath'."
        return
    }

    # Extract Level 1
    $rootTotal = $rootArchives.Count
    Write-Host "Extracting $rootTotal root archive(s)..." -ForegroundColor Cyan
    $r = 0
    foreach ($archive in $rootArchives) {
        $r++
        $pct = [math]::Round(($r / $rootTotal) * 100)
        Write-Progress -Activity "Level 1 Extraction" -Status "Archive $r of $rootTotal ($pct%)" -PercentComplete $pct
        Write-Host "`r[Level 1] Unpacking: $r / $rootTotal ($pct%)" -NoNewline -ForegroundColor Yellow

        $target = Join-Path $workDir $archive.BaseName
        & $7z x $archive.FullName "-o$target" "mean*.xml" "*.zip" "*.7z" -r -y *>$null
    }
    Write-Progress -Activity "Level 1 Extraction" -Completed
    Write-Host "`nLevel 1 complete." -ForegroundColor Green

    # 4. Recursively extract nested levels (Levels 2 to MaxDepth)
    for ($level = 2; $level -le $MaxDepth; $level++) {
        $nestedArchives = @(Get-ChildItem -Path $workDir -Recurse -File | Where-Object { $_.Extension -match '^\.(zip|7z)$' })
        $totalNested = $nestedArchives.Count
        if ($totalNested -eq 0) {
            Write-Host "No more nested archives found at level $level." -ForegroundColor Cyan
            break
        }

        Write-Host "Processing level $level ($totalNested nested archives found)..." -ForegroundColor Cyan
        $i = 0
        $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()

        foreach ($archive in $nestedArchives) {
            $i++
            
            # Update terminal progress every 25 files to preserve speed
            if ($i % 25 -eq 0 -or $i -eq $totalNested) {
                $pct = [math]::Round(($i / $totalNested) * 100)
                Write-Progress -Activity "Level $level Extraction" -Status "Archive $i of $totalNested ($pct%) - $($archive.Name)" -PercentComplete $pct
                Write-Host "`r[Level $level] $i / $totalNested ($pct%) | Elapsed: $($stopwatch.Elapsed.ToString('mm\:ss'))" -NoNewline -ForegroundColor Yellow
            }

            $target = Join-Path $archive.DirectoryName ($archive.BaseName + "_extracted")
            & $7z x $archive.FullName "-o$target" "mean*.xml" "*.zip" "*.7z" -r -y *>$null
            
            # Delete parent archive immediately to free disk space and avoid re-reading
            Remove-Item -Path $archive.FullName -Force
        }
        $stopwatch.Stop()
        Write-Progress -Activity "Level $level Extraction" -Completed
        Write-Host "`nLevel $level finished in $($stopwatch.Elapsed.ToString('mm\:ss'))." -ForegroundColor Green
    }

    # 5. Collect all mean*.xml files into the output directory
    $xmlResults = @(Get-ChildItem -Path $workDir -Recurse -File -Filter "mean*.xml")
    $totalXml = $xmlResults.Count
    Write-Host "Found $totalXml total 'mean*.xml' files. Moving to destination..." -ForegroundColor Green

    $x = 0
    foreach ($xml in $xmlResults) {
        $x++
        if ($x % 50 -eq 0 -or $x -eq $totalXml) {
            $pct = [math]::Round(($x / $totalXml) * 100)
            Write-Progress -Activity "Saving XML Files" -Status "$x of $totalXml ($pct%)" -PercentComplete $pct
            Write-Host "`rSaving XMLs: $x / $totalXml ($pct%)" -NoNewline -ForegroundColor Yellow
        }

        $destinationFile = Join-Path $OutputDir $xml.Name

        # Rename on collision to prevent overwrites
        if (Test-Path $destinationFile) {
            $base = [System.IO.Path]::GetFileNameWithoutExtension($xml.Name)
            $ext  = [System.IO.Path]::GetExtension($xml.Name)
            $idx  = 1
            while (Test-Path $destinationFile) {
                $destinationFile = Join-Path $OutputDir "${base}_$idx$ext"
                $idx++
            }
        }

        Move-Item -Path $xml.FullName -Destination $destinationFile
    }
    Write-Progress -Activity "Saving XML Files" -Completed
    Write-Host "`nAll $totalXml XML files successfully moved to '$OutputDir'." -ForegroundColor Green
}
finally {
    # 6. Clean up temporary working directory
    Write-Host "Cleaning up temp files..." -ForegroundColor Gray
    if (Test-Path $workDir) {
        Remove-Item -Path $workDir -Recurse -Force
    }
    Write-Host "Done!" -ForegroundColor Green
}