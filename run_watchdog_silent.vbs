Set WinScriptHost = CreateObject("WScript.Shell")
WinScriptHost.Run "cmd /c C:\laragon\www\Baytasks-api\run_watchdog.bat", 0, True
Set WinScriptHost = Nothing
