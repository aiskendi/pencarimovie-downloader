param(
    [int]$Port = 8088,
    [string]$OpenUrl = '',
    [string]$StopBat = '',
    [string]$IconPath = '',
    [string]$PidFile = '',
    [switch]$StartServer
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path

function Write-TrayLog([string]$message) {
    try {
        $logDir = Join-Path $root 'storage'
        if (-not (Test-Path -LiteralPath $logDir)) {
            New-Item -ItemType Directory -Path $logDir | Out-Null
        }
        Add-Content -LiteralPath (Join-Path $logDir 'tray.log') -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message)
    }
    catch {
    }
}

function ConvertTo-CommandLine([string[]]$items) {
    if (-not $items -or $items.Count -eq 0) { return '' }
    return (
        $items | ForEach-Object {
            $a = [string]$_
            if ($a -notmatch '[ \t"]') { $a }
            else { '"' + ($a -replace '"', '\"') + '"' }
        }
    ) -join ' '
}

function Resolve-LaunchPath([string]$path) {
    if ([string]::IsNullOrWhiteSpace($path)) {
        throw 'FileName is required'
    }
    if (Test-Path -LiteralPath $path) {
        return (Resolve-Path -LiteralPath $path).ProviderPath
    }
    $cmd = Get-Command $path -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($cmd) {
        if ($cmd.Source) { return $cmd.Source }
        if ($cmd.Path) { return $cmd.Path }
    }
    throw "File not found: $path"
}

if (-not ('PencariMovieHiddenStart' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;

public static class PencariMovieHiddenStart {
    [StructLayout(LayoutKind.Sequential)]
    private struct SECURITY_ATTRIBUTES {
        public int nLength;
        public IntPtr lpSecurityDescriptor;
        public int bInheritHandle;
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct STARTUPINFO {
        public int cb;
        public string lpReserved;
        public string lpDesktop;
        public string lpTitle;
        public int dwX;
        public int dwY;
        public int dwXSize;
        public int dwYSize;
        public int dwXCountChars;
        public int dwYCountChars;
        public int dwFillAttribute;
        public int dwFlags;
        public short wShowWindow;
        public short cbReserved2;
        public IntPtr lpReserved2;
        public IntPtr hStdInput;
        public IntPtr hStdOutput;
        public IntPtr hStdError;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct PROCESS_INFORMATION {
        public IntPtr hProcess;
        public IntPtr hThread;
        public int dwProcessId;
        public int dwThreadId;
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr CreateFileW(
        string lpFileName,
        uint dwDesiredAccess,
        uint dwShareMode,
        ref SECURITY_ATTRIBUTES lpSecurityAttributes,
        uint dwCreationDisposition,
        uint dwFlagsAndAttributes,
        IntPtr hTemplateFile);

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool CreateProcessW(
        string lpApplicationName,
        StringBuilder lpCommandLine,
        IntPtr lpProcessAttributes,
        IntPtr lpThreadAttributes,
        bool bInheritHandles,
        uint dwCreationFlags,
        IntPtr lpEnvironment,
        string lpCurrentDirectory,
        ref STARTUPINFO lpStartupInfo,
        out PROCESS_INFORMATION lpProcessInformation);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool CloseHandle(IntPtr hObject);

    private const uint GENERIC_READ = 0x80000000;
    private const uint GENERIC_WRITE = 0x40000000;
    private const uint FILE_SHARE_READ = 0x00000001;
    private const uint FILE_SHARE_WRITE = 0x00000002;
    private const uint OPEN_EXISTING = 3;
    private const uint FILE_ATTRIBUTE_NORMAL = 0x00000080;
    private const uint CREATE_NO_WINDOW = 0x08000000;
    private const uint CREATE_NEW_PROCESS_GROUP = 0x00000200;
    private const int STARTF_USESHOWWINDOW = 0x00000001;
    private const int STARTF_USESTDHANDLES = 0x00000100;

    public static int Start(string fileName, string arguments, string workingDirectory, bool redirectStdio) {
        if (string.IsNullOrWhiteSpace(fileName)) {
            throw new ArgumentException("FileName is required");
        }

        var si = new STARTUPINFO();
        si.cb = Marshal.SizeOf(typeof(STARTUPINFO));
        si.dwFlags = STARTF_USESHOWWINDOW;
        si.wShowWindow = 0;

        IntPtr nul = IntPtr.Zero;
        bool inherit = false;
        if (redirectStdio) {
            var sa = new SECURITY_ATTRIBUTES();
            sa.nLength = Marshal.SizeOf(typeof(SECURITY_ATTRIBUTES));
            sa.bInheritHandle = 1;
            nul = CreateFileW(
                @"\\.\NUL",
                GENERIC_READ | GENERIC_WRITE,
                FILE_SHARE_READ | FILE_SHARE_WRITE,
                ref sa,
                OPEN_EXISTING,
                FILE_ATTRIBUTE_NORMAL,
                IntPtr.Zero);
            if (nul == new IntPtr(-1)) {
                throw new Win32Exception(Marshal.GetLastWin32Error(), "CreateFile NUL failed");
            }
            si.dwFlags |= STARTF_USESTDHANDLES;
            si.hStdInput = nul;
            si.hStdOutput = nul;
            si.hStdError = nul;
            inherit = true;
        }

        try {
            var cmd = new StringBuilder();
            cmd.Append('"').Append(fileName).Append('"');
            if (!string.IsNullOrWhiteSpace(arguments)) {
                cmd.Append(' ').Append(arguments);
            }

            PROCESS_INFORMATION pi;
            bool ok = CreateProcessW(
                fileName,
                cmd,
                IntPtr.Zero,
                IntPtr.Zero,
                inherit,
                CREATE_NO_WINDOW | CREATE_NEW_PROCESS_GROUP,
                IntPtr.Zero,
                string.IsNullOrWhiteSpace(workingDirectory) ? null : workingDirectory,
                ref si,
                out pi);
            if (!ok) {
                throw new Win32Exception(Marshal.GetLastWin32Error(), "CreateProcess failed");
            }

            int pid = pi.dwProcessId;
            if (pi.hThread != IntPtr.Zero) { CloseHandle(pi.hThread); }
            if (pi.hProcess != IntPtr.Zero) { CloseHandle(pi.hProcess); }
            return pid;
        }
        finally {
            if (nul != IntPtr.Zero && nul != new IntPtr(-1)) {
                CloseHandle(nul);
            }
        }
    }
}
'@
}

function Start-HiddenProcess {
    param(
        [Parameter(Mandatory = $true)][string]$FileName,
        [string[]]$Arguments = @(),
        [string]$WorkingDirectory = $root
    )

    $resolved = Resolve-LaunchPath $FileName
    $argLine = ConvertTo-CommandLine $Arguments
    $useNul = $true
    if ($resolved -match '(?i)[\\/]?(powershell|pwsh|powershell_ise)\.exe$') {
        $useNul = $false
    }
    $childPid = [PencariMovieHiddenStart]::Start($resolved, $argLine, $WorkingDirectory, $useNul)
    if ($childPid -le 0) { return $null }
    for ($i = 0; $i -lt 20; $i++) {
        $proc = Get-Process -Id $childPid -ErrorAction SilentlyContinue
        if ($proc) { return $proc }
        Start-Sleep -Milliseconds 50
    }
    return $null
}

if ([Threading.Thread]::CurrentThread.GetApartmentState() -ne 'STA') {
    $argList = @(
        '-NoProfile',
        '-STA',
        '-WindowStyle', 'Hidden',
        '-ExecutionPolicy', 'Bypass',
        '-File', $PSCommandPath,
        '-Port', "$Port"
    )
    if ($OpenUrl) { $argList += @('-OpenUrl', $OpenUrl) }
    if ($StopBat) { $argList += @('-StopBat', $StopBat) }
    if ($IconPath) { $argList += @('-IconPath', $IconPath) }
    if ($PidFile) { $argList += @('-PidFile', $PidFile) }
    if ($StartServer) { $argList += '-StartServer' }
    Start-HiddenProcess -FileName 'powershell.exe' -Arguments $argList | Out-Null
    exit 0
}

if (-not $OpenUrl) { $OpenUrl = "http://127.0.0.1:$Port" }
if (-not $StopBat) { $StopBat = Join-Path $root 'stop.bat' }
if (-not $IconPath) { $IconPath = Join-Path $root 'tray.ico' }
if (-not $PidFile) { $PidFile = Join-Path $root 'storage\tray.pid' }

$createdNew = $false
$mutex = New-Object System.Threading.Mutex($true, 'Global\PencariMovieServerTray', [ref]$createdNew)
if (-not $createdNew) {
    exit 0
}

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$notify = $null
$icon = $null
$timer = $null
$appContext = $null
$stopping = $false
$missedChecks = 0
$startedAt = Get-Date
$serverProc = $null
$stopEvent = New-Object System.Threading.EventWaitHandle $false, ([System.Threading.EventResetMode]::AutoReset), 'Global\PencariMovieServerTrayStop'

function Get-TrayIcon {
    if (Test-Path -LiteralPath $IconPath) {
        return New-Object System.Drawing.Icon $IconPath
    }

    $pngPath = Join-Path $root 'tray.png'
    if (Test-Path -LiteralPath $pngPath) {
        $bmp = New-Object System.Drawing.Bitmap $pngPath
        $hicon = $bmp.GetHicon()
        $fromBmp = [System.Drawing.Icon]::FromHandle($hicon)
        $clone = [System.Drawing.Icon]$fromBmp.Clone()
        $bmp.Dispose()
        return $clone
    }

    return [System.Drawing.SystemIcons]::Application
}

function Test-ServerListening {
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $async = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        $ok = $async.AsyncWaitHandle.WaitOne(400, $false)
        $connected = $ok -and $client.Connected
        if ($ok) {
            try { $client.EndConnect($async) } catch { $connected = $false }
        }
        $client.Close()
        return $connected
    }
    catch {
        return $false
    }
}

function Start-AppServer {
    if (Test-ServerListening) {
        Write-TrayLog "server already listening on $Port"
        return
    }

    $franken = Join-Path $root 'bin\frankenphp.exe'
    if (Test-Path -LiteralPath $franken) {
        Write-TrayLog "starting hidden frankenphp $franken"
        $script:serverProc = Start-HiddenProcess -FileName $franken -Arguments @('php-server', '--listen', "0.0.0.0:$Port", '--root', $root)
        if ($script:serverProc) {
            Write-TrayLog "frankenphp pid=$($script:serverProc.Id)"
        }
        return
    }

    $php = Get-Command php -ErrorAction SilentlyContinue | Select-Object -First 1
    $phpPath = $null
    if ($php) {
        if ($php.Source) { $phpPath = $php.Source }
        elseif ($php.Path) { $phpPath = $php.Path }
    }
    if ($phpPath) {
        Write-TrayLog "starting hidden php $phpPath"
        $script:serverProc = Start-HiddenProcess -FileName $phpPath -Arguments @('-S', "0.0.0.0:$Port", 'router.php')
        if ($script:serverProc) {
            Write-TrayLog "php pid=$($script:serverProc.Id)"
        }
        return
    }

    throw 'PHP or FrankenPHP is required but was not found.'
}

function Stop-AppServer {
    if ($script:serverProc) {
        try {
            if (-not $script:serverProc.HasExited) {
                Write-TrayLog "stopping server pid=$($script:serverProc.Id)"
                Stop-Process -Id $script:serverProc.Id -Force -ErrorAction SilentlyContinue
            }
        }
        catch {
        }
        $script:serverProc = $null
    }
}

function Open-App {
    Start-Process $OpenUrl | Out-Null
}

function Stop-App {
    if ($stopping) { return }
    $script:stopping = $true
    Hide-Tray
    Stop-AppServer
    if (Test-Path -LiteralPath $StopBat) {
        Start-HiddenProcess -FileName 'cmd.exe' -Arguments @('/c', "`"$StopBat`"") | Out-Null
    }
    if ($appContext) {
        $appContext.ExitThread()
    }
}

function Hide-Tray {
    if ($timer) {
        $timer.Stop()
        $timer.Dispose()
        $script:timer = $null
    }
    if ($notify) {
        $notify.Visible = $false
        $notify.Dispose()
        $script:notify = $null
    }
    if ($icon) {
        $icon.Dispose()
        $script:icon = $null
    }
}

try {
    Write-TrayLog "starting pid=$PID port=$Port url=$OpenUrl startServer=$StartServer"
    $storageDir = Split-Path -Parent $PidFile
    if ($storageDir -and -not (Test-Path -LiteralPath $storageDir)) {
        New-Item -ItemType Directory -Path $storageDir | Out-Null
    }
    Set-Content -LiteralPath $PidFile -Value $PID -Encoding ASCII

    Start-AppServer
    for ($i = 0; $i -lt 15; $i++) {
        if (Test-ServerListening) { break }
        Start-Sleep -Milliseconds 200
    }
    if (-not (Test-ServerListening)) {
        Write-TrayLog "server not listening after start (startServer=$StartServer)"
    }

    $icon = Get-TrayIcon
    $notify = New-Object System.Windows.Forms.NotifyIcon
    $notify.Icon = $icon
    $notify.Text = 'PencariMovie Server'
    $notify.Visible = $true

    $menu = New-Object System.Windows.Forms.ContextMenuStrip
    $openItem = New-Object System.Windows.Forms.ToolStripMenuItem 'Open PencariMovie Server'
    $openItem.Add_Click({ Open-App })
    $stopItem = New-Object System.Windows.Forms.ToolStripMenuItem 'Stop Server'
    $stopItem.Add_Click({ Stop-App })
    [void]$menu.Items.Add($openItem)
    [void]$menu.Items.Add((New-Object System.Windows.Forms.ToolStripSeparator))
    [void]$menu.Items.Add($stopItem)
    $notify.ContextMenuStrip = $menu
    $notify.Add_DoubleClick({ Open-App })
    $notify.ShowBalloonTip(2500, 'PencariMovie Server', "Running at $OpenUrl", [System.Windows.Forms.ToolTipIcon]::None)

    $timer = New-Object System.Windows.Forms.Timer
    $timer.Interval = 1000
    $timer.Add_Tick({
            if ($stopping) { return }
            if ($stopEvent -and $stopEvent.WaitOne(0)) {
                Stop-App
                return
            }
            if (Test-ServerListening) {
                $script:missedChecks = 0
                return
            }
            if ($script:serverProc -and $script:serverProc.HasExited) {
                Write-TrayLog "server process exited code=$($script:serverProc.ExitCode); restarting"
                $script:serverProc = $null
            }
            Start-AppServer
            $script:missedChecks++
            $aliveFor = ((Get-Date) - $startedAt).TotalSeconds
            if ($aliveFor -ge 60 -and $script:missedChecks -ge 5) {
                Write-TrayLog "server not listening after ${aliveFor}s"
                Stop-App
            }
        })
    $timer.Start()

    $appContext = New-Object System.Windows.Forms.ApplicationContext
    [System.Windows.Forms.Application]::Run($appContext)
}
catch {
    Write-TrayLog ("error: " + $_.Exception.Message)
    throw
}
finally {
    Write-TrayLog "exiting pid=$PID"
    Hide-Tray
    Stop-AppServer
    if (Test-Path -LiteralPath $PidFile) {
        $current = (Get-Content -LiteralPath $PidFile -ErrorAction SilentlyContinue | Select-Object -First 1)
        if ("$current" -eq "$PID") {
            Remove-Item -LiteralPath $PidFile -Force -ErrorAction SilentlyContinue
        }
    }
    if ($stopEvent) {
        $stopEvent.Dispose()
        $script:stopEvent = $null
    }
    if ($mutex) {
        try { $mutex.ReleaseMutex() } catch { }
        $mutex.Dispose()
    }
}
