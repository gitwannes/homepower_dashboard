@echo off
REM --- file: helpers/p1-sync.bat ---
setlocal EnableDelayedExpansion

REM Local-only launcher (gitignored). Forwards to p1-sync.ps1.

set "PS_SCRIPT=%~dp0p1-sync.ps1"
set "MODE="
set "VERBOSE_ARG="
set "ALL_ARG="
set "TARGET_ARGS="

echo.
echo ======================================================================
echo P1 Sync Wrapper (PC -^> SMB)
echo Timestamp: %DATE% %TIME%
echo Host: %COMPUTERNAME%   User: %USERNAME%
echo ======================================================================
echo.

if not exist "%PS_SCRIPT%" (
    echo ERROR: PowerShell sync script not found:
    echo   "%PS_SCRIPT%"
    exit /b 3
)

:parse_args
if "%~1"=="" goto :validate_args
set "ARG=%~1"

if /I "%ARG%"=="help" goto :help
if /I "%ARG%"=="-h" goto :help
if /I "%ARG%"=="/?" goto :help
if /I "%ARG%"=="test" set "MODE=test" & shift & goto :parse_args
if /I "%ARG%"=="run" set "MODE=run" & shift & goto :parse_args
if /I "%ARG%"=="verbose" set "VERBOSE_ARG=-VerboseSync" & shift & goto :parse_args
if /I "%ARG%"=="-verbose" set "VERBOSE_ARG=-VerboseSync" & shift & goto :parse_args
if /I "%ARG%"=="--verbose" set "VERBOSE_ARG=-VerboseSync" & shift & goto :parse_args
if /I "%ARG%"=="all" set "ALL_ARG=-All" & shift & goto :parse_args
if /I "%ARG%"=="-all" set "ALL_ARG=-All" & shift & goto :parse_args
if /I "%ARG%"=="--all" set "ALL_ARG=-All" & shift & goto :parse_args

REM Target name from p1-sync.config.json keys
set "TARGET_ARGS=!TARGET_ARGS! -Target %ARG%"
shift
goto :parse_args

:validate_args
if "%MODE%"=="" goto :help

if "%ALL_ARG%"=="" if "!TARGET_ARGS!"=="" (
    echo ERROR: No target specified.
    echo Use -all or one or more target names from p1-sync.config.json.
    echo.
    goto :help
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SCRIPT%" -Mode %MODE% %ALL_ARG% !TARGET_ARGS! %VERBOSE_ARG%
set "RC=%ERRORLEVEL%"
exit /b %RC%

:help
echo Usage:
echo   .\p1-sync.bat test {-all ^| targetName ...} [verbose]
echo   .\p1-sync.bat run  {-all ^| targetName ...} [verbose]
echo.
echo Targets come from helpers\p1-sync.config.json (local only).
echo .env is never synced.
exit /b 1
