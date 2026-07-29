Set WinScriptHost = CreateObject("WScript.Shell")
WinScriptHost.Run "cmd /c C:\laragon\www\Baytasks-api\run_ngrok.bat", 0, False
Set WinScriptHost = Nothing