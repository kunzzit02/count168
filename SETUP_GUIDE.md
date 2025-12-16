# Data Capture Submit - Quick Setup Guide

## 🎯 Quick Start (3 Steps)

### Step 1: Install Database Tables
You have two options:

#### Option A: Using Web Interface (Recommended for beginners)
1. Open your browser and navigate to:
   ```
   http://your-domain/install_data_capture_tables.php
   ```
2. Click the "Install Tables Now" button
3. Verify installation was successful

#### Option B: Using SQL Script (Recommended for advanced users)
1. Open your MySQL client or command line
2. Run the SQL script:
   ```bash
   mysql -u your_username -p your_database < create_data_capture_tables.sql
   ```
   Or copy the contents of `create_data_capture_tables.sql` and execute in your SQL client

### Step 2: Verify Installation
1. Open your browser and navigate to:
   ```
   http://your-domain/verify_data_capture_tables.php
   ```
2. Check that all statuses show ✅ Success
3. If you see any errors, re-run Step 1

### Step 3: Start Using
1. Go to Data Capture page:
   ```
   http://your-domain/datacapture.php
   ```
2. Fill in the form:
   - Select Date
   - Select Process
   - Select Currency
   - Create your data table
3. Click "Save" button
4. You'll be redirected to Summary page
5. Add account information to each row
6. Click "Submit" button
7. Your data will be saved to the database!

---

## 📊 What Gets Saved?

When you click Submit on the Data Capture Summary page, the system saves:

### Main Record (`data_captures` table):
- Date (from Data Capture form)
- Process (from Data Capture form)
- Currency (from Data Capture form)
- Created timestamp
- User ID (from session)

### Detail Records (`data_capture_details` table):
For each row in the summary table:
- ID Product
- Account
- Currency (can be different per row)
- Columns (e.g., "5+4")
- Source (e.g., "100+200")
- Source % (e.g., 50.00)
- Formula (e.g., "(100+200)*0.5")
- Processed Amount (calculated value)

---

## 🔍 Verification Tools

### 1. Verify Database Tables
**File:** `verify_data_capture_tables.php`
- Checks if tables exist
- Validates table structure
- Shows column details
- Checks foreign keys

### 2. Browser Console
Open browser Developer Tools (F12) and check Console tab:
- You'll see data being collected before submit
- You'll see the API response after submit
- Any errors will be logged here

### 3. Database Query
After submitting, check your database:
```sql
-- Check main records
SELECT * FROM data_captures ORDER BY id DESC LIMIT 10;

-- Check detail records for a specific capture
SELECT * FROM data_capture_details WHERE capture_id = 1;

-- Check with joined data
SELECT 
    dc.id,
    dc.capture_date,
    dcd.id_product,
    dcd.processed_amount
FROM data_captures dc
JOIN data_capture_details dcd ON dc.id = dcd.capture_id
ORDER BY dc.id DESC;
```

---

## 🐛 Troubleshooting

### Problem: "No process data found" error
**Solution:** 
- Make sure you came from the Data Capture page
- Make sure you clicked "Save" button on Data Capture page
- Check localStorage in browser (F12 > Application > Local Storage)

### Problem: "No data to submit" warning
**Solution:**
- Make sure you have added account information to at least one row
- Rows without account data will not be submitted
- Click the "+" button next to each row to add account data

### Problem: Database error on submit
**Solution:**
1. Check that tables are installed:
   - Run `verify_data_capture_tables.php`
2. Check database connection:
   - Verify `config.php` has correct credentials
3. Check user permissions:
   - Database user needs INSERT, SELECT permissions
4. Check browser console for specific error message

### Problem: Tables not created
**Solution:**
1. Check database user has CREATE TABLE permission
2. Check database supports InnoDB engine
3. Try using the web installer: `install_data_capture_tables.php`
4. Check error logs in `verify_data_capture_tables.php`

---

## 📁 Files Overview

### Core Files (Modified):
- `datacapturesummary.php` - Added submit functionality
- `datacapturesummaryapi.php` - Added submit API endpoint
- `datacapture.php` - (Already saves to localStorage)

### New Database Files:
- `create_data_capture_tables.sql` - SQL script to create tables
- `install_data_capture_tables.php` - Web interface to install tables
- `verify_data_capture_tables.php` - Verify tables are installed correctly

### Documentation:
- `DATA_CAPTURE_SUBMIT_README.md` - Detailed documentation
- `SETUP_GUIDE.md` - This file (quick setup guide)

---

## 🔐 Security Notes

1. **User Authentication**: Make sure users are logged in before submitting data
2. **Data Validation**: The system validates all input data
3. **SQL Injection**: All queries use prepared statements (protected)
4. **Transaction Safety**: Data is saved in a transaction (all-or-nothing)

---

## 📝 Next Steps After Installation

1. **Test the functionality:**
   - Create a test capture
   - Submit to database
   - Verify data was saved correctly

2. **Set up user permissions:**
   - Make sure only authorized users can access the pages
   - Add authentication checks if needed

3. **Backup your database:**
   - Regular backups are important
   - Test your backup/restore process

4. **Monitor the system:**
   - Check error logs regularly
   - Monitor database growth
   - Watch for any issues

---

## 💡 Tips

1. **Always fill in account data** before submitting - empty rows are skipped
2. **Check the summary table** before submitting to ensure all data is correct
3. **Use the Edit button (✏️)** to modify row data if needed
4. **Select multiple rows** and use "Batch Source Columns" to update them all at once
5. **Data is automatically cleared** from localStorage after successful submit

---

## 🆘 Need Help?

If you encounter any issues:

1. Check the browser console (F12) for error messages
2. Run `verify_data_capture_tables.php` to check database setup
3. Check the `DATA_CAPTURE_SUBMIT_README.md` for detailed information
4. Review the error logs in your PHP error log file

---

## ✅ Installation Checklist

- [ ] Database tables created (run `install_data_capture_tables.php`)
- [ ] Tables verified (run `verify_data_capture_tables.php`)
- [ ] Test capture created on Data Capture page
- [ ] Test capture submitted successfully
- [ ] Data verified in database
- [ ] Users can access and use the functionality

---

**Last Updated:** 2024
**Version:** 1.0

