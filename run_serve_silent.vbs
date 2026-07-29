Set WinScriptHost = CreateObject("WScript.Shell")
WinScriptHost.Run "cmd /c C:\laragon\www\Baytasks-api\run_serve.bat", 0, False
Set WinScriptHost = Nothing