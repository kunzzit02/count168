@echo off
echo ========================================
echo 启动 PHP 内置服务器
echo ========================================
echo.
echo 项目目录: %CD%
echo 访问地址: http://localhost:8000
echo.
echo 按 Ctrl+C 停止服务器
echo ========================================
echo.

php -S localhost:8000

pause
