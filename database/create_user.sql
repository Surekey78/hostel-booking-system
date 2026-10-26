-- =====================================================================
--  HOSTEL ROOM BOOKING SYSTEM - application database account (optional)
--
--  Creates the dedicated user that includes/config.php connects with.
--  Skip this file if your host already gives you a DB user, and put those
--  credentials in includes/config.php instead.
--      mysql -u root -p < create_user.sql
-- =====================================================================
CREATE USER IF NOT EXISTS 'hostel_user'@'localhost' IDENTIFIED BY 'Hostel@2026';
GRANT ALL PRIVILEGES ON hostel_booking.* TO 'hostel_user'@'localhost';
FLUSH PRIVILEGES;
