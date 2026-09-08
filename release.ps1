<#
    release.ps1 - build and publish a new release of an AQM plugin.

    Run it from anywhere:

        powershell -ExecutionPolicy Bypass -File "C:\AQMProjects\<plugin>\release.ps1"

    It reads the version from the plugin header, checks the version constant
    agrees, builds a ZIP whose top-level folder is the plugin slug, verifies
    the entry paths, and publishes a GitHub release with the ZIP attached.
    The site's updater then offers it on the Plugins screen.

    Requires the GitHub CLI:  winget install --id GitHub.cli
    Sign in once with:        gh auth login

    Deliberately plain ASCII - Windows PowerShell reads scripts as ANSI unless
    they carry a BOM, so a stray accented character can break parsing.
#>

param(
    [string]$Notes = ""
)

$ErrorActionPreference = 'Continue'

$root = $PSScriptRoot

# The gh CLI resolves the repository from the CURRENT directory, not from where
# this script lives. Anchoring here lets the script be started from anywhere
# without gh failing with "not a git repository" - which would also make the
# tag check below pass without testing anything.
Set-Location -LiteralPath $root

$slug       = Split-Path $root -Leaf
$srcDir     = Join-Path $root $slug
$pluginFile = Join-Path $srcDir "$slug.php"
$buildDir   = Join-Path $root 'build'
$zipPath    = Join-Path $root "$slug.zip"

function Fail($message) {
    Write-Host ""
    Write-Host "  $message" -ForegroundColor Red
    Write-Host ""
    exit 1
}

Write-Host ""
Write-Host "  AQM release builder - $slug"
Write-Host ("  " + ('-' * (24 + $slug.Length)))

if (-not (Test-Path $pluginFile)) { Fail "Plugin file not found: $pluginFile" }
$text = Get-Content $pluginFile -Raw

$m = [regex]::Match($text, '(?m)^\s*\*\s*Version:\s*(\S+)')
if (-not $m.Success) { Fail "Could not find the Version header in $slug.php." }
$version = $m.Groups[1].Value

# Any AQM_*_VERSION constant, so this one script serves every plugin.
$c = [regex]::Match($text, "AQM_[A-Z0-9_]*VERSION',\s*'([^']+)'")
if (-not $c.Success) { Fail "Could not find an AQM_*_VERSION constant in $slug.php." }
$constVersion = $c.Groups[1].Value

if ($version -ne $constVersion) {
    Fail "Version mismatch: the header says $version but the constant says $constVersion. Make them match, then run this again."
}

$tag = "v$version"
Write-Host "  Version:  $version"
Write-Host "  Tag:      $tag"

cmd /c "gh repo view >nul 2>&1"
if ($LASTEXITCODE -ne 0) {
    Fail "The GitHub CLI cannot see this repository. Check 'gh auth login', and that $root is a git checkout with a remote."
}

cmd /c "gh release view $tag >nul 2>&1"
if ($LASTEXITCODE -eq 0) {
    Fail "A release tagged $tag already exists. Bump the version in $slug.php first."
}

# --- Build ------------------------------------------------------------------

try {
    $ErrorActionPreference = 'Stop'

    if (Test-Path $buildDir) { Remove-Item $buildDir -Recurse -Force }
    if (Test-Path $zipPath)  { Remove-Item $zipPath -Force }

    $target = Join-Path $buildDir $slug
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    Copy-Item -Path (Join-Path $srcDir '*') -Destination $target -Recurse -Force

    # DO NOT use Compress-Archive here.
    #
    # On Windows PowerShell it writes ZIP entry names with a BACKSLASH
    # separator, because .NET Framework before 4.6.1 sets
    # ZipArchiveEntry.FullName that way. WordPress then unpacks a single file
    # literally named  <slug>\<slug>.php  instead of a folder containing the
    # plugin, and the plugin deactivates itself. AQM Contact Form hit exactly
    # this on 7.1.x and had to be fixed by hand.
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
    try {
        foreach ($f in (Get-ChildItem -Path $target -File -Recurse)) {
            $relative = $f.FullName.Substring($buildDir.Length + 1).Replace('\', '/')
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip, $f.FullName, $relative,
                [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    }
    finally {
        $zip.Dispose()
    }

    # Prove it, rather than trust it.
    $verify  = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    $entries = @($verify.Entries | ForEach-Object { $_.FullName })
    $verify.Dispose()

    $bad = @($entries | Where-Object { $_.Contains('\') })
    if ($bad.Count -gt 0) {
        Fail "The ZIP contains backslash path separators: $($bad -join ', '). WordPress would install a broken file. Do not upload it."
    }
    if (-not ($entries -contains "$slug/$slug.php")) {
        Fail "The ZIP does not contain $slug/$slug.php. Found: $($entries -join ', ')"
    }

    Remove-Item $buildDir -Recurse -Force
}
catch {
    $ErrorActionPreference = 'Continue'
    Fail "Could not build the ZIP: $($_.Exception.Message)"
}
$ErrorActionPreference = 'Continue'

$sizeKb = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
Write-Host "  Built:    $slug.zip, $sizeKb KB - entry paths verified" -ForegroundColor Green

# --- Publish ----------------------------------------------------------------

if ([string]::IsNullOrWhiteSpace($Notes)) {
    $Notes = Read-Host "  Release notes (shown in the WordPress update screen)"
}
if ([string]::IsNullOrWhiteSpace($Notes)) {
    $Notes = "Release $version"
}

Write-Host "  Publishing to GitHub..."
gh release create $tag $zipPath --title $tag --notes $Notes

if ($LASTEXITCODE -ne 0) {
    Fail "The release could not be published. The ZIP is still here if you want to upload it by hand."
}

Write-Host ""
Write-Host "  Published $tag" -ForegroundColor Green
Write-Host "  The site sees it within 12 hours, or immediately via the"
Write-Host "  Check for updates link on the Plugins screen."
Write-Host ""
