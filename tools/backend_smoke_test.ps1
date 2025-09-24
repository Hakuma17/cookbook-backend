# Backend smoke test for Cookbook API
# - PowerShell 5.1+ (Windows) compatible
# - Hits public endpoints and basic auth/cart flow
# - Saves session cookie to persist login

param(
  [string]$BaseUrl = "http://localhost/cookbookapp",
  [string]$Email = "",
  [securestring]$Password
)

$ProgressPreference = 'SilentlyContinue'

function Write-Title($text) {
  Write-Host "`n=== $text ===" -ForegroundColor Cyan
}

function Invoke-Api($Method, $Path, $Body = $null, $ContentType = "application/x-www-form-urlencoded") {
  $uri = "$BaseUrl/$Path".TrimEnd('/')
  try {
    if ($Method -eq 'GET') {
      return Invoke-WebRequest -UseBasicParsing -Method GET -Uri $uri -WebSession $global:Session
    } elseif ($ContentType -eq 'application/json') {
      $json = if ($Body -is [string]) { $Body } else { ($Body | ConvertTo-Json -Depth 5 -Compress) }
      return Invoke-WebRequest -UseBasicParsing -Method POST -Uri $uri -Body $json -ContentType 'application/json' -WebSession $global:Session
    } else {
      $form = if ($Body -is [string]) { $Body } else { ($Body.GetEnumerator() | ForEach-Object { "{0}={1}" -f [uri]::EscapeDataString($_.Key), [uri]::EscapeDataString([string]$_.Value) } -join '&') }
      return Invoke-WebRequest -UseBasicParsing -Method POST -Uri $uri -Body $form -ContentType 'application/x-www-form-urlencoded' -WebSession $global:Session
    }
  }
  catch {
    return $_.Exception.Response
  }
}

function Show-Result($resp, $label) {
  if ($null -eq $resp) { Write-Host ("{0}: no response" -f $label) -ForegroundColor Red; return }
  $code = $resp.StatusCode.value__
  $body = $resp.Content
  $ok = $code -ge 200 -and $code -lt 300
  $color = if ($ok) { 'Green' } else { 'Yellow' }
  Write-Host ("{0} [{1}]" -f $label, $code) -ForegroundColor $color
  try {
    $json = $body | ConvertFrom-Json
    if ($json.success -ne $true -and $ok) { Write-Host "  success: $($json.success)" -ForegroundColor Yellow }
    if ($json.data) {
      $cnt = if ($json.data -is [array]) { $json.data.Count } elseif ($json.count) { $json.count } else { 1 }
      Write-Host "  data: $cnt item(s)"
    }
    if ($json.message) { Write-Host "  message: $($json.message)" }
  } catch { Write-Host ("  body: {0}" -f ($body.Substring(0, [Math]::Min(200, $body.Length)))) }
}

# New web session for cookies
$global:Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

Write-Title "Ping"
$r = Invoke-Api GET 'ping.php'
Show-Result $r 'ping.php'

Write-Title "Popular recipes"
$r = Invoke-Api GET 'get_popular_recipes.php'
Show-Result $r 'get_popular_recipes.php'

Write-Title "New recipes"
$r = Invoke-Api GET 'get_new_recipes.php'
Show-Result $r 'get_new_recipes.php'

Write-Title "Unified search (name_asc)"
$r = Invoke-Api GET "search_recipes_unified.php?q=ผัด&sort=name_asc&limit=10"
Show-Result $r 'search_recipes_unified (name_asc)'

Write-Title "Unified search (popular)"
$r = Invoke-Api GET "search_recipes_unified.php?q=หมู&sort=popular&limit=10"
Show-Result $r 'search_recipes_unified (popular)'

Write-Title "Group listing (name_asc)"
$r = Invoke-Api GET "get_recipes_by_group.php?group=เนื้อสัตว์&sort=name_asc&limit=10"
Show-Result $r 'get_recipes_by_group (name_asc)'

if ($Email -and $Password) {
  # Convert SecureString to plain text for API call (kept in memory briefly)
  $PasswordPlain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($Password))
  Write-Title "Login"
  $r = Invoke-Api POST 'login.php' @{ email=$Email; password=$PasswordPlain }
  Show-Result $r 'login.php'

  Write-Title "Profile"
  $r = Invoke-Api GET 'get_profile.php'
  Show-Result $r 'get_profile.php'

  # Cart flow
  Write-Title "Add to cart"
  $r = Invoke-Api POST 'add_cart_item.php' @{ recipe_id=1; nServings=2 }
  Show-Result $r 'add_cart_item.php'

  Start-Sleep -Milliseconds 300

  Write-Title "Get cart items"
  $r = Invoke-Api GET 'get_cart_items.php'
  Show-Result $r 'get_cart_items.php'

  Write-Title "Remove from cart"
  $r = Invoke-Api POST 'remove_cart_item.php' @{ recipe_id=1 }
  Show-Result $r 'remove_cart_item.php'
}

Write-Host "`nDone." -ForegroundColor Cyan
