Set WinScriptHost = CreateObject("WScript.Shell")
WinScriptHost.Run "cmd /c C:\laragon\www\Baytasks-api\run_tunnel.bat", 0, False
Set WinScriptHost = Nothing