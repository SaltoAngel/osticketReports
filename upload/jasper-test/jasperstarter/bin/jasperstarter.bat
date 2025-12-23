@echo off
setlocal enabledelayedexpansion

:: Configurar Java 8 específicamente
set JAVA_HOME=C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6
set JAVA_EXE="%JAVA_HOME%\bin\java.exe"

:: Ruta al JAR (mismo directorio que este batch)
set JAR_FILE=%~dp0jasperstarter.jar

:: Verificar que Java existe
if not exist %JAVA_EXE% (
    echo ERROR: Java 8 no encontrado en %JAVA_HOME%
    echo.
    echo Solucion: Instala Java 8 desde: https://adoptium.net/?variant=openjdk8
    exit /b 1
)

:: Verificar que el JAR existe
if not exist "%JAR_FILE%" (
    echo ERROR: jasperstarter.jar no encontrado
    echo Buscando en: %JAR_FILE%
    exit /b 1
)

:: Ejecutar JasperStarter con Java 8
echo Usando Java 8: %JAVA_HOME%
%JAVA_EXE% -jar "%JAR_FILE%" %*
endlocal