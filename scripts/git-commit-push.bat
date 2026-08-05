@echo off
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul

rem ============================================================
rem  EFPIC-GALLERY — commit + push (Windows)
rem  Lietošana: veici izmaiņas, palaid šo failu, ievadi ziņu.
rem ============================================================

cd /d "%~dp0.."
if errorlevel 1 (
  echo [KLUDA] Neizdevas atrast projekta sakni.
  pause
  exit /b 1
)

set "GIT=git -c safe.directory=%CD:\=/%"

echo.
echo === Git status ===
%GIT% status --short --branch
echo.

rem Vai ir ko commitot?
%GIT% status --porcelain > "%TEMP%\efpic_git_status.txt"
for %%A in ("%TEMP%\efpic_git_status.txt") do set "STATUS_SIZE=%%~zA"
if "%STATUS_SIZE%"=="0" (
  echo Nav izmainu ko commitot.
  pause
  exit /b 0
)

echo Ko pievienot staging?
echo   1 = Visas izmainitas/untracked faili  (git add -A)
echo   2 = Tikai jau izsekotie izmainitie   (git add -u)
echo   3 = Pats noradisu failu celus
echo.
set /p "MODE=Izvele [1/2/3]: "
if "%MODE%"=="" set "MODE=2"

if "%MODE%"=="1" (
  %GIT% add -A
) else if "%MODE%"=="3" (
  echo.
  echo Ievadi failu celus atdalitus ar atstarpi.
  echo Piemers: web/api/VERSION web/api/lib.php
  set /p "FILES=Faili: "
  if "!FILES!"=="" (
    echo [KLUDA] Nav noraditi faili.
    pause
    exit /b 1
  )
  %GIT% add -- !FILES!
) else (
  rem noklusejums: tikai tracked
  %GIT% add -u
)

echo.
echo === Staging ===
%GIT% diff --cached --stat
echo.

set /p "MSG=Commit zina: "
if "%MSG%"=="" (
  echo [KLUDA] Commit zina nedrikst but tuksa.
  pause
  exit /b 1
)

%GIT% commit -m "%MSG%"
if errorlevel 1 (
  echo [KLUDA] Commit neizdevas.
  pause
  exit /b 1
)

echo.
echo === Push uz origin ===
%GIT% push origin HEAD
if errorlevel 1 (
  echo [KLUDA] Push neizdevas.
  pause
  exit /b 1
)

echo.
echo === Gatavs ===
%GIT% log -1 --oneline
%GIT% status --short --branch
echo.
pause
endlocal
