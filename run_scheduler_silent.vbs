Set WinScriptHost = CreateObject("WScript.Shell")
WinScriptHost.Run "cmd /c C:\laragon\www\Baytasks-api\run_scheduler.bat", 0, False
Set WinScriptHost = Nothing
