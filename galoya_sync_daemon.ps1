# GalOya ERP & Telematics Cloud Sync Daemon
# ==========================================
# Automated 2-Way Synchronization Service:
# 1. PULLS newly issued Cane Delivery Notes (CDNs) from Field Officers (track.galoya.lk) -> MSSQL ERP
# 2. PUSHES Factory Weighbridge Tickets & Gross/Tare weights from MSSQL ERP -> Cloud / Field Officers
# 3. PERIODICALLY SYNCS Extended Masters: Nominees, Active Vehicles, Trailers, Active Standing Crops & Plots
#
# Created by: GOPL IT Department
# Version: 2.1.0

param(
    [string]$MssqlServer = "124.43.10.210,41511",
    [string]$MssqlDb = "GaloyaTracker",
    [string]$MssqlUser = "sa",
    [string]$MssqlPass = "(GopliT@#4900%)",
    [string]$ApiBase = "https://track.galoya.lk/api.php?action=erp_sync_bridge&key=galoya2026",
    [int]$IntervalSec = 60,
    [switch]$RunOnce = $false
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$LogFile = Join-Path $ScriptDir "sync_daemon.log"
$MaxLogBytes = 5 * 1024 * 1024

$Headers = @{
    'User-Agent' = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
}

function Log-Write([string]$text, [string]$color = "White") {
    $ts = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    $line = "[$ts] $text"
    try {
        if ([Environment]::UserInteractive) {
            Write-Host $line -ForegroundColor $color
        }
    } catch {}
    try {
        if (Test-Path $LogFile) {
            $f = Get-Item $LogFile
            if ($f.Length -gt $MaxLogBytes) {
                $old = Join-Path $ScriptDir "sync_daemon.old.log"
                Move-Item -Path $LogFile -Destination $old -Force
            }
        }
        Add-Content -Path $LogFile -Value $line -Encoding utf8
    } catch {}
}

$MssqlConnStr = "Server=$MssqlServer;Database=$MssqlDb;User Id=$MssqlUser;Password=$MssqlPass;TrustServerCertificate=True;Connect Timeout=15;"

$CycleCounter = 0

function Execute-SyncCycle {
    $global:CycleCounter++
    Log-Write "--- Sync Cycle Started (Cycle #$CycleCounter) ---" "Cyan"

    try {
        $conn = New-Object System.Data.SqlClient.SqlConnection($MssqlConnStr)
        $conn.Open()
    } catch {
        Log-Write "❌ Failed to connect to MSSQL ($($_.Exception.Message))" "Red"
        return
    }

    try {
        # ----------------------------------------------------
        # 1. PULL PENDING CDNs FROM CLOUD -> INSERT TO MSSQL
        # ----------------------------------------------------
        $pullUrl = "$ApiBase&sub=pull_pending_cdns&limit=100"
        $pullRes = $null
        try {
            $pullRes = Invoke-RestMethod -Uri $pullUrl -Method Get -Headers $Headers -TimeoutSec 15
        } catch {
            Log-Write "⚠️ Cloud API unreachable: $($_.Exception.Message)" "Yellow"
        }

        if ($pullRes -and $pullRes.ok -and $pullRes.cdns.Count -gt 0) {
            Log-Write "🚚 Found $($pullRes.cdns.Count) pending CDN(s) from field officers..." "Yellow"
            $syncedCdns = [System.Collections.Generic.List[string]]::new()

            foreach ($c in $pullRes.cdns) {
                $cdnNo = $c.cdn.ToString().Trim()
                $plotNo = if ($c.plot_no) { $c.plot_no.ToString().Trim() } else { "" }
                $type = if ($c.type) { $c.type.ToString().Trim() } else { "OUT" }
                $caneCat = if ($c.cane_category_code) { $c.cane_category_code.ToString().Trim() } else { "CAT1" }
                $vehNo = if ($c.vehicle_no) { $c.vehicle_no.ToString().Trim() } else { "" }
                $trailerNo = if ($c.trailer_no) { $c.trailer_no.ToString().Trim() } else { "" }
                $cropCycle = if ($c.crop_cycle) { $c.crop_cycle.ToString().Trim() } else { "" }
                $user = if ($c.create_user) { $c.create_user.ToString().Trim() } else { "FieldOfficer" }
                $fa = if ($c.field_asst_no) { $c.field_asst_no.ToString().Trim() } else { "" }

                $cmd = $conn.CreateCommand()
                $cmd.CommandText = @"
IF NOT EXISTS (SELECT 1 FROM [dbo].[CDN_Details] WHERE [cdn] = @cdn)
BEGIN
    INSERT INTO [dbo].[CDN_Details] ([cdn], [CDNIssued], [Plot_No], [Type], [Cane_Catagory_Code], [vehicle_No], [Trailer_no], [Crop_Cycle], [CreateUser], [CreateTime], [FieldAssistant_No], [WeigeTicket_No], [TonnageRecived], [Delete])
    VALUES (@cdn, 1, @plot, @type, @cat, @veh, @trailer, @cycle, @user, GETDATE(), @fa, NULL, '0', 0)
END
"@
                $cmd.Parameters.AddWithValue("@cdn", $cdnNo) | Out-Null
                $cmd.Parameters.AddWithValue("@plot", $plotNo) | Out-Null
                $cmd.Parameters.AddWithValue("@type", $type) | Out-Null
                $cmd.Parameters.AddWithValue("@cat", $caneCat) | Out-Null
                $cmd.Parameters.AddWithValue("@veh", $vehNo) | Out-Null
                $cmd.Parameters.AddWithValue("@trailer", $trailerNo) | Out-Null
                $cmd.Parameters.AddWithValue("@cycle", $cropCycle) | Out-Null
                $cmd.Parameters.AddWithValue("@user", $user) | Out-Null
                $cmd.Parameters.AddWithValue("@fa", $fa) | Out-Null

                $cmd.ExecuteNonQuery() | Out-Null
                $syncedCdns.Add($cdnNo)
                Log-Write "  -> Inserted CDN: $cdnNo (Plot: $plotNo, Vehicle: $vehNo, Crop: $cropCycle, FA: $fa)" "Green"
            }

            if ($syncedCdns.Count -gt 0) {
                # Mark as synced in cloud
                $markBody = @{ cdns = $syncedCdns } | ConvertTo-Json
                $markRes = Invoke-RestMethod -Uri "$ApiBase&sub=mark_cdn_synced" -Method Post -Body $markBody -Headers $Headers -ContentType "application/json" -TimeoutSec 15
                Log-Write "✅ Marked $($syncedCdns.Count) CDN(s) as synced in MySQL cloud." "Green"
            }
        } else {
            Log-Write "ℹ️ No pending CDNs in cloud queue." "DarkGray"
        }

        # ----------------------------------------------------
        # 2. PUSH COMPLETED WEIGHBRIDGE TICKETS -> CLOUD
        # ----------------------------------------------------
        $wbtCmd = $conn.CreateCommand()
        $wbtCmd.CommandText = @"
SELECT [cdn], [WeigeTicket_No], [TonnageRecived], [modifyDate]
FROM [dbo].[CDN_Details]
WHERE [WeigeTicket_No] IS NOT NULL
  AND LTRIM(RTRIM([WeigeTicket_No])) != ''
  AND [TonnageRecived] IS NOT NULL
  AND LTRIM(RTRIM([TonnageRecived])) != '0'
  AND ([Delete] = 0 OR [Delete] IS NULL)
"@
        $reader = $wbtCmd.ExecuteReader()
        $wbtBatch = [System.Collections.Generic.List[PSObject]]::new()

        while ($reader.Read()) {
            $cdn = $reader["cdn"].ToString().Trim()
            $wbt = $reader["WeigeTicket_No"].ToString().Trim()
            $tonnage = [double]($reader["TonnageRecived"].ToString().Trim())
            $mDate = if ($reader["modifyDate"] -ne [DBNull]::Value) { [DateTime]$reader["modifyDate"] } else { Get-Date }

            $wbtBatch.Add([PSCustomObject]@{
                cdn             = $cdn
                WeigeTicket_No  = $wbt
                TonnageRecived  = $tonnage
                weighed_date    = $mDate.ToString("yyyy-MM-dd HH:mm:ss")
            })
        }
        $reader.Close()

        if ($wbtBatch.Count -gt 0) {
            Log-Write "⚖️ Found $($wbtBatch.Count) completed Weighbridge ticket(s) in MSSQL..." "Yellow"
            $wbtBody = @{ items = $wbtBatch } | ConvertTo-Json -Depth 5
            $wbtRes = Invoke-RestMethod -Uri "$ApiBase&sub=push_wbt_batch" -Method Post -Body $wbtBody -Headers $Headers -ContentType "application/json" -TimeoutSec 15
            Log-Write "✅ Cloud updated with $($wbtRes.updated) completed weighbridge record(s)!" "Green"
        } else {
            Log-Write "ℹ️ No new completed weighbridge tickets." "DarkGray"
        }

        # ----------------------------------------------------
        # 3. PERIODIC MASTER SYNC (Every 15 cycles ~ 15 minutes)
        # ----------------------------------------------------
        if ($CycleCounter % 15 -eq 1) {
            Log-Write "🔄 Running scheduled Master Tables sync (Vehicles, Trailers, Active Crops)..." "Cyan"
            
            # Sync Active Vehicles
            $vCmd = $conn.CreateCommand()
            $vCmd.CommandText = "SELECT [Vehicle_No], [Vehicle], [Contractor_No], [Transporter_No], [status] FROM [dbo].[tblMa_Vehicle] WHERE [status] = 'Active' AND ([deleted] = 0 OR [deleted] IS NULL)"
            $vr = $vCmd.ExecuteReader()
            $vList = [System.Collections.Generic.List[PSObject]]::new()
            while ($vr.Read()) {
                $vList.Add([PSCustomObject]@{
                    Vehicle_No     = $vr["Vehicle_No"].ToString().Trim()
                    Vehicle        = if ($vr["Vehicle"] -ne [DBNull]::Value) { $vr["Vehicle"].ToString().Trim() } else { "" }
                    Contractor_No  = if ($vr["Contractor_No"] -ne [DBNull]::Value) { $vr["Contractor_No"].ToString().Trim() } else { "" }
                    Transporter_No = if ($vr["Transporter_No"] -ne [DBNull]::Value) { $vr["Transporter_No"].ToString().Trim() } else { "" }
                    status         = "Active"
                })
            }
            $vr.Close()
            if ($vList.Count -gt 0) {
                $vBody = @{ entity = 'vehicles'; data = $vList } | ConvertTo-Json -Depth 4
                Invoke-RestMethod -Uri "$ApiBase&sub=push_master_batch" -Method Post -Body $vBody -Headers $Headers -ContentType "application/json" -TimeoutSec 30 | Out-Null
                Log-Write "  -> Synced $($vList.Count) active vehicles." "Green"
            }
        }

    } catch {
        Log-Write "❌ Sync Error: $($_.Exception.Message)" "Red"
    } finally {
        if ($conn -and $conn.State -eq [System.Data.ConnectionState]::Open) {
            $conn.Close()
        }
    }

    Log-Write "--- Sync Cycle Finished ---" "Cyan"
}

# Main Execution Loop
Log-Write "=================================================" "Green"
Log-Write "   GalOya ERP Sync Daemon v2.1.0 Started        " "Green"
Log-Write "=================================================" "Green"
Log-Write "MSSQL Host : $MssqlServer" "White"
Log-Write "Database   : $MssqlDb" "White"
Log-Write "Cloud API  : $ApiBase" "White"
Log-Write "Log File   : $LogFile" "White"

if ($RunOnce) {
    Execute-SyncCycle
} else {
    Log-Write "Running continuously every $IntervalSec seconds (Press Ctrl+C to stop)..." "Yellow"
    while ($true) {
        Execute-SyncCycle
        Start-Sleep -Seconds $IntervalSec
    }
}
