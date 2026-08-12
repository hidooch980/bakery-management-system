# Pulls the shop's nightly database backups off the server onto this machine.
#
# Every backup the server takes lives on the same disk as the database it
# protects, so the day that disk goes, all thirty of them go with it. This
# is the copy that survives that.
#
# It pulls rather than pushes: this machine can reach the server, but the
# server cannot reach a laptop behind a home router, and a backup that only
# works when someone is at their desk is not a backup either.

param(
    [string]$Destination = 'D:\aziz\backups',
    [string]$ServerHost  = '37.32.21.125',
    [int]   $Port        = 22,
    [string]$User        = 'ubuntu',
    [string]$RemoteDir   = '/home/ubuntu/bakery-management-system/backend/storage/app/backups',
    # Roughly a month, matching what the server keeps.
    [int]   $Keep        = 30,
    # Named outright rather than left to ssh-agent. The agent belongs to a
    # logged-in desktop session; Task Scheduler has none, so the first
    # nightly run authenticated against nothing and exited having copied
    # nothing — while reporting only an exit code nobody was reading.
    [string]$KeyFile     = "$env:USERPROFILE\.ssh\id_ed25519_new"
)

$ErrorActionPreference = 'Stop'

function Say($message) {
    Write-Output ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message)
}

# A gzip file that unpacks is a backup; one that merely exists is a guess.
# Decompressing it here catches a copy that was cut short in transit, which
# otherwise sits looking perfectly fine until the day it is needed.
function Test-GzipReadable($path) {
    try {
        $input  = [IO.File]::OpenRead($path)
        $gzip   = New-Object IO.Compression.GZipStream($input, [IO.Compression.CompressionMode]::Decompress)
        $buffer = New-Object byte[] 65536
        $total  = 0

        while (($read = $gzip.Read($buffer, 0, $buffer.Length)) -gt 0) {
            $total += $read
        }

        $gzip.Close()
        $input.Close()

        return $total -gt 0
    } catch {
        return $false
    }
}

if (-not (Test-Path $Destination)) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    Say "made $Destination"
}

$target = "$User@$ServerHost"

if (-not (Test-Path $KeyFile)) {
    Say "no ssh key at $KeyFile - cannot reach the server"
    exit 1
}

# IdentitiesOnly stops ssh wandering off to the agent or to default key
# names; IdentityAgent=none makes the scheduled run behave the same way as
# a desktop one, rather than passing by luck when someone happens to be
# logged in.
$sshArgs = @(
    '-i', $KeyFile,
    '-o', 'IdentitiesOnly=yes',
    '-o', 'IdentityAgent=none',
    '-o', 'BatchMode=yes',
    '-o', 'ConnectTimeout=20'
)

Say 'asking the server what it has'
$remote = & ssh -p $Port @sshArgs $target "ls -1 $RemoteDir/*.sql.gz 2>/dev/null"

if ($LASTEXITCODE -ne 0 -or -not $remote) {
    Say 'could not reach the server, or it has no backups yet'
    exit 1
}

$fetched = 0
$skipped = 0

foreach ($path in $remote) {
    $path = $path.Trim()
    if (-not $path) { continue }

    $name  = Split-Path $path -Leaf
    $local = Join-Path $Destination $name

    # Already here and readable, so there is nothing to fetch. A local copy
    # that fails the check is pulled again rather than trusted.
    if ((Test-Path $local) -and (Test-GzipReadable $local)) {
        $skipped++
        continue
    }

    & scp -P $Port @sshArgs "${target}:$path" $local 2>&1 | Out-Null

    if (-not (Test-Path $local)) {
        Say "FAILED to copy $name"
        continue
    }

    if (-not (Test-GzipReadable $local)) {
        # Better no copy than a corrupt one wearing a trustworthy name.
        Remove-Item $local -Force
        Say "DISCARDED $name - arrived corrupt"
        continue
    }

    $size = [math]::Round((Get-Item $local).Length / 1KB)
    Say "pulled $name (${size} KB)"
    $fetched++
}

# Newest first, so everything past the keep count is the old end.
$local = Get-ChildItem -Path $Destination -Filter '*.sql.gz' | Sort-Object LastWriteTime -Descending

foreach ($old in ($local | Select-Object -Skip $Keep)) {
    Remove-Item $old.FullName -Force
    Say "pruned $($old.Name)"
}

$held = (Get-ChildItem -Path $Destination -Filter '*.sql.gz').Count
Say "done - $fetched new, $skipped already held, $held on disk"
