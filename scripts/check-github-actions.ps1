param(
    [string] $Repo = 'matssz/trilhagov',
    [int] $Limit = 5,
    [int] $Attempts = 6,
    [int] $DelaySeconds = 10
)

$ErrorActionPreference = 'Stop'
$env:GODEBUG = if ($env:GODEBUG) { "$env:GODEBUG,http2client=0" } else { 'http2client=0' }
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Write-RunTable {
    param([object[]] $Runs)

    $Runs |
        Select-Object `
            @{ Name = 'CreatedAt'; Expression = { $_.createdAt } },
            @{ Name = 'Status'; Expression = { $_.status } },
            @{ Name = 'Conclusion'; Expression = { $_.conclusion } },
            @{ Name = 'Title'; Expression = { $_.displayTitle } },
            @{ Name = 'Sha'; Expression = { if ($_.headSha) { $_.headSha.Substring(0, 7) } else { '' } } },
            @{ Name = 'RunId'; Expression = { $_.databaseId } } |
        Format-Table -AutoSize
}

function Get-RunsWithGh {
    $raw = & gh run list `
        --repo $Repo `
        --limit $Limit `
        --json databaseId,displayTitle,status,conclusion,headSha,createdAt 2>&1

    if ($LASTEXITCODE -ne 0) {
        throw ($raw -join [Environment]::NewLine)
    }

    return @($raw | ConvertFrom-Json)
}

function Get-RunsWithRest {
    $owner, $name = $Repo.Split('/', 2)

    if (-not $owner -or -not $name) {
        throw "Invalid repo format. Use owner/name."
    }

    $token = (& gh auth token 2>$null)

    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($token)) {
        throw 'Could not read gh auth token for REST fallback.'
    }

    $headers = @{
        Authorization         = "Bearer $($token.Trim())"
        Accept                = 'application/vnd.github+json'
        'X-GitHub-Api-Version' = '2022-11-28'
        'User-Agent'          = 'trilhagov-actions-check'
    }

    $uri = "https://api.github.com/repos/$owner/$name/actions/runs?per_page=$Limit&exclude_pull_requests=true"
    $response = Invoke-RestMethod -Uri $uri -Headers $headers -TimeoutSec 45

    return @(
        $response.workflow_runs | ForEach-Object {
            [pscustomobject] @{
                databaseId   = $_.id
                displayTitle = $_.display_title
                status       = $_.status
                conclusion   = $_.conclusion
                headSha      = $_.head_sha
                createdAt    = $_.created_at
            }
        }
    )
}

$lastError = $null

for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
    try {
        Write-Host "Checking GitHub Actions ($attempt/$Attempts) for $Repo with gh..."

        try {
            $runs = Get-RunsWithGh
        } catch {
            $ghError = $_.Exception.Message
            Write-Warning "gh failed: $ghError"
            Write-Host "Trying GitHub REST fallback..."
            $runs = Get-RunsWithRest
        }

        Write-RunTable -Runs $runs

        $blockingRuns = @(
            $runs | Where-Object {
                $_.status -eq 'completed' -and
                $_.conclusion -and
                $_.conclusion -notin @('success', 'skipped')
            }
        )

        if ($blockingRuns.Count -gt 0) {
            Write-Error "GitHub Actions returned completed runs with failures. Open the RunId above for details."
            exit 2
        }

        exit 0
    } catch {
        $lastError = $_.Exception.Message

        if ($attempt -eq $Attempts) {
            break
        }

        $sleepFor = $DelaySeconds * $attempt
        Write-Warning "GitHub API check failed: $lastError"
        Write-Warning "Retrying in $sleepFor seconds..."
        Start-Sleep -Seconds $sleepFor
    }
}

Write-Error "Could not verify GitHub Actions after $Attempts attempts. Last error: $lastError"
exit 1
