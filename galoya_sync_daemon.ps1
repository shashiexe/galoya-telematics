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
    [int]$IntervalSec = 15,
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
        $pullUrl = "$($ApiBase)&sub=pull_pending_cdns&limit=100"
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
                $type = if ($c.type) { $c.type.ToString().Trim() } else { "CC" }
                $caneCat = if ($c.cane_category_code) { $c.cane_category_code.ToString().Trim() } else { "CAT1" }
                $vehNo = if ($c.vehicle_no) { $c.vehicle_no.ToString().Trim() } else { "" }
                $trailerNo = if ($c.trailer_no) { $c.trailer_no.ToString().Trim() } else { "" }
                $cropCycle = if ($c.crop_cycle) { $c.crop_cycle.ToString().Trim() } else { "" }
                $cropCycleId = if ($c.crop_cycle_id) { $c.crop_cycle_id.ToString().Trim() } else { $null }
                $lstHvst = if ($c.lst_hvst_tonnage) { [int]$c.lst_hvst_tonnage } else { 0 }
                $user = if ($c.create_user) { $c.create_user.ToString().Trim() } else { "FieldOfficer" }
                $fa = if ($c.field_asst_no) { $c.field_asst_no.ToString().Trim() } else { "" }
                $isDeleted = if ($c.is_deleted) { [int]$c.is_deleted } else { 0 }
                $modUser = if ($c.modify_user) { $c.modify_user.ToString().Trim() } else { $user }

                $cmd = $conn.CreateCommand()
                $cmd.CommandText = "IF NOT EXISTS (SELECT 1 FROM [dbo].[CDN_Details] WHERE [cdn] = @cdn) BEGIN INSERT INTO [dbo].[CDN_Details] ([cdn], [CDNIssued], [Plot_No], [Type], [Cane_Catagory_Code], [vehicle_No], [Trailer_no], [Crop_Cycle], [CreateUser], [CreateTime], [FieldAssistant_No], [CropCycleID], [LstHvstTonnage], [WeigeTicket_No], [TonnageRecived], [Delete]) VALUES (@cdn, 1, @plot, @type, @cat, @veh, @trailer, @cycle, @user, GETDATE(), @fa, @cropCycleId, @lstHvst, NULL, '0', @del) END ELSE BEGIN UPDATE [dbo].[CDN_Details] SET [Plot_No] = CASE WHEN [WeigeTicket_No] IS NULL OR LTRIM(RTRIM([WeigeTicket_No])) = '' THEN @plot ELSE [Plot_No] END, [Type] = CASE WHEN [WeigeTicket_No] IS NULL OR LTRIM(RTRIM([WeigeTicket_No])) = '' THEN @type ELSE [Type] END, [Cane_Catagory_Code] = CASE WHEN [WeigeTicket_No] IS NULL OR LTRIM(RTRIM([WeigeTicket_No])) = '' THEN @cat ELSE [Cane_Catagory_Code] END, [vehicle_No] = CASE WHEN [WeigeTicket_No] IS NULL OR LTRIM(RTRIM([WeigeTicket_No])) = '' THEN @veh ELSE [vehicle_No] END, [Trailer_no] = CASE WHEN [WeigeTicket_No] IS NULL OR LTRIM(RTRIM([WeigeTicket_No])) = '' THEN @trailer ELSE [Trailer_no] END, [Crop_Cycle] = CASE WHEN [WeigeTicket_No] IS NULL OR LTRIM(RTRIM([WeigeTicket_No])) = '' THEN @cycle ELSE [Crop_Cycle] END, [FieldAssistant_No] = CASE WHEN [WeigeTicket_No] IS NULL OR LTRIM(RTRIM([WeigeTicket_No])) = '' THEN @fa ELSE [FieldAssistant_No] END, [CropCycleID] = CASE WHEN [WeigeTicket_No] IS NULL OR LTRIM(RTRIM([WeigeTicket_No])) = '' THEN @cropCycleId ELSE [CropCycleID] END, [LstHvstTonnage] = @lstHvst, [Delete] = @del, [modifyUser] = @modUser, [modifyDate] = GETDATE() WHERE [cdn] = @cdn END"
                $cmd.Parameters.AddWithValue("@cdn", $cdnNo) | Out-Null
                $cmd.Parameters.AddWithValue("@plot", $plotNo) | Out-Null
                $cmd.Parameters.AddWithValue("@type", $type) | Out-Null
                $cmd.Parameters.AddWithValue("@cat", $caneCat) | Out-Null
                $cmd.Parameters.AddWithValue("@veh", $vehNo) | Out-Null
                $cmd.Parameters.AddWithValue("@trailer", $trailerNo) | Out-Null
                $cmd.Parameters.AddWithValue("@cycle", $cropCycle) | Out-Null
                $cropCycleVal = if ($cropCycleId) { $cropCycleId } else { [DBNull]::Value }
                $cmd.Parameters.AddWithValue("@cropCycleId", $cropCycleVal) | Out-Null
                $cmd.Parameters.AddWithValue("@lstHvst", $lstHvst) | Out-Null
                $cmd.Parameters.AddWithValue("@del", $isDeleted) | Out-Null
                $cmd.Parameters.AddWithValue("@user", $user) | Out-Null
                $cmd.Parameters.AddWithValue("@fa", $fa) | Out-Null
                $cmd.Parameters.AddWithValue("@modUser", $modUser) | Out-Null

                $cmd.ExecuteNonQuery() | Out-Null
                $syncedCdns.Add($cdnNo)
                Log-Write "  -> Synced CDN to MSSQL: $cdnNo (Plot: $plotNo, Vehicle: $vehNo, Crop: $cropCycle, CropCycleID: $cropCycleId, FinalLoad: $lstHvst, Del: $isDeleted)" "Green"
            }

            if ($syncedCdns.Count -gt 0) {
                # Mark as synced in cloud
                $markBody = @{ cdns = $syncedCdns } | ConvertTo-Json
                $markRes = Invoke-RestMethod -Uri "$($ApiBase)&sub=mark_cdn_synced" -Method Post -Body $markBody -Headers $Headers -ContentType "application/json" -TimeoutSec 15
                Log-Write "✅ Marked $($syncedCdns.Count) CDN(s) as synced in MySQL cloud." "Green"
            }
        } else {
            Log-Write "ℹ️ No pending CDNs in cloud queue." "DarkGray"
        }

        # ----------------------------------------------------
        # 2. PUSH COMPLETED WEIGHBRIDGE TICKETS -> CLOUD
        # ----------------------------------------------------
        $wbtCmd = $conn.CreateCommand()
        $wbtCmd.CommandText = "SELECT [cdn], [WeigeTicket_No], [net_weight], [TonnageRecived], [WBTCreate_Time], [modifyDate] FROM [dbo].[CDN_Details] WHERE [WeigeTicket_No] IS NOT NULL AND LTRIM(RTRIM([WeigeTicket_No])) != '' AND ([Delete] = 0 OR [Delete] IS NULL)"
        $reader = $wbtCmd.ExecuteReader()
        $wbtBatch = [System.Collections.Generic.List[PSObject]]::new()

        while ($reader.Read()) {
            $cdn = $reader["cdn"].ToString().Trim()
            $wbt = $reader["WeigeTicket_No"].ToString().Trim()
            $rawNet = $reader["net_weight"]
            $netWeight = 0.0
            if ($rawNet -ne [DBNull]::Value -and $rawNet -ne $null) {
                $netWeight = [double]$rawNet
            }
            $rawWbtTime = $reader["WBTCreate_Time"]
            $wbtTime = $null
            if ($rawWbtTime -ne [DBNull]::Value -and $rawWbtTime -ne $null) {
                $wbtTime = ([DateTime]$rawWbtTime).ToString("yyyy-MM-dd HH:mm:ss")
            }
            $rawMDate = $reader["modifyDate"]
            $mDate = Get-Date
            if ($rawMDate -ne [DBNull]::Value -and $rawMDate -ne $null) {
                $mDate = [DateTime]$rawMDate
            }
            $finalWbtTime = if ($wbtTime) { $wbtTime } else { $mDate.ToString("yyyy-MM-dd HH:mm:ss") }

            $wbtBatch.Add([PSCustomObject]@{
                cdn             = $cdn
                WeigeTicket_No  = $wbt
                net_weight      = $netWeight
                WBTCreate_Time  = $wbtTime
                weighed_date    = $finalWbtTime
            })
        }
        $reader.Close()

        if ($wbtBatch.Count -gt 0) {
            Log-Write "⚖️ Found $($wbtBatch.Count) completed Weighbridge ticket(s) in MSSQL..." "Yellow"
            $wbtBody = @{ items = $wbtBatch } | ConvertTo-Json -Depth 5
            $wbtUri = "$($ApiBase)&sub=push_wbt_batch"
            $wbtRes = Invoke-RestMethod -Uri $wbtUri -Method Post -Body $wbtBody -Headers $Headers -ContentType "application/json" -TimeoutSec 15
            Log-Write "✅ Cloud updated with $($wbtRes.updated) completed weighbridge record(s)!" "Green"
        } else {
            Log-Write "ℹ️ No new completed weighbridge tickets." "DarkGray"
        }

        # ----------------------------------------------------
        # 3. PERIODIC MASTER SYNC (Every 60 cycles = 15 minutes with 15s interval)
        # ----------------------------------------------------
        if ($CycleCounter % 60 -eq 1) {
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
                Invoke-RestMethod -Uri "$($ApiBase)&sub=push_master_batch" -Method Post -Body $vBody -Headers $Headers -ContentType "application/json" -TimeoutSec 30 | Out-Null
                Log-Write "  -> Synced $($vList.Count) active vehicles." "Green"
            }

            # Sync Trailers
            $tCmd = $conn.CreateCommand()
            $tCmd.CommandText = "SELECT [Trailerno], [Trailer], [Weight], [VehicleNumber] FROM [dbo].[tblma_tailer] WHERE [Deleted_Date] IS NULL"
            $tr = $tCmd.ExecuteReader()
            $tList = [System.Collections.Generic.List[PSObject]]::new()
            while ($tr.Read()) {
                $tList.Add([PSCustomObject]@{
                    Trailerno     = $tr["Trailerno"].ToString().Trim()
                    Trailer       = if ($tr["Trailer"] -ne [DBNull]::Value) { $tr["Trailer"].ToString().Trim() } else { "" }
                    Weight        = if ($tr["Weight"] -ne [DBNull]::Value) { [double]$tr["Weight"] } else { 0.0 }
                    VehicleNumber = if ($tr["VehicleNumber"] -ne [DBNull]::Value) { $tr["VehicleNumber"].ToString().Trim() } else { "" }
                })
            }
            $tr.Close()
            if ($tList.Count -gt 0) {
                for ($i = 0; $i -lt $tList.Count; $i += 400) {
                    $chunk = $tList.GetRange($i, [Math]::Min(400, $tList.Count - $i))
                    $tBody = @{ entity = 'trailers'; data = $chunk } | ConvertTo-Json -Depth 4
                    Invoke-RestMethod -Uri "$($ApiBase)&sub=push_master_batch" -Method Post -Body $tBody -Headers $Headers -ContentType "application/json" -TimeoutSec 30 | Out-Null
                }
                Log-Write "  -> Synced $($tList.Count) trailers." "Green"
            }

            # Sync Active Standing Crops
            $cCmd = $conn.CreateCommand()
            $cCmd.CommandText = "SELECT [ID], [Plot_No], [Crop_Cycle], [Crop_Extent], [Variety], [Status], [Age_Month], 0 AS is_agronomy FROM [dbo].[TblCropCycle] WHERE [Status] = 1 UNION ALL SELECT [ID], [Plot_No], [Crop_Cycle], [Crop_Extent], [Variety], [Status], [Age_Month], 1 AS is_agronomy FROM [dbo].[Agronomy_TblCropCycle] WHERE [Status] = 1"
            $cr = $cCmd.ExecuteReader()
            $cList = [System.Collections.Generic.List[PSObject]]::new()
            while ($cr.Read()) {
                $cList.Add([PSCustomObject]@{
                    ID          = $cr["ID"].ToString().Trim()
                    Plot_No     = $cr["Plot_No"].ToString().Trim()
                    Crop_Cycle  = if ($cr["Crop_Cycle"] -ne [DBNull]::Value) { $cr["Crop_Cycle"].ToString().Trim() } else { "" }
                    Crop_Extent = if ($cr["Crop_Extent"] -ne [DBNull]::Value) { [double]$cr["Crop_Extent"] } else { 0.0 }
                    Variety     = if ($cr["Variety"] -ne [DBNull]::Value) { $cr["Variety"].ToString().Trim() } else { "" }
                    Status      = 1
                    Age_Month   = if ($cr["Age_Month"] -ne [DBNull]::Value) { $cr["Age_Month"].ToString().Trim() } else { "" }
                    is_agronomy = [int]$cr["is_agronomy"]
                })
            }
            $cr.Close()
            if ($cList.Count -gt 0) {
                for ($i = 0; $i -lt $cList.Count; $i += 500) {
                    $chunk = $cList.GetRange($i, [Math]::Min(500, $cList.Count - $i))
                    $cBody = @{ entity = 'crops'; data = $chunk } | ConvertTo-Json -Depth 4
                    Invoke-RestMethod -Uri "$($ApiBase)&sub=push_master_batch" -Method Post -Body $cBody -Headers $Headers -ContentType "application/json" -TimeoutSec 30 | Out-Null
                }
                Log-Write "  -> Synced $($cList.Count) active standing crops." "Green"
            }

            # Sync Plot Nominees & Permissions (Plots with Nominee or Permissions)
            $pCmd = $conn.CreateCommand()
            $pCmd.CommandText = @"
SELECT [Plot_No], [PlotManual_Code], [Sector_No], [Farmer_No], [Nominee], 
       CASE WHEN [PaymentPermission] = 1 THEN 1 ELSE 0 END AS [PaymentPermission], 
       CASE WHEN [DebitPermission] = 1 THEN 1 ELSE 0 END AS [DebitPermission], 
       [Block_ID], [Land_Area], 
       [Payment_Catagory], [FieldAsst_No], [Registration_No], [method], [Ownership], 
       CASE WHEN [Is_Active] = 0 THEN 0 ELSE 1 END AS [Is_Active]
FROM [dbo].[tblMa_Plot]
WHERE ([Nominee] IS NOT NULL AND LTRIM(RTRIM([Nominee])) <> '') OR [PaymentPermission] = 1 OR [DebitPermission] = 1
"@
            $pr = $pCmd.ExecuteReader()
            $pList = [System.Collections.Generic.List[PSObject]]::new()
            while ($pr.Read()) {
                $pList.Add([PSCustomObject]@{
                    Plot_No           = $pr["Plot_No"].ToString().Trim()
                    PlotManual_Code   = if ($pr["PlotManual_Code"] -ne [DBNull]::Value) { $pr["PlotManual_Code"].ToString().Trim() } else { "" }
                    Sector_No         = if ($pr["Sector_No"] -ne [DBNull]::Value) { $pr["Sector_No"].ToString().Trim() } else { "" }
                    Farmer_No         = if ($pr["Farmer_No"] -ne [DBNull]::Value) { $pr["Farmer_No"].ToString().Trim() } else { "" }
                    Nominee           = if ($pr["Nominee"] -ne [DBNull]::Value) { $pr["Nominee"].ToString().Trim() } else { "" }
                    PaymentPermission = [int]$pr["PaymentPermission"]
                    DebitPermission   = [int]$pr["DebitPermission"]
                    Block_ID          = if ($pr["Block_ID"] -ne [DBNull]::Value) { [int]$pr["Block_ID"] } else { $null }
                    Land_Area         = if ($pr["Land_Area"] -ne [DBNull]::Value) { [double]$pr["Land_Area"] } else { 0.0 }
                    Payment_Catagory  = if ($pr["Payment_Catagory"] -ne [DBNull]::Value) { $pr["Payment_Catagory"].ToString().Trim() } else { "" }
                    FieldAsst_No      = if ($pr["FieldAsst_No"] -ne [DBNull]::Value) { $pr["FieldAsst_No"].ToString().Trim() } else { "" }
                    Registration_No   = if ($pr["Registration_No"] -ne [DBNull]::Value) { $pr["Registration_No"].ToString().Trim() } else { "" }
                    method            = if ($pr["method"] -ne [DBNull]::Value) { $pr["method"].ToString().Trim() } else { "" }
                    Ownership         = if ($pr["Ownership"] -ne [DBNull]::Value) { $pr["Ownership"].ToString().Trim() } else { "" }
                    Is_Active         = [int]$pr["Is_Active"]
                })
            }
            $pr.Close()
            if ($pList.Count -gt 0) {
                for ($i = 0; $i -lt $pList.Count; $i += 500) {
                    $chunk = $pList.GetRange($i, [Math]::Min(500, $pList.Count - $i))
                    $pBody = @{ entity = 'plots'; data = $chunk } | ConvertTo-Json -Depth 4
                    Invoke-RestMethod -Uri "$($ApiBase)&sub=push_master_batch" -Method Post -Body $pBody -Headers $Headers -ContentType "application/json" -TimeoutSec 30 | Out-Null
                }
                Log-Write "  -> Synced $($pList.Count) plots with nominees & permissions." "Green"
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
