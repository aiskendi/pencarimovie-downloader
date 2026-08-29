param(
    [Parameter(Mandatory = $true)]
    [string]$FilePath,
    [string]$CommandLine = '',
    [switch]$RedirectStdio,
    [switch]$NoStdioRedirect,
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$ArgumentList
)

# 9router Windows hide: Node spawn({ windowsHide: true, detached: true, stdio: "ignore" }).
# That is CreateProcess CREATE_NO_WINDOW + stdio to NUL, and do not wait.
# WScript.Shell.Run(..., 0) is only SW_HIDE and does not hide console EXEs.
# Start-Process -WindowStyle Hidden also does not set CREATE_NO_WINDOW.
# No extra .vbs file, so Stop + re-click start.bat cannot fail with "cannot find script file".
# PowerShell tray must NOT get stdin=NUL or -InputFormat Text: NUL is EOF and PowerShell exits.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path

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

function Resolve-LaunchPath([string]$path) {
    if ([string]::IsNullOrWhiteSpace($path)) {
        throw 'FilePath is required'
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

$resolved = Resolve-LaunchPath $FilePath
$arguments = $CommandLine
if ([string]::IsNullOrWhiteSpace($arguments)) {
    $argItems = @()
    if ($ArgumentList) {
        $argItems = @($ArgumentList | Where-Object { $_ -and $_ -ne '--' })
    }
    $arguments = ConvertTo-CommandLine $argItems
}

$useNul = $true
if ($NoStdioRedirect) {
    $useNul = $false
}
elseif ($RedirectStdio) {
    $useNul = $true
}
elseif ($resolved -match '(?i)[\\/]?(powershell|pwsh|powershell_ise)\.exe$') {
    $useNul = $false
}

[void][PencariMovieHiddenStart]::Start($resolved, $arguments, $root, $useNul)
