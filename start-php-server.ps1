# PHP 内置服务器启动脚本
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "启动 PHP 内置服务器" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "项目目录: $(Get-Location)" -ForegroundColor Green
Write-Host "访问地址: http://localhost:8000" -ForegroundColor Green
Write-Host ""
Write-Host "按 Ctrl+C 停止服务器" -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# 启动 PHP 内置服务器
php -S localhost:8000
