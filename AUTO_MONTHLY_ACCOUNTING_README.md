# 每月自动算账 - 定时任务配置说明

## 功能说明

- **脚本**：`auto_monthly_accounting.php`
- **作用**：每月 4 号下午 2:30 自动把 Process 的金额写入 Transaction：
  - **Buy Price（成本）** → 记入 **Supplier（Process 里选的账户）**
  - **Sell Price（卖价）** → 记入 **Customer（Process 里选的账户）**
  - **Profit（利润）** → 记入 **Company（Process 里选的利润账户）**

脚本**不会自己定时运行**，必须在服务器/本机配置**定时任务**才会在每月 4 号 14:30 执行。

---

## 一、Windows 计划任务（推荐）

1. 打开 **任务计划程序**（`taskschd.msc`）。
2. 右侧 **“创建基本任务”**。
3. 名称：例如 `Count168 每月4号自动算账`。
4. 触发器：**每月**，选 **4 号**，时间 **14:30**。
5. 操作：**启动程序**  
   - 程序：`php.exe` 的完整路径（例如 `C:\php\php.exe` 或 `C:\xampp\php\php.exe`）。  
   - 参数：`auto_monthly_accounting.php` 的完整路径，例如：  
     `C:\Users\User\OneDrive\Desktop\count168\auto_monthly_accounting.php`
6. 完成创建。

**“起始于”** 建议填项目目录，例如：  
`C:\Users\User\OneDrive\Desktop\count168`

---

## 二、Linux / 服务器 Cron

编辑 crontab：

```bash
crontab -e
```

添加一行（每月 4 号 14:30 执行，请把路径改成你服务器上的实际路径）：

```cron
30 14 4 * * /usr/bin/php /var/www/count168/auto_monthly_accounting.php >> /var/www/count168/auto_accounting_log.txt 2>&1
```

说明：`30 14 4 * *` = 每月 4 号 14:30。

---

## 三、今天 4 号但还没跑过？手动补跑

在项目目录下执行（例如今天就是 4 号、已经过了 14:30 但 Transaction 仍没数据时）：

```bash
php auto_monthly_accounting.php --force
```

- `--force` 表示不检查“是否 4 号”，直接执行一次。
- 脚本有防重复：同一天已经跑过会自动跳过，不会重复入账。

---

## 四、日志

- 运行记录会追加到项目目录下的 **`auto_accounting_log.txt`**。
- 若 Transaction 仍无数据，可先看该日志是否有报错或 “Skip” 说明。
