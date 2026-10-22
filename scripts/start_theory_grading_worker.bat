@echo off
setlocal
set "ROOT=%~dp0.."
for %%I in ("%ROOT%") do set "ROOT=%%~fI"

set "PHP_EXE="
if exist "%ROOT%\php\php.exe" set "PHP_EXE=%ROOT%\php\php.exe"
if not defined PHP_EXE if exist "%ROOT%\xampp\php\php.exe" set "PHP_EXE=%ROOT%\xampp\php\php.exe"
if not defined PHP_EXE if exist "%ROOT%\apache\bin\php.exe" set "PHP_EXE=%ROOT%\apache\bin\php.exe"
if not defined PHP_EXE if exist "%ROOT%\bin\php.exe" set "PHP_EXE=%ROOT%\bin\php.exe"
if not defined PHP_EXE for /f "delims=" %%P in ('where php.exe 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%P"

if not defined PHP_EXE (
    echo Examcenter PHP CLI was not found. Configure this launcher with the bundled php.exe path.
    exit /b 1
)

if not exist "%ROOT%\scripts\theory_grading_worker.php" (
    echo Theory grading worker script is missing.
    exit /b 1
)

start "Examcenter Theory Grading Worker" /min "%PHP_EXE%" "%ROOT%\scripts\theory_grading_worker.php"
exit /b 0
